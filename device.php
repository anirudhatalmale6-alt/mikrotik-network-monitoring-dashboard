<?php
/**
 * Open a device that lives behind a MikroTik.
 *
 * The devices are on private addresses (10.x, 192.168.x). A browser somewhere
 * else cannot reach them, and nobody sane forwards a port on the internet for
 * every access point. RouterOS already solves this: it has a SOCKS proxy built
 * in, with an access list. Switch it on, allow only this server's address, and
 * the router becomes the way in - no VPN, no agent, nothing installed on the
 * device.
 *
 *   /ip/socks set enabled=yes port=1080
 *   /ip/socks/access add src-address=<this server> action=allow
 *   /ip/socks/access add action=deny
 *   /ip/firewall/filter add chain=input protocol=tcp dst-port=1080 \
 *       src-address=<this server> action=accept place-before=0
 *
 * Two URLs:
 *   device.php?id=N        the framed page, with a header saying what you are on
 *   device.php/N/<path>    the device's own content, fetched through the router
 *
 * The address is never taken from the URL - only the row id is. Whatever is
 * fetched is the address the poller discovered for that device, so this cannot
 * be turned into an open proxy by editing the query string.
 */

require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/helpers.php';

$db = mt_db();

if (!mt_is_admin()) {
    http_response_code(401);
    header('Content-Type: text/html; charset=utf-8');
    exit('<!doctype html><meta charset="utf-8"><p style="font:15px system-ui;padding:24px">'
       . 'Please sign in to the dashboard first, then open the device again.</p>');
}

/* ------------------------------------------------------------------ target */

$pathInfo = (string)($_SERVER['PATH_INFO'] ?? '');
$framed   = $pathInfo === '' || $pathInfo === '/';

if ($framed) {
    $id = (int)($_GET['id'] ?? 0);
    $sub = '/';
} else {
    $parts = explode('/', ltrim($pathInfo, '/'), 2);
    $id  = (int)$parts[0];
    $sub = '/' . ($parts[1] ?? '');
}

