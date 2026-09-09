<?php
/**
 * Reading one MikroTik and storing the result.
 *
 * Everything the dashboard shows comes from here. Nothing is invented: if the
 * router cannot be reached the device is marked offline with the real error, and
 * the figures stay at their last known values rather than being made up.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/routeros.php';

/** Round-trip time in ms. Uses ICMP where the host allows it, otherwise the TCP
 *  handshake to the API port, and always reports which one it measured - they are
 *  not the same thing and the difference matters when reading the number. */
function mt_ping($host, $tcpMs) {
    if (function_exists('exec') && !in_array('exec', array_map('trim', explode(',', (string)ini_get('disable_functions'))), true)) {
        $out = []; $rc = 1;
        @exec('ping -c 1 -W 1 ' . escapeshellarg($host) . ' 2>/dev/null', $out, $rc);
        if ($rc === 0) {
            foreach ($out as $line) {
                if (preg_match('/time[=<]([0-9.]+)\s*ms/i', $line, $m)) {
                    return [(float)$m[1], 'icmp'];
                }
            }
        }
    }
    return [round((float)$tcpMs, 1), 'tcp'];
}

/** RouterOS reports uptime as "22h9m29s" / "15d08:42:00" depending on version.
 *  Normalise to something readable without pretending to more precision. */
function mt_uptime_text($raw) {
    $raw = trim((string)$raw);
    if ($raw === '') return '';
    if (preg_match('/^(?:(\d+)w)?(?:(\d+)d)?(?:(\d+)h)?(?:(\d+)m)?(?:(\d+)s)?$/', $raw, $m)) {
        $d = (int)($m[1] ?? 0) * 7 + (int)($m[2] ?? 0);
        $h = (int)($m[3] ?? 0);
        $mi = (int)($m[4] ?? 0);
        if ($d > 0) return $d . 'd ' . $h . 'h ' . $mi . 'm';
        if ($h > 0) return $h . 'h ' . $mi . 'm';
        return $mi . 'm';
    }
    return $raw;
}

/**
 * RouterOS reports durations as "26ms", "1s26ms", "1m2s". Turn one into ms.
 * Returns null when there is no usable figure, so a timed-out packet is never
 * counted as a fast one.
 */
function mt_duration_ms($raw) {
    $raw = trim((string)$raw);
    if ($raw === '') return null;
    if (!preg_match_all('/(\d+(?:\.\d+)?)(ms|us|s|m|h)/', $raw, $m, PREG_SET_ORDER)) return null;
    $ms = 0.0;
    foreach ($m as $part) {
        $v = (float)$part[1];
        switch ($part[2]) {
            case 'us': $ms += $v / 1000; break;
            case 'ms': $ms += $v; break;
            case 's':  $ms += $v * 1000; break;
            case 'm':  $ms += $v * 60000; break;
            case 'h':  $ms += $v * 3600000; break;
        }
    }
    return $ms;
}

/**
 * Ask the ROUTER to ping the internet and report what it saw.
 *
 * This is the number an operator recognises from WinBox, and it is a different
 * measurement from ping_ms: this is router -> internet, ping_ms is this server ->
 * router. On a router in Bangladesh monitored from Europe the two legitimately
 * differ by an order of magnitude, and showing only one of them is what makes the
 * dashboard look wrong.
 *
 * /ping costs about a second per packet, so it runs on its own slower schedule.
 * Returns [ms|null, error]; the error is kept so "no permission" is visible rather
 * than looking like a router that cannot reach the internet.
 */
function mt_router_ping(RouterOs $ros, $target, $count = 3) {
    try {
        $rows = $ros->query('/ping', ['=address=' . $target, '=count=' . (int)$count]);
    } catch (Exception $e) {
        $msg = $e->getMessage();
        // The API user needs the "test" policy for /ping. Say so plainly - it is a
        // one-tick fix on the router, not a fault in the link.
        if (stripos($msg, 'not enough permissions') !== false) {
            return [null, 'API user needs the "test" permission on the router'];
        }
        return [null, $msg];
    }
    $best = null;
    foreach ($rows as $r) {
        if (isset($r['status']) && $r['status'] !== '' && stripos($r['status'], 'timeout') !== false) continue;
        $ms = mt_duration_ms($r['time'] ?? '');
        if ($ms === null) continue;
        if ($best === null || $ms < $best) $best = $ms;   // best of N, like ping's min
    }
    if ($best === null) return [null, 'no reply from ' . $target];
    return [round($best, 1), ''];
}

