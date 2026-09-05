<?php
/**
 * JSON API for the dashboard.
 *
 * Reads come from SQLite; the routers themselves are read by the poller. On a
 * host with no background service the summary endpoint will start one poll itself
 * when the data has gone stale (see mt_maybe_poll), so the dashboard still works
 * on plain shared hosting - just with the first request of each cycle paying for
 * the poll.
 */

define('MT_JSON', true);   // preflight answers JSON rather than an HTML page
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/poll.php';
require_once __DIR__ . '/lib/bwlane.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/helpers.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$db     = mt_db();
$action = $_GET['action'] ?? $_POST['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

/** POST bodies arrive as JSON from the dashboard's fetch() calls. */
function mt_body() {
    static $body = null;
    if ($body !== null) return $body;
    $raw = file_get_contents('php://input');
    $j = json_decode($raw, true);
    $body = is_array($j) ? $j : $_POST;
    return $body;
}

function mt_out($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data);
    exit;
}

/** Every state-changing call must be signed in AND carry the CSRF token. */
function mt_guard() {
    mt_require_admin();
    $b = mt_body();
    if (!mt_csrf_ok($b['csrf'] ?? ($_POST['csrf'] ?? ''))) {
        mt_out(['success' => false, 'message' => 'Your session expired - please sign in again.'], 403);
    }
}

/**
 * Refresh the data if it has gone stale and nothing else is polling.
 * Prefers handing the job to a background process; only polls inline when the
 * host forbids that, because an inline poll makes the caller wait for every router.
 */
function mt_maybe_poll(PDO $db) {
    $poll = max(3, (int)mt_setting('poll_seconds', 5));

    // Gate on when a poll last STARTED, not on when it last finished. last_try is
    // written part way through, so gating on it made every cycle cost the interval
    // PLUS the duration of the poll - measured at 9 s for a 5 s interval against a
    // router 160 ms away.
    //
    // This marker is deliberately separate from last_try: last_try is what the page
    // uses to decide the data is stale, and if starting a poll also refreshed that,
    // a poller that never actually ran would keep the dashboard looking healthy.
    $started = (int)mt_setting_now($db, 'last_poll_started', 0);
    if ($started && (time() - $started) < $poll) return;

    // Watchdog. If a poll was dispatched but nothing ever wrote a result, the
    // background command is not running - and because the dispatch marked the poll
    // as started, the gate above would block every retry and polling would stop for
    // good. That is exactly what a silently failing exec() produced. When the last
    // dispatch is far older than the last actual result, stop trusting the
    // background route and do the work here instead.
    $lastTry  = $db->query("SELECT MAX(last_try) FROM status")->fetchColumn();
    $lastTryTs = $lastTry ? strtotime($lastTry) : 0;
    $dispatchFailed = $started > 0 && ($started - $lastTryTs) > max(30, $poll * 4);

    $lock = mt_lock(0);
    if ($lock === false || $lock === null) return;      // someone else has it

    mt_set_setting('last_poll_started', (string)time());

    $bin = $dispatchFailed ? false : mt_php_cli($db);
    if ($bin !== false) {
        mt_unlock($lock);                                // the child takes the lock
        mt_spawn($bin, [__DIR__ . '/poller.php', '--once']);
        return;
    }

    // No usable command line, or the background route proved unreliable: poll here.
    // Slower for whoever triggers it, but it is the difference between a dashboard
    // that updates and one that quietly freezes.
    if ($dispatchFailed) mt_set_setting('php_cli', 'none');
    try { mt_poll_all($db); } catch (Throwable $e) { /* a failed poll must not break the page */ }
    mt_unlock($lock);
}

/**
 * How the bandwidth figures are being produced right now:
 *   stream - a lane process holding connections open, one reading per second
 *   direct - read on each page refresh, about a round trip per router
 *   poll   - whatever the ordinary poll last wrote, at the poll interval
 */
function mt_live_mode(PDO $db, $onlineDevices) {
    if ($onlineDevices <= 0) return 'poll';
    if (mt_bw_alive($db)) return 'stream';
    // Generous window: this is asked at the END of a request that may have just
    // spent several seconds on a full poll, and the live endpoint runs every
    // second regardless, so anything this recent still means "reading directly".
    $inline = (float)mt_setting_now($db, 'bw_inline_at', 0);
    if ($inline > 0 && (microtime(true) - $inline) < 15) return 'direct';
    return 'poll';
}

