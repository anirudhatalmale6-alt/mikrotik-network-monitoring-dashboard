<?php
/**
 * The fast lane: live download / upload figures, once per second.
 *
 * Why this exists. The ordinary poll opens a connection to each router, logs in
 * and asks six questions (resource, identity, hotspot, PPP, interfaces, routes).
 * Measured against this client's routers, each of those questions costs one round
 * trip of 400-550 ms, so a single router takes 4-6 seconds and three routers take
 * about sixteen. Bandwidth read on that clock is not live, and no amount of
 * refreshing the page changes it - the delay is on the wire, not in the browser.
 *
 * So bandwidth is taken off that clock entirely. This keeps ONE connection open
 * per router and asks RouterOS for /interface/monitor-traffic without =once=,
 * which is what the WinBox traffic graph uses: the router then pushes a new
 * reading every second on its own. After the first command there are no requests
 * left to pay for, so the figure updates once per second no matter how far away
 * the router is.
 *
 * It writes only rx_bps / tx_bps and the history points. Everything else - CPU,
 * RAM, users, uptime, ping - stays with the ordinary poller in its own process,
 * because a slow full poll must never be able to stall the live numbers.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/routeros.php';
require_once __DIR__ . '/poll.php';   // mt_php_cli, and the lock helpers

/**
 * True while dispatching a lane is known not to work on this host.
 *
 * Set by the watchdog in mt_maybe_bw after repeated dispatches produced no
 * heartbeat, and it expires on its own so a host that is fixed - exec re-enabled,
 * a process limit raised - starts streaming again without anyone intervening.
 */
function mt_bw_spawn_broken(PDO $db) {
    return (int)mt_setting_now($db, 'bw_no_spawn_until', 0) > time();
}

/** Its own lock, separate from the poll lock: the lane and the ordinary poller are
 *  meant to run at the same time, they just must not each run twice. */
function mt_bw_lock() {
    if (!is_dir(MT_DATA)) @mkdir(MT_DATA, 0775, true);
    $fh = @fopen(MT_DATA . '/bw.lock', 'c');
    if (!$fh) return null;
    if (flock($fh, LOCK_EX | LOCK_NB)) return $fh;
    fclose($fh);
    return false;
}

/**
 * Run the lane until $lifetime seconds have passed (0 = forever, for a service).
 *
 * Kept short-lived by default: on hosting with no systemd the lane is started by
 * a page request, and a process that outlives the interest in it is a process
 * nobody will ever stop.
 */