/**
 * Which interface faces the internet.
 *
 * A router counts the same packet on the bridge, on the member port and on the
 * WAN port, so "speed" has to come from ONE interface or the number is several
 * times too big. The default route says which one, but it can name more than one
 * at a time: on a PPPoE site the session rides on ether1, so both appear, and a
 * VPN tunnel installs one too without being an internet feed. Preference:
 * PPPoE/LTE session, then a physical port, and a tunnel only as a last resort.
 */
function mt_detect_wan(array $routes, array $ifaces) {
    $candidates = [];
    try {
        foreach ($routes as $r) {
            $dst = trim((string)($r['dst-address'] ?? ''));
            if ($dst !== '0.0.0.0/0' && $dst !== '::/0') continue;
            if (isset($r['active']) && in_array(strtolower($r['active']), ['false', 'no'], true)) continue;
            $gw = (string)($r['immediate-gw'] ?? '');
            if (strpos($gw, '%') !== false) { $candidates[] = trim(explode('%', $gw, 2)[1]); continue; }
            $st = (string)($r['gateway-status'] ?? '');
            if (strpos($st, ' via ') !== false) { $candidates[] = trim(explode(' via ', $st, 2)[1]); continue; }
            $g = trim((string)($r['gateway'] ?? ''));
            if ($g !== '' && !preg_match('/\d/', $g)) $candidates[] = $g;   // bare interface name
        }
    } catch (Exception $e) {
        // No route table - fall through to the name-based guess below.
    }

    $types = [];
    foreach ($ifaces as $i) $types[$i['name']] = strtolower((string)($i['type'] ?? ''));

    $tunnels = ['l2tp', 'pptp', 'sstp', 'ovpn', 'gre', 'ipip', 'eoip', 'wg'];
    $rank = function ($name) use ($types, $tunnels) {
        $t = $types[$name] ?? '';
        foreach ($tunnels as $x) if (strpos($t, $x) !== false) return 0;
        if (strpos($t, 'pppoe') !== false || $t === 'lte' || $t === 'wwan') return 3;
        if ($t === 'ether' || strpos($t, 'sfp') !== false) return 2;
        return 1;
    };

    $best = ''; $bestRank = -1;
    foreach (array_unique($candidates) as $c) {
        if ($c === '' || !isset($types[$c])) continue;
        $r = $rank($c);
        if ($r > $bestRank) { $bestRank = $r; $best = $c; }
    }
    if ($best !== '') return $best;

    // Nothing usable from the routing table: fall back to the busiest running
    // physical port, and let the admin correct it in the UI.
    $bestBytes = -1;
    foreach ($ifaces as $i) {
        if (!empty($i['disabled']) && $i['disabled'] === 'true') continue;
        if (($i['running'] ?? '') !== 'true') continue;
        $bytes = (int)($i['rx-byte'] ?? 0) + (int)($i['tx-byte'] ?? 0);
        if ($bytes > $bestBytes) { $bestBytes = $bytes; $best = $i['name']; }
    }
    return $best;
}

/**
 * Write one device's status row from data already fetched.
 *
 * Kept separate from the fetching so there is exactly one place that decides
 * what a reading means - the rate maths, the wrong-port guard and the live-lane
 * rule are subtle enough that having a second copy of them would guarantee the
 * two drifted apart.
 */
