# Eliminacode (repo `numeratore`) — handoff notes

`F:\numeratore`, repo `ispbillingai/numeratore` (**public**). A **standalone** take-a-number
queue app (eliminacode). It started on 2026-10-06 as a copy of the Focacciami POS (`F:\pub`), and the
same day all the POS code (cash desk, orders, kitchen, menu, payments, Glovo, WhatsApp…) was removed
at the user's request. Integrating it into other projects is a later decision of the user: keep it standalone.

## What is in it
- `queue/totem.php`, `queue/monitor.php` (no login, `?k=` = `settings.queue.key`), `queue/operator.php`.
  The totem runs on a tablet: vmin sizes, buttons share the height (columns by orientation and `data-count`),
  Wake Lock + full screen on first touch, installable full screen via `queue/totem-manifest.php?k=`.
- `api/queue.php` — state, feed (weather/news/tiles), take, next/prev/recall/call (prev = the number before the current
  one, which goes back in the queue). next/prev/recall/call (and reset, key only) also work
  without a login (GET or POST) with `?ok=` = `settings.queue.op_key`, for another server; the links are in
  Amministrazione › Eliminacode › "Comandi operatore per un altro server" (`called_by` stays NULL).
- `bin/udp-listener.php` — the same commands over UDP (port 9999, `<op_key> next 1`, or JSON), run by the
  systemd unit `bin/eliminacode-udp.service`. Installed on the LAN server and, since 2026-10-15, on production too,
  where the server-only drop-in `/etc/systemd/system/eliminacode-udp.service.d/allow.conf`
  (`IPAddressDeny=any`, `IPAddressAllow=localhost 77.108.35.218`) accepts only the sender's server. Command words
  also accept aliases (`QUEUE_ACTION_ALIASES`: previous/precedente → prev…). After changing the listener:
  `systemctl restart eliminacode-udp` on each server that runs it.
- IP camera tiles (`stream_type` = 'camera', LAN server only): go2rtc 1.9.9 (`eliminacode-go2rtc` service, user
  www-data, API on 127.0.0.1:1984, config `bin/go2rtc.yaml`) turns RTSP / ONVIF into MSE. The camera URL (with
  password) stays in the DB; the monitor gets only `/go2rtc/api/ws?src=c<16 hex>` (`queueCameraName()`), and Apache
  (`bin/apache-go2rtc.conf`) proxies only `video-rtc.js` and that websocket. `bin/cameras-sync.php` loads the tiles
  into go2rtc at its start; the admin re-syncs after every save. `admin/cameras.php` = ONVIF search / profiles /
  preview. Set up with `bin/install-go2rtc.sh`. The "Telecamera" kind shows only where go2rtc answers.
- TV on the LAN server's own video output (HP EliteDesk mini, DisplayPort/HDMI): `bin/eliminacode-kiosk.service`
  runs `bin/monitor-kiosk.sh` (Chrome .deb `--kiosk` inside `cage`, user `monitor`, tty1, sound via pipewire)
  on http://localhost/queue/monitor.php. Waits until a screen is plugged in. Set up once with
  `bin/install-kiosk.sh` (also masks standby/hibernate); LAN server only. Reload the screen: `systemctl restart eliminacode-kiosk`.
- `admin/index.php` (Eliminacode admin), `admin/printer.php` (Stampante: the totem's non-fiscal ESC/POS printer,
  `printer_*` in settings.queue, connection check + test ticket; production uses pub's kitchen printer
  192.168.8.159 through the WireGuard route), `admin/users.php`, `admin/activity.php`.
- Monitor tiles (`queue_slides`) take a photo or a video (`video_path`, MP4/WebM/MOV up to
  `QUEUE_VIDEO_MAX_MB` = 200, limits raised in `.htaccess`). The monitor downloads each video in full as a blob
  before showing it, plays it to the end, then moves on; `video_audio` = its own sound, muted during a call.
  A tile can also be a live TV channel or a radio (`stream_type`/`stream_url`/`stream_logo`/`stream_seconds`):
  picked from `admin/channels.php` (Italian lists from iptv-org and radio-browser, cached 1 day) or a pasted
  .m3u8 / MP3 / YouTube link, played by `assets/js/live-player.js` (hls.js from cdnjs); "Prova" in the admin.
  A radio can instead be background music (`stream_background`, one at a time): not a tile, it plays all the
  time while the tiles rotate, quiet during a call and on tiles with their own sound. Only the tile on screen streams; a channel that fails is skipped for 2 min. On the https site http://
  streams are blocked by the browser (mixed content); the LAN server is http, so they play there.