/** Why the once-a-second lane is not running, in words the installer can act on. */
function mt_live_why(PDO $db, $deviceCount) {
    if (mt_setting('live_bandwidth', '1') !== '1') return 'Live bandwidth is switched off in Settings.';
    if ($deviceCount === 0) return 'No routers added yet - add one and the live figures start.';
    $online = (int)$db->query("SELECT COUNT(*) FROM status s JOIN devices d ON d.id=s.device_id
                                WHERE d.enabled=1 AND s.online=1")->fetchColumn();
    if ($online === 0) return 'No router is reachable yet, so there is nothing to stream.';
    if (mt_setting_now($db, 'php_cli', '') === 'none' || mt_bw_spawn_broken($db)) {
        // Do NOT send anyone to cron here. This host cannot run a background process,
        // but the page can hold the stream open itself - that is the once-a-second
        // path on exactly these hosts, and it connects a moment after the page loads.
        // Telling him to set up a cron job he does not need is worse than saying
        // nothing: it is work, on the strength of a message that is about to stop
        // being true.
        return 'This host will not run a background process, so the page reads the figures itself. '
             . 'It switches to the once-a-second stream as soon as the connection is established - '
             . 'if this message is still here after a few seconds, the stream is being blocked and '
             . 'each reading costs about a second per router instead.';
    }
    return 'Connecting the live stream...';
}

/** The combined graph, newest last. */
function mt_history(PDO $db) {
    $keep = max(30, (int)mt_setting('history_points', 180));
    $rows = $db->query("SELECT ts, rx_bps rx, tx_bps tx FROM totals
                         ORDER BY ts DESC LIMIT " . (int)$keep)->fetchAll();
    // Older installations have history in `samples` only, so fall back to summing
    // those rather than showing an empty chart after an upgrade.
    if (!$rows) {
        $rows = $db->query("SELECT ts, SUM(rx_bps) rx, SUM(tx_bps) tx FROM samples
                             GROUP BY ts ORDER BY ts DESC LIMIT " . (int)$keep)->fetchAll();
    }
    $out = [];
    foreach (array_reverse($rows) as $h) {
        $out[] = ['ts' => (int)$h['ts'], 'rx' => (int)$h['rx'], 'tx' => (int)$h['tx']];
    }
    return $out;
}

switch ($action) {

    /* ------------------------------------------------------------------ stream
       Once-a-second bandwidth on a host that will not run anything in the
       background - LiteSpeed and CyberPanel kill a process the moment the request
       that started it ends, so there is nowhere for a lane to live.

       The answer is to make the REQUEST the lane. This holds the connection open,
       runs exactly the same loop the background lane runs, and pushes each reading
       to the browser as a server-sent event. The page gets a genuine value per
       second with nothing installed and no cron.

       It gives up the slot after a minute and the browser reconnects, so a PHP
       worker is never held indefinitely, and the page falls back to asking once a
       second by itself if this endpoint is buffered or refused. */
    case 'stream':
        if (mt_setting('live_bandwidth', '1') !== '1') mt_out(['success' => false, 'message' => 'Live bandwidth is off.'], 409);

        // The session lock would block every other request to this site for as long
        // as this one runs, which on a one-worker host is the whole dashboard.
        if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

        @set_time_limit(0);
        ignore_user_abort(false);          // stop as soon as the browser goes away

        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('Connection: keep-alive');
        header('X-Accel-Buffering: no');    // nginx and LiteSpeed: do not buffer this
        while (ob_get_level() > 0) ob_end_flush();

        $emit = function ($event, array $data) {
            echo 'event: ' . $event . "\n";
            echo 'data: ' . json_encode($data) . "\n\n";
            @ob_flush();
            flush();
            return connection_aborted() === 0;
        };

        // A first frame straight away: the page uses it to decide the stream is
        // really coming through rather than sitting in somebody's buffer.
        $emit('hello', ['ok' => true, 'ts' => time()]);

        $lock = mt_bw_lock();
        if ($lock === false || $lock === null) {
            // A lane already has it - that is the better source, so let the page go
            // back to its ordinary once-a-second requests rather than compete.
            $emit('busy', ['message' => 'another live reader is already running']);
            exit;
        }

        try {
            mt_bw_run($db, 55, false, function ($ts, $rx, $tx, $rates) use ($emit) {
                $devs = [];
                foreach ($rates as $id => $r) {
                    $devs[] = ['id' => (int)$id, 'downloadBps' => (int)$r['rx'], 'uploadBps' => (int)$r['tx']];
                }
                return $emit('tick', ['ts' => $ts, 'rx' => $rx, 'tx' => $tx, 'devices' => $devs]);
            });
        } catch (Throwable $e) {
            $emit('error', ['message' => $e->getMessage()]);
        } finally {
            if (is_resource($lock)) { flock($lock, LOCK_UN); fclose($lock); }
        }
        exit;

    // ------------------------------------------------------- live figures only
    // Small and cheap on purpose: the page asks for this every second to move the
    // bandwidth numbers, and asks for the full summary on a slower clock. Sending
    // everything once a second would rebuild the whole page each time.
    case 'live':
        if (mt_setting('live_bandwidth', '1') === '1') {
            mt_maybe_bw($db);
            // No lane, and no way to start one on this host: take the reading here.
            // "No way" covers both a host with no command line at all AND one where
            // the command line works but the process gets reaped - the second case
            // used to fall through to nothing, which is what left the figures at the
            // poll interval with no explanation.
            if (!mt_bw_alive($db) && (mt_php_cli($db) === false || mt_bw_spawn_broken($db))) {
                $last = (float)mt_setting_now($db, 'bw_inline_at', 0);
                if (microtime(true) - $last >= 0.8) {
                    mt_set_setting('bw_inline_at', (string)microtime(true));
                    try { mt_bw_inline($db); } catch (Throwable $e) { /* never break the page */ }
                }
            }
        }
        mt_maybe_poll($db);

        $rows = $db->query("SELECT s.device_id, s.online, s.rx_bps, s.tx_bps, s.hotspot_users,
                                   s.ppp_users, s.traffic_bytes
                              FROM status s JOIN devices d ON d.id = s.device_id
                             WHERE d.enabled = 1")->fetchAll();
        $tot = ['totalDownloadBps' => 0, 'totalUploadBps' => 0, 'totalHotspotUsers' => 0,
                'totalPppoeUsers' => 0, 'onlineDevices' => 0];
        $devs = [];
        foreach ($rows as $r) {
            $on = (int)$r['online'] === 1;
            if ($on) {
                $tot['onlineDevices']++;
                $tot['totalDownloadBps']  += (int)$r['rx_bps'];
                $tot['totalUploadBps']    += (int)$r['tx_bps'];
                $tot['totalHotspotUsers'] += (int)$r['hotspot_users'];
                $tot['totalPppoeUsers']   += (int)$r['ppp_users'];
            }
            $devs[] = ['id' => (int)$r['device_id'], 'online' => $on,
                       'downloadBps' => $on ? (int)$r['rx_bps'] : 0,
                       'uploadBps'   => $on ? (int)$r['tx_bps'] : 0,
                       'trafficBytes'=> (int)$r['traffic_bytes']];
        }
        $tot['totalBandwidthBps'] = $tot['totalDownloadBps'] + $tot['totalUploadBps'];
        mt_out(['success' => true, 'summary' => $tot, 'devices' => $devs,
                'history' => mt_history($db),
                'liveLane' => mt_bw_alive($db) && $tot['onlineDevices'] > 0,
                'liveMode' => mt_live_mode($db, $tot['onlineDevices'])]);
        break;

    // -------------------------------------------------------------- dashboard
    case 'summary':
        if (mt_setting('live_bandwidth', '1') === '1') mt_maybe_bw($db);
        mt_maybe_poll($db);

        $rows = $db->query("
            SELECT d.*, s.online, s.error, s.conn_status, s.ping_ms, s.ping_source, s.cpu, s.ram_pct,
                   s.ram_total_mb, s.ram_free_mb, s.uptime, s.ros_version AS live_version, s.board,
                   s.identity, s.hotspot_users, s.ppp_users, s.wan_iface AS live_wan,
                   s.rx_bps, s.tx_bps, s.traffic_bytes, s.last_seen, s.last_try,
                   s.net_ping_ms, s.net_ping_target, s.net_ping_err
              FROM devices d LEFT JOIN status s ON s.device_id = d.id
             ORDER BY d.sort_order, d.id")->fetchAll();

        $summary = ['onlineDevices' => 0, 'offlineDevices' => 0, 'disabledDevices' => 0,
                    'totalDevices' => count($rows), 'totalHotspotUsers' => 0, 'totalPppoeUsers' => 0,
                    'totalDownloadBps' => 0, 'totalUploadBps' => 0, 'totalTrafficBytes' => 0];

        $devices = [];
        foreach ($rows as $r) {
            $enabled = (int)$r['enabled'] === 1;
            $online  = $enabled && (int)$r['online'] === 1;
            if (!$enabled)      $summary['disabledDevices']++;
            elseif ($online)    $summary['onlineDevices']++;
            else                $summary['offlineDevices']++;

            if ($online) {
                $summary['totalHotspotUsers'] += (int)$r['hotspot_users'];
                $summary['totalPppoeUsers']   += (int)$r['ppp_users'];
                $summary['totalDownloadBps']  += (int)$r['rx_bps'];
                $summary['totalUploadBps']    += (int)$r['tx_bps'];
            }
            $summary['totalTrafficBytes'] += (int)$r['traffic_bytes'];

            $devices[] = [
                'id'          => (int)$r['id'],
                'name'        => $r['name'],
                'host'        => $r['host'],
                'apiPort'     => (int)$r['api_port'],
                'username'    => $r['username'],
                'location'    => $r['location'],
                'description' => $r['description'],
                'enabled'     => $enabled,
                'status'      => !$enabled ? 'disabled' : ($online ? 'online' : 'offline'),
                'connStatus'  => $enabled ? ($r['conn_status'] ?: 'Never polled') : 'Monitoring disabled',
                'error'       => (string)$r['error'],
                'pingMs'      => $online ? (float)$r['ping_ms'] : null,
                'pingSource'  => (string)$r['ping_source'],
                // The router's own ping to the internet - a different direction from
                // pingMs, so both are sent and both are labelled on the card.
                'netPingMs'     => ($online && $r['net_ping_ms'] !== null) ? (float)$r['net_ping_ms'] : null,
                'netPingTarget' => (string)$r['net_ping_target'],
                'netPingErr'    => (string)$r['net_ping_err'],
                'cpu'         => $online ? (int)$r['cpu'] : 0,
                'ramPct'      => $online ? (int)$r['ram_pct'] : 0,
                'ramTotalMb'  => (int)$r['ram_total_mb'],
                'uptime'      => $online ? (string)$r['uptime'] : '',
                'rosVersion'  => (string)($r['live_version'] ?: $r['ros_version']),
                'board'       => (string)$r['board'],
                'identity'    => (string)$r['identity'],
                'hotspotUsers'=> $online ? (int)$r['hotspot_users'] : 0,
                'pppoeUsers'  => $online ? (int)$r['ppp_users'] : 0,
                'wanIface'    => (string)($r['live_wan'] ?: $r['wan_iface']),
                'downloadBps' => $online ? (int)$r['rx_bps'] : 0,
                'uploadBps'   => $online ? (int)$r['tx_bps'] : 0,
                'trafficBytes'=> (int)$r['traffic_bytes'],
                'lastSeen'    => $r['last_seen'],
                'lastTry'     => $r['last_try'],
            ];
        }
        $summary['totalBandwidthBps'] = $summary['totalDownloadBps'] + $summary['totalUploadBps'];

        $history = mt_history($db);
        $newest  = $db->query("SELECT MAX(last_try) FROM status")->fetchColumn();
        // "Live" has to mean live figures are arriving, not merely that the process
        // exists. A lane running with nothing reachable to stream is not live, and
        // labelling it so would be the dashboard telling a comfortable lie.
        $liveLane = mt_bw_alive($db) && $summary['onlineDevices'] > 0;
        mt_out([
            'success'    => true,
            'summary'    => $summary,
            'devices'    => $devices,
            'history'    => $history,
            'pollSeconds'=> (int)mt_setting('poll_seconds', 5),
            // How often the PAGE should re-read. Deliberately shorter than the poll
            // interval: a poll runs in the background and writes after the request
            // that triggered it has already answered, so a page refreshing on the
            // same clock as the poll always shows the previous round's numbers and
            // updates half as often as it should.
            'uiRefresh'  => max(2, min(5, (int)mt_setting('poll_seconds', 5))),
            // Bandwidth comes from the router's own traffic monitor, which reports
            // every second, so the page reads the small live endpoint on that clock.
            'liveLane'   => $liveLane,
            'liveMode'   => mt_live_mode($db, $summary['onlineDevices']),
            'liveRefresh'=> 1,
            // When the live lane is NOT running, say why rather than quietly showing
            // the slower interval - "every 5s" on its own reads like nothing was
            // fixed, when the real answer is usually "no routers added yet".
            'liveWhy'    => $liveLane ? '' : mt_live_why($db, count($devices)),
            'lastPoll'   => $newest,
            // True when nothing has polled recently: the page says so instead of
            // letting old numbers pass for live ones. A brand new install with no
            // routers yet is not stale, it is empty - warning there just makes a
            // correct installation look broken.
            'stale'      => count($devices) > 0
                            && (!$newest || (time() - strtotime($newest)) > max(60, (int)mt_setting('poll_seconds', 10) * 4)),
            'isAdmin'    => mt_is_admin(),
            'adminUser'  => mt_admin_name(),
            'csrf'       => mt_is_admin() ? mt_csrf() : '',
            'siteName'   => mt_setting('site_name', 'Ariyan-IT Solutions'),
            'tagline'    => mt_setting('site_tagline', 'MikroTik Network Monitoring Dashboard'),
            'defaultPassword' => mt_setting('default_password_in_use', '0') === '1',
        ]);
        break;

    // ------------------------------------------------------------------- auth
    case 'login':
        if ($method !== 'POST') mt_out(['success' => false, 'message' => 'POST required.'], 405);
        $b = mt_body();
        if (mt_login($b['username'] ?? '', $b['password'] ?? '')) {
            mt_out(['success' => true, 'username' => mt_admin_name(), 'csrf' => mt_csrf(),
                    'message' => 'Signed in.']);
        }
        mt_out(['success' => false, 'message' => 'Wrong username or password.'], 401);
        break;

    case 'logout':
        mt_logout();
        mt_out(['success' => true]);
        break;

    case 'session':
        mt_out(['success' => true, 'isAdmin' => mt_is_admin(), 'username' => mt_admin_name(),
                'csrf' => mt_is_admin() ? mt_csrf() : '']);
        break;

    case 'change_password':
        mt_guard();
        $b = mt_body();
        $new = (string)($b['password'] ?? '');
        if (strlen($new) < 6) mt_out(['success' => false, 'message' => 'Use at least 6 characters.'], 400);
        mt_change_password(mt_admin_name(), $new);
        mt_out(['success' => true, 'message' => 'Password changed.']);
        break;

    // ------------------------------------------------------- secure the database
    case 'secure_db':
        mt_guard();

        // Somewhere the web server cannot serve: the folder above the document root.
        $docRoot = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
        if ($docRoot === '' || !is_dir($docRoot)) {
            mt_out(['success' => false, 'message' => 'Cannot work out the web root on this host - please move the data folder by hand, see README.md.'], 400);
        }
        // Nothing to do if the data folder is already somewhere the web server does
        // not serve. Checked by containment rather than by comparing against the one
        // path this endpoint would have picked, so a folder moved by hand - or by an
        // older version of this code - is recognised instead of being moved again.
        if (strpos(rtrim(MT_DATA, '/') . '/', $docRoot . '/') !== 0) {
            mt_out(['success' => true, 'message' => 'The database is already outside the web root (' . MT_DATA . ').']);
        }

        // The folder name carries the site's own hostname. Two subdomains on this
        // panel can share a parent directory - /home/site/public_html/live and
        // /home/site/public_html/speed both sit under /home/site/public_html - and a
        // fixed name would have pointed both installations at ONE database, which is
        // the opposite of keeping each subdomain's routers to itself.
        $host = preg_replace('/:\d+$/', '', strtolower((string)($_SERVER['HTTP_HOST'] ?? '')));
        $host = trim(preg_replace('/[^a-z0-9.-]+/', '-', $host), '-.');
        $target = dirname($docRoot) . '/mikrotik-monitor-data' . ($host !== '' ? '-' . $host : '');

        if (!is_dir($target) && !@mkdir($target, 0750, true) && !is_dir($target)) {
            mt_out(['success' => false, 'message' => 'Could not create ' . $target . ' - the account may not have permission.'], 500);
        }
        if (!is_writable($target)) {
            mt_out(['success' => false, 'message' => $target . ' is not writable.'], 500);
        }

        // Flush the write-ahead log into the main file first, so a plain copy is a
        // complete database rather than one missing its most recent transactions.
        try { $db->exec('PRAGMA wal_checkpoint(TRUNCATE)'); } catch (Throwable $e) {}

        $src = MT_DB_FILE;
        $dst = $target . '/monitor.sqlite';

        // A database already in the target folder is this site's OWN, from before it
        // was reinstalled - the folder name carries the hostname, so it cannot belong
        // to another subdomain. Refusing here (which an earlier version did) blocks
        // the one case that happens most: delete the files, upload them again, press
        // the button. Neither file is ever destroyed; the one not chosen is kept
        // beside it with a dated name, inside the protected folder.
        if (is_file($dst)) {
            $here  = (int)$db->query("SELECT COUNT(*) FROM devices")->fetchColumn();
            $there = null;
            try {
                $chk = new PDO('sqlite:' . $dst, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
                $there = (int)$chk->query("SELECT COUNT(*) FROM devices")->fetchColumn();
                $chk = null;
            } catch (Throwable $e) {
                $there = null;      // not a database this app can read
            }

            // The install being used wins. An empty one - a fresh upload with nothing
            // typed into it yet - adopts what was already there, which is how a
            // reinstall gets its routers back.
            $adopt = ($here === 0 && $there !== null);
            $stamp = date('Ymd-His');
            $aside = $target . '/monitor-' . ($adopt ? 'replaced' : 'previous') . '-' . $stamp . '.sqlite';

            if ($adopt) {
                if (!@copy($src, $aside)) {
                    mt_out(['success' => false, 'message' => 'Could not write to ' . $target . ', so nothing was changed.'], 500);
                }
                $conf = MT_ROOT . '/config.local.php';
                $php  = "<?php if (!defined('MT_DATA')) define('MT_DATA', " . var_export($target, true) . ");\n";
                if (@file_put_contents($conf, $php) === false) {
                    @unlink($aside);
                    mt_out(['success' => false, 'message' => 'Could not write config.local.php, so nothing was changed.'], 500);
                }
                foreach ([$src, $src . '-wal', $src . '-shm', MT_DATA . '/poll.lock'] as $old) {
                    if (is_file($old)) @unlink($old);
                }
                mt_out(['success' => true, 'moved' => $target, 'adopted' => true,
                        'message' => 'Found the database this site was using before, with ' . $there
                            . ' router' . ($there === 1 ? '' : 's') . ' in it, and kept that one. It is now at '
                            . $target . ', outside the web root. Reload the page - your routers should be back, '
                            . 'and the dashboard login is the one that database was using.']);
            }

            // Otherwise this installation has routers of its own, so it keeps them and
            // the older file is set aside rather than overwritten.
            if (!@rename($dst, $aside)) {
                mt_out(['success' => false, 'message' => 'Could not set aside the older database at ' . $dst
                        . ', so nothing was changed.'], 500);
            }
            foreach ([$dst . '-wal', $dst . '-shm'] as $side) { if (is_file($side)) @unlink($side); }
            $setAside = $aside;
        }

        if (!@copy($src, $dst)) {
            mt_out(['success' => false, 'message' => 'Could not copy the database to ' . $target . '.'], 500);
        }

        // Verify the copy BEFORE removing anything. A half-written database that is
        // merely in a safer place is a worse outcome than one that is exposed.
        try {
            $check = new PDO('sqlite:' . $dst, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $devA = (int)$db->query("SELECT COUNT(*) FROM devices")->fetchColumn();
            $devB = (int)$check->query("SELECT COUNT(*) FROM devices")->fetchColumn();
            $admA = (int)$db->query("SELECT COUNT(*) FROM admins")->fetchColumn();
            $admB = (int)$check->query("SELECT COUNT(*) FROM admins")->fetchColumn();
            $check = null;
            if ($devA !== $devB || $admA !== $admB) {
                @unlink($dst);
                mt_out(['success' => false, 'message' => 'The copy did not match the original, so nothing was changed.'], 500);
            }
        } catch (Throwable $e) {
            @unlink($dst);
            mt_out(['success' => false, 'message' => 'The copy could not be opened, so nothing was changed: ' . $e->getMessage()], 500);
        }

        // Point the app at the new location.
        $conf = MT_ROOT . '/config.local.php';
        $php  = "<?php if (!defined('MT_DATA')) define('MT_DATA', " . var_export($target, true) . ");\n";
        if (@file_put_contents($conf, $php) === false) {
            @unlink($dst);
            mt_out(['success' => false, 'message' => 'Could not write config.local.php, so nothing was changed.'], 500);
        }

        // Only now remove the exposed copy, the lock, and the WAL side files.
        foreach ([$src, $src . '-wal', $src . '-shm', MT_DATA . '/poll.lock'] as $old) {
            if (is_file($old)) @unlink($old);
        }
        $msg = 'Database moved to ' . $target . ', outside the web root.';
        if (!empty($setAside)) {
            $msg .= ' An older database was already there; it was not deleted, it is beside it as '
                  . basename($setAside) . '.';
        }
        mt_out(['success' => true, 'moved' => $target, 'setAside' => $setAside ?? null,
                'message' => $msg . ' Reload the page.']);
        break;

    // ------------------------------------------------------- backup / restore
    //
    // A backup of THIS installation only: the routers that were typed in, the
    // settings, and the admin logins. Deliberately not the history - samples and
    // totals are thousands of rows a day and none of it is anything you would have
    // to type again. What this file protects is the part that is expensive to lose.
    case 'backup':
        mt_require_admin();

        $devs = $db->query(
            "SELECT name, host, api_port, username, password, location, description,
                    enabled, wan_iface, sort_order
               FROM devices ORDER BY sort_order, id")->fetchAll(PDO::FETCH_ASSOC);

        // Whitelist, not blacklist. The settings table also holds bookkeeping the
        // lane writes to itself (heartbeats, dispatch markers, the cached path to
        // the PHP binary); carrying those to another host would tell the new
        // install things about the old one that are not true there.
        $keep = ['site_name', 'site_tagline', 'poll_seconds', 'net_ping_target',
                 'net_ping_every', 'history_points', 'live_bandwidth', 'api_timeout'];
        $settings = [];
        foreach ($keep as $k) {
            $v = mt_setting_now($db, $k, null);
            if ($v !== null) $settings[$k] = (string)$v;
        }

        $admins = $db->query("SELECT username, password_hash FROM admins ORDER BY id")
                     ->fetchAll(PDO::FETCH_ASSOC);

        $out = [
            'format'   => 'mikrotik-monitor-backup',
            'version'  => 1,
            'created'  => gmdate('c'),
            'site'     => (string)($_SERVER['HTTP_HOST'] ?? ''),
            'counts'   => ['devices' => count($devs), 'admins' => count($admins)],
            'settings' => $settings,
            'devices'  => $devs,
            'admins'   => $admins,
        ];

        $name = preg_replace('/[^a-z0-9.-]+/i', '-', (string)($_SERVER['HTTP_HOST'] ?? 'monitor'));
        $file = 'monitor-backup-' . trim($name, '-') . '-' . gmdate('Y-m-d') . '.json';
        $json = json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $file . '"');
        header('Content-Length: ' . strlen($json));
        echo $json;
        exit;

    case 'restore':
        mt_guard();

        // Accepts either an uploaded file (the normal route) or the text pasted
        // into the request body, so a restore is still possible on a host where
        // file uploads are switched off.
        $raw = '';
        if (!empty($_FILES['file']['tmp_name']) && is_uploaded_file($_FILES['file']['tmp_name'])) {
            $raw = (string)@file_get_contents($_FILES['file']['tmp_name']);
        } else {
            $raw = (string)(mt_body()['backup'] ?? '');
        }
        if (trim($raw) === '') {
            mt_out(['success' => false, 'message' => 'No backup file was received.'], 400);
        }

        $in = json_decode($raw, true);
        if (!is_array($in) || ($in['format'] ?? '') !== 'mikrotik-monitor-backup') {
            mt_out(['success' => false, 'message' => 'That is not a backup file from this dashboard.'], 400);
        }
        if (!isset($in['devices']) || !is_array($in['devices'])) {
            mt_out(['success' => false, 'message' => 'The backup file has no router list in it.'], 400);
        }

        // Refuse an empty restore rather than quietly wiping a working install:
        // the most likely cause is a truncated download, and by the time anyone
        // noticed, the routers it was meant to protect would already be gone.
        if (count($in['devices']) === 0) {
            mt_out(['success' => false, 'message' => 'The backup contains no routers, so nothing was changed.'], 400);
        }

        $me = mt_admin_name();
        $db->beginTransaction();
        try {
            // Replace the router list wholesale. Rows in `status` and `samples`
            // reference devices(id) and foreign keys are on for every connection
            // (see mt_db), so the delete carries the readings of the old routers
            // with it rather than leaving them behind pointing at nothing.
            $db->exec('DELETE FROM devices');

            $ins = $db->prepare(
                "INSERT INTO devices (name, host, api_port, username, password, location,
                                      description, enabled, wan_iface, sort_order)
                 VALUES (?,?,?,?,?,?,?,?,?,?)");
            $n = 0;
            foreach ($in['devices'] as $d) {
                if (!is_array($d)) continue;
                $dname = trim((string)($d['name'] ?? ''));
                $dhost = trim((string)($d['host'] ?? ''));
                if ($dname === '' || $dhost === '') continue;
                $port = (int)($d['api_port'] ?? 8728);
                if ($port < 1 || $port > 65535) $port = 8728;
                $ins->execute([
                    substr($dname, 0, 60), substr($dhost, 0, 120), $port,
                    (string)($d['username'] ?? 'admin'), (string)($d['password'] ?? ''),
                    (string)($d['location'] ?? ''), (string)($d['description'] ?? ''),
                    !empty($d['enabled']) ? 1 : 0, (string)($d['wan_iface'] ?? ''),
                    (int)($d['sort_order'] ?? 0),
                ]);
                $n++;
            }
            if ($n === 0) throw new RuntimeException('none of the routers in the file were usable');

            // A device with no status row is invisible to the poller's joins.
            $db->exec("INSERT OR IGNORE INTO status (device_id) SELECT id FROM devices");

            if (!empty($in['settings']) && is_array($in['settings'])) {
                $sst = $db->prepare("INSERT INTO settings (k, v) VALUES (?, ?)
                                     ON CONFLICT(k) DO UPDATE SET v = excluded.v");
                $allowed = ['site_name', 'site_tagline', 'poll_seconds', 'net_ping_target',
                            'net_ping_every', 'history_points', 'live_bandwidth', 'api_timeout'];
                foreach ($allowed as $k) {
                    if (isset($in['settings'][$k])) $sst->execute([$k, (string)$in['settings'][$k]]);
                }
            }

            // Logins are merged in, not swapped for the file's list: an account that
            // exists here but not in the backup keeps working. The password of the
            // account doing the restore IS overwritten, because carrying the old
            // dashboard password across is most of the point - and since a PHP
            // session is not re-checked against the hash, whoever is doing this
            // stays signed in and can change it again straight away if it is wrong.
            $ka = 0;
            $mine = false;
            if (!empty($in['admins']) && is_array($in['admins'])) {
                $ast = $db->prepare(
                    "INSERT INTO admins (username, password_hash) VALUES (?, ?)
                     ON CONFLICT(username) DO UPDATE SET password_hash = excluded.password_hash");
                foreach ($in['admins'] as $a) {
                    $au = trim((string)($a['username'] ?? ''));
                    $ah = (string)($a['password_hash'] ?? '');
                    if ($au === '' || strlen($ah) < 20) continue;
                    $ast->execute([$au, $ah]);
                    if ($au === $me) $mine = true; else $ka++;
                }
            }

            // The "still on the default password" banner has to follow the passwords
            // that just arrived, or a restored install would nag about a password it
            // no longer uses - or worse, stay quiet about one it does.
            $onDefault = 0;
            foreach ($db->query("SELECT password_hash FROM admins")->fetchAll() as $row) {
                if (password_verify('admin123', $row['password_hash'])) { $onDefault = 1; break; }
            }
            $db->prepare("INSERT INTO settings (k,v) VALUES ('default_password_in_use', ?)
                          ON CONFLICT(k) DO UPDATE SET v = excluded.v")->execute([(string)$onDefault]);

            $db->commit();
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            mt_out(['success' => false, 'message' => 'Restore failed, nothing was changed: ' . $e->getMessage()], 500);
        }

        mt_settings_reset();
        // The next poll must start from scratch rather than from the old install's clock.
        mt_set_setting('last_poll_started', '0');

        $msg = $n . ' router' . ($n === 1 ? '' : 's') . ' restored';
        if ($ka > 0) $msg .= ', ' . $ka . ' extra login' . ($ka === 1 ? '' : 's') . ' added';
        $msg .= '. The routers will be read on the next refresh.';
        if ($mine) $msg .= ' Your dashboard password is now the one from the backup.';
        mt_out(['success' => true, 'devices' => $n, 'admins' => $ka, 'passwordChanged' => $mine,
                'message' => $msg]);
        break;

    // --------------------------------------------------------------- settings
    case 'settings_get':
        mt_require_admin();
        mt_out(['success' => true, 'settings' => [
            'site_name'       => mt_setting('site_name', 'Ariyan-IT Solutions'),
            'site_tagline'    => mt_setting('site_tagline', 'MikroTik Network Monitoring Dashboard'),
            'poll_seconds'    => (int)mt_setting('poll_seconds', 5),
            'net_ping_target' => mt_setting('net_ping_target', '8.8.8.8'),
            'net_ping_every'  => (int)mt_setting('net_ping_every', 30),
            'live_bandwidth'  => mt_setting('live_bandwidth', '1') === '1',
        // Shown in Settings so an installer can SEE which folder this site is using.
        // Two subdomains are separate because they are two folders, and the fastest
        // way to be sure of that is to read the two paths rather than be told.
        ], 'dataDir' => MT_DATA, 'dbFile' => MT_DB_FILE]);
        break;

    case 'settings_save':
        mt_guard();
        $b = mt_body();
        // Clamped: a 1 second poll would hammer the routers, and a ping target that
        // is blank would leave the card with nothing to show.
        $name = trim((string)($b['site_name'] ?? ''));
        $tag  = trim((string)($b['site_tagline'] ?? ''));
        $tgt  = trim((string)($b['net_ping_target'] ?? ''));
        mt_set_setting('site_name',       $name !== '' ? substr($name, 0, 60) : 'Ariyan-IT Solutions');
        mt_set_setting('site_tagline',    $tag  !== '' ? substr($tag, 0, 80)  : 'MikroTik Network Monitoring Dashboard');
        mt_set_setting('poll_seconds',    (string)max(3, min(300, (int)($b['poll_seconds'] ?? 5))));
        mt_set_setting('net_ping_target', $tgt !== '' ? substr($tgt, 0, 64) : '8.8.8.8');
        mt_set_setting('net_ping_every',  (string)max(10, min(3600, (int)($b['net_ping_every'] ?? 30))));
        mt_set_setting('live_bandwidth',  !empty($b['live_bandwidth']) ? '1' : '0');
        mt_out(['success' => true, 'message' => 'Settings saved.']);
        break;

    // ---------------------------------------------------------------- devices
    case 'device_save':
        mt_guard();
        $b = mt_body();
        $name = trim((string)($b['name'] ?? ''));
        $host = trim((string)($b['host'] ?? ''));
        if ($name === '' || $host === '') {
            mt_out(['success' => false, 'message' => 'Device name and IP / hostname are required.'], 400);
        }
        $port = (int)($b['apiPort'] ?? 8728);
        if ($port < 1 || $port > 65535) $port = 8728;

        $fields = [
            'name'        => $name,
            'host'        => $host,
            'api_port'    => $port,
            'username'    => trim((string)($b['username'] ?? 'admin')),
            'location'    => trim((string)($b['location'] ?? '')),
            'description' => trim((string)($b['description'] ?? '')),
            'ros_version' => trim((string)($b['rosVersion'] ?? '')),
            'wan_iface'   => trim((string)($b['wanIface'] ?? '')),
            'enabled'     => !empty($b['enabled']) ? 1 : 0,
        ];

        $id = (int)($b['id'] ?? 0);
        if ($id > 0) {
            $sql = [];
            $args = [];
            foreach ($fields as $k => $v) { $sql[] = "$k = ?"; $args[] = $v; }
            // An empty password box means "keep the stored one", so editing a device
            // does not silently wipe credentials that are working.
            if (($b['password'] ?? '') !== '') { $sql[] = "password = ?"; $args[] = (string)$b['password']; }
            $args[] = $id;
            $db->prepare("UPDATE devices SET " . implode(', ', $sql) . " WHERE id = ?")->execute($args);
            mt_out(['success' => true, 'id' => $id, 'message' => 'Device updated.']);
        }

        $fields['password'] = (string)($b['password'] ?? '');
        $cols = implode(',', array_keys($fields));
        $qs   = implode(',', array_fill(0, count($fields), '?'));
        $db->prepare("INSERT INTO devices ($cols) VALUES ($qs)")->execute(array_values($fields));
        $newId = (int)$db->lastInsertId();
        $db->prepare("INSERT OR IGNORE INTO status (device_id) VALUES (?)")->execute([$newId]);
        mt_out(['success' => true, 'id' => $newId, 'message' => 'Device added.']);
        break;

    case 'device_delete':
        mt_guard();
        $id = (int)(mt_body()['id'] ?? 0);
        $st = $db->prepare("SELECT name FROM devices WHERE id=?");
        $st->execute([$id]);
        $name = $st->fetchColumn();
        if ($name === false) mt_out(['success' => false, 'message' => 'Device not found.'], 404);
        $db->prepare("DELETE FROM status  WHERE device_id=?")->execute([$id]);
        $db->prepare("DELETE FROM samples WHERE device_id=?")->execute([$id]);
        $db->prepare("DELETE FROM devices WHERE id=?")->execute([$id]);
        mt_out(['success' => true, 'message' => 'Deleted "' . $name . '".']);
        break;

    case 'device_toggle':
        mt_guard();
        $id = (int)(mt_body()['id'] ?? 0);
        $st = $db->prepare("SELECT name, enabled FROM devices WHERE id=?");
        $st->execute([$id]);
        $d = $st->fetch();
        if (!$d) mt_out(['success' => false, 'message' => 'Device not found.'], 404);
        $new = (int)$d['enabled'] === 1 ? 0 : 1;
        $db->prepare("UPDATE devices SET enabled=? WHERE id=?")->execute([$new, $id]);
        if ($new === 0) {
            // A disabled device stops being polled; blank its live figures so the
            // dashboard cannot keep showing yesterday's speed as if it were current.
            $db->prepare("UPDATE status SET online=0, rx_bps=0, tx_bps=0, hotspot_users=0, ppp_users=0,
                            cpu=0, ram_pct=0, conn_status='Monitoring disabled' WHERE device_id=?")->execute([$id]);
        }
        mt_out(['success' => true, 'enabled' => $new === 1,
                'message' => 'Monitoring ' . ($new ? 'enabled' : 'disabled') . ' for "' . $d['name'] . '".']);
        break;

    case 'device_poll':
        mt_guard();
        $id = (int)(mt_body()['id'] ?? 0);
        $st = $db->prepare("SELECT * FROM devices WHERE id=?");
        $st->execute([$id]);
        $d = $st->fetch();
        if (!$d) mt_out(['success' => false, 'message' => 'Device not found.'], 404);
        $lock = mt_lock(10);
        if ($lock === false) mt_out(['success' => false, 'message' => 'A poll is already running - try again in a moment.'], 409);
        try { $r = mt_poll_device($db, $d); } finally { mt_unlock($lock); }
        mt_out(['success' => true, 'online' => $r['online'], 'message' => $r['online'] ? 'Polled OK.' : 'Poll failed: ' . $r['error']]);
        break;

    // ------------------------------------------------------------ diagnostics
    case 'test_connection':
        mt_require_admin();
        $b = mt_body();
        $host = trim((string)($b['host'] ?? ''));
        $port = (int)($b['apiPort'] ?? 8728);
        $user = (string)($b['username'] ?? 'admin');
        $pass = (string)($b['password'] ?? '');
        if ($host === '') mt_out(['success' => false, 'message' => 'Enter an IP address or hostname first.'], 400);

        // Editing an existing device with the password box left blank: test with the
        // credentials already stored rather than with an empty password.
        if ($pass === '' && !empty($b['id'])) {
            $st = $db->prepare("SELECT password FROM devices WHERE id=?");
            $st->execute([(int)$b['id']]);
            $stored = $st->fetchColumn();
            if ($stored !== false) $pass = (string)$stored;
        }

        // A real test. If the router cannot be reached, or the credentials are
        // wrong, this says so - it never reports success it did not observe.
        $ros = new RouterOs(max(2, (int)mt_setting('api_timeout', 6)));
        try {
            $ms = $ros->connect($host, $port, $user, $pass);
            $res = $ros->query('/system/resource/print');
            $r = $res[0] ?? [];
            $ident = '';
            try { $i = $ros->query('/system/identity/print'); $ident = (string)($i[0]['name'] ?? ''); } catch (Exception $e) {}
            $ros->close();
            mt_out([
                'success' => true,
                'message' => sprintf('Connected to %s:%d in %d ms. Logged in as "%s".', $host, $port, round($ms), $user),
                'identity' => $ident,
                'rosVersion' => (string)($r['version'] ?? ''),
                'board' => (string)($r['board-name'] ?? ''),
                'uptime' => mt_uptime_text($r['uptime'] ?? ''),
                'latencyMs' => round($ms),
            ]);
        } catch (Exception $e) {
            $ros->close();
            mt_out(['success' => false,
                    'message' => sprintf('Could not connect to %s:%d - %s', $host, $port, $e->getMessage())]);
        }
        break;

    case 'device_interfaces':
        mt_require_admin();
        $b = mt_body();
        $id = (int)($b['id'] ?? 0);
        $st = $db->prepare("SELECT * FROM devices WHERE id=?");
        $st->execute([$id]);
        $d = $st->fetch();
        if (!$d) mt_out(['success' => false, 'message' => 'Device not found.'], 404);
        $ros = new RouterOs(max(2, (int)mt_setting('api_timeout', 6)));
        try {
            $ros->connect($d['host'], $d['api_port'], $d['username'], $d['password']);
            $list = [];
            foreach ($ros->query('/interface/print') as $i) {
                $list[] = ['name' => $i['name'], 'type' => (string)($i['type'] ?? ''),
                           'running' => ($i['running'] ?? '') === 'true'];
            }
            $ros->close();
            mt_out(['success' => true, 'interfaces' => $list, 'current' => $d['wan_iface']]);
        } catch (Exception $e) {
            $ros->close();
            mt_out(['success' => false, 'message' => $e->getMessage()]);
        }
        break;

    case 'device_history':
        $id = (int)($_GET['id'] ?? 0);
        $keep = max(30, (int)mt_setting('history_points', 180));
        $st = $db->prepare("SELECT ts, rx_bps rx, tx_bps tx FROM samples WHERE device_id=?
                             ORDER BY ts DESC LIMIT " . (int)$keep);
        $st->execute([$id]);
        $pts = array_reverse($st->fetchAll());
        mt_out(['success' => true, 'history' => array_map(function ($p) {
            return ['ts' => (int)$p['ts'], 'rx' => (int)$p['rx'], 'tx' => (int)$p['tx']];
        }, $pts)]);
        break;

    default:
        mt_out(['success' => false, 'message' => 'Unknown action.'], 400);
}
