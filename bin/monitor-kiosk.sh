#!/bin/sh
# Eliminacode: the monitor full screen on the server's own video output (HDMI / DisplayPort TV).
# Run by bin/eliminacode-kiosk.service inside cage (a one-app Wayland screen), as the user "monitor".
# Sound on from the start (--autoplay-policy), own profile, restarted by systemd if it closes.
APP=/var/www/html/eliminacode

# Wait for Apache + MariaDB after a power cut.
i=0
until curl -fs -o /dev/null http://localhost/login.php || [ $i -ge 60 ]; do i=$((i + 1)); sleep 2; done

KEY=$(cd "$APP" && php -r 'require "includes/functions.php"; require_once "includes/queue.php"; echo queueSettings()["key"];' 2>/dev/null)

# Chrome left "crashed" after a power cut: no restore bubble.
PREFS="$HOME/.kiosk/Default/Preferences"
[ -f "$PREFS" ] && sed -i 's/"exit_type":"[A-Za-z]*"/"exit_type":"Normal"/; s/"exited_cleanly":false/"exited_cleanly":true/' "$PREFS"

exec google-chrome --kiosk --ozone-platform=wayland \
    --user-data-dir="$HOME/.kiosk" --no-first-run --no-default-browser-check \
    --noerrdialogs --disable-infobars --disable-session-crashed-bubble \
    --disable-features=Translate,MediaRouter --password-store=basic \
    --autoplay-policy=no-user-gesture-required --check-for-update-interval=31536000 \
    "http://localhost/queue/monitor.php?k=$KEY"