function mt_store_poll(PDO $db, array $dev, array $prev, array $d) {
    $r        = $d['resource'];
    $ifaces   = $d['ifaces'];
    $routes   = $d['routes'];
    $totalMem = (int)($r['total-memory'] ?? 0);
    $freeMem  = (int)($r['free-memory'] ?? 0);
    $ramPct   = $totalMem > 0 ? (int)round(($totalMem - $freeMem) / $totalMem * 100) : 0;

    $identity = $d['identity'] !== '' ? $d['identity'] : (string)($prev['identity'] ?? '');
    $hotspot  = (int)$d['hotspot'];
    $ppp      = (int)$d['ppp'];

    // Stamp the reading at the moment the counters were read, not at the start of
    // the poll: dividing the bytes by an interval that includes the connect and
    // login round trips would make the rate depend on how slow the login was.
    $nowTs = (int)$d['ts'];
    $now   = date('Y-m-d H:i:s', $nowTs);

    $wan = trim((string)$dev['wan_iface']);
    if ($wan === '' || !array_filter($ifaces, function ($i) use ($wan) { return $i['name'] === $wan; })) {
        $wan = mt_detect_wan($routes, $ifaces);
        if ($wan !== '') {
            $db->prepare("UPDATE devices SET wan_iface=? WHERE id=?")->execute([$wan, $dev['id']]);
        }
    }

    /**
     * Guard against measuring the wrong port.
     *
     * The interface exists, so the check above is happy, but it is a port that
     * carries nothing - an unused ether, or the WAN from before the ISP was
     * moved. Its counters never move, so the dashboard reports 0 bps and 0 bytes
     * for ever while CPU, memory, uptime and the user count all keep updating.
     *
     * Byte counters are cumulative since boot, so one reading settles it: a port
     * that has passed a few kilobytes next to one that has passed hundreds of
     * gigabytes is not the internet feed.
     */
    $wanBytes = null;
    $busiest = ['name' => '', 'bytes' => 0];
    foreach ($ifaces as $i) {
        $bytes = (int)($i['rx-byte'] ?? 0) + (int)($i['tx-byte'] ?? 0);
        if ($i['name'] === $wan) $wanBytes = $bytes;
        if (($i['running'] ?? '') !== 'true') continue;
        if (($i['disabled'] ?? 'false') === 'true') continue;
        if ($bytes > $busiest['bytes']) $busiest = ['name' => $i['name'], 'bytes' => $bytes];
    }

    $wanNote = '';
    if ($wanBytes !== null && $busiest['bytes'] > 10000000 && $wanBytes < $busiest['bytes'] / 100) {
        // Re-detect rather than jumping to the busiest interface: a bridge and its
        // member port both count the same packet, and the default route is what
        // says which of them is the real feed.
        $again = mt_detect_wan($routes, $ifaces);
        $pick  = ($again !== '' && $again !== $wan) ? $again : $busiest['name'];
        if ($pick !== '' && $pick !== $wan) {
            $wanNote = 'No traffic was passing on ' . $wan . ', so the speed is now read from ' . $pick . '.';
            $wan = $pick;
            $db->prepare("UPDATE devices SET wan_iface=? WHERE id=?")->execute([$wan, $dev['id']]);
            // The stored counters belong to the old interface; keeping them would
            // draw one enormous fake spike on the next poll.
            $prev['last_rx'] = null;
            $prev['last_tx'] = null;
        } else {
            $wanNote = 'No traffic is passing on ' . $wan . ' - check which port faces the internet.';
        }
    }

    $rx = $tx = 0;
    foreach ($ifaces as $i) {
        if ($i['name'] === $wan) { $rx = (int)($i['rx-byte'] ?? 0); $tx = (int)($i['tx-byte'] ?? 0); }
    }

    // Counters are cumulative since boot. Two readings make a rate; a counter that
    // went backwards means the router rebooted, and that sample is dropped rather
    // than drawn as an enormous spike.
    $rxBps = $txBps = 0;
    $moved = 0;
    if (!empty($prev['last_at']) && $prev['last_rx'] !== null) {
        $elapsed = $nowTs - strtotime($prev['last_at']);
        $maxGap  = max(60, (int)mt_setting('poll_seconds', 10) * 10);
        if ($elapsed > 0 && $elapsed <= $maxGap && $rx >= (int)$prev['last_rx'] && $tx >= (int)$prev['last_tx']) {
            $dRx = $rx - (int)$prev['last_rx'];
            $dTx = $tx - (int)$prev['last_tx'];
            $rxBps = (int)round($dRx * 8 / $elapsed);
            $txBps = (int)round($dTx * 8 / $elapsed);
            $moved = $dRx + $dTx;
        }
    }

    // The router's own ping to the internet, on its slower clock.
    $netMs     = isset($prev['net_ping_ms']) ? $prev['net_ping_ms'] : null;
    $netTarget = (string)($prev['net_ping_target'] ?? '');
    $netAt     = $prev['net_ping_at'] ?? null;
    $netErr    = (string)($prev['net_ping_err'] ?? '');
    if ($d['netPing'] !== null) {
        list($netMs, $netErr) = $d['netPing'];
        $netTarget = (string)$d['netTarget'];
        $netAt = $now;
    }

    /**
     * Keep the explanation on screen for a few hours after the interface was
     * switched. Without this the note is written by the one poll that made the
     * change and wiped by the next one seconds later - he would see the figures
     * come back to life and never learn why they had been zero.
     */
    $noteAt = $prev['wan_note_at'] ?? null;
    if ($wanNote !== '') {
        $noteAt = $now;
    } else {
        $prevNote = (string)($prev['wan_note'] ?? '');
        if ($prevNote !== '' && $noteAt && (time() - strtotime($noteAt)) < 21600) {
            $wanNote = $prevNote;          // still recent: leave it up
        } else {
            $noteAt = null;
        }
    }

    $conn = 'Connected (RouterOS API' . (isset($r['version']) && $r['version'] !== '' ? ' ' . explode(' ', $r['version'])[0] : '') . ')';

    // Whoever wrote this row's speed most recently owns it: the lane when it is
    // streaming, but also the direct reader on a host where no lane can run.
    // Checking only for the lane meant the poll overwrote the direct reader's
    // figure every cycle, dropping it to zero whenever this poll had no usable
    // pair of counters of its own.
    $bwFresh  = !empty($prev['bw_at']) && (time() - strtotime($prev['bw_at'])) <= 10;
    $liveLane = mt_bw_alive($db) || $bwFresh;
    if ($liveLane) { $rxBps = (int)$prev['rx_bps']; $txBps = (int)$prev['tx_bps']; }

    mt_db_write($db, "UPDATE status SET online=1, error='', conn_status=?, ping_ms=?, ping_source=?,
                    cpu=?, ram_pct=?, ram_total_mb=?, ram_free_mb=?, uptime=?, ros_version=?, board=?,
                    identity=?, hotspot_users=?, ppp_users=?, wan_iface=?, wan_note=?, wan_note_at=?, rx_bps=?, tx_bps=?,
                    last_rx=?, last_tx=?, traffic_bytes=traffic_bytes+?, last_at=?, last_seen=?, last_try=?,
                    net_ping_ms=?, net_ping_target=?, net_ping_at=?, net_ping_err=?
                  WHERE device_id=?",
        [
            $conn, round((float)$d['tcpMs'], 1), 'tcp',
            (int)($r['cpu-load'] ?? 0), $ramPct,
            (int)round($totalMem / 1048576), (int)round($freeMem / 1048576),
            mt_uptime_text($r['uptime'] ?? ''), (string)($r['version'] ?? ''), (string)($r['board-name'] ?? ''),
            $identity, $hotspot, $ppp, $wan, $wanNote, $noteAt, $rxBps, $txBps,
            $rx, $tx, max(0, $moved), $now, $now, $now,
            $netMs, $netTarget, $netAt, $netErr,
            $dev['id'],
        ]);

    // Keep the reported RouterOS version in the device record in step with
    // reality, so the admin form is not showing a value from an old firmware.
    if (!empty($r['version'])) {
        $db->prepare("UPDATE devices SET ros_version=? WHERE id=?")->execute([(string)$r['version'], $dev['id']]);
    }

    if (!$liveLane && ($rxBps > 0 || $txBps > 0 || $moved > 0)) {
        $db->prepare("INSERT INTO samples (device_id, ts, rx_bps, tx_bps) VALUES (?,?,?,?)")
           ->execute([$dev['id'], $nowTs, $rxBps, $txBps]);
    }

    if ($d['disco'] !== null) {
        list($dCount, $dInfra, $dErr) = $d['disco'];
        mt_db_write($db, "UPDATE status SET disco_at=?, disco_count=?, disco_infra=?, disco_err=?
                          WHERE device_id=?",
                    [date('Y-m-d H:i:s'), $dCount, $dInfra, $dErr, $dev['id']]);
    }

    return ['online' => true, 'error' => ''];
}

