<?php
/**
 * SQLite storage. The database file is created on first visit - there is nothing
 * to install and no MySQL server to configure.
 */

define('MT_ROOT', dirname(__DIR__));

// Optional per-installation overrides. Create config.local.php next to index.php
// and define MT_DATA there to keep the database outside the web root - the tidiest
// way to protect it when you cannot add a deny rule to the web server config:
//
//   <?php define('MT_DATA', '/var/lib/mikrotik-monitor');
//
if (is_readable(MT_ROOT . '/config.local.php')) require_once MT_ROOT . '/config.local.php';

if (!defined('MT_DATA')) define('MT_DATA', MT_ROOT . '/data');
define('MT_DB_FILE', MT_DATA . '/monitor.sqlite');

function mt_db() {
    static $pdo = null;
    if ($pdo !== null) return $pdo;

    // Turn "no SQLite driver" or "read-only folder" into a page that says so,
    // rather than a blank 500 that leaves the installer guessing.
    require_once __DIR__ . '/preflight.php';
    mt_preflight();

    if (!is_dir(MT_DATA)) @mkdir(MT_DATA, 0775, true);
    $fresh = !file_exists(MT_DB_FILE);

    $pdo = new PDO('sqlite:' . MT_DB_FILE);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    // WAL lets the dashboard keep reading while the poller writes; without it a
    // poll in progress makes every page wait.
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA busy_timeout = 5000');
    $pdo->exec('PRAGMA foreign_keys = ON');

    mt_schema($pdo);
    if ($fresh) @chmod(MT_DB_FILE, 0664);
    return $pdo;
}

