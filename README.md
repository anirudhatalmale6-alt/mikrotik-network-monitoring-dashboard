# MikroTik Network Monitoring Dashboard

Centralised real-time monitoring for any number of MikroTik routers.
Built for **Ariyan-IT Solutions**.

Everything on the dashboard is read from the routers over the RouterOS API.
There is no demo data and nothing is simulated: if a router cannot be reached it
is shown as offline with the actual error the connection returned.

## What it shows

**Summary across all routers** - online devices, offline devices, total active
hotspot users, total PPPoE sessions, total download, total upload, combined
bandwidth, and the number of routers being monitored. Updates on a timer without
reloading the page.

**Per router** - name, IP/hostname and API port, online/offline, latency,
CPU %, RAM % (and total RAM), uptime, RouterOS version, board model, identity,
active hotspot users, active PPPoE users, download and upload speed, traffic
measured, last seen, and the connection status.

**Real-time bandwidth graph** - combined download and upload over time, one point
per poll, with a hover readout.

**Admin panel** - sign in from the button at the top right, then add, edit,
delete, enable and disable routers, poll one on demand, and change the admin
password. Everything else on the page is read-only for visitors.

## Requirements

- PHP 8.0+ with PDO SQLite (`pdo_sqlite`, standard on virtually every host)
- The RouterOS API service enabled on each router (`/ip service`, port 8728 by default)

No MySQL, no composer, no build step, no frameworks, and nothing loaded from a
CDN, so it also works on a LAN with no internet access.

## Install

1. Upload the whole folder to your web server.
2. Make sure PHP can write to the `data/` directory - the SQLite database is
   created there on the first visit.
   ```sh
   chmod 775 data          # or: chown -R www-data:www-data data
   ```
3. Open the site. Click **Admin** and sign in with:

   ```
   username: admin
   password: admin123
   ```

   **Change this immediately** - the padlock button next to your name does it.
   The dashboard shows a warning until you do.
4. Click **Add MikroTik**, fill in the router's address, API port, username and
   password, press **Test connection** to confirm it answers, then save.

### Keeping `data/` private

The database holds your router passwords, because the API needs them to log in.
Keep the folder out of reach of the web:

**nginx**
```nginx
location ^~ /data/ { deny all; }
```

**Apache** - a `.htaccess` file is already included in `data/`; make sure
`AllowOverride All` is on.

**Or move it out of the web root entirely** - the safest option, and it needs no
web server config at all. Create `config.local.php` next to `index.php`:

```php
<?php define('MT_DATA', '/var/lib/mikrotik-monitor');
```
and make that directory writable by PHP.

## Polling

Out of the box the dashboard polls the routers itself: when the data goes stale
the next page request starts a poll in the background. That needs nothing
installed and is what makes the folder work on plain shared hosting.

On a server you control, run the poller as a service instead - then no page view
ever waits for a router and the graph keeps filling in while nobody is watching.

**systemd**
```ini
# /etc/systemd/system/mikrotik-monitor.service
[Unit]
Description=MikroTik monitor poller
After=network-online.target

[Service]
ExecStart=/usr/bin/php /var/www/mikrotik-monitor/poller.php --loop
User=www-data
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
```
```sh
systemctl daemon-reload && systemctl enable --now mikrotik-monitor
```

**cron** (once a minute is the finest cron allows)
```
* * * * * php /var/www/mikrotik-monitor/poller.php --once >/dev/null 2>&1
```

Command line:
```sh
php poller.php --once -v     # one round, printing what each router answered
php poller.php --loop        # what the service runs
```

## How the numbers are produced

- **Speed** comes from the interface byte counters, read twice. Two readings and
  the time between them give a rate.
- **One interface per router.** A MikroTik counts the same packet on the bridge,
  on the member port and on the WAN port, so adding every interface up reports
  several times the real throughput. The internet-facing interface is detected
  from the router's own default route, preferring a PPPoE/LTE session over the
  physical port it rides on, and ignoring VPN tunnels. You can override it per
  device in the edit form.
- **Counter resets are dropped.** A reboot restarts the counters at zero; that
  sample is discarded rather than drawn as an enormous spike. "Traffic measured"
  is what this monitor has actually accumulated, so a reboot does not wipe it.
- **Latency** is an ICMP ping where the host permits it, otherwise the TCP
  handshake to the API port. The card says which one it measured - they are not
  the same thing.
- **Only one poll runs at a time.** A file lock in `data/` stops the service and
  an on-demand poll from measuring against each other's baselines, which would
  invent spikes that never happened.
- **Stale data is labelled.** If nothing has polled recently the page says so
  instead of letting old readings pass as live.

## Files

```
index.php           the dashboard
api.php             JSON endpoints (summary, CRUD, auth, test connection)
poller.php          command line poller
lib/routeros.php    RouterOS API client (pure PHP, no extensions)
lib/poll.php        reads one router and stores the result
lib/db.php          SQLite schema, created automatically
lib/auth.php        admin login (bcrypt)
lib/helpers.php     formatting
assets/app.css      styling
assets/app.js       dashboard rendering, charts, modals
data/               SQLite database and the poll lock (created on first run)
```

## Security notes

- Router API passwords are stored so the poller can log in. Keep `data/` private
  and serve the site over HTTPS if it is reachable from the internet.
- The admin login uses a bcrypt hash and rejects a wrong password. Add/edit/
  delete also require a CSRF token from the signed-in session.
- Use a RouterOS API user with **read-only** permission where you can. This
  monitor never writes to a router - it only issues `/print` commands.