/** Is this device due for one of the things that runs on a slower clock? */
function mt_poll_due(array $prev) {
    $netErr = (string)($prev['net_ping_err'] ?? '');
    $every  = max(15, (int)mt_setting('net_ping_every', 60));
    // Back off after a failure: a router whose API user lacks the permission must
    // not be asked every minute for ever.
    if ($netErr !== '') $every = max($every, 600);
    $netAt  = $prev['net_ping_at'] ?? null;
    $target = trim((string)mt_setting('net_ping_target', '8.8.8.8'));
    $net    = $target !== '' && (!$netAt || (time() - strtotime($netAt)) >= $every);

    $disco = false;
    if (mt_setting('discover_enabled', '1') === '1') {
        $dEvery = max(60, (int)mt_setting('discover_every', 300));
        $dAt    = $prev['disco_at'] ?? null;
        $disco  = !$dAt || (time() - strtotime($dAt)) >= $dEvery;
    }
    return ['net' => $net, 'disco' => $disco, 'target' => $target];
}

/**
 * Poll a list of devices, all at the same time.
 *
 * Everything here is waiting on the network, so the routers are worked on
 * together rather than one after another, and each one is asked everything it
 * needs in a single tagged batch. Ten routers 160 ms away took 24 seconds one at
 * a time; this is about one round trip regardless of how many there are.
 *
 * Returns [device_id => ['online'=>bool, 'error'=>string]].
 */
