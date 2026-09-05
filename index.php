<?php
/**
 * MikroTik Network Monitoring Dashboard - Ariyan-IT Solutions.
 *
 * The page is a skeleton; every figure is filled in by assets/app.js from
 * api.php?action=summary and refreshed on a timer, so the numbers update without
 * the page reloading under you.
 */
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/helpers.php';

$db   = mt_db();               // creates the database on first visit
$site = mt_setting('site_name', 'Ariyan-IT Solutions');
$tag  = mt_setting('site_tagline', 'MikroTik Network Monitoring Dashboard');

/**
 * Cache buster taken from the file's own modification time.
 *
 * A hardcoded ?v=1 meant that after replacing the files the browser happily kept
 * serving the JavaScript it already had, so an update looked like it had not been
 * applied at all - the user re-uploads, sees the old screen, and reasonably
 * concludes nothing changed. Stamping mtime makes every changed file a new URL.
 */
function mt_asset($rel) {
    $path = __DIR__ . '/' . ltrim($rel, '/');
    $v = @filemtime($path);
    return mt_h($rel) . '?v=' . ($v ?: '1');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= mt_h($site) ?> - <?= mt_h($tag) ?></title>
<link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><rect width='100' height='100' rx='22' fill='%234f46e5'/><path d='M20 55h13l9-24 12 44 9-24h17' fill='none' stroke='white' stroke-width='8' stroke-linecap='round' stroke-linejoin='round'/></svg>">
<link rel="stylesheet" href="<?= mt_asset('assets/app.css') ?>">
</head>
<body>

<header class="hdr">
  <div class="hdr-in">
    <div class="brand">
      <div class="mark">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round">
          <rect x="2" y="2" width="20" height="8" rx="2"/><rect x="2" y="14" width="20" height="8" rx="2"/>
          <line x1="6" y1="6" x2="6.01" y2="6"/><line x1="6" y1="18" x2="6.01" y2="18"/>
        </svg>
        <span class="dot"></span>
      </div>
      <div>
        <h1><?= mt_h($site) ?> <span class="chip">CyberPanel</span></h1>
        <p><?= mt_h($tag) ?></p>
      </div>
    </div>

    <div class="hdr-right">
      <div class="live-pill" id="livePill">
        <span class="blip"><i></i></span>
        <span>Auto refresh: <b id="pollLabel">live</b></span>
      </div>
      <div id="adminBox"></div>
    </div>
  </div>
</header>

<div class="wrap">
  <div id="notices"></div>
  <div class="tiles" id="tiles"></div>

  <div class="card">
    <div class="card-hd">
      <div>
        <h3>
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12h4l3-8 4 16 3-8h4"/></svg>
          Real-time bandwidth
        </h3>
        <div class="sub" id="chartSub">Combined traffic across every monitored router.</div>
      </div>
      <div class="legend">
        <span><i style="background:#0891b2"></i>Download</span>
        <span><i style="background:#4f46e5"></i>Upload</span>
      </div>
    </div>
    <div class="card-bd"><div id="chart" class="chart-wrap"></div></div>
  </div>

  <div class="card">
    <div class="card-hd">
      <div>
        <h3>
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="2" width="20" height="8" rx="2"/><rect x="2" y="14" width="20" height="8" rx="2"/><line x1="6" y1="6" x2="6.01" y2="6"/><line x1="6" y1="18" x2="6.01" y2="18"/></svg>
          MikroTik devices
        </h3>
        <div class="sub" id="devSub">Live status for every router.</div>
      </div>
      <div id="devHeadTools"></div>
    </div>
    <div class="card-bd"><div class="devices" id="devices"></div></div>
  </div>
</div>

<!-- ------------------------------------------------------------- admin login -->
<div class="ovl" id="loginModal">
  <div class="modal sm">
    <div class="modal-hd">
      <div class="ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg></div>
      <div><h3>Administrator sign in</h3><p>Only administrators can add or change routers.</p></div>
    </div>
    <form id="loginForm">
      <div class="modal-bd">
        <div class="fields">
          <div class="f full"><label>Username</label><input type="text" name="username" autocomplete="username" required></div>
          <div class="f full"><label>Password</label><input type="password" name="password" autocomplete="current-password" required></div>
        </div>
        <div class="result" id="loginResult"></div>
      </div>
      <div class="modal-ft">
        <button type="button" class="btn btn-slate" data-close>Cancel</button>
        <button type="submit" class="btn btn-primary">Sign in</button>
      </div>
    </form>
  </div>
</div>

<!-- ------------------------------------------------------------ add / edit -->
<div class="ovl" id="deviceModal">
  <div class="modal">
    <div class="modal-hd">
      <div class="ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="2" width="20" height="8" rx="2"/><rect x="2" y="14" width="20" height="8" rx="2"/><line x1="6" y1="6" x2="6.01" y2="6"/><line x1="6" y1="18" x2="6.01" y2="18"/></svg></div>
      <div><h3 id="deviceModalTitle">Add MikroTik</h3><p>Details of the router and its API login.</p></div>
    </div>
    <form id="deviceForm">
      <input type="hidden" name="id">
      <div class="modal-bd">
        <div class="fields">
          <div class="f full"><label>Device name *</label><input type="text" name="name" required placeholder="MikroTik - Dubai Branch"></div>
          <div class="f"><label>IP address / hostname *</label><input type="text" name="host" required placeholder="192.168.88.1"></div>
          <div class="f"><label>API port</label><input type="number" name="apiPort" value="8728" min="1" max="65535"></div>
          <div class="f"><label>API username</label><input type="text" name="username" value="admin" autocomplete="off"></div>
          <div class="f"><label>API password</label><input type="password" name="password" autocomplete="new-password" placeholder="">
            <span class="hint" id="pwHint">Leave blank when editing to keep the saved password.</span></div>
          <div class="f"><label>Location</label><input type="text" name="location" placeholder="Dubai Main Office"></div>
          <div class="f"><label>RouterOS version</label><input type="text" name="rosVersion" placeholder="detected automatically" readonly>
            <span class="hint">Read from the router on each poll.</span></div>
          <div class="f full"><label>Description</label><input type="text" name="description" placeholder="Core edge router - CCR2004"></div>
          <div class="f full"><label>Speed is measured on interface</label>
            <select name="wanIface"><option value="">Detect automatically from the default route</option></select>
            <span class="hint">A router counts the same traffic on the bridge and on its member ports, so the speed is read from one interface only.</span></div>
          <label class="f-check"><input type="checkbox" name="enabled" checked> Monitoring enabled</label>
        </div>
        <div class="result" id="deviceResult"></div>
      </div>
      <div class="modal-ft">
        <button type="button" class="btn btn-light" id="testBtn">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg>
          Test connection
        </button>
        <button type="button" class="btn btn-slate" data-close>Cancel</button>
        <button type="submit" class="btn btn-primary">Save device</button>
      </div>
    </form>
  </div>
</div>

<!-- ---------------------------------------------------------- delete confirm -->
<div class="ovl" id="deleteModal">
  <div class="modal sm">
    <div class="modal-hd danger">
      <div class="ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg></div>
      <div><h3>Delete this router?</h3><p>Its monitoring history is removed as well.</p></div>
    </div>
    <div class="modal-bd">
      <p style="font-size:13.5px;color:var(--ink-2)">You are about to remove <b id="delName"></b> from the monitoring system. The router itself is not touched - only this dashboard forgets it.</p>
    </div>
    <div class="modal-ft">
      <button type="button" class="btn btn-slate" data-close>Cancel</button>
      <button type="button" class="btn btn-danger-solid" id="delConfirm">Delete router</button>
    </div>
  </div>
</div>

<!-- --------------------------------------------------------- change password -->
<div class="ovl" id="passModal">
  <div class="modal sm">
    <div class="modal-hd">
      <div class="ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg></div>
      <div><h3>Change admin password</h3><p>Signed in as <b id="passUser"></b>.</p></div>
    </div>
    <form id="passForm">
      <div class="modal-bd">
        <div class="fields">
          <div class="f full"><label>New password</label><input type="password" name="password" minlength="6" required autocomplete="new-password"><span class="hint">At least 6 characters.</span></div>
        </div>
        <div class="result" id="passResult"></div>
      </div>
      <div class="modal-ft">
        <button type="button" class="btn btn-slate" data-close>Cancel</button>
        <button type="submit" class="btn btn-primary">Change password</button>
      </div>
    </form>
  </div>
</div>

<!-- ---------------------------------------------------------------- settings -->
<div class="ovl" id="settingsModal">
  <div class="modal">
    <div class="modal-hd">
      <div class="ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg></div>
      <div><h3>Settings</h3><p>How often the routers are read, and what they ping.</p></div>
    </div>
    <form id="settingsForm">
      <div class="modal-bd">
        <div class="fields">
          <div class="f full"><label>Panel name</label><input type="text" name="site_name" maxlength="60"></div>
          <div class="f full"><label>Subtitle</label><input type="text" name="site_tagline" maxlength="80"></div>
          <div class="f"><label>Read the routers every (seconds)</label>
            <input type="number" name="poll_seconds" min="3" max="300">
            <span class="hint">Lower is more live but asks the routers more often.</span></div>
          <div class="f"><label>Ping target</label>
            <input type="text" name="net_ping_target" placeholder="8.8.8.8" maxlength="64">
            <span class="hint">The router pings this itself.</span></div>
          <div class="f full"><label>Run that ping every (seconds)</label>
            <input type="number" name="net_ping_every" min="10" max="3600">
            <span class="hint">Each ping costs the router about a second, so it runs on a slower clock than the rest.</span></div>
          <div class="f full"><label class="f-check"><input type="checkbox" name="live_bandwidth"> Live bandwidth (one reading per second)</label>
            <span class="hint">Keeps one connection open per router and reads its own traffic monitor, the same
              source as the WinBox graph. Turn this off to fall back to the poll interval above.</span></div>
        </div>
        <div class="result" id="settingsResult"></div>

        <!-- Backup of this installation only: the routers, the settings and the
             logins. Not the graph history, which is thousands of rows a day and
             costs nothing to rebuild. -->
        <div class="bk">
          <h4>Backup of this dashboard</h4>
          <p class="hint">Saves the routers you added, their API logins, your settings and your
            dashboard password - so if you move to another host you type nothing in again.
            The graph history is not included.</p>
          <div class="bk-row">
            <a class="btn btn-slate" id="backupBtn" href="api.php?action=backup" download>Download backup</a>
          </div>
          <p class="hint bk-warn">Keep the file somewhere private - it contains your router passwords.</p>

          <!-- Restore is the half nobody needs on the dashboard they are already
               using, and the file picker looks broken if you open it before you
               have made a backup. Say so before the buttons, not after. -->
          <h4 class="bk-h2">Restore from a backup</h4>
          <p class="hint">Only needed on a <em>new</em> installation - after moving to another host.
            Make the backup on the old dashboard first, then bring the file here.
            Restoring replaces the router list on this dashboard.</p>
          <div class="bk-row">
            <label class="btn btn-slate" for="restoreFile">Choose backup file</label>
            <input type="file" id="restoreFile" accept=".json,application/json" hidden>
            <button type="button" class="btn btn-primary" id="restoreBtn" disabled>Restore</button>
          </div>
          <p class="hint" id="restoreName"></p>
          <div class="result" id="restoreResult"></div>
        </div>
      </div>
      <div class="modal-ft">
        <button type="button" class="btn btn-slate" data-close>Cancel</button>
        <button type="submit" class="btn btn-primary">Save settings</button>
      </div>
    </form>
  </div>
</div>

<!-- ------------------------------------------------- which routers are in this count -->
<div class="ovl" id="listModal">
  <div class="modal">
    <div class="modal-hd">
      <div class="ico"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="2" width="20" height="8" rx="2"/><rect x="2" y="14" width="20" height="8" rx="2"/><line x1="6" y1="6" x2="6.01" y2="6"/><line x1="6" y1="18" x2="6.01" y2="18"/></svg></div>
      <div><h3 id="listTitle">Routers</h3><p id="listSub"></p></div>
    </div>
    <div class="modal-bd"><div class="lrows" id="listBody"></div></div>
    <div class="modal-ft"><button type="button" class="btn btn-slate" data-close>Close</button></div>
  </div>
</div>

<div class="toasts" id="toasts"></div>
<script src="<?= mt_asset('assets/app.js') ?>"></script>
</body>
</html>
