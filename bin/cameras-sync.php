<?php
/**
 * Loads the "Telecamera" tiles into go2rtc (run by eliminacode-go2rtc.service at every start;
 * the admin page does the same after each change). CLI only.
 */
if (PHP_SAPI !== 'cli') {
    exit;
}
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/queue.php';

$n = queueCamerasSync();
echo $n === null ? "go2rtc not reachable\n" : "$n camera(s) loaded\n";
