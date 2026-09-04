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

require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/poll.php';
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
    $poll = max(5, (int)mt_setting('poll_seconds', 10));
    $newest = $db->query("SELECT MAX(last_try) FROM status")->fetchColumn();
    if ($newest && (time() - strtotime($newest)) < $poll) return;

    $lock = mt_lock(0);
    if ($lock === false || $lock === null) return;      // someone else has it

    $disabled = array_map('trim', explode(',', (string)ini_get('disable_functions')));
    $canExec  = function_exists('exec') && !in_array('exec', $disabled, true);
    if ($canExec && PHP_OS_FAMILY !== 'Windows') {
        mt_unlock($lock);                                // the child takes the lock
        @exec('php ' . escapeshellarg(__DIR__ . '/poller.php') . ' --once > /dev/null 2>&1 &');
        return;
    }
    try { mt_poll_all($db); } catch (Throwable $e) { /* a failed poll must not break the page */ }
    mt_unlock($lock);
}

switch ($action) {

    // -------------------------------------------------------------- dashboard
    case 'summary':
        mt_maybe_poll($db);

        $rows = $db->query("
            SELECT d.*, s.online, s.error, s.conn_status, s.ping_ms, s.ping_source, s.cpu, s.ram_pct,
                   s.ram_total_mb, s.ram_free_mb, s.uptime, s.ros_version AS live_version, s.board,
                   s.identity, s.hotspot_users, s.ppp_users, s.wan_iface AS live_wan,
                   s.rx_bps, s.tx_bps, s.traffic_bytes, s.last_seen, s.last_try
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

        // Combined history: samples taken in one poll share a timestamp, so grouping
        // by it lines the routers up without interpolating anything.
        $keep = max(30, (int)mt_setting('history_points', 120));
        $hist = $db->query("SELECT ts, SUM(rx_bps) rx, SUM(tx_bps) tx FROM samples
                             GROUP BY ts ORDER BY ts DESC LIMIT " . (int)$keep)->fetchAll();
        $history = [];
        foreach (array_reverse($hist) as $h) {
            $history[] = ['ts' => (int)$h['ts'], 'rx' => (int)$h['rx'], 'tx' => (int)$h['tx']];
        }

        $newest = $db->query("SELECT MAX(last_try) FROM status")->fetchColumn();
        mt_out([
            'success'    => true,
            'summary'    => $summary,
            'devices'    => $devices,
            'history'    => $history,
            'pollSeconds'=> (int)mt_setting('poll_seconds', 10),
            'lastPoll'   => $newest,
            // True when nothing has polled recently: the page says so instead of
            // letting old numbers pass for live ones.
            'stale'      => !$newest || (time() - strtotime($newest)) > max(60, (int)mt_setting('poll_seconds', 10) * 4),
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
        $keep = max(30, (int)mt_setting('history_points', 120));
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
