/* MikroTik Network Monitoring Dashboard - front end.
   Plain JavaScript and inline SVG. No build step and nothing loaded from a CDN,
   so the folder works exactly as uploaded, including on a LAN with no internet. */
(function () {
  'use strict';

  var state = { csrf: '', isAdmin: false, devices: [], pollSeconds: 5, uiRefresh: 3,
                timer: null, liveTimer: null, liveLane: false, liveMode: 'poll', deleteId: 0 };
  var $  = function (s, r) { return (r || document).querySelector(s); };
  var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };

  // ------------------------------------------------------------- formatting
  function bps(v) {
    v = Number(v) || 0;
    if (v >= 1e9) return (v / 1e9).toFixed(2) + ' Gbps';
    if (v >= 1e6) return (v / 1e6).toFixed(2) + ' Mbps';
    if (v >= 1e3) return (v / 1e3).toFixed(1) + ' kbps';
    return Math.round(v) + ' bps';
  }
  function bytes(v) {
    v = Number(v) || 0;
    if (v >= 1099511627776) return (v / 1099511627776).toFixed(2) + ' TB';
    if (v >= 1073741824) return (v / 1073741824).toFixed(2) + ' GB';
    if (v >= 1048576) return (v / 1048576).toFixed(1) + ' MB';
    if (v >= 1024) return (v / 1024).toFixed(1) + ' KB';
    return Math.round(v) + ' B';
  }
  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function two(n) { return (n < 10 ? '0' : '') + n; }
  function clockOf(ts) { var d = new Date(ts * 1000); return two(d.getHours()) + ':' + two(d.getMinutes()) + ':' + two(d.getSeconds()); }
  function ago(str) {
    if (!str) return 'never';
    var s = Math.floor((Date.now() - new Date(str.replace(' ', 'T')).getTime()) / 1000);
    if (s < 0) return 'just now';
    if (s < 60) return s + 's ago';
    if (s < 3600) return Math.floor(s / 60) + 'm ago';
    if (s < 86400) return Math.floor(s / 3600) + 'h ago';
    return Math.floor(s / 86400) + 'd ago';
  }

  var ICON = {
    wifi:    '<path d="M5 12.55a11 11 0 0 1 14 0"/><path d="M1.42 9a16 16 0 0 1 21.16 0"/><path d="M8.53 16.11a6 6 0 0 1 6.95 0"/><circle cx="12" cy="20" r="1" fill="currentColor" stroke="none"/>',
    wifioff: '<line x1="1" y1="1" x2="23" y2="23"/><path d="M16.72 11.06A10.94 10.94 0 0 1 19 12.55"/><path d="M5 12.55a10.94 10.94 0 0 1 5.17-2.39"/><path d="M10.71 5.05A16 16 0 0 1 22.58 9"/><path d="M1.42 9a15.91 15.91 0 0 1 4.7-2.88"/><path d="M8.53 16.11a6 6 0 0 1 6.95 0"/><circle cx="12" cy="20" r="1" fill="currentColor" stroke="none"/>',
    users:   '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/>',
    down:    '<circle cx="12" cy="12" r="10"/><polyline points="8 12 12 16 16 12"/><line x1="12" y1="8" x2="12" y2="16"/>',
    up:      '<circle cx="12" cy="12" r="10"/><polyline points="16 12 12 8 8 12"/><line x1="12" y1="16" x2="12" y2="8"/>',
    act:     '<path d="M3 12h4l3-8 4 16 3-8h4"/>',
    server:  '<rect x="2" y="2" width="20" height="8" rx="2"/><rect x="2" y="14" width="20" height="8" rx="2"/><line x1="6" y1="6" x2="6.01" y2="6"/><line x1="6" y1="18" x2="6.01" y2="18"/>',
    cpu:     '<rect x="4" y="4" width="16" height="16" rx="2"/><rect x="9" y="9" width="6" height="6"/><line x1="9" y1="1" x2="9" y2="4"/><line x1="15" y1="1" x2="15" y2="4"/><line x1="9" y1="20" x2="9" y2="23"/><line x1="15" y1="20" x2="15" y2="23"/><line x1="20" y1="9" x2="23" y2="9"/><line x1="20" y1="14" x2="23" y2="14"/><line x1="1" y1="9" x2="4" y2="9"/><line x1="1" y1="14" x2="4" y2="14"/>',
    hdd:     '<line x1="22" y1="12" x2="2" y2="12"/><path d="M5.45 5.11 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z"/><line x1="6" y1="16" x2="6.01" y2="16"/>',
    clock:   '<circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/>',
    radio:   '<circle cx="12" cy="12" r="2"/><path d="M16.24 7.76a6 6 0 0 1 0 8.49m-8.48-.01a6 6 0 0 1 0-8.49m11.31-2.82a10 10 0 0 1 0 14.14m-14.14 0a10 10 0 0 1 0-14.14"/>',
    bolt:    '<polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/>',
    edit:    '<path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.12 2.12 0 0 1 3 3L12 15l-4 1 1-4z"/>',
    trash:   '<polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/>',
    power:   '<path d="M18.36 6.64a9 9 0 1 1-12.73 0"/><line x1="12" y1="2" x2="12" y2="12"/>',
    refresh: '<polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-2.12-9.36L23 10"/>',
    shield:  '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>',
    logout:  '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/>',
    plus:    '<line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/>',
    lock:    '<rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>',
    settings:'<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/>',
    warn:    '<path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>',
    check:   '<path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/>'
  };
  function svg(name, cls) {
    return '<svg class="' + (cls || '') + '" viewBox="0 0 24 24" fill="none" stroke="currentColor" '
         + 'stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' + (ICON[name] || '') + '</svg>';
  }

  // ------------------------------------------------------------------- toast
  function toast(msg, kind) {
    var box = $('#toasts');
    var el = document.createElement('div');
    el.className = 'toast ' + (kind || '');
    el.innerHTML = svg(kind === 'bad' ? 'warn' : 'check') + '<span>' + esc(msg) + '</span>';
    box.appendChild(el);
    setTimeout(function () {
      el.style.transition = 'opacity .25s'; el.style.opacity = '0';
      setTimeout(function () { el.remove(); }, 260);
    }, kind === 'bad' ? 6500 : 3800);
  }

  // --------------------------------------------------------------------- api
  function api(action, body, method) {
    var opts = { credentials: 'same-origin', cache: 'no-store' };
    if (body) {
      body.csrf = state.csrf;
      opts.method = method || 'POST';
      opts.headers = { 'Content-Type': 'application/json' };
      opts.body = JSON.stringify(body);
    }
    return fetch('api.php?action=' + encodeURIComponent(action), opts).then(function (r) {
      return r.json().catch(function () {
        return { success: false, message: 'The server returned an unreadable response (HTTP ' + r.status + ').' };
      });
    });
  }

  // ------------------------------------------------------------------- chart
  function drawChart(host, points) {
    if (typeof host === 'string') host = $(host);
    if (!host) return;
    host.innerHTML = '';
    if (!points.length) {
      host.innerHTML = '<div style="padding:36px 0;text-align:center;color:var(--muted);font-size:13px">'
        + 'Waiting for the first readings. The graph needs two polls before it can show a speed.</div>';
      return;
    }
    var W = Math.max(280, host.clientWidth || 800), H = 240;
    var padL = 88, padR = 14, padT = 12, padB = 26;
    var iw = W - padL - padR, ih = H - padT - padB;

    var t0 = points[0].ts, t1 = points[points.length - 1].ts;
    var span = Math.max(1, t1 - t0), peak = 0, i;
    for (i = 0; i < points.length; i++) { peak = Math.max(peak, points[i].rx, points[i].tx); }

    // A round axis maximum so the gridline labels read as sensible numbers.
    var exp = Math.pow(10, Math.floor(Math.log(peak || 1) / Math.LN10));
    var f = (peak || 1) / exp;
    var ymax = (f <= 1 ? 1 : f <= 2 ? 2 : f <= 2.5 ? 2.5 : f <= 5 ? 5 : 10) * exp;

    var X = function (t) { return padL + (t - t0) / span * iw; };
    var Y = function (v) { return padT + ih - (v / ymax) * ih; };
    var NS = 'http://www.w3.org/2000/svg';
    var s = document.createElementNS(NS, 'svg');
    s.setAttribute('viewBox', '0 0 ' + W + ' ' + H);
    s.setAttribute('height', H);

    function add(tag, attrs, text) {
      var n = document.createElementNS(NS, tag);
      for (var k in attrs) n.setAttribute(k, attrs[k]);
      if (text !== undefined) n.textContent = text;
      s.appendChild(n); return n;
    }

    for (i = 0; i <= 4; i++) {
      var gy = Y(ymax * i / 4);
      add('line', { x1: padL, y1: gy, x2: W - padR, y2: gy, stroke: '#eef2f7', 'stroke-width': 1 });
      add('text', { x: padL - 9, y: gy + 4, 'text-anchor': 'end', fill: '#94a3b8', 'font-size': 10.5 }, bps(ymax * i / 4));
    }
    var ticks = Math.max(2, Math.min(6, Math.floor(iw / 110)));
    for (i = 0; i <= ticks; i++) {
      var tt = t0 + span * i / ticks;
      add('text', { x: X(tt), y: H - 7, fill: '#94a3b8', 'font-size': 10.5,
        'text-anchor': i === 0 ? 'start' : (i === ticks ? 'end' : 'middle') }, clockOf(tt));
    }

    var defs = document.createElementNS(NS, 'defs');
    s.appendChild(defs);
    function series(key, color, gid) {
      var d = '', j, p;
      for (j = 0; j < points.length; j++) {
        p = points[j];
        d += (j ? 'L' : 'M') + X(p.ts).toFixed(1) + ' ' + Y(p[key]).toFixed(1) + ' ';
      }
      var g = document.createElementNS(NS, 'linearGradient');
      g.setAttribute('id', gid); g.setAttribute('x1', 0); g.setAttribute('y1', 0);
      g.setAttribute('x2', 0); g.setAttribute('y2', 1);
      [['0%', .32], ['100%', 0]].forEach(function (st) {
        var stop = document.createElementNS(NS, 'stop');
        stop.setAttribute('offset', st[0]); stop.setAttribute('stop-color', color);
        stop.setAttribute('stop-opacity', st[1]); g.appendChild(stop);
      });
      defs.appendChild(g);
      add('path', { d: d + 'L' + X(t1).toFixed(1) + ' ' + (padT + ih) + ' L' + X(t0).toFixed(1) + ' ' + (padT + ih) + ' Z',
                    fill: 'url(#' + gid + ')', stroke: 'none' });
      add('path', { d: d, fill: 'none', stroke: color, 'stroke-width': 2, 'stroke-linejoin': 'round', 'stroke-linecap': 'round' });
    }
    var uid = 'g' + Math.floor(t1);
    series('rx', '#0891b2', uid + 'd');
    series('tx', '#4f46e5', uid + 'u');

    var cur = add('line', { x1: 0, y1: padT, x2: 0, y2: padT + ih, stroke: '#cbd5e1',
                            'stroke-width': 1, 'stroke-dasharray': '3 3', opacity: 0 });
    host.appendChild(s);
    var tip = document.createElement('div');
    tip.className = 'tip';
    host.appendChild(tip);

    s.addEventListener('mousemove', function (ev) {
      var r = s.getBoundingClientRect();
      var mx = (ev.clientX - r.left) * (W / r.width);
      if (mx < padL || mx > W - padR) { tip.style.opacity = 0; cur.setAttribute('opacity', 0); return; }
      var at = t0 + (mx - padL) / iw * span, best = 0, bd = Infinity, j;
      for (j = 0; j < points.length; j++) {
        var dd = Math.abs(points[j].ts - at);
        if (dd < bd) { bd = dd; best = j; }
      }
      var p = points[best];
      cur.setAttribute('x1', X(p.ts)); cur.setAttribute('x2', X(p.ts)); cur.setAttribute('opacity', 1);
      tip.innerHTML = '<b>' + clockOf(p.ts) + '</b><br>Download ' + bps(p.rx) + '<br>Upload ' + bps(p.tx);
      tip.style.opacity = 1;
      var left = (X(p.ts) / W) * r.width + 14;
      if (left + tip.offsetWidth > r.width) left = (X(p.ts) / W) * r.width - tip.offsetWidth - 14;
      tip.style.left = left + 'px';
      tip.style.top = '10px';
    });
    s.addEventListener('mouseleave', function () { tip.style.opacity = 0; cur.setAttribute('opacity', 0); });
  }

  // ------------------------------------------------------------------ render
  /* opts.id   - element id for the value, so the live tick can rewrite just the
                 number instead of re-rendering the whole row every second.
     opts.list - which device list clicking the tile should open. */
  function tile(cls, label, value, iconName, foot, opts) {
    opts = opts || {};
    var attrs = 'class="tile ' + cls + (opts.list ? ' tile-click"' : '"');
    if (opts.list) attrs += ' data-list="' + opts.list + '" title="Click to see which routers"';
    return '<div ' + attrs + '><div class="top"><div>'
      + '<div class="lbl">' + esc(label) + '</div>'
      + '<div class="val"' + (opts.id ? ' id="' + opts.id + '"' : '') + '>' + value + '</div></div>'
      + '<div class="ico">' + svg(iconName) + '</div></div>'
      + '<div class="foot"' + (opts.footId ? ' id="' + opts.footId + '"' : '') + '>' + foot + '</div></div>';
  }

  function renderTiles(s) {
    $('#tiles').innerHTML =
      tile('t-emerald', 'Online devices', s.onlineDevices + ' <small>/ ' + s.totalDevices + '</small>', 'wifi',
           '<span class="blip" style="width:8px;height:8px"><i></i></span> Active and reachable &middot; <b>see list</b>',
           { id: 'tv-online', list: 'online' })
      + tile('t-rose', 'Offline devices', s.offlineDevices, 'wifioff',
             (s.disabledDevices > 0 ? 'Unreachable &middot; <b>' + s.disabledDevices + ' disabled</b>' : 'Unreachable')
             + ' &middot; <b>see list</b>',
             { id: 'tv-offline', list: 'offline' })
      + tile('t-amber', 'Active hotspot users', s.totalHotspotUsers.toLocaleString(), 'users',
             '<b>' + s.totalPppoeUsers + '</b> PPPoE sessions', { id: 'tv-hs', footId: 'tf-hs' })
      + tile('t-cyan', 'Total download', bps(s.totalDownloadBps), 'down', 'Combined ingress', { id: 'tv-dl' })
      + tile('t-indigo', 'Total upload', bps(s.totalUploadBps), 'up', 'Combined egress', { id: 'tv-ul' })
      + tile('t-blue', 'Total bandwidth', bps(s.totalBandwidthBps), 'act', 'Download + upload', { id: 'tv-bw' })
      + tile('t-slate', 'Total MikroTik devices', s.totalDevices, 'server', 'Monitored routers &middot; <b>see list</b>',
             { id: 'tv-total', list: 'all' });
  }

  /* ------------------------------------------------------------- device list
     He asked to be able to click a count and see WHICH routers it means. The
     data is already on the page, so this needs no request. */
  function openDeviceList(kind) {
    var devs = state.devices || [];
    var title, rows;
    if (kind === 'online')       { title = 'Online routers';  rows = devs.filter(function (d) { return d.status === 'online'; }); }
    else if (kind === 'offline') { title = 'Offline routers'; rows = devs.filter(function (d) { return d.status !== 'online'; }); }
    else                         { title = 'All routers';     rows = devs.slice(); }

    $('#listTitle').textContent = title;
    $('#listSub').textContent = rows.length + ' of ' + devs.length + ' router' + (devs.length === 1 ? '' : 's');
    $('#listBody').innerHTML = rows.length
      ? rows.map(function (d) {
          var pill = d.status === 'online'
            ? '<span class="pill pill-on"><span class="d"></span>Online</span>'
            : d.status === 'disabled'
              ? '<span class="pill pill-dis"><span class="d"></span>Disabled</span>'
              : '<span class="pill pill-off"><span class="d"></span>Offline</span>';
          // For an offline router the reason is the useful part, so it is shown
          // here rather than made him hunt for the card.
          var why = d.status === 'online'
            ? '<span class="mono">' + bps(d.downloadBps) + ' down &middot; ' + bps(d.uploadBps) + ' up</span>'
            : '<span class="lrow-err">' + esc(d.error || (d.status === 'disabled' ? 'Monitoring disabled' : 'Not reachable')) + '</span>';
          return '<div class="lrow"><div class="lrow-main">'
            + '<div class="lrow-name">' + esc(d.name) + '</div>'
            + '<div class="lrow-meta mono">' + esc(d.host) + ':' + d.apiPort
            + (d.location ? ' &middot; ' + esc(d.location) : '') + '</div>'
            + '<div class="lrow-meta">' + why + '</div>'
            + '</div><div>' + pill + '</div></div>';
        }).join('')
      : '<div class="lrow"><div class="lrow-main"><div class="lrow-meta">Nothing in this list right now.</div></div></div>';
    open('#listModal');
  }

  function deviceCard(d) {
    var on = d.status === 'online';
    var cls = on ? 'dev on' : (d.status === 'disabled' ? 'dev dis' : 'dev off');
    var pill = on ? '<span class="pill pill-on"><span class="d"></span>Online</span>'
      : d.status === 'disabled' ? '<span class="pill pill-dis"><span class="d"></span>Disabled</span>'
      : '<span class="pill pill-off"><span class="d"></span>Offline</span>';

    var latCls = !on ? 'lat-bad' : (d.pingMs < 50 ? 'lat-good' : d.pingMs < 150 ? 'lat-mid' : 'lat-bad');
    var cpuCls = d.cpu > 80 ? 'b-hot' : d.cpu > 50 ? 'b-mid' : 'b-ok';
    var ramCls = d.ramPct > 85 ? 'b-hot' : 'b-ram';

    var admin = state.isAdmin ? '<div class="grp">'
      + '<button class="btn ' + (d.enabled ? 'btn-amber' : 'btn-emerald') + ' btn-sm btn-icon" data-act="toggle" data-id="' + d.id + '" title="' + (d.enabled ? 'Disable monitoring' : 'Enable monitoring') + '">' + svg('power') + '</button>'
      + '<button class="btn btn-slate btn-sm btn-icon" data-act="edit" data-id="' + d.id + '" title="Edit">' + svg('edit') + '</button>'
      + '<button class="btn btn-danger btn-sm btn-icon" data-act="delete" data-id="' + d.id + '" title="Delete">' + svg('trash') + '</button>'
      + '</div>' : '';

    return '<div class="' + cls + '">'
      + '<div class="dev-hd"><div style="display:flex;gap:12px;align-items:center;min-width:0">'
      +   '<div class="ico">' + svg('server') + '</div>'
      +   '<div style="min-width:0"><h4>' + esc(d.name) + '</h4>'
      +     '<div class="meta mono">' + esc(d.host) + ':' + d.apiPort
      +       (d.location ? ' <span style="color:#cbd5e1">&bull;</span> <span style="font-family:inherit">' + esc(d.location) + '</span>' : '')
      +     '</div></div></div>'
      + '<div class="right">' + pill
      +   (d.rosVersion ? '<span class="ver mono">' + esc(d.rosVersion.split(' ')[0]) + '</span>' : '')
      + '</div></div>'

      + (d.error && d.status === 'offline' ? '<div class="dev-err" style="margin-top:12px">' + esc(d.error) + '</div>' : '')

      // The router's OWN ping. He asked for this and only this: the figure from
      // the monitoring server to the router told him nothing he wanted to know.
      + '<div class="ping-row"><div style="display:flex;align-items:center;gap:7px">'
      +   '<span style="color:var(--muted)">' + svg('radio') + '</span>'
      +   '<span style="color:var(--muted)">Ping:</span>'
      +   (on && d.netPingMs !== null && d.netPingMs !== undefined
            ? '<span class="lat ' + (d.netPingMs < 50 ? 'lat-good' : d.netPingMs < 150 ? 'lat-mid' : 'lat-bad')
              + ' mono">' + d.netPingMs + ' ms</span>'
              + '<span style="color:var(--muted-2);font-size:10.5px">to ' + esc(d.netPingTarget || '8.8.8.8') + '</span>'
            : (on ? '<span style="color:var(--muted-2);font-size:11.5px">'
                    + esc(d.netPingErr || 'measuring...') + '</span>'
                  : '<span class="lat lat-bad mono">N/A</span>'))
      + '</div><div class="cs" title="' + esc(d.connStatus) + '">' + esc(d.connStatus) + '</div></div>'

      // ids so the once-a-second live tick can rewrite just these two numbers.
      // Re-rendering the whole card at that rate would flicker and would drop
      // whatever the mouse was over.
      + '<div class="speeds">'
      +   '<div class="speed speed-dl"><div class="k">' + svg('down') + 'Download</div><div class="v mono" id="dl-' + d.id + '">' + bps(d.downloadBps) + '</div></div>'
      +   '<div class="speed speed-ul"><div class="k">' + svg('up') + 'Upload</div><div class="v mono" id="ul-' + d.id + '">' + bps(d.uploadBps) + '</div></div>'
      + '</div>'

      + '<div class="gauge"><div class="row"><span>' + svg('cpu') + 'CPU usage</span><b>' + (on ? d.cpu + '%' : '-') + '</b></div>'
      +   '<div class="bar"><i class="' + cpuCls + '" style="width:' + (on ? d.cpu : 0) + '%"></i></div></div>'
      + '<div class="gauge"><div class="row"><span>' + svg('hdd') + 'RAM usage</span><b>'
      +   (on ? d.ramPct + '%' + (d.ramTotalMb ? ' <span style="color:var(--muted);font-weight:500">of ' + d.ramTotalMb + ' MB</span>' : '') : '-') + '</b></div>'
      +   '<div class="bar"><i class="' + ramCls + '" style="width:' + (on ? d.ramPct : 0) + '%"></i></div></div>'

      + '<div class="grid4">'
      +   '<div class="mini"><span class="k">Hotspot active</span><span class="v v-amber">' + svg('users') + (on ? d.hotspotUsers : 0) + '</span></div>'
      +   '<div class="mini"><span class="k">PPPoE active</span><span class="v v-indigo">' + svg('users') + (on ? d.pppoeUsers : 0) + '</span></div>'
      +   '<div class="mini"><span class="k">Uptime</span><span class="v v-ink" style="font-size:12px">' + svg('clock') + (on ? esc(d.uptime || '-') : 'Offline') + '</span></div>'
      +   '<div class="mini"><span class="k">Traffic measured</span><span class="v v-ink" style="font-size:12px" id="tb-' + d.id + '">' + bytes(d.trafficBytes) + '</span></div>'
      + '</div>'

      + '<div class="dev-ft"><div class="seen">'
      +   '<span>Last seen: ' + esc(ago(d.lastSeen)) + '</span>'
      +   '<span class="mono">' + (d.wanIface ? 'iface ' + esc(d.wanIface) : '') + '</span>'
      + '</div><div class="tools">'
      +   (state.isAdmin
            ? '<button class="btn btn-light btn-sm" data-act="poll" data-id="' + d.id + '">' + svg('refresh') + 'Poll now</button>'
            : '<span style="font-size:11px;color:var(--muted-2)">' + (d.board ? esc(d.board) : '') + '</span>')
      +   admin
      + '</div></div></div>';
  }

  function renderDevices(devices) {
    var box = $('#devices');
    if (!devices.length) {
      box.innerHTML = '<div class="empty" style="grid-column:1/-1">' + svg('server')
        + '<h3>No routers yet</h3><p>Sign in as administrator and add your first MikroTik to start monitoring.</p></div>';
      return;
    }
    box.innerHTML = devices.map(deviceCard).join('');
  }

  function renderAdminBox() {
    $('#adminBox').innerHTML = state.isAdmin
      ? '<div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">'
        + '<button class="btn btn-primary btn-sm" id="addBtn">' + svg('plus') + 'Add MikroTik</button>'
        + '<button class="btn btn-light btn-sm" id="setBtn" title="Settings">' + svg('settings') + '</button>'
        + '<button class="btn btn-light btn-sm" id="passBtn" title="Change admin password">' + svg('lock') + '</button>'
        + '<span class="pill pill-on" style="font-weight:600"><span class="d"></span>' + esc(state.adminUser) + '</span>'
        + '<button class="btn btn-slate btn-sm" id="logoutBtn">' + svg('logout') + 'Logout</button></div>'
      : '<button class="btn btn-light" id="adminBtn">' + svg('shield') + 'Admin</button>';
  }

  function renderNotices(d) {
    var out = '';
    if (d.stale) {
      out += '<div class="notice notice-warn">' + svg('warn')
        + '<div><b>The routers have not been polled recently.</b> Last poll: ' + esc(ago(d.lastPoll))
        + '. The figures below are the last readings, not live. If you installed the background poller, check it is running'
        + ' (<span class="mono">systemctl status mikrotik-monitor</span>).</div></div>';
    }
    // Not an error - it is the normal state of a fresh install, and saying so beats
    // leaving someone staring at empty tiles wondering what went wrong.
    if (!d.stale && d.devices.length && d.liveMode !== 'stream' && d.liveWhy) {
      out += '<div class="notice notice-info">' + svg('bolt')
        + '<div><b>' + (d.liveMode === 'direct'
            ? 'Bandwidth is being read on every refresh.'
            : 'Live bandwidth is not running.')
        + '</b> ' + esc(d.liveWhy) + '</div></div>';
    }
    if (d.defaultPassword && d.isAdmin) {
      out += '<div class="notice notice-info">' + svg('lock')
        + '<div><b>The admin account still uses the default password.</b> Anyone who can open this page can change your routers.'
        + ' <a href="#" id="noticePass">Change it now</a>.</div></div>';
    }
    if (state.dbExposed) {
      out += '<div class="notice notice-warn">' + svg('warn')
        + '<div><b>Your database is downloadable from the web.</b> data/monitor.sqlite answers over HTTP,'
        + ' and it holds your router API passwords.'
        + (state.isAdmin
            ? ' <a href="#" id="secureDb"><b>Move it out of the web root now</b></a> - one click, the copy is'
              + ' checked before the exposed one is deleted.'
            : ' Ask an administrator to move it out of the web root.')
        + '</div></div>';
    }
    $('#notices').innerHTML = out;
    var np = $('#noticePass');
    if (np) np.addEventListener('click', function (e) { e.preventDefault(); openPass(); });
    var sd = $('#secureDb');
    if (sd) sd.addEventListener('click', function (e) {
      e.preventDefault();
      sd.textContent = 'Moving...';
      api('secure_db', {}).then(function (r) {
        toast(r.message, r.success ? 'ok' : 'bad');
        if (r.success) { state.dbExposed = false; setTimeout(function () { location.reload(); }, 1200); }
        else { sd.innerHTML = '<b>Move it out of the web root now</b>'; }
      });
    });
  }

  // -------------------------------------------------------------- data cycle
  var lastPoints = [];

  // Ask the web server for the database file. Anything other than a 200 is the
  // healthy answer. Checked once per page load, not on every refresh.
  function checkDbExposed() {
    fetch('data/monitor.sqlite', { method: 'HEAD', cache: 'no-store' })
      .then(function (r) { if (r.status === 200) { state.dbExposed = true; } })
      .catch(function () {});
  }

  function refresh() {
    return api('summary').then(function (d) {
      if (!d || !d.success) return;
      state.isAdmin = !!d.isAdmin;
      state.adminUser = d.adminUser || '';
      state.csrf = d.csrf || state.csrf;
      state.devices = d.devices;
      state.pollSeconds = d.pollSeconds || 5;
      state.uiRefresh = d.uiRefresh || 3;

      renderAdminBox();
      renderNotices(d);
      renderTiles(d.summary);
      renderDevices(d.devices);

      state.liveMode = d.liveMode || 'poll';
      $('#pollLabel').textContent = liveLabel(state.liveMode);
      // A bare "every 5s" reads like the live figures never arrived. Say what is
      // actually happening instead, on the pill itself.
      $('#livePill').title = d.liveMode === 'stream'
        ? 'Bandwidth is streaming from the routers, one reading per second.'
        : (d.liveWhy || '');
      $('#livePill').className = 'live-pill' + (d.stale ? ' warn' : '');
      $('#devSub').textContent = d.devices.length
        ? d.summary.onlineDevices + ' of ' + d.summary.totalDevices + ' reachable, last checked ' + ago(d.lastPoll)
        : 'No routers configured yet.';
      $('#chartSub').textContent = 'Combined traffic across every monitored router, '
        + (d.liveLane ? 'one point per second, straight from the routers.' : 'one point per poll.');

      lastPoints = d.history || [];
      drawChart('#chart', lastPoints);
    });
  }

  function loop() {
    clearTimeout(state.timer);
    state.timer = setTimeout(function () {
      refresh().then(loop, loop);
    }, Math.max(2, state.uiRefresh) * 1000);
  }

  /* ------------------------------------------------------------- live tick
     The bandwidth lane writes a new reading every second. This asks only for
     those numbers and writes them straight into the existing elements, so the
     figure moves once a second without rebuilding the page - which is what the
     full refresh above does on its slower clock. */
  function setText(id, txt) { var el = document.getElementById(id); if (el && el.textContent !== txt) el.textContent = txt; }

  // Three honest states, not two: streaming, read on each refresh, or whatever the
  // ordinary poll last wrote.
  function liveLabel(mode) {
    if (mode === 'stream') return 'live, every 1s';
    if (mode === 'direct') return 'live, read on refresh';
    return 'every ' + state.pollSeconds + 's';
  }

  function liveTick() {
    return api('live').then(function (d) {
      if (!d || !d.success) return;
      var s = d.summary;
      state.liveLane = !!d.liveLane;

      setText('tv-dl', bps(s.totalDownloadBps));
      setText('tv-ul', bps(s.totalUploadBps));
      setText('tv-bw', bps(s.totalBandwidthBps));
      setText('tv-hs', Number(s.totalHotspotUsers || 0).toLocaleString());
      var hsFoot = document.getElementById('tf-hs');
      if (hsFoot) hsFoot.innerHTML = '<b>' + (s.totalPppoeUsers || 0) + '</b> PPPoE sessions';

      (d.devices || []).forEach(function (x) {
        setText('dl-' + x.id, bps(x.downloadBps));
        setText('ul-' + x.id, bps(x.uploadBps));
        setText('tb-' + x.id, bytes(x.trafficBytes));
        // Keep the copy the device-list modal reads in step, so opening it does
        // not show speeds from the last full refresh.
        for (var i = 0; i < state.devices.length; i++) {
          if (state.devices[i].id === x.id) {
            state.devices[i].downloadBps = x.downloadBps;
            state.devices[i].uploadBps   = x.uploadBps;
          }
        }
      });

      state.liveMode = d.liveMode || state.liveMode;
      var pl = $('#pollLabel');
      if (pl) pl.textContent = liveLabel(state.liveMode);

      lastPoints = d.history || lastPoints;
      drawChart('#chart', lastPoints);
    });
  }

  function liveLoop() {
    clearTimeout(state.liveTimer);
    state.liveTimer = setTimeout(function () {
      // Nothing to update while the tab is in the background, and a phone left on
      // the dashboard would otherwise keep asking once a second all night.
      if (document.hidden) { liveLoop(); return; }
      liveTick().then(liveLoop, liveLoop);
    }, 1000);
  }

  var rt;
  window.addEventListener('resize', function () {
    clearTimeout(rt);
    rt = setTimeout(function () { drawChart('#chart', lastPoints); }, 180);
  });

  // ------------------------------------------------------------------ modals
  function open(sel) { $(sel).classList.add('show'); }
  function close(sel) {
    var m = $(sel);
    m.classList.remove('show');
    $$('.result', m).forEach(function (r) { r.className = 'result'; r.textContent = ''; });
  }
  function showResult(sel, ok, msg) {
    var r = $(sel);
    r.className = 'result show ' + (ok ? 'ok' : 'bad');
    r.textContent = msg;
  }

  document.addEventListener('click', function (e) {
    var closeBtn = e.target.closest('[data-close]');
    if (closeBtn) { close('#' + closeBtn.closest('.ovl').id); return; }
    if (e.target.classList && e.target.classList.contains('ovl')) { close('#' + e.target.id); return; }

    var listTile = e.target.closest('.tile-click');
    if (listTile) { openDeviceList(listTile.getAttribute('data-list')); return; }

    var b = e.target.closest('button');
    if (!b) return;
    if (b.id === 'adminBtn')  { open('#loginModal'); $('#loginForm [name=username]').focus(); }
    if (b.id === 'logoutBtn') { api('logout', {}).then(function () { state.isAdmin = false; state.csrf = ''; refresh(); toast('Signed out.'); }); }
    if (b.id === 'addBtn')    openDevice(null);
    if (b.id === 'passBtn')   openPass();
    if (b.id === 'setBtn')    openSettings();

    var act = b.getAttribute('data-act');
    if (!act) return;
    var id = parseInt(b.getAttribute('data-id'), 10);
    var dev = state.devices.filter(function (d) { return d.id === id; })[0];
    if (!dev) return;

    if (act === 'edit')   openDevice(dev);
    if (act === 'delete') { state.deleteId = id; $('#delName').textContent = dev.name; open('#deleteModal'); }
    if (act === 'toggle') {
      api('device_toggle', { id: id }).then(function (r) {
        toast(r.message, r.success ? 'ok' : 'bad');
        refresh();
      });
    }
    if (act === 'poll') {
      b.disabled = true;
      var old = b.innerHTML;
      b.innerHTML = svg('refresh', 'spin') + 'Polling';
      api('device_poll', { id: id }).then(function (r) {
        toast(r.message, r.success && r.online ? 'ok' : 'bad');
        b.disabled = false; b.innerHTML = old;
        refresh();
      });
    }
  });

  $('#delConfirm').addEventListener('click', function () {
    api('device_delete', { id: state.deleteId }).then(function (r) {
      toast(r.message, r.success ? 'ok' : 'bad');
      close('#deleteModal');
      refresh();
    });
  });

  $('#loginForm').addEventListener('submit', function (e) {
    e.preventDefault();
    var f = e.target;
    api('login', { username: f.username.value, password: f.password.value }).then(function (r) {
      if (!r.success) { showResult('#loginResult', false, r.message); return; }
      state.csrf = r.csrf; state.isAdmin = true;
      f.reset();
      close('#loginModal');
      toast('Signed in as ' + r.username, 'ok');
      refresh();
    });
  });

  $('#passForm').addEventListener('submit', function (e) {
    e.preventDefault();
    var f = e.target;
    api('change_password', { password: f.password.value }).then(function (r) {
      if (!r.success) { showResult('#passResult', false, r.message); return; }
      f.reset(); close('#passModal');
      toast('Password changed.', 'ok');
      refresh();
    });
  });
  function openPass() { $('#passUser').textContent = state.adminUser; open('#passModal'); }

  function openSettings() {
    var f = $('#settingsForm');
    api('settings_get').then(function (r) {
      if (!r.success) { toast(r.message || 'Could not read the settings.', 'bad'); return; }
      f.site_name.value       = r.settings.site_name;
      f.site_tagline.value    = r.settings.site_tagline;
      f.poll_seconds.value    = r.settings.poll_seconds;
      f.net_ping_target.value = r.settings.net_ping_target;
      f.net_ping_every.value  = r.settings.net_ping_every;
      f.live_bandwidth.checked = !!r.settings.live_bandwidth;
      open('#settingsModal');
    });
  }

  $('#settingsForm').addEventListener('submit', function (e) {
    e.preventDefault();
    var f = e.target;
    api('settings_save', {
      site_name: f.site_name.value, site_tagline: f.site_tagline.value,
      poll_seconds: f.poll_seconds.value, net_ping_target: f.net_ping_target.value,
      net_ping_every: f.net_ping_every.value, live_bandwidth: f.live_bandwidth.checked
    }).then(function (r) {
      if (!r.success) { showResult('#settingsResult', false, r.message); return; }
      close('#settingsModal');
      toast(r.message, 'ok');
      refresh();
    });
  });

  // ------------------------------------------------------------ device modal
  function openDevice(dev) {
    var f = $('#deviceForm');
    f.reset();
    var sel = f.wanIface;
    sel.innerHTML = '<option value="">Detect automatically from the default route</option>';

    if (dev) {
      $('#deviceModalTitle').textContent = 'Edit MikroTik';
      $('#pwHint').textContent = 'Leave blank to keep the saved password.';
      f.id.value = dev.id;
      f.name.value = dev.name;
      f.host.value = dev.host;
      f.apiPort.value = dev.apiPort;
      f.username.value = dev.username;
      f.location.value = dev.location;
      f.description.value = dev.description;
      f.rosVersion.value = dev.rosVersion;
      f.enabled.checked = dev.enabled;
      if (dev.wanIface) {
        sel.innerHTML += '<option value="' + esc(dev.wanIface) + '" selected>' + esc(dev.wanIface) + ' (current)</option>';
      }
      // Offer the real interface list, but only if the router answers.
      api('device_interfaces', { id: dev.id }).then(function (r) {
        if (!r.success) return;
        sel.innerHTML = '<option value="">Detect automatically from the default route</option>';
        r.interfaces.forEach(function (i) {
          var o = document.createElement('option');
          o.value = i.name;
          o.textContent = i.name + ' (' + i.type + (i.running ? ', up' : ', down') + ')';
          if (i.name === r.current) o.selected = true;
          sel.appendChild(o);
        });
      });
    } else {
      $('#deviceModalTitle').textContent = 'Add MikroTik';
      $('#pwHint').textContent = 'The API password for this router.';
      f.id.value = '';
      f.apiPort.value = 8728;
      f.username.value = 'admin';
      f.enabled.checked = true;
    }
    open('#deviceModal');
    f.name.focus();
  }

  $('#testBtn').addEventListener('click', function () {
    var f = $('#deviceForm'), b = this;
    if (!f.host.value.trim()) { showResult('#deviceResult', false, 'Enter an IP address or hostname first.'); return; }
    b.disabled = true;
    var old = b.innerHTML;
    b.innerHTML = svg('refresh', 'spin') + 'Testing';
    api('test_connection', {
      id: f.id.value, host: f.host.value.trim(), apiPort: f.apiPort.value,
      username: f.username.value, password: f.password.value
    }).then(function (r) {
      b.disabled = false; b.innerHTML = old;
      if (r.success) {
        var extra = [];
        if (r.identity) extra.push('identity "' + r.identity + '"');
        if (r.rosVersion) extra.push('RouterOS ' + r.rosVersion);
        if (r.board) extra.push(r.board);
        if (r.uptime) extra.push('up ' + r.uptime);
        showResult('#deviceResult', true, r.message + (extra.length ? ' - ' + extra.join(', ') + '.' : ''));
        if (r.rosVersion) f.rosVersion.value = r.rosVersion;
      } else {
        showResult('#deviceResult', false, r.message);
      }
    });
  });

  $('#deviceForm').addEventListener('submit', function (e) {
    e.preventDefault();
    var f = e.target;
    api('device_save', {
      id: f.id.value, name: f.name.value.trim(), host: f.host.value.trim(),
      apiPort: f.apiPort.value, username: f.username.value.trim(), password: f.password.value,
      location: f.location.value.trim(), description: f.description.value.trim(),
      rosVersion: f.rosVersion.value.trim(), wanIface: f.wanIface.value, enabled: f.enabled.checked
    }).then(function (r) {
      if (!r.success) { showResult('#deviceResult', false, r.message); return; }
      close('#deviceModal');
      toast(r.message, 'ok');
      // Poll the new or edited device straight away so its card is not blank.
      api('device_poll', { id: r.id }).then(refresh);
    });
  });

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') $$('.ovl.show').forEach(function (m) { close('#' + m.id); });
  });

  checkDbExposed();
  refresh().then(loop, loop);
  liveLoop();
})();
