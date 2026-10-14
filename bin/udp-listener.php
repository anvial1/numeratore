<?php
/**
 * UDP commands from another server: the same next / recall / call as the
 * operator links (api/queue.php ?ok=), one datagram per command.
 *
 *   <op_key> next <service>              → calls the next number
 *   <op_key> recall <service>            → calls the current number again
 *   <op_key> call <service> <number>     → calls that number
 *   <op_key> state                       → numbers being served, waiting
 *   <op_key> reset [<service>]           → today's queue cleared, numbering from 1 (no service = all)
 *   or JSON: {"ok":"<op_key>","action":"next","service_id":1[,"number":15]}
 *
 * <service> = the service id (1) or its letter (A). Words may be separated by
 * spaces, ";" or ",". The reply goes back to the sender as JSON, e.g.
 * {"success":true,"called":"A015"}. op_key = settings.queue.op_key
 * (Amministrazione › Eliminacode › Comandi operatore per un altro server).
 *
 * Runs as a service: bin/eliminacode-udp.service (port 9999 by default, or the first argument).
 */
if (PHP_SAPI !== 'cli') exit;
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/queue.php';

$port = (int) ($argv[1] ?? QUEUE_UDP_PORT);
$sock = stream_socket_server("udp://0.0.0.0:$port", $errno, $errstr, STREAM_SERVER_BIND);
if (!$sock) {
    fwrite(STDERR, "udp-listener: cannot bind port $port: $errstr\n");
    exit(1);
}
$pdo = getDBConnection();
$pdo->exec("SET SESSION wait_timeout = 31536000"); // one connection for the whole life of the service
echo date('Y-m-d H:i:s') . " udp-listener on port $port\n";

/** The key is read again for every command: a new key from the admin works at once. */
$keyOk = static function (string $key) use ($pdo): bool {
    $st = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'queue'");
    $st->execute();
    $op = (string) ((json_decode((string) $st->fetchColumn(), true) ?: [])['op_key'] ?? '');
    return $op !== '' && $key !== '' && hash_equals($op, $key);
};

/** Service id or letter → id (0 = unknown). */
$serviceId = static function (string $s): int {
    if (ctype_digit($s)) return (int) $s;
    foreach (queueServices(false) as $svc) {
        if (strcasecmp((string) $svc['letter'], $s) === 0) return (int) $svc['id'];
    }
    return 0;
};

while (true) {
    $msg = stream_socket_recvfrom($sock, 2048, 0, $peer);
    if ($msg === false || $peer === null) continue;
    $msg = trim($msg);
    $action = $svc = '';
    try {
        if ($msg !== '' && $msg[0] === '{') {
            $in = json_decode($msg, true) ?: [];
            [$key, $action, $svc, $num] = [(string) ($in['ok'] ?? ''), (string) ($in['action'] ?? ''), (string) ($in['service_id'] ?? ''), (int) ($in['number'] ?? 0)];
        } else {
            $w = preg_split('/[\s;,]+/', $msg, -1, PREG_SPLIT_NO_EMPTY);
            [$key, $action, $svc, $num] = [$w[0] ?? '', strtolower($w[1] ?? ''), $w[2] ?? '', (int) ($w[3] ?? 0)];
        }
        if (!$keyOk($key)) {
            $out = ['success' => false, 'message' => 'Unauthorized'];
        } elseif ($action === 'state') {
            $out = ['success' => true] + queueState();
        } elseif ($action === 'reset') {
            $all = $svc === '' || in_array(strtolower($svc), ['0', 'all', 'tutti'], true);
            $sid = $all ? null : $serviceId($svc);
            if (!$all && !queueService($sid)) { // an unknown service never resets them all
                $out = ['success' => false, 'message' => 'Servizio non trovato'];
            } else {
                queueReset($sid);
                logActivity('queue_reset', 'queue_service', $sid, ['from' => 'udp ' . preg_replace('/:\d+$/', '', $peer)]);
                $out = ['success' => true, 'reset' => $sid ?? 'all'];
            }
        } elseif (in_array($action, ['next', 'recall', 'call'], true)) {
            $sid = $serviceId($svc);
            $t = match ($action) {
                'recall' => queueRecall($sid, null),
                'call'   => queueCall($sid, max(1, $num), null),
                default  => queueCall($sid, 'next', null),
            };
            $out = $t ? ['success' => true, 'called' => $t['label']]
                      : ['success' => false, 'message' => $action === 'next' ? 'Nessuno in attesa' : ($action === 'recall' ? 'Nessun numero da richiamare' : 'Numero non trovato tra i biglietti di oggi')];
        } else {
            $out = ['success' => false, 'message' => 'Unknown action'];
        }
    } catch (Throwable $e) {
        error_log('udp-listener: ' . $e->getMessage());
        $out = ['success' => false, 'message' => 'Errore del server'];
        if ($e instanceof PDOException) { // lost DB connection: systemd starts the service again
            stream_socket_sendto($sock, json_encode($out), 0, $peer);
            exit(1);
        }
    }
    $peerIp = preg_replace('/:\d+$/', '', $peer);
    echo date('Y-m-d H:i:s') . " $peerIp $action $svc → " . ($out['called'] ?? $out['message'] ?? (isset($out['reset']) ? 'reset' : 'ok')) . "\n";
    stream_socket_sendto($sock, json_encode($out, JSON_UNESCAPED_UNICODE), 0, $peer);
}
