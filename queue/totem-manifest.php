<?php
/**
 * Web app manifest of the totem: on a tablet, "Aggiungi a schermata Home" installs the totem
 * as an app that opens full screen, straight on the totem link with its key.
 */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/queue.php';

$key = (string) ($_GET['k'] ?? '');
if (!queueKeyOk($key)) {
    http_response_code(403);
    exit;
}
header('Content-Type: application/manifest+json');
echo json_encode([
    'name'             => 'Totem ' . appName(),
    'short_name'       => 'Totem',
    'start_url'        => '/queue/totem.php?k=' . rawurlencode($key),
    'scope'            => '/queue/',
    'display'          => 'fullscreen',
    'orientation'      => 'any',
    'background_color' => '#e0f2fe',
    'theme_color'      => '#e0f2fe',
    'icons'            => [
        ['src' => '/assets/brand/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png'],
        ['src' => '/assets/brand/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png'],
    ],
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
