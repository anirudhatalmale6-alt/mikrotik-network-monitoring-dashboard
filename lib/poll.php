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
function mt_detect_wan(RouterOs $ros, array $ifaces) {
    $candidates = [];
    try {
        foreach ($ros->query('/ip/route/print') as $r) {
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
        // No route table access - fall through to the name-based guess below.
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
 * Poll one device and write its status row.
 * Returns ['online'=>bool, 'error'=>string].
 */
function mt_poll_device(PDO $db, array $dev) {
    $timeout = max(2, (int)mt_setting('api_timeout', 6));
    // $now is stamped later, at the moment the byte counters are actually read.
    // Timing it from the start of the poll instead would divide the bytes by an
    // interval that includes the connect and login round trips, which vary.
    $now = $nowTs = null;

    $db->prepare("INSERT OR IGNORE INTO status (device_id) VALUES (?)")->execute([$dev['id']]);
    $prev = $db->prepare("SELECT * FROM status WHERE device_id=?");
    $prev->execute([$dev['id']]);
    $prev = $prev->fetch() ?: [];

    $ros = new RouterOs($timeout);
    try {
        $tcpMs = $ros->connect($dev['host'], $dev['api_port'], $dev['username'], $dev['password']);
    } catch (Exception $e) {
        $db->prepare("UPDATE status SET online=0, error=?, conn_status=?, ping_ms=0, rx_bps=0, tx_bps=0,
                      hotspot_users=0, ppp_users=0, cpu=0, ram_pct=0, last_try=? WHERE device_id=?")
           ->execute([$e->getMessage(), 'Unreachable: ' . $e->getMessage(), date('Y-m-d H:i:s'), $dev['id']]);
        return ['online' => false, 'error' => $e->getMessage()];
    }

    try {
        list($pingMs, $pingSrc) = mt_ping($dev['host'], $tcpMs);

        $res  = $ros->query('/system/resource/print');
        $r    = $res[0] ?? [];
        $totalMem = (int)($r['total-memory'] ?? 0);
        $freeMem  = (int)($r['free-memory'] ?? 0);
        $ramPct   = $totalMem > 0 ? (int)round(($totalMem - $freeMem) / $totalMem * 100) : 0;

        // Identity is cosmetic and costs a whole round trip - half a second on these
        // routers. Read it the first time and then leave it to the slow lane below,
        // rather than paying for it on every single poll.
        $identity = (string)($prev['identity'] ?? '');
        if ($identity === '') {
            try { $id = $ros->query('/system/identity/print'); $identity = (string)($id[0]['name'] ?? ''); }
            catch (Exception $e) { /* keep the stored one */ }
        }

        // A router without a hotspot or without PPP is normal, not a fault.
        $hotspot = 0;
        try { $hotspot = count($ros->query('/ip/hotspot/active/print')); } catch (Exception $e) {}
        $ppp = 0;
        try { $ppp = count($ros->query('/ppp/active/print')); } catch (Exception $e) {}

        $ifaces = $ros->query('/interface/print');
        // Stamp the reading here: these are the counters the rate is computed from.
        $nowTs = time();
        $now   = date('Y-m-d H:i:s', $nowTs);

        $wan = trim((string)$dev['wan_iface']);
        if ($wan === '' || !array_filter($ifaces, function ($i) use ($wan) { return $i['name'] === $wan; })) {
            $wan = mt_detect_wan($ros, $ifaces);
            if ($wan !== '') {
                $db->prepare("UPDATE devices SET wan_iface=? WHERE id=?")->execute([$wan, $dev['id']]);
            }
        }

        $rx = $tx = 0;
        foreach ($ifaces as $i) {
            if ($i['name'] === $wan) { $rx = (int)($i['rx-byte'] ?? 0); $tx = (int)($i['tx-byte'] ?? 0); }
        }

        // Counters are cumulative since boot. Two readings make a rate; a counter
        // that went backwards means the router rebooted, and that sample is dropped
        // rather than drawn as an enormous spike.
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

        // The router's own internet ping, refreshed on a slower clock than the rest
        // because each packet costs about a second of the poll.
        $netMs     = isset($prev['net_ping_ms']) ? $prev['net_ping_ms'] : null;
        $netTarget = (string)($prev['net_ping_target'] ?? '');
        $netAt     = $prev['net_ping_at'] ?? null;
        $netErr    = (string)($prev['net_ping_err'] ?? '');
        $target    = trim((string)mt_setting('net_ping_target', '8.8.8.8'));
        $every     = max(15, (int)mt_setting('net_ping_every', 60));
        // Back off after a failure: a router whose API user lacks the permission
        // must not be asked every minute forever.
        if ($netErr !== '') $every = max($every, 600);
        if ($target !== '' && (!$netAt || (time() - strtotime($netAt)) >= $every)) {
            list($netMs, $netErr) = mt_router_ping($ros, $target);
            $netTarget = $target;
            $netAt = $now;
            // Same slow clock: catch a router that has been renamed, without paying
            // for the question on every poll.
            try { $id = $ros->query('/system/identity/print'); $identity = (string)($id[0]['name'] ?? $identity); }
            catch (Exception $e) {}
        }

        $conn = 'Connected (RouterOS API' . (isset($r['version']) && $r['version'] !== '' ? ' ' . explode(' ', $r['version'])[0] : '') . ')';

        // If the live lane is streaming this router's speed once a second, its
        // figure is newer than anything derived here from two counters taken poll
        // interval apart. Writing ours would make the number jump backwards to an
        // older average every time the slow poll came round.
        $liveLane = mt_bw_alive($db);
        if ($liveLane) { $rxBps = (int)$prev['rx_bps']; $txBps = (int)$prev['tx_bps']; }

        $db->prepare("UPDATE status SET online=1, error='', conn_status=?, ping_ms=?, ping_source=?,
                        cpu=?, ram_pct=?, ram_total_mb=?, ram_free_mb=?, uptime=?, ros_version=?, board=?,
                        identity=?, hotspot_users=?, ppp_users=?, wan_iface=?, rx_bps=?, tx_bps=?,
                        last_rx=?, last_tx=?, traffic_bytes=traffic_bytes+?, last_at=?, last_seen=?, last_try=?,
                        net_ping_ms=?, net_ping_target=?, net_ping_at=?, net_ping_err=?
                      WHERE device_id=?")
           ->execute([
               $conn, $pingMs, $pingSrc,
               (int)($r['cpu-load'] ?? 0), $ramPct,
               (int)round($totalMem / 1048576), (int)round($freeMem / 1048576),
               mt_uptime_text($r['uptime'] ?? ''), (string)($r['version'] ?? ''), (string)($r['board-name'] ?? ''),
               $identity, $hotspot, $ppp, $wan, $rxBps, $txBps,
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

        $ros->close();
        return ['online' => true, 'error' => ''];
    } catch (Exception $e) {
        $ros->close();
        $db->prepare("UPDATE status SET online=0, error=?, conn_status=?, last_try=? WHERE device_id=?")
           ->execute([$e->getMessage(), 'Error: ' . $e->getMessage(), date('Y-m-d H:i:s'), $dev['id']]);
        return ['online' => false, 'error' => $e->getMessage()];
    }
}

/** Poll every enabled device once. */
function mt_poll_all(PDO $db, $verbose = false) {
    // Claim the slot for the interval gate in api.php, so a page request and the
    // background service do not both decide it is their turn.
    mt_set_setting('last_poll_started', (string)time());
    $devs = $db->query("SELECT * FROM devices WHERE enabled=1 ORDER BY sort_order, id")->fetchAll();
    foreach ($devs as $d) {
        $r = mt_poll_device($db, $d);
        if ($verbose) {
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
function mt_php_cli(PDO $db) {
    $cached = mt_setting_now($db, 'php_cli', '');
    $when   = (int)mt_setting_now($db, 'php_cli_at', 0);
    if ($cached !== '' && (time() - $when) < 86400) {
        return $cached === 'none' ? false : $cached;
    }

    $disabled = array_map('trim', explode(',', (string)ini_get('disable_functions')));
    if (!function_exists('exec') || in_array('exec', $disabled, true) || PHP_OS_FAMILY === 'Windows') {
        mt_set_setting('php_cli', 'none');
        mt_set_setting('php_cli_at', (string)time());
        return false;
    }

    $candidates = [];
    if (defined('PHP_BINARY') && PHP_BINARY !== '') $candidates[] = PHP_BINARY;
    $candidates[] = 'php';
    // CyberPanel / OpenLiteSpeed, newest first.
    foreach (['84', '83', '82', '81', '80', '74'] as $v) {
        $candidates[] = "/usr/local/lsws/lsphp$v/bin/php";
    }
    $candidates[] = '/usr/bin/php';
    $candidates[] = '/usr/local/bin/php';

    foreach (array_unique($candidates) as $bin) {
        $out = []; $rc = 1;
        @exec(escapeshellarg($bin) . ' -r ' . escapeshellarg('echo "MTOK";') . ' 2>/dev/null', $out, $rc);
        if ($rc === 0 && in_array('MTOK', array_map('trim', $out), true)) {
            mt_set_setting('php_cli', $bin);
            mt_set_setting('php_cli_at', (string)time());
            return $bin;
        }
    }
    mt_set_setting('php_cli', 'none');
    mt_set_setting('php_cli_at', (string)time());
    return false;
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
