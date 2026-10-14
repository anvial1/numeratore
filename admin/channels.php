<?php
/**
 * Channel picker of the monitor tiles (admin/index.php): ?type=tv → Italian online
 * TV channels (iptv-org), ?type=radio → Italian radio stations (radio-browser).
 * Loaded on demand so the admin page never waits for the lists.
 */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/queue.php';
requireRole(['admin']);

$type = ($_GET['type'] ?? '') === 'radio' ? 'radio' : 'tv';
jsonResponse(['success' => true, 'type' => $type, 'channels' => $type === 'radio' ? queueRadioStations() : queueTvChannels()]);