function mt_poll_many(PDO $db, array $devs) {
    require_once __DIR__ . '/parallel.php';
    require_once __DIR__ . '/discover.php';

    $timeout = max(2, (int)mt_setting('api_timeout', 6));
    $out = [];
    if (!$devs) return $out;

    $prevs = [];
    foreach ($devs as $dev) {
        $db->prepare("INSERT OR IGNORE INTO status (device_id) VALUES (?)")->execute([$dev['id']]);
        $st = $db->prepare("SELECT * FROM status WHERE device_id=?");
        $st->execute([$dev['id']]);
        $prevs[$dev['id']] = $st->fetch() ?: [];
    }

    $conns = mt_par_open($devs, $timeout);
    mt_par_login($conns, $timeout);

    // One batch for the things every poll needs. count-only on the session tables
    // matters: a busy hotspot answers /ip/hotspot/active/print with hundreds of
    // rows, and the dashboard only ever shows the number.
    $commands = [
        'res'   => ['/system/resource/print'],
        'if'    => ['/interface/print'],
        'route' => ['/ip/route/print'],
        'hs'    => ['/ip/hotspot/active/print', '=count-only='],
        'ppp'   => ['/ppp/active/print', '=count-only='],
    ];
    mt_par_batch($conns, $commands, $timeout + 6);
    $ts = time();
    // Keep this batch's answers: the slow batch below reuses $c->results, and
    // reading them afterwards would hand back the wrong set - which showed up as
    // every router reporting "did not answer /system/resource" while its figures
    // were plainly being collected.
    foreach ($conns as $c) $c->fastResults = $c->results;

    // A second batch, only for the routers due for something slow, and again all
    // at once. The router's own ping costs about a second per packet, so it must
    // never sit in front of the traffic figures.
    $slow = [];
    foreach ($conns as $c) {
        if (!$c->alive()) continue;
        $prev = $prevs[$c->dev['id']];
        $due  = mt_poll_due($prev);
        $c->due = $due;
        $cmds = [];
        if ((string)($prev['identity'] ?? '') === '' || $due['net']) {
            $cmds['ident'] = ['/system/identity/print'];
        }
        if ($due['net'])   $cmds['ping'] = ['/ping', '=address=' . $due['target'], '=count=2'];
        if ($due['disco']) {
            $cmds['nb']    = ['/ip/neighbor/print'];
            $cmds['arp']   = ['/ip/arp/print'];
            $cmds['lease'] = ['/ip/dhcp-server/lease/print'];
        }
        if ($cmds) { $c->slowCmds = $cmds; $slow[] = $c; }
    }
    if ($slow) {
        // Every slow batch has the same shape per connection, but mt_par_batch
        // sends one command list to all of them - so group by that list.
        $groups = [];
        foreach ($slow as $c) $groups[implode(',', array_keys($c->slowCmds))][] = $c;
        foreach ($groups as $g) {
            mt_par_batch($g, $g[0]->slowCmds, $timeout + 8);
            foreach ($g as $c) $c->slowResults = $c->results;
        }
    }

    foreach ($conns as $c) {
        $dev  = $c->dev;
        $prev = $prevs[$dev['id']];
        if (!$c->alive()) {
            $err = $c->err !== '' ? $c->err : 'connection failed';
            mt_db_write($db, "UPDATE status SET online=0, error=?, conn_status=?, ping_ms=0, rx_bps=0, tx_bps=0,
                              hotspot_users=0, ppp_users=0, cpu=0, ram_pct=0, last_try=? WHERE device_id=?",
                        [$err, 'Unreachable: ' . $err, date('Y-m-d H:i:s'), $dev['id']]);
            $out[$dev['id']] = ['online' => false, 'error' => $err];
            continue;
        }

        $fast = $c->fastResults;                   // from the first batch
        $slowR = isset($c->slowResults) ? $c->slowResults : [];

        $res = $fast['res'][0] ?? null;
        if ($res === null) {
            $err = 'the router did not answer /system/resource';
            mt_db_write($db, "UPDATE status SET online=0, error=?, conn_status=?, last_try=? WHERE device_id=?",
                        [$err, 'Error: ' . $err, date('Y-m-d H:i:s'), $dev['id']]);
            $out[$dev['id']] = ['online' => false, 'error' => $err];
            continue;
        }

        // count-only answers with =ret=N and no rows; a router with no hotspot
        // traps instead, which leaves no result at all - both mean "none".
        $countOf = function ($tag) use ($fast) {
            if (!isset($fast[$tag][0]['ret'])) return 0;
            return (int)$fast[$tag][0]['ret'];
        };

        $netPing = null;
        if (isset($slowR['ping'])) {
            $best = null;
            foreach ($slowR['ping'] as $row) {
                if (isset($row['status']) && $row['status'] !== '' && stripos($row['status'], 'timeout') !== false) continue;
                $ms = mt_duration_ms($row['time'] ?? '');
                if ($ms === null) continue;
                if ($best === null || $ms < $best) $best = $ms;
            }
            $netPing = $best === null
                ? [null, 'no reply from ' . $c->due['target']]
                : [round($best, 1), ''];
        } elseif (!empty($c->due['net'])) {
            // Asked for and not answered: the API user needs the "test" policy.
            $netPing = [null, 'API user needs the "test" permission on the router'];
        }

        $disco = null;
        if (!empty($c->due['disco'])) {
            $errs = [];
            if (!isset($slowR['lease'])) $errs[] = 'DHCP leases: not available on this router';
            try {
                $disco = mt_discover_store($db, $dev['id'],
                    $slowR['nb'] ?? [], $slowR['arp'] ?? [], $slowR['lease'] ?? [], $errs);
            } catch (Exception $e) {
                $disco = [0, 0, $e->getMessage()];
            }
        }

        try {
            $out[$dev['id']] = mt_store_poll($db, $dev, $prev, [
                'tcpMs'     => $c->tcpMs,
                'resource'  => $res,
                'identity'  => (string)($slowR['ident'][0]['name'] ?? ''),
                'hotspot'   => $countOf('hs'),
                'ppp'       => $countOf('ppp'),
                'ifaces'    => $fast['if'] ?? [],
                'routes'    => $fast['route'] ?? [],
                'ts'        => $ts,
                'netPing'   => $netPing,
                'netTarget' => $c->due['target'] ?? '',
                'disco'     => $disco,
            ]);
        } catch (Exception $e) {
            mt_db_write($db, "UPDATE status SET online=0, error=?, conn_status=?, last_try=? WHERE device_id=?",
                        [$e->getMessage(), 'Error: ' . $e->getMessage(), date('Y-m-d H:i:s'), $dev['id']]);
            $out[$dev['id']] = ['online' => false, 'error' => $e->getMessage()];
        }
    }

    mt_par_close($conns);
    return $out;
}