$st = $db->prepare("SELECT l.*, d.name AS router, d.host AS router_host, d.socks_port
                      FROM lan_devices l JOIN devices d ON d.id = l.device_id
                     WHERE l.id = ?");
$st->execute([$id]);
$dev = $st->fetch();

function mt_dev_page($title, $body, $code = 200) {
    http_response_code($code);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><title>' . htmlspecialchars($title) . '</title>'
       . '<style>body{margin:0;background:#f1f5f9;color:#0f172a;font:14px/1.55 system-ui,sans-serif}'
       . '.w{max-width:640px;margin:40px auto;background:#fff;border:1px solid #e2e8f0;'
       . 'border-radius:14px;padding:22px 24px}h1{font-size:17px;margin:0 0 10px}'
       . 'code{background:#f1f5f9;border:1px solid #e2e8f0;border-radius:6px;padding:1px 5px;'
       . 'font-size:12.5px}pre{background:#0f172a;color:#e2e8f0;padding:13px 15px;border-radius:10px;'
       . 'overflow-x:auto;font-size:12px;line-height:1.6}p{margin:9px 0}</style>'
       . '<div class="w">' . $body . '</div>';
    exit;
}

if (!$dev) {
    mt_dev_page('Not found', '<h1>That device is not in the list</h1>'
        . '<p>It may have been forgotten after going unseen for a week. Scan the router again.</p>', 404);
}

$ip = trim((string)$dev['ip']);
if ($ip === '') {
    mt_dev_page('No address', '<h1>' . htmlspecialchars($dev['mac']) . ' has no IP address</h1>'
        . '<p>The router knows its MAC but has no address for it, so there is nothing to open.</p>', 409);
}

$socksPort = (int)$dev['socks_port'];
if ($socksPort <= 0) {
    // The address the router must allow. SERVER_ADDR is the address this host
    // answers on, which is the right one on a VPS but is a loopback or a NAT
    // address on some setups - and a command line with "this server" written in
    // it looks copy-pasteable and is not. Say plainly when it has to be filled in.
    $me   = (string)($_SERVER['SERVER_ADDR'] ?? '');
    $ok   = $me !== '' && !preg_match('/^(127\.|::1$|0\.0\.0\.0$)/', $me);
    $note = '';
    if (!$ok) {
        $me = 'YOUR.SERVER.IP';
        $note = '<p><b>Replace YOUR.SERVER.IP</b> with the public address of the server running this '
              . 'dashboard - that is the address the router will see the connection coming from.</p>';
    }
    mt_dev_page('Not set up yet',
        '<h1>' . htmlspecialchars($dev['router']) . ' has no way in yet</h1>'
      . '<p><b>' . htmlspecialchars($dev['ip']) . '</b> is a private address. This server cannot reach it '
      . 'unless the router lets it through.</p>'
      . '<p>RouterOS has that built in. On <b>' . htmlspecialchars($dev['router']) . '</b>:</p>'
      . '<pre>/ip/socks set enabled=yes port=1080 version=5'
      . "\n" . '/ip/socks/access add src-address=' . htmlspecialchars($me) . ' action=allow'
      . "\n" . '/ip/socks/access add action=deny'
      . "\n" . '/ip/firewall/filter add chain=input protocol=tcp dst-port=1080 \\'
      . "\n" . '    src-address=' . htmlspecialchars($me) . ' action=accept place-before=0</pre>'
      . $note
      . '<p>Then set the SOCKS port to 1080 on this router in the dashboard. Only this server is '
      . 'allowed in, and nothing on your network is exposed to the internet.</p>', 409);
}

/* ------------------------------------------------------------- framed shell */

if ($framed) {
    $inner = htmlspecialchars(($_SERVER['SCRIPT_NAME'] ?? '/device.php') . '/' . $id . '/');
    $name  = $dev['identity'] ?: ($dev['hostname'] ?: ($dev['board'] ?: $dev['mac']));
    header('Content-Type: text/html; charset=utf-8');
    ?><!doctype html>
<html lang="en"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars($name) ?> - <?= htmlspecialchars($dev['router']) ?></title>
<style>
  html,body{margin:0;height:100%;background:#0f172a;font:14px system-ui,sans-serif;color:#e2e8f0}
  .bar{display:flex;align-items:center;gap:12px;padding:9px 14px;background:#111c30;
       border-bottom:1px solid #1e293b}
  .bar b{font-size:13.5px}
  .bar .muted{color:#94a3b8;font-size:12px}
  .bar .kind{margin-left:auto;font-size:11px;border:1px solid #334155;border-radius:999px;
             padding:2px 9px;color:#cbd5e1}
  .bar a{color:#818cf8;text-decoration:none;font-size:12px}
  iframe{display:block;width:100%;height:calc(100% - 41px);border:0;background:#fff}
</style></head><body>
<div class="bar">
  <b><?= htmlspecialchars($name) ?></b>
  <span class="muted"><?= htmlspecialchars($dev['ip']) ?> &middot; behind <?= htmlspecialchars($dev['router']) ?></span>
  <span class="kind"><?= htmlspecialchars($dev['kind'] ?: 'device') ?> &middot; via router SOCKS</span>
  <a href="<?= $inner ?>" target="_blank" rel="noopener">open on its own</a>
</div>
<iframe src="<?= $inner ?>"></iframe>
</body></html><?php
    exit;
}

/* ------------------------------------------------------- fetch through SOCKS */

$prefix = ($_SERVER['SCRIPT_NAME'] ?? '/device.php') . '/' . $id;
$query  = $_SERVER['QUERY_STRING'] ?? '';
$url    = 'http://' . $ip . $sub . ($query !== '' ? '?' . $query : '');

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_PROXY          => $dev['router_host'],
    CURLOPT_PROXYPORT      => $socksPort,
    // SOCKS5_HOSTNAME so the name is resolved at the router's end, which is the
    // only place a private name means anything.
    CURLOPT_PROXYTYPE      => CURLPROXY_SOCKS5_HOSTNAME,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HEADER         => true,
    CURLOPT_CONNECTTIMEOUT => 8,
    CURLOPT_TIMEOUT        => 25,
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_SSL_VERIFYPEER => false,      // these devices ship self-signed certs
    CURLOPT_SSL_VERIFYHOST => false,
    CURLOPT_USERAGENT      => $_SERVER['HTTP_USER_AGENT'] ?? 'Mozilla/5.0',
]);
$raw = curl_exec($ch);

if ($raw === false) {
    $err = curl_error($ch);
    curl_close($ch);
    mt_dev_page('Cannot reach it',
        '<h1>Could not open ' . htmlspecialchars($ip) . '</h1>'
      . '<p><code>' . htmlspecialchars($err) . '</code></p>'
      . '<p>The route to the router is working or this page would not have loaded, so the usual '
      . 'reasons are: the SOCKS access list does not allow this server, the device has its web '
      . 'interface switched off, or it only listens on HTTPS. Cisco switches in particular are '
      . 'often managed over SSH with no web server at all.</p>', 502);
}

$headerLen = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
$status    = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
$ctype     = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
curl_close($ch);

$head = substr($raw, 0, $headerLen);
$body = substr($raw, $headerLen);

http_response_code($status ?: 200);
if ($ctype !== '') header('Content-Type: ' . $ctype);
// Let the device set its own cookies, so a login on the device survives.
foreach (explode("\r\n", $head) as $line) {
    if (stripos($line, 'Set-Cookie:') === 0) header($line, false);
}
header('Content-Security-Policy: sandbox allow-forms allow-scripts allow-same-origin');

/**
 * Root-relative links inside the device's page ("/style.css") would otherwise
 * point at this dashboard instead of at the device. A <base> tag cannot fix
 * those - it only affects relative ones - so they are rewritten to carry the
 * proxy prefix. Anything already absolute is left alone.
 */
if (stripos($ctype, 'text/html') !== false) {
    $body = preg_replace('#\b(src|href|action)=(["\'])/(?!/)#i', '$1=$2' . $prefix . '/', $body);
    $base = '<base href="' . htmlspecialchars($prefix . $sub, ENT_QUOTES) . '">';
    if (preg_match('#<head[^>]*>#i', $body, $m)) {
        $body = preg_replace('#<head[^>]*>#i', $m[0] . $base, $body, 1);
    } else {
        $body = $base . $body;
    }
} elseif (stripos($ctype, 'text/css') !== false) {
    $body = preg_replace('#url\((["\']?)/(?!/)#i', 'url($1' . $prefix . '/', $body);
}

echo $body;
