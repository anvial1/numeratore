#!/bin/sh
# Eliminacode: one-time setup of the monitor on the server's own TV output (LAN server only, as root).
#   sh /var/www/html/eliminacode/bin/install-kiosk.sh
set -e
APP=/var/www/html/eliminacode
export DEBIAN_FRONTEND=noninteractive

# Google Chrome (.deb, not the Ubuntu snap: snaps do not start well from a system service).
if ! command -v google-chrome >/dev/null; then
    curl -fsSL https://dl.google.com/linux/linux_signing_key.pub | gpg --dearmor -o /usr/share/keyrings/google-chrome.gpg
    echo "deb [arch=amd64 signed-by=/usr/share/keyrings/google-chrome.gpg] https://dl.google.com/linux/chrome/deb/ stable main" > /etc/apt/sources.list.d/google-chrome.list
fi
apt-get update -q
apt-get install -y -q cage google-chrome-stable pipewire pipewire-pulse wireplumber pulseaudio-utils fonts-dejavu fonts-noto-color-emoji

id monitor >/dev/null 2>&1 || useradd -m -s /usr/sbin/nologin monitor
usermod -aG video,render,audio,input monitor
loginctl enable-linger monitor   # its pipewire (sound) runs without a login

cp "$APP/bin/eliminacode-kiosk.service" /etc/systemd/system/
systemctl daemon-reload
systemctl enable --now eliminacode-kiosk