function mt_schema(PDO $db) {
    $db->exec("
    CREATE TABLE IF NOT EXISTS devices (
        id            INTEGER PRIMARY KEY AUTOINCREMENT,
        name          TEXT NOT NULL,
        host          TEXT NOT NULL,
        api_port      INTEGER NOT NULL DEFAULT 8728,
        username      TEXT NOT NULL DEFAULT 'admin',
        password      TEXT NOT NULL DEFAULT '',
        ros_version   TEXT NOT NULL DEFAULT '',
        location      TEXT NOT NULL DEFAULT '',
        description   TEXT NOT NULL DEFAULT '',
        enabled       INTEGER NOT NULL DEFAULT 1,
        -- Which interface the device's speed is read from. Empty = auto-detect
        -- from the router's default route on the next poll.
        wan_iface     TEXT NOT NULL DEFAULT '',
        sort_order    INTEGER NOT NULL DEFAULT 0,
        created_at    TEXT NOT NULL DEFAULT (datetime('now'))
    )");

    // One row per device holding the newest reading. Kept separate from `devices`
    // so a poll never rewrites the settings an admin typed in.
    $db->exec("
    CREATE TABLE IF NOT EXISTS status (
        device_id       INTEGER PRIMARY KEY REFERENCES devices(id) ON DELETE CASCADE,
        online          INTEGER NOT NULL DEFAULT 0,
        error           TEXT NOT NULL DEFAULT '',
        conn_status     TEXT NOT NULL DEFAULT '',
        ping_ms         REAL NOT NULL DEFAULT 0,
        ping_source     TEXT NOT NULL DEFAULT '',
        cpu             INTEGER NOT NULL DEFAULT 0,
        ram_pct         INTEGER NOT NULL DEFAULT 0,
        ram_total_mb    INTEGER NOT NULL DEFAULT 0,
        ram_free_mb     INTEGER NOT NULL DEFAULT 0,
        uptime          TEXT NOT NULL DEFAULT '',
        ros_version     TEXT NOT NULL DEFAULT '',
        board           TEXT NOT NULL DEFAULT '',
        identity        TEXT NOT NULL DEFAULT '',
        hotspot_users   INTEGER NOT NULL DEFAULT 0,
        ppp_users       INTEGER NOT NULL DEFAULT 0,
        wan_iface       TEXT NOT NULL DEFAULT '',
        rx_bps          INTEGER NOT NULL DEFAULT 0,
        tx_bps          INTEGER NOT NULL DEFAULT 0,
        -- raw counters from the router, used only to turn two readings into a rate
        last_rx         INTEGER,
        last_tx         INTEGER,
        -- bytes this monitor has actually measured, so a router reboot (which zeroes
        -- the router's own counters) does not wipe the running total
        traffic_bytes   INTEGER NOT NULL DEFAULT 0,
        last_at         TEXT,
        last_seen       TEXT,
        last_try        TEXT
    )");

    $db->exec("
    CREATE TABLE IF NOT EXISTS samples (
        id        INTEGER PRIMARY KEY AUTOINCREMENT,
        device_id INTEGER NOT NULL REFERENCES devices(id) ON DELETE CASCADE,
        ts        INTEGER NOT NULL,
        rx_bps    INTEGER NOT NULL DEFAULT 0,
        tx_bps    INTEGER NOT NULL DEFAULT 0
    )");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_samples_ts ON samples(ts)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_samples_dev ON samples(device_id, ts)");

    // The combined graph, one row per second, written by whichever process is
    // producing the live figures. Summing the per-device samples by timestamp
    // instead would dip every time a router happened to report on a different
    // second from its neighbour.
    $db->exec("
    CREATE TABLE IF NOT EXISTS totals (
        ts     INTEGER PRIMARY KEY,
        rx_bps INTEGER NOT NULL DEFAULT 0,
        tx_bps INTEGER NOT NULL DEFAULT 0
    )");

    $db->exec("
    CREATE TABLE IF NOT EXISTS admins (
        id            INTEGER PRIMARY KEY AUTOINCREMENT,
        username      TEXT NOT NULL UNIQUE,
        password_hash TEXT NOT NULL,
        created_at    TEXT NOT NULL DEFAULT (datetime('now'))
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS settings (k TEXT PRIMARY KEY, v TEXT NOT NULL DEFAULT '')");

    // Columns added after the first release. SQLite has no ADD COLUMN IF NOT EXISTS,
    // so check what the table already has - this must be safe to run on every start.
    mt_add_columns($db, 'status', [
        // The router's OWN ping to the internet. Different from ping_ms, which is this
        // server's ping TO the router: two different directions over two different
        // paths, and showing only one of them is what makes the figure look "wrong".
        'net_ping_ms'     => 'REAL',
        'net_ping_target' => "TEXT NOT NULL DEFAULT ''",
        'net_ping_at'     => 'TEXT',
        'net_ping_err'    => "TEXT NOT NULL DEFAULT ''",
        // When the live bandwidth lane last wrote this row. Lets the ordinary poll
        // tell whether someone faster is already keeping rx_bps up to date.
        'bw_at'           => 'TEXT',
        // Counters kept by the inline fallback, which runs on hosts that forbid
        // background processes. Deliberately separate from last_rx / last_tx: those
        // belong to the full poll, and two readers sharing one baseline would each
        // compute a rate over the other's interval.
        'inline_rx'       => 'INTEGER',
        'inline_tx'       => 'INTEGER',
        'inline_at'       => 'REAL',
    ]);

    $defaults = [
        'site_name'      => 'Ariyan-IT Solutions',
        'site_tagline'   => 'MikroTik Network Monitoring Dashboard',
        'poll_seconds'   => '5',
        // The router pings this itself, so the dashboard can show internet quality
        // from the router's point of view as well as reachability from this server.
        'net_ping_target'=> '8.8.8.8',
        'net_ping_every' => '30',    // seconds; /ping costs about a second per packet
        'history_points' => '180',   // points kept per device for the live graph
        // Live bandwidth straight from the router's own traffic monitor, one reading
        // per second. Off means bandwidth comes from the ordinary poll instead, which
        // is as slow as the poll interval.
        'live_bandwidth' => '1',
        'api_timeout'    => '6',
    ];
    $ins = $db->prepare("INSERT OR IGNORE INTO settings (k, v) VALUES (?, ?)");
    foreach ($defaults as $k => $v) $ins->execute([$k, $v]);

    // First run only: a default administrator, because there is no installer.
    // The dashboard shows a warning until this password is changed.
    $n = (int)$db->query("SELECT COUNT(*) FROM admins")->fetchColumn();
    if ($n === 0) {
        $st = $db->prepare("INSERT INTO admins (username, password_hash) VALUES (?, ?)");
        $st->execute(['admin', password_hash('admin123', PASSWORD_DEFAULT)]);
        $db->prepare("INSERT OR REPLACE INTO settings (k,v) VALUES ('default_password_in_use','1')")->execute();
    }
}

/** Add any of $cols that the table does not already have. Idempotent by design:
 *  it runs on every request, so upgrading is just replacing the files. */
function mt_add_columns(PDO $db, $table, array $cols) {
    $have = [];
    foreach ($db->query("PRAGMA table_info(" . $table . ")")->fetchAll() as $c) {
        $have[$c['name']] = true;
    }
    foreach ($cols as $name => $decl) {
        if (isset($have[$name])) continue;
        $db->exec("ALTER TABLE $table ADD COLUMN $name $decl");
    }
}

function mt_setting($key, $default = '') {
    $cache = &mt_settings_cache();
    if ($cache === null) {
        $cache = [];
        foreach (mt_db()->query("SELECT k, v FROM settings")->fetchAll() as $r) $cache[$r['k']] = $r['v'];
    }
    return isset($cache[$key]) && $cache[$key] !== '' ? $cache[$key] : $default;
}

function &mt_settings_cache() {
    static $cache = null;
    return $cache;
}

/** Read a setting straight from the table, bypassing the per-request cache. For
 *  values that change on every poll and must never be read stale. */
function mt_setting_now(PDO $db, $key, $default = '') {
    $st = $db->prepare("SELECT v FROM settings WHERE k = ?");
    $st->execute([$key]);
    $v = $st->fetchColumn();
    return ($v === false || $v === null || $v === '') ? $default : $v;
}

/**
 * Is the live bandwidth lane running right now?
 *
 * It writes a heartbeat every second. Anything that would otherwise write rx_bps
 * asks this first, so a slow poll cannot overwrite a newer figure with an older
 * average. Lives here rather than in bwlane.php so the poller can ask without
 * pulling in the lane.
 */
function mt_bw_alive(PDO $db, $withinSeconds = 8) {
    $hb = (int)mt_setting_now($db, 'bw_alive', 0);
    return $hb > 0 && (time() - $hb) <= $withinSeconds;
}

/** The poller runs for weeks; without this it would never notice a setting change. */
function mt_settings_reset() {
    $cache = &mt_settings_cache();
    $cache = null;
}

function mt_set_setting($key, $value) {
    $st = mt_db()->prepare("INSERT INTO settings (k,v) VALUES (?,?)
                            ON CONFLICT(k) DO UPDATE SET v = excluded.v");
    $st->execute([$key, (string)$value]);
    mt_settings_reset();
}
