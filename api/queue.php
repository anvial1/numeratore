<?php
/**
 * Eliminacode API (includes/queue.php).
 *
 * Screens without a login (totem, monitor) send ?k=<settings.queue.key>;
 * calling numbers needs a logged-in staff user (QUEUE_OPERATOR_ROLES).
 *
 * GET  ?action=state[&k=]              → numbers being served, waiting, last called
 * GET  ?action=feed&k=                 → weather, news headlines, product tiles
 * POST {action: take, k, service_id}   → new ticket (printed on the network printer
 *                                        if configured; printed:false = print in the browser)
 * POST {action: next|recall|call, service_id[, number]}  (staff)
 * GET|POST action=next|recall|call&service_id=&number=&ok=<settings.queue.op_key>
 *                                      → the same without a login, for another server
 * GET|POST action=reset[&service_id=]&ok=  → today's queue of one service (or all) cleared:
 *                                        the next ticket is number 1 again (operator key only)
 */

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/queue.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');

$input  = $_SERVER['REQUEST_METHOD'] === 'POST' ? (json_decode(file_get_contents('php://input'), true) ?: []) : $_GET;
$action = (string) ($input['action'] ?? 'state');
$key    = (string) ($input['k'] ?? $_GET['k'] ?? '');
$screen = queueKeyOk($key);
$staff  = isLoggedIn() && hasRole(QUEUE_OPERATOR_ROLES);
$remote = queueOpKeyOk((string) ($input['ok'] ?? $_GET['ok'] ?? ''));

try {
    switch ($action) {
        case 'state':
            if (!$screen && !$staff && !$remote) {
                jsonResponse(['success' => false, 'message' => 'Unauthorized'], 401);
            }
            jsonResponse(['success' => true, 'now' => date('H:i')] + queueState());

        case 'feed':
            if (!$screen && !$staff) {
                jsonResponse(['success' => false, 'message' => 'Unauthorized'], 401);
            }
            $slides = array_map(static fn($s) => [
                'title'    => (string) $s['title'],
                'subtitle' => (string) ($s['subtitle'] ?? ''),
                'price'    => (string) ($s['price'] ?? ''),
                'image'    => (string) ($s['image_path'] ?? ''),
                'video'    => (string) ($s['video_path'] ?? ''),
                'audio'    => (bool) ($s['video_audio'] ?? false) || ($s['stream_type'] ?? '') === 'radio',
                'live'     => $s['stream_url'] ? (string) $s['stream_type'] : '',
                'stream'   => (string) ($s['stream_url'] ?? ''),
                'logo'     => (string) ($s['stream_logo'] ?? ''),
                'live_secs' => (int) ($s['stream_seconds'] ?? 60),
                'bg'       => ($s['stream_type'] ?? '') === 'radio' && !empty($s['stream_background']),
            ], queueSlides());
            jsonResponse([
                'success' => true,
                'weather' => queueWeather(),
                'news'    => queueNews(),
                'slides'  => $slides,
                'seconds' => max(3, (int) queueSettings()['slide_seconds']),
            ]);

        case 'take':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !$screen) {
                jsonResponse(['success' => false, 'message' => 'Unauthorized'], 401);
            }
            $ticket = queueTake((int) ($input['service_id'] ?? 0));
            if (!$ticket) {
                jsonResponse(['success' => false, 'message' => 'Servizio non disponibile']);
            }
            $print = queuePrinter() ? queuePrintTicket($ticket) : ['ok' => false, 'error' => 'printer_not_configured'];
            if (!$print['ok'] && $print['error'] !== 'printer_not_configured') {
                error_log('queue ticket print failed: ' . $print['error']);
            }
            jsonResponse(['success' => true, 'ticket' => $ticket, 'printed' => $print['ok'], 'print_error' => $print['ok'] ? null : $print['error']]);

        case 'next':
        case 'recall':
        case 'call':
            if (!$remote && ($_SERVER['REQUEST_METHOD'] !== 'POST' || !$staff)) {
                jsonResponse(['success' => false, 'message' => 'Unauthorized'], 401);
            }
            $sid = (int) ($input['service_id'] ?? 0);
            $uid = $staff ? (int) $_SESSION['user_id'] : null;
            if ($action === 'recall') {
                $t = queueRecall($sid, $uid);
                $msg = 'Nessun numero da richiamare';
            } elseif ($action === 'call') {
                $t = queueCall($sid, max(1, (int) ($input['number'] ?? 0)), $uid);
                $msg = 'Numero non trovato tra i biglietti di oggi';
            } else {
                $t = queueCall($sid, 'next', $uid);
                $msg = 'Nessuno in attesa';
            }
            if (!$t) {
                jsonResponse(['success' => false, 'message' => $msg] + queueState());
            }
            jsonResponse(['success' => true, 'called' => $t['label']] + queueState());

        case 'reset':
            if (!$remote) {
                jsonResponse(['success' => false, 'message' => 'Unauthorized'], 401);
            }
            $sid = (int) ($input['service_id'] ?? 0) ?: null;
            if ($sid && !queueService($sid)) {
                jsonResponse(['success' => false, 'message' => 'Servizio non trovato'] + queueState());
            }
            queueReset($sid);
            logActivity('queue_reset', 'queue_service', $sid, ['from' => 'remote']);
            jsonResponse(['success' => true, 'reset' => $sid ?? 'all'] + queueState());

        default:
            jsonResponse(['success' => false, 'message' => 'Unknown action'], 400);
    }
} catch (Throwable $e) {
    error_log('api/queue.php: ' . $e->getMessage());
    jsonResponse(['success' => false, 'message' => 'Errore del server'], 500);
}