function mt_bw_run(PDO $db, $lifetime = 120, $verbose = false, $onTick = null) {
    $deadline  = $lifetime > 0 ? time() + $lifetime : PHP_INT_MAX;
    $conns     = [];     // device_id => ['ros'=>RouterOs,'iface'=>string,'name'=>string]
    $rates     = [];     // device_id => ['rx'=>int,'tx'=>int,'at'=>int]
    $retryAt   = [];     // device_id => unix time before which we do not retry
    $fails     = [];     // device_id => consecutive connect failures, for the backoff
    $lastDevs  = 0;      // when the device list was last re-read
    $lastTotal = 0;      // when a combined history point was last written
    $lastTrim  = 0;
    $devs      = [];

    $say = function ($m) use ($verbose) {
        if ($verbose) fwrite(STDERR, date('H:i:s') . ' ' . $m . "\n");
    };

    $tPass = microtime(true);
    while (time() < $deadline) {
        $passMs = (microtime(true) - $tPass) * 1000;
        if ($passMs > 1500) $say(sprintf('previous pass took %.0f ms', $passMs));
        $tPass = microtime(true);
        $now = time();

        // Re-read the devices periodically so adding or disabling a router in the
        // dashboard takes effect without restarting anything.
        if ($now - $lastDevs >= 15) {
            mt_settings_reset();
            // Only routers the ordinary poller has just reached. Dialling a dead one
            // from here is what put holes in the graph: a refused port answers at
            // once, but a filtered one does not answer at all and the connect sat
            // there for the whole timeout with every other router's readings waiting
            // behind it. Reachability is the poller's job, in the poller's process.
            $devs = $db->query("SELECT d.id, d.name, d.host, d.api_port, d.username, d.password,
                                       d.wan_iface, s.wan_iface AS live_wan
                                  FROM devices d JOIN status s ON s.device_id = d.id
                                 WHERE d.enabled = 1 AND s.online = 1
                                 ORDER BY d.sort_order, d.id")->fetchAll();
            $lastDevs = $now;

            // Drop connections for devices that are gone or have been disabled.
            $keep = [];
            foreach ($devs as $d) $keep[(int)$d['id']] = true;
            foreach (array_keys($conns) as $id) {
                if (empty($keep[$id])) { $conns[$id]['ros']->close(); unset($conns[$id], $rates[$id]); }
            }
        }

        // Open anything that is not connected yet. Bounded by a time budget rather
        // than a count: connecting them all at once is what makes the numbers start
        // moving immediately after a restart, but a row of dead routers must not be
        // able to hold the loop for the whole timeout each pass.
        $pending = [];
        $ifaceOf = [];
        foreach ($devs as $d) {
            $id = (int)$d['id'];
            if (isset($conns[$id])) continue;
            if (isset($retryAt[$id]) && $now < $retryAt[$id]) continue;

            // The interface comes from the ordinary poll's WAN detection. Without
            // one there is nothing to monitor - guessing here would double-count a
            // bridge and its member port.
            $iface = trim((string)($d['wan_iface'] !== '' ? $d['wan_iface'] : $d['live_wan']));
            if ($iface === '') { $retryAt[$id] = $now + 30; continue; }
            $ifaceOf[$id] = $iface;
            $pending[] = $d;
        }

        /**
         * Open them all together.
         *
         * This used to connect one router after another inside a three second
         * budget, so with ten routers only the first few got a stream on each
         * pass - and the browser reconnects this stream about every minute, so
         * the slow start happened again and again. Connecting in parallel makes
         * the whole fleet start streaming at once.
         */
        if ($pending) {
            require_once __DIR__ . '/parallel.php';
            $opened = mt_par_open($pending, 2);
            mt_par_login($opened, 2);
            foreach ($opened as $c) {
                $id = (int)$c->dev['id'];
                if ($c->alive() && $c->rbuf === '') {
                    $ros = new RouterOs(2);
                    if ($ros->adopt($c->sock, $c->rbuf)) {
                        $c->sock = null;               // ownership moved to $ros
                        try {
                            $ros->startStream('/interface/monitor-traffic',
                                              ['=interface=' . $ifaceOf[$id]]);
                            $conns[$id] = ['ros' => $ros, 'iface' => $ifaceOf[$id], 'name' => $c->dev['name']];
                            unset($fails[$id]);
                            $say('streaming ' . $c->dev['name'] . ' on ' . $ifaceOf[$id]);
                            continue;
                        } catch (Exception $e) {
                            $ros->close();
                            $c->err = $e->getMessage();
                        }
                    }
                }
                // Back off, and keep backing off: a router that refuses every time
                // must cost less and less, not the same every twenty seconds.
                $fails[$id] = min(5, (int)($fails[$id] ?? 0) + 1);
                $retryAt[$id] = $now + min(300, 15 * (1 << ($fails[$id] - 1)));
                $say('cannot stream ' . $c->dev['name'] . ': ' . ($c->err !== '' ? $c->err : 'connect failed'));
            }
            mt_par_close($opened);
        }

        if (!$conns) {
            /**
             * A lane with nothing to stream must NOT claim to be alive.
             *
             * bw_alive is what tells the ordinary poll "somebody faster owns the
             * speed figures, leave them alone". Setting it here meant that when no
             * router would stream - the API user lacking permission for
             * monitor-traffic is enough - the poll handed ownership to a lane that
             * never wrote a figure, and the dashboard sat at 0 bps while the poll
             * had perfectly good numbers in its hand. Say nothing, and the poll
             * keeps the job.
             */
            sleep(1);
            continue;
        }

        // Wait for whichever router speaks first, then drain everything that is
        // already waiting. Nothing here blocks on a single router.
        $anyReadable = false;
        foreach ($conns as $id => $c) {
            $tSel = microtime(true);
            $ready = $c['ros']->readable(0.25);
            $selMs = (microtime(true) - $tSel) * 1000;
            if ($selMs > 400) $say(sprintf('select on %s took %.0f ms', $c['name'], $selMs));
            if (!$ready) continue;
            $anyReadable = true;
            $tRead = microtime(true);
            try {
                // Drain: if the process was busy, several seconds of updates can be
                // queued and only the newest one is the current speed.
                $rx = $tx = null;
                do {
                    $s = $c['ros']->readNext();
                    if ($s['type'] === '!re') {
                        $a = $s['attrs'];
                        if (isset($a['rx-bits-per-second'])) $rx = (int)$a['rx-bits-per-second'];
                        if (isset($a['tx-bits-per-second'])) $tx = (int)$a['tx-bits-per-second'];
                    } elseif ($s['type'] === '!trap') {
                        throw new RouterOsException($s['attrs']['message'] ?? 'monitor refused');
                    }
                } while ($c['ros']->readable(0));
                $readMs = (microtime(true) - $tRead) * 1000;
                if ($readMs > 400) $say(sprintf('read from %s took %.0f ms', $c['name'], $readMs));
                if ($rx !== null || $tx !== null) {
                    $rates[$id] = ['rx' => (int)$rx, 'tx' => (int)$tx, 'at' => time()];
                    mt_bw_store($db, $id, (int)$rx, (int)$tx);
                }
            } catch (Exception $e) {
                $say('lost ' . $c['name'] . ': ' . $e->getMessage());
                $c['ros']->close();
                unset($conns[$id], $rates[$id]);
                $retryAt[$id] = time() + 10;
            }
        }
        if (!$anyReadable) usleep(200000);

        // One combined point per second, written here rather than derived from the
        // per-device rows: each router reports on its own second, so summing the
        // per-device samples by timestamp would leave gaps where only one of them
        // happened to land on that second, and the graph would show sawtooth that
        // never existed on the wire.
        $now = time();
        if ($now > $lastTotal) {
            $rxT = $txT = 0;
            $fresh = 0;
            foreach ($rates as $r) {
                if ($now - $r['at'] > 10) continue;         // stale: leave it out
                $rxT += $r['rx']; $txT += $r['tx'];
                $fresh++;
            }
            $lastTotal = $now;

            /**
             * No fresh reading from anybody is not "the network is idle", it is
             * "nobody answered". Writing a zero would draw a drop to the floor
             * that never happened on the wire, and claiming bw_alive would take
             * the figures away from the ordinary poll, which has good numbers.
             * So on an empty round this does nothing at all and lets the poll
             * keep the job.
             */
            if ($fresh > 0) {
                $db->prepare("INSERT INTO totals (ts, rx_bps, tx_bps) VALUES (?,?,?)
                              ON CONFLICT(ts) DO UPDATE SET rx_bps=excluded.rx_bps, tx_bps=excluded.tx_bps")
                   ->execute([$now, $rxT, $txT]);
                mt_set_setting('bw_alive', (string)$now);
            }

            // Let a caller see each tick as it happens. This is what lets the page
            // itself hold the stream open on a host that will not run a background
            // process - same loop, same readings, delivered to a browser instead of
            // only to the database. It is told how many routers actually reported,
            // so it can stop pretending a silent round is a reading of zero.
            if ($onTick !== null && $onTick($now, $rxT, $txT, $rates, $fresh) === false) break;
        }

        if ($now - $lastTrim >= 60) { mt_bw_trim($db); $lastTrim = $now; }
    }

    foreach ($conns as $c) $c['ros']->close();
    return true;
}

/**
 * Live bandwidth on a host that will not let anything run in the background.
 *
 * CyberPanel and most shared hosting disable exec, so there is no lane process and
 * no way to hold a connection open between requests. What is still possible is to
 * make the request itself cheap: ask each router for ONE interface's byte counters
 * - a single round trip of about half a second - instead of the six questions a
 * full poll asks. Two of those readings and the time between them give the rate.
 *
 * It is not as good as the streaming lane and does not pretend to be: it costs a
 * round trip per router per update. But it turns "every 5 seconds" into roughly
 * once a second for a small network, with nothing installed.
 *
 * Bounded by $budget seconds so a page request can never hang on a slow router,
 * and it keeps its own counters so it cannot disturb the full poll's rate.
 */
function mt_bw_inline(PDO $db, $budget = 2.5) {
    require_once __DIR__ . '/parallel.php';

    // One at a time. Two requests each taking a reading would measure against each
    // other's baseline and invent rates that never happened.
    $lock = mt_bw_lock();
    if ($lock === false || $lock === null) return false;

    try {
        $devs = $db->query("SELECT d.id, d.name, d.host, d.api_port, d.username, d.password,
                                   d.wan_iface, s.wan_iface AS live_wan,
                                   s.inline_rx, s.inline_tx, s.inline_at
                              FROM devices d JOIN status s ON s.device_id = d.id
                             WHERE d.enabled = 1 AND s.online = 1
                             ORDER BY d.sort_order, d.id")->fetchAll();

        /**
         * Every router at once.
         *
         * This used to connect to them one after another inside a time budget,
         * and stop when the budget ran out. With three routers that was fine.
         * With ten, each costing about half a second, the budget was spent
         * before the end of the list - so the routers at the bottom had their
         * speed read almost never, and looked like they were passing no traffic.
         * That is not a slow dashboard, it is a dashboard that never asked.
         */
        if ($devs) {
            $conns = mt_par_open($devs, 4);
            mt_par_login($conns, 4);
            // One uniform command, and the interface picked from the reply here:
            // a per-router filter would need a different command per connection
            // for no saving - the whole list is a handful of rows.
            mt_par_batch($conns, ['if' => ['/interface/print', '=.proplist=name,rx-byte,tx-byte']], 6);

            foreach ($conns as $c) {
                if (!$c->alive()) continue;          // the full poll decides reachability
                $d = $c->dev;
                $iface = trim((string)($d['wan_iface'] !== '' ? $d['wan_iface'] : $d['live_wan']));
                if ($iface === '') continue;

                $rx = $tx = null;
                foreach (($c->results['if'] ?? []) as $row) {
                    if (($row['name'] ?? '') === $iface) {
                        $rx = (int)($row['rx-byte'] ?? 0);
                        $tx = (int)($row['tx-byte'] ?? 0);
                    }
                }
                if ($rx === null) continue;
                $now = microtime(true);

                $prevAt = (float)$d['inline_at'];
                if ($prevAt > 0 && $d['inline_rx'] !== null) {
                    $dt = $now - $prevAt;
                    // Too soon to be meaningful, or so long ago the router may have
                    // rebooted in between - either way, take a fresh baseline.
                    if ($dt >= 0.4 && $dt <= 120 && $rx >= (int)$d['inline_rx'] && $tx >= (int)$d['inline_tx']) {
                        $rxBps = (int)round(($rx - (int)$d['inline_rx']) * 8 / $dt);
                        $txBps = (int)round(($tx - (int)$d['inline_tx']) * 8 / $dt);
                        mt_db_write($db, "UPDATE status SET rx_bps=?, tx_bps=?, bw_at=? WHERE device_id=?",
                                    [$rxBps, $txBps, date('Y-m-d H:i:s'), $d['id']]);
                        $db->prepare("INSERT INTO samples (device_id, ts, rx_bps, tx_bps) VALUES (?,?,?,?)")
                           ->execute([$d['id'], time(), $rxBps, $txBps]);
                    }
                }
                mt_db_write($db, "UPDATE status SET inline_rx=?, inline_tx=?, inline_at=? WHERE device_id=?",
                            [$rx, $tx, $now, $d['id']]);
            }
            mt_par_close($conns);
        }

        // The combined point, from whatever the rows now hold.
        $t = $db->query("SELECT COALESCE(SUM(rx_bps),0) rx, COALESCE(SUM(tx_bps),0) tx
                           FROM status s JOIN devices d ON d.id=s.device_id
                          WHERE s.online=1 AND d.enabled=1")->fetch();
        $db->prepare("INSERT INTO totals (ts, rx_bps, tx_bps) VALUES (?,?,?)
                      ON CONFLICT(ts) DO UPDATE SET rx_bps=excluded.rx_bps, tx_bps=excluded.tx_bps")
           ->execute([time(), (int)$t['rx'], (int)$t['tx']]);
    } finally {
        if (is_resource($lock)) { flock($lock, LOCK_UN); fclose($lock); }
    }
    return true;
}

/** Store one live reading for a device. */
function mt_bw_store(PDO $db, $deviceId, $rx, $tx) {
    $ts = time();
    $db->prepare("UPDATE status SET rx_bps=?, tx_bps=?, bw_at=? WHERE device_id=?")
       ->execute([$rx, $tx, date('Y-m-d H:i:s', $ts), $deviceId]);
    $db->prepare("INSERT INTO samples (device_id, ts, rx_bps, tx_bps) VALUES (?,?,?,?)")
       ->execute([$deviceId, $ts, $rx, $tx]);
}

/** Per-second history fills up fast; keep only what the graph can show. */
function mt_bw_trim(PDO $db) {
    $window = max(120, (int)mt_setting('history_points', 180) * 2);
    $cutoff = time() - $window;
    $db->prepare("DELETE FROM samples WHERE ts < ?")->execute([$cutoff]);
    $db->prepare("DELETE FROM totals  WHERE ts < ?")->execute([$cutoff]);
}

/**
 * Start the lane in the background if it is not already running.
 *
 * Same rule as the ordinary poller: the binary is never guessed, only one that has
 * been proved to run is used, and a dispatch that produces no heartbeat is not
 * repeated every second.
 */
function mt_maybe_bw(PDO $db) {
    if (mt_bw_alive($db)) {
        mt_set_setting('bw_dispatch_fails', '0');
        return;
    }
    if (mt_bw_spawn_broken($db)) return;

    $tried = (int)mt_setting_now($db, 'bw_dispatched', 0);
    $age   = $tried > 0 ? time() - $tried : PHP_INT_MAX;

    // Watchdog, and it has to be judged BEFORE the re-dispatch gate below - putting
    // the gate first meant the verdict could only ever be reached on the same slow
    // clock as the retries, so giving up took twice as long as intended.
    //
    // A host can allow exec - the probe runs and prints its token - and still not let
    // a process outlive the request that started it: LiteSpeed reaps orphans. The
    // dispatch then "succeeds", nothing reports back, and because a command line WAS
    // found the inline fallback never got its turn either. That is what leaves the
    // figures at the poll interval with nothing saying why.
    if ($tried > 0 && $age >= 10 && (int)mt_setting_now($db, 'bw_alive', 0) < $tried) {
        $fails = (int)mt_setting_now($db, 'bw_dispatch_fails', 0) + 1;
        mt_set_setting('bw_dispatch_fails', (string)$fails);
        if ($fails >= 2) {
            mt_set_setting('bw_no_spawn_until', (string)(time() + 600));
            mt_set_setting('bw_dispatch_fails', '0');
            mt_set_setting('bw_spawn_why', 'the background process starts but this host stops it straight away');
            return;                      // the direct read takes over from here
        }
    }

    // Give the last dispatch time to report in before starting another.
    if ($age < 10) return;

    mt_set_setting('bw_dispatched', (string)time());

    $bin = mt_php_cli($db);
    if ($bin === false) return;          // no command line: the ordinary poll covers it

    // Long enough that restarts are rare - each one costs a second or two while the
    // connections are re-opened - but still short enough that the process goes away
    // on its own once nobody is looking at the dashboard.
    mt_spawn($bin, [dirname(__DIR__) . '/poller.php', '--bw', '--seconds=180']);
}