- `admin/monitor-launcher.php`: downloads "Monitor eliminacode.bat" (Chrome/Edge kiosk with
  `--autoplay-policy=no-user-gesture-required`, own profile) so the monitor's sound is on without a click.
  Browsers never let a page turn sound on by itself; in a normal browser the first touch/key does it.
- `admin/stats.php` (Statistiche): tickets per day / month and per service (reparto), CSV export and PDF report
  (`?pdf=1`, `includes/StatsPdf.php` on FPDF 1.86 bundled in `includes/lib/fpdf`, core Helvetica = Windows-1252). Source is
  `queue_daily_counts` (+1 per ticket in `queueTake()`), which survives the midnight and manual resets.
  Average wait = ticket taken → FIRST call (`queue_tickets.first_called_at`; recall/prev don't change it), summed
  per day in `queue_daily_counts.wait_seconds` / `waited` by `queueCall()` (waits over 4 h ignored).
  The ticket (printer, browser print, totem screen) shows "In coda prima di te: N" and "Attesa stimata: circa X min"
  (`queueWaitEstimate()`: people ahead × median gap between the service's recent first calls; none until 3 gaps).
- `includes/queue.php` (all queue logic, settings in `settings.queue`), `includes/functions.php`
  (session, auth, `appName()`), `includes/ThermalPrinter.php` (ESC/POS ticket over TCP 9100).
- Roles: `admin`, `operator`. Users are never deleted, only disabled.
- Italian-only UI (no i18n). `assets/css/style.css` is inherited from the POS.

## Where it runs
- Server 217.160.131.242 (IONOS Ubuntu 24.04, Apache 2.4, PHP 8.3, MariaDB 10.11), shared with
  Focacciami (`/var/www/html/focacciami`… see memory) and chiamata. SSH as root: credentials and the plink
  one-liner are in Claude's local memory `pub-server.md`, never in git.
- App folder `/var/www/html/eliminacode`, branch `main`.
- https://eliminacode.upgradesrls.com — vhosts `eliminacode.conf` (:80 → https) and
  `eliminacode-le-ssl.conf` (:443, Let's Encrypt, auto-renew). The old name numeratore.upgradesrls.com
  (renamed 2026-10-06) only 301-redirects to eliminacode (`numeratore.conf` / `numeratore-le-ssl.conf`).
- DB `eliminacode`, user `eliminacode` (password only in the server's `config/database.php`).
  The old DB `numeratore` (with the POS tables copied from pub) is kept untouched as a backup.
- Logs: `/var/log/apache2/eliminacode.upgradesrls.com-error.log`. Timezone Europe/Rome (also forced in
  `includes/functions.php`).
- Cron `/etc/cron.d/eliminacode` (copy of `bin/eliminacode.cron`): `bin/midnight-reset.php` at 00:00 closes
  yesterday's open tickets, so every counter restarts from 1. If you change the cron file, copy it again.
- Ticket printer: the server can't reach the shop LAN by itself; needs a route (OpenVPN, never touch
  WireGuard). Until then the totem prints from the browser.

## Workflow (after EVERY change, without being asked)
1. Edit locally, `git commit`, `git push origin main`.
2. Server: `cd /var/www/html/eliminacode && git pull origin main && php migrate.php`.
3. Wait ~3 s, then test: `php -l` changed files; `curl -s -o /dev/null -w '%{http_code}'
   https://eliminacode.upgradesrls.com/login.php`; render changed pages with a CLI script that sets
   `$_SESSION['user_id']`; `tail` the error log.
4. Report the commit hash and the test result.

## Rules
- Deploy only to `/var/www/html/eliminacode`; never to Focacciami, chiamata or ristorante.
- Never hand-edit tracked files on the server; `config/database.php` is gitignored and server-only.
- Public repo: no passwords, keys or tokens in git.
- New tables: `COLLATE utf8mb4_unicode_ci`.
- Never change the WireGuard tunnel on this server.