/**
 * Poll one device. The same path as a full poll, with a list of one - there is
 * no second implementation to drift out of step.
 */
function mt_poll_device(PDO $db, array $dev) {
    $r = mt_poll_many($db, [$dev]);
    return $r[$dev['id']] ?? ['online' => false, 'error' => 'no result'];
}

function mt_poll_all(PDO $db, $verbose = false) {
    // Claim the slot for the interval gate in api.php, so a page request and the
    // background service do not both decide it is their turn.
    mt_set_setting('last_poll_started', (string)time());
    $devs = $db->query("SELECT * FROM devices WHERE enabled=1 ORDER BY sort_order, id")->fetchAll();
    $res  = mt_poll_many($db, $devs);
    if ($verbose) {
        foreach ($devs as $d) {
            $r = $res[$d['id']] ?? ['online' => false, 'error' => 'no result'];
            printf("%-22s %s%s\n", $d['name'], $r['online'] ? 'online' : 'OFFLINE',
                   $r['error'] !== '' ? '  (' . $r['error'] . ')' : '');
        }
    }

    // The combined graph point. The lane writes its own once a second; this is the
    // fallback for a host where the lane cannot run, so the chart is never empty.
    if (!mt_bw_alive($db)) {
        $t = $db->query("SELECT COALESCE(SUM(rx_bps),0) rx, COALESCE(SUM(tx_bps),0) tx
                           FROM status s JOIN devices d ON d.id=s.device_id
                          WHERE s.online=1 AND d.enabled=1")->fetch();
        $db->prepare("INSERT INTO totals (ts, rx_bps, tx_bps) VALUES (?,?,?)
                      ON CONFLICT(ts) DO UPDATE SET rx_bps=excluded.rx_bps, tx_bps=excluded.tx_bps")
           ->execute([time(), (int)$t['rx'], (int)$t['tx']]);
    }

    mt_trim_history($db);
    return count($devs);
}

/** Keep the graph history bounded - this runs forever on a small server. */
function mt_trim_history(PDO $db) {
    $keep = max(30, (int)mt_setting('history_points', 180));
    // The lane produces a point per second; the ordinary poll one per interval.
    // Size the window for whichever is actually filling the table.
    $step = mt_bw_alive($db) ? 1 : max(5, (int)mt_setting('poll_seconds', 10));
    $cutoff = time() - ($keep * $step) - 60;
    $db->prepare("DELETE FROM samples WHERE ts < ?")->execute([$cutoff]);
    $db->prepare("DELETE FROM totals  WHERE ts < ?")->execute([$cutoff]);
}

/**
 * Find a PHP command line binary that actually works, or false.
 *
 * The dashboard used to shell out to a bare "php", which is not on PATH for the web
 * user on plenty of hosts - CyberPanel and OpenLiteSpeed ship lsphp under
 * /usr/local/lsws/lsphpXX/bin/php. exec() then failed silently, and because the
 * poll had already been marked as started nothing ever polled again.
 *
 * So this does not guess: every candidate is asked to print a token, and only a
 * binary that prints it is used. The answer is cached because the check costs a
 * process, and re-checked occasionally in case the host changes.
 */
define('MT_CLI_PROBE_VERSION', 2);   // bump whenever the candidate list changes

function mt_php_cli(PDO $db) {
    $cached = mt_setting_now($db, 'php_cli', '');
    $when   = (int)mt_setting_now($db, 'php_cli_at', 0);
    $ver    = (int)mt_setting_now($db, 'php_cli_ver', 0);

    // A cached answer must not outlive the code that produced it. The first version
    // of this refused to look at all on Windows and wrote "none"; after the fix, that
    // stale "none" would have gone on blocking the live lane for another day on every
    // installation that had already run once. And "none" is a failure, not a fact
    // about the host, so it is retried in minutes while a working binary is trusted
    // for a day.
    if ($cached !== '' && $ver === MT_CLI_PROBE_VERSION) {
        $maxAge = $cached === 'none' ? 600 : 86400;
        if ((time() - $when) < $maxAge) return $cached === 'none' ? false : $cached;
    }

    $remember = function ($value, $why) {
        mt_set_setting('php_cli', $value);
        mt_set_setting('php_cli_at', (string)time());
        mt_set_setting('php_cli_ver', (string)MT_CLI_PROBE_VERSION);
        mt_set_setting('php_cli_why', $why);
    };

    // Which of these the host forbids decides what the answer is, so name it rather
    // than reporting a bare failure: "exec is disabled" and "no PHP binary found"
    // need completely different fixes.
    $disabled = array_map('trim', explode(',', (string)ini_get('disable_functions')));
    $blocked = [];
    foreach (['exec', 'popen'] as $fn) {
        if (!function_exists($fn) || in_array($fn, $disabled, true)) $blocked[] = $fn;
    }
    if ($blocked) {
        $remember('none', 'PHP on this host has ' . implode(' and ', $blocked) . ' disabled');
        return false;
    }

    $win = PHP_OS_FAMILY === 'Windows';
    $candidates = [];
    if (defined('PHP_BINARY') && PHP_BINARY !== '') $candidates[] = PHP_BINARY;

    if ($win) {
        // Under Apache or IIS, PHP_BINARY is the web server executable, not php.exe.
        // PHP_BINDIR is where the CLI actually lives, and the usual local stacks put
        // it somewhere predictable - tested for real below, never assumed.
        if (defined('PHP_BINDIR') && PHP_BINDIR !== '') $candidates[] = PHP_BINDIR . '\\php.exe';
        $candidates[] = 'php';
        foreach (['C:\\xampp\\php\\php.exe', 'C:\\wamp64\\bin\\php\\php.exe',
                  'C:\\php\\php.exe', 'C:\\laragon\\bin\\php\\php.exe'] as $p) $candidates[] = $p;
        foreach (['C:\\laragon\\bin\\php\\*\\php.exe', 'C:\\wamp64\\bin\\php\\*\\php.exe',
                  'C:\\Program Files\\php*\\php.exe'] as $g) {
            foreach ((array)@glob($g) as $hit) $candidates[] = $hit;
        }
    } else {
        $candidates[] = 'php';
        // CyberPanel / OpenLiteSpeed, newest first.
        foreach (['84', '83', '82', '81', '80', '74'] as $v) {
            $candidates[] = "/usr/local/lsws/lsphp$v/bin/php";
        }
        $candidates[] = '/usr/bin/php';
        $candidates[] = '/usr/local/bin/php';
    }

    foreach (array_unique($candidates) as $bin) {
        $out = []; $rc = 1;
        // Windows escapeshellarg strips double quotes, which would mangle a probe
        // written with them - so the probe uses single quotes and its own quoting.
        $cmd = $win
            ? mt_win_cmd(str_replace('"', '', $bin), ['-r', "echo 'MTOK';"], false)
            : escapeshellarg($bin) . ' -r ' . escapeshellarg('echo "MTOK";') . ' 2>/dev/null';
        @exec($cmd, $out, $rc);
        if ($rc === 0 && in_array('MTOK', array_map('trim', $out), true)) {
            $remember($bin, '');
            return $bin;
        }
    }
    $remember('none', 'no working PHP command line was found on this host');
    return false;
}

/**
 * Build a command line for cmd.exe.
 *
 * cmd.exe strips the outermost pair of quotes when the command it is given both
 * starts and ends with one, which silently breaks a quoted program path that has a
 * space in it - "C:\Program Files\...". The documented answer is to wrap the whole
 * line in one more pair, and only then.
 *
 * $background prefixes "start /B", which hands the process off and returns at once.
 */
function mt_win_cmd($bin, array $args, $background) {
    $line = '"' . $bin . '"';
    foreach ($args as $a) $line .= ' "' . str_replace('"', '', $a) . '"';
    // The empty "" is the window title, which start would otherwise take from the
    // first quoted argument - and then try to run the title as the program. With
    // that title in place start already handles a spaced path, so the extra pair
    // below must NOT be added here as well: it would be parsed as the title.
    if ($background) return 'start /B "" ' . $line;
    if (strpos($bin, ' ') !== false) $line = '"' . $line . '"';
    return $line;
}

/**
 * Start a command in the background and return immediately.
 *
 * Windows has no "&", and proc_close() would wait for the child - which is exactly
 * what must not happen here. "start /B" hands the process to the shell, and the
 * shell itself exits at once, so pclose() does not block.
 */
function mt_spawn($bin, array $args) {
    if (PHP_OS_FAMILY === 'Windows') {
        $h = @popen(mt_win_cmd(str_replace('"', '', $bin), $args, true), 'r');
        if ($h === false) return false;
        pclose($h);
        return true;
    }
    $cmd = escapeshellarg($bin);
    foreach ($args as $a) $cmd .= ' ' . escapeshellarg($a);

    // Detach properly where the system allows it. LiteSpeed and php-fpm can reap
    // what is still a child of the request once the request ends, which kills a
    // lane that was starting perfectly well - setsid puts it in its own session and
    // nohup keeps the hangup from reaching it.
    static $prefix = null;
    if ($prefix === null) {
        $out = []; $rc = 1;
        @exec('command -v setsid 2>/dev/null', $out, $rc);
        $prefix = ($rc === 0 && !empty($out[0])) ? 'setsid nohup ' : 'nohup ';
    }
    @exec($prefix . $cmd . ' > /dev/null 2>&1 &');
    return true;
}

/**
 * Only one poll at a time.
 *
 * The background service polls on its own clock while a page view can also
 * trigger one. Two pollers would each compute a rate from a baseline the other
 * had already moved, inventing spikes that never happened on the wire.
 *
 * The lock file lives next to the database, NOT in /tmp: a service started with
 * systemd PrivateTmp gets a different /tmp from a CLI run, and a lock in a
 * world-writable sticky directory can be refused by fs.protected_regular. Either
 * way the lock would silently protect nothing.
 */
function mt_lock($waitSeconds = 0) {
    if (!is_dir(MT_DATA)) @mkdir(MT_DATA, 0775, true);
    $fh = @fopen(MT_DATA . '/poll.lock', 'c');
    if (!$fh) return null;                 // cannot lock; caller decides
    $deadline = time() + $waitSeconds;
    do {
        if (flock($fh, LOCK_EX | LOCK_NB)) return $fh;
        if (time() >= $deadline) break;
        usleep(200000);
    } while (true);
    fclose($fh);
    return false;
}

function mt_unlock($fh) {
    if (is_resource($fh)) { flock($fh, LOCK_UN); fclose($fh); }
}
