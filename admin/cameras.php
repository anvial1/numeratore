<?php
/**
 * IP camera picker of the monitor tiles (admin/index.php), through go2rtc on this server
 * (LAN server only, bin/install-go2rtc.sh). POST action=
 *   discover                  → ONVIF devices in the network [{host}]
 *   profiles (host,user,pass) → the device's streams [{name, url}] (main / sub stream)
 *   preview (url)             → a temporary stream for the "Prova" button {stream: ws path}
 */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/queue.php';
requireRole(['admin']);

if (!queueCamerasAvailable()) {
    jsonResponse(['success' => false, 'message' => 'Le telecamere funzionano solo sul server locale (go2rtc non è installato qui).']);
}

switch ($_POST['action'] ?? '') {
    case 'discover':
        $found = queueGo2rtc('GET', '/api/onvif', 12);
        $hosts = [];
        foreach ((array) ($found['sources'] ?? []) as $src) {
            $host = (string) parse_url((string) ($src['url'] ?? ''), PHP_URL_HOST);
            $port = parse_url((string) ($src['url'] ?? ''), PHP_URL_PORT);
            if ($host !== '') $hosts[] = $host . ($port && $port != 80 ? ":$port" : '');
        }
        natsort($hosts);
        jsonResponse(['success' => true, 'devices' => array_map(static fn($h) => ['host' => $h], array_values(array_unique($hosts)))]);

    case 'profiles':
        $host = trim((string) ($_POST['host'] ?? ''));
        if (!preg_match('/^[\w.-]+(:\d{1,5})?$/', $host)) {
            jsonResponse(['success' => false, 'message' => 'Indirizzo della telecamera non valido.']);
        }
        $auth = rawurlencode((string) ($_POST['user'] ?? '')) . ':' . rawurlencode((string) ($_POST['pass'] ?? ''));
        $res = queueGo2rtc('GET', '/api/onvif?src=' . rawurlencode("onvif://$auth@$host"), 12);
        $profiles = [];
        foreach ((array) ($res['sources'] ?? []) as $src) {
            if (!str_contains((string) $src['url'], 'snapshot')) {
                $profiles[] = ['name' => (string) $src['name'], 'url' => (string) $src['url']];
            }
        }
        jsonResponse($profiles
            ? ['success' => true, 'profiles' => $profiles]
            : ['success' => false, 'message' => 'La telecamera ' . $host . ' non risponde o utente / password non sono giusti.']);

    case 'preview':
        $url = queueCameraUrl($_POST['url'] ?? '');
        if (!$url) {
            jsonResponse(['success' => false, 'message' => 'Indirizzo non valido: deve iniziare con rtsp:// o onvif://']);
        }
        $name = queueCameraName(bin2hex(random_bytes(8)), 't');
        jsonResponse(queueCameraPut($name, $url)
            ? ['success' => true, 'stream' => '/go2rtc/api/ws?src=' . $name]
            : ['success' => false, 'message' => 'go2rtc non accetta questo indirizzo.']);
}
jsonResponse(['success' => false, 'message' => 'Azione sconosciuta.'], 400);
