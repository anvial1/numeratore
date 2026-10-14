#!/bin/sh
# Eliminacode TV kiosk: writes the monitor key for bin/monitor-kiosk.sh. Run as root by
# eliminacode-kiosk.service (ExecStartPre=+), because the user "monitor" cannot read the DB settings.
# Waits up to 2 minutes for MariaDB after a power cut.
OUT=/run/eliminacode-kiosk/key
cd /var/www/html/eliminacode || exit 1
i=0
while [ $i -lt 60 ]; do
    K=$(php -r 'require "includes/functions.php"; require_once "includes/queue.php"; echo queueSettings()["key"];' 2>/dev/null)
    [ -n "$K" ] && break
    i=$((i + 1))
    sleep 2
done
printf '%s' "$K" > "$OUT"
chown monitor "$OUT"
chmod 400 "$OUT"
