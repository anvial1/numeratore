#!/bin/sh
# Eliminacode: the monitor full screen on the server's own video output (HDMI / DisplayPort TV).
# Run by bin/eliminacode-kiosk.service inside cage (a one-app Wayland screen), as the user "monitor".
# Sound on from the start (--autoplay-policy), own profile, restarted by systemd if it closes.
APP=/var/www/html/eliminacode

# Wait for a TV on HDMI / DisplayPort (Chrome stalls on a screen with no output).
until grep -qx connected /sys/class/drm/card*-*/status 2>/dev/null; do sleep 3; done

# Sound to the TV: the first HDMI / DisplayPort audio output with something plugged in.
sleep 2
HDMI=$(pactl list cards 2>/dev/null | grep -oE '^\s*output:hdmi-stereo[a-z0-9-]*: .*available: yes' | head -1 | sed 's/^\s*//; s/: .*//')
CARD=$(pactl list short cards 2>/dev/null | awk 'NR==1 {print $2}')
[ -n "$HDMI" ] && [ -n "$CARD" ] && pactl set-card-profile "$CARD" "$HDMI" && echo "audio: $HDMI"

# Wait for Apache + MariaDB after a power cut.
i=0
until curl -fs -o /dev/null http://localhost/login.php || [ $i -ge 60 ]; do i=$((i + 1)); sleep 2; done

# Monitor key, written by the service's ExecStartPre (as root: this user cannot read the DB settings).
KEY=$(cat /run/eliminacode-kiosk/key 2>/dev/null)

# Chrome left "crashed" after a power cut: no restore bubble.
PREFS="$HOME/.kiosk/Default/Preferences"
[ -f "$PREFS" ] && sed -i 's/"exit_type":"[A-Za-z]*"/"exit_type":"Normal"/; s/"exited_cleanly":false/"exited_cleanly":true/' "$PREFS"

exec google-chrome --kiosk --ozone-platform=wayland \
    --user-data-dir="$HOME/.kiosk" --no-first-run --no-default-browser-check \
    --noerrdialogs --disable-infobars --disable-session-crashed-bubble \
    --disable-features=Translate,MediaRouter --password-store=basic \
    --autoplay-policy=no-user-gesture-required --check-for-update-interval=31536000 \
    "http://localhost/queue/monitor.php?k=$KEY"
