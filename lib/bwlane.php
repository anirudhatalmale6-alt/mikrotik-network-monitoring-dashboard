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
function mt_bw_run(PDO $db, $lifetime = 120, $verbose = false) {
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
        $budget = microtime(true) + 3.0;
        foreach ($devs as $d) {
            if (microtime(true) > $budget) break;
            $id = (int)$d['id'];
            if (isset($conns[$id])) continue;
            if (isset($retryAt[$id]) && $now < $retryAt[$id]) continue;

            // The interface comes from the ordinary poll's WAN detection. Without
            // one there is nothing to monitor - guessing here would double-count a
            // bridge and its member port.
            $iface = trim((string)($d['wan_iface'] !== '' ? $d['wan_iface'] : $d['live_wan']));
            if ($iface === '') { $retryAt[$id] = $now + 30; continue; }

            // Deliberately shorter than the poller's timeout. This connect happens
            // between two readings that are due every second, so it has to fail fast;
            // a router that needs more than a couple of seconds to answer cannot
            // keep up a per-second stream anyway.
            $ros = new RouterOs(2);
            try {
                $ros->connect($d['host'], $d['api_port'], $d['username'], $d['password']);
                $ros->startStream('/interface/monitor-traffic', ['=interface=' . $iface]);
                $conns[$id] = ['ros' => $ros, 'iface' => $iface, 'name' => $d['name']];
                unset($fails[$id]);
                $say('streaming ' . $d['name'] . ' on ' . $iface);
            } catch (Exception $e) {
                $ros->close();
                // Back off, and keep backing off: a router that refuses every time
                // must cost less and less, not the same every twenty seconds.
                $fails[$id] = min(5, (int)($fails[$id] ?? 0) + 1);
                $retryAt[$id] = $now + min(300, 15 * (1 << ($fails[$id] - 1)));
                $say('cannot stream ' . $d['name'] . ': ' . $e->getMessage());
            }
        }

        if (!$conns) {
            mt_set_setting('bw_alive', (string)time());
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
            foreach ($rates as $r) {
                if ($now - $r['at'] > 10) continue;         // stale: leave it out
                $rxT += $r['rx']; $txT += $r['tx'];
            }
            $db->prepare("INSERT INTO totals (ts, rx_bps, tx_bps) VALUES (?,?,?)
                          ON CONFLICT(ts) DO UPDATE SET rx_bps=excluded.rx_bps, tx_bps=excluded.tx_bps")
               ->execute([$now, $rxT, $txT]);
            $lastTotal = $now;
            mt_set_setting('bw_alive', (string)$now);
        }

        if ($now - $lastTrim >= 60) { mt_bw_trim($db); $lastTrim = $now; }
    }

    foreach ($conns as $c) $c['ros']->close();
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
    if (mt_bw_alive($db)) return;

    // Do not re-dispatch faster than the lane takes to make its first heartbeat.
    $tried = (int)mt_setting_now($db, 'bw_dispatched', 0);
    if ($tried && (time() - $tried) < 20) return;
    mt_set_setting('bw_dispatched', (string)time());

    $bin = mt_php_cli($db);
    if ($bin === false) return;          // no command line: the ordinary poll covers it

    // Long enough that restarts are rare - each one costs a second or two while the
    // connections are re-opened - but still short enough that the process goes away
    // on its own once nobody is looking at the dashboard.
    @exec(escapeshellarg($bin) . ' ' . escapeshellarg(dirname(__DIR__) . '/poller.php')
          . ' --bw --seconds=180 > /dev/null 2>&1 &');
}
