#!/bin/sh
# Eliminacode: one-time setup of the IP cameras (go2rtc) on the LAN server, as root.
#   sh /var/www/html/eliminacode/bin/install-go2rtc.sh
set -e
APP=/var/www/html/eliminacode
VER=1.9.9

if ! /usr/local/bin/go2rtc -version 2>/dev/null | grep -q "$VER"; then
    curl -fsSL -o /usr/local/bin/go2rtc.new "https://github.com/AlexxIT/go2rtc/releases/download/v$VER/go2rtc_linux_amd64"
    chmod 755 /usr/local/bin/go2rtc.new
    mv /usr/local/bin/go2rtc.new /usr/local/bin/go2rtc
fi
mkdir -p /etc/go2rtc
cp "$APP/bin/go2rtc.yaml" /etc/go2rtc/go2rtc.yaml

a2enmod -q proxy proxy_http proxy_wstunnel
cp "$APP/bin/apache-go2rtc.conf" /etc/apache2/conf-available/eliminacode-go2rtc.conf
a2enconf -q eliminacode-go2rtc
apache2ctl configtest
systemctl reload apache2

cp "$APP/bin/go2rtc.service" /etc/systemd/system/eliminacode-go2rtc.service
systemctl daemon-reload
systemctl enable --now eliminacode-go2rtc
systemctl restart eliminacode-go2rtc
