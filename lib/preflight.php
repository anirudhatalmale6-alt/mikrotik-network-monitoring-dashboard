<?php
/**
 * Start-up checks.
 *
 * Without this, a host missing the SQLite driver or a read-only data folder just
 * produces a blank page or a raw HTTP 500, which tells the person installing it
 * nothing. This turns each of those into a page that names the problem and the
 * one command that fixes it.
 */

function mt_preflight() {
    $problems = [];

    if (version_compare(PHP_VERSION, '7.4.0', '<')) {
        $problems[] = [
            'PHP ' . PHP_VERSION . ' is too old',
            'This dashboard needs PHP 7.4 or newer (8.x recommended). In CyberPanel or cPanel, '
            . 'switch the PHP version for this domain and reload the page.',
        ];
    }

    if (!extension_loaded('pdo_sqlite')) {
        $problems[] = [
            'The SQLite driver is missing',
            'PHP does not have <code>pdo_sqlite</code> enabled, so the database cannot be opened. '
            . 'On CyberPanel: <b>PHP &rarr; Edit PHP Extensions</b>, tick <code>sqlite3</code> and '
            . '<code>pdo_sqlite</code> for the PHP version this domain uses. '
            . 'On Ubuntu: <code>apt install php' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION . '-sqlite3</code> '
            . 'then reload PHP.',
        ];
    }

    // Only worth testing the folder once the driver is there.
    if (!$problems) {
        if (!is_dir(MT_DATA)) {
            if (!@mkdir(MT_DATA, 0775, true) && !is_dir(MT_DATA)) {
                $problems[] = [
                    'The data folder could not be created',
                    'PHP cannot create <code>' . htmlspecialchars(MT_DATA) . '</code>. Create it yourself '
                    . 'and give the web server write access:<br><code>mkdir -p ' . htmlspecialchars(MT_DATA)
                    . ' &amp;&amp; chmod 775 ' . htmlspecialchars(MT_DATA) . '</code>',
                ];
            }
        }
        if (!$problems && !is_writable(MT_DATA)) {
            $problems[] = [
                'The data folder is not writable',
                'PHP cannot write to <code>' . htmlspecialchars(MT_DATA) . '</code>, so the database cannot '
                . 'be created. Fix the permissions:<br><code>chmod 775 ' . htmlspecialchars(MT_DATA)
                . '</code><br>and if that is not enough, give it to the web server user:<br>'
                . '<code>chown -R ' . htmlspecialchars(mt_web_user()) . ' ' . htmlspecialchars(MT_DATA) . '</code>',
            ];
        }
    }

    if (!$problems) return;                    // everything is in order

    // api.php sets MT_JSON: a JSON caller must get JSON back, otherwise the
    // dashboard's fetch() sees an HTML page and can only say "unreadable response".
    if (defined('MT_JSON')) {
        http_response_code(503);
        header('Content-Type: application/json');
        echo json_encode(['success' => false,
            'message' => $problems[0][0] . ' - open the dashboard in a browser for the fix.',
            'setup' => true]);
        exit;
    }
    mt_preflight_page($problems);
}

/** Best guess at the account PHP runs as, so the chown line is copy-pasteable. */
function mt_web_user() {
    if (function_exists('posix_getpwuid') && function_exists('posix_geteuid')) {
        $u = @posix_getpwuid(posix_geteuid());
        if (!empty($u['name'])) return $u['name'];
    }
    return get_current_user() ?: 'www-data';
}

function mt_preflight_page(array $problems) {
    http_response_code(503);
    header('Content-Type: text/html; charset=utf-8');
    ?><!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Setup needed - MikroTik Network Monitoring Dashboard</title>
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:'Segoe UI',system-ui,-apple-system,sans-serif;background:#f1f5f9;color:#0f172a;
 padding:34px 18px;line-height:1.55;font-size:14.5px}
.box{max-width:760px;margin:0 auto;background:#fff;border:1px solid #e2e8f0;border-radius:18px;
 box-shadow:0 1px 2px rgba(15,23,42,.05),0 10px 30px -20px rgba(15,23,42,.5);overflow:hidden}
.hd{padding:22px 26px;border-bottom:1px solid #eef2f7;display:flex;gap:14px;align-items:center}
.hd .m{width:46px;height:46px;border-radius:14px;background:#fffbeb;border:1px solid #fde68a;color:#d97706;
 display:flex;align-items:center;justify-content:center;flex:0 0 auto}
.hd svg{width:24px;height:24px}
.hd h1{font-size:18px;font-weight:800}
.hd p{font-size:13px;color:#64748b;margin-top:2px}
.bd{padding:22px 26px}
.p{border:1px solid #fecdd3;background:#fff1f2;border-radius:14px;padding:14px 16px;margin-bottom:14px}
.p h2{font-size:15px;font-weight:700;color:#9f1239;margin-bottom:6px}
.p div{font-size:13.5px;color:#334155}
code{background:#0f172a;color:#f8fafc;padding:2px 7px;border-radius:6px;font-size:12.5px;
 font-family:ui-monospace,Menlo,Consolas,monospace;display:inline-block;margin:2px 0}
.ok{border:1px solid #e2e8f0;border-radius:14px;padding:14px 16px;background:#f8fafc;font-size:13px;color:#475569}
.ok b{color:#0f172a}
table{width:100%;border-collapse:collapse;margin-top:10px;font-size:13px}
td{padding:5px 0;color:#475569}
td:first-child{color:#94a3b8;width:190px}
</style></head><body>
<div class="box">
  <div class="hd">
    <div class="m"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg></div>
    <div>
      <h1>Almost there - the server needs one thing</h1>
      <p>The dashboard cannot start until the point<?= count($problems) === 1 ? '' : 's' ?> below <?= count($problems) === 1 ? 'is' : 'are' ?> fixed.</p>
    </div>
  </div>
  <div class="bd">
    <?php foreach ($problems as $p): ?>
      <div class="p"><h2><?= htmlspecialchars($p[0]) ?></h2><div><?= $p[1] ?></div></div>
    <?php endforeach; ?>
    <div class="ok">
      <b>This server right now</b>
      <table>
        <tr><td>PHP version</td><td><?= htmlspecialchars(PHP_VERSION) ?></td></tr>
        <tr><td>pdo_sqlite</td><td><?= extension_loaded('pdo_sqlite') ? 'enabled' : 'MISSING' ?></td></tr>
        <tr><td>Running as</td><td><?= htmlspecialchars(mt_web_user()) ?></td></tr>
        <tr><td>Data folder</td><td><?= htmlspecialchars(MT_DATA) ?></td></tr>
        <tr><td>Folder writable</td><td><?= is_dir(MT_DATA) ? (is_writable(MT_DATA) ? 'yes' : 'NO') : 'does not exist' ?></td></tr>
      </table>
    </div>
  </div>
</div>
</body></html><?php
    exit;
}
