<?php
/**
 * Eliminacode (take-a-number queue).
 *
 * The totem (queue/totem.php) takes a ticket for a service, the operator page
 * (queue/operator.php) calls the next one and the monitor (queue/monitor.php)
 * shows who is being served, product tiles, the weather and the news.
 * Numbers restart from 1 every day per service. Dates and times come from PHP
 * so they follow the app's timezone, not the database server's.
 *
 * Settings: settings.queue (see queueSettings()). The totem and the monitor are
 * unattended screens without a login: they carry settings.queue.key in the URL.
 */

require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/ThermalPrinter.php';

const QUEUE_NEWS_DEFAULT = 'https://www.ansa.it/sito/notizie/topnews/topnews_rss.xml';
/** UDP port of bin/udp-listener.php (bin/eliminacode-udp.service). */
const QUEUE_UDP_PORT = 9999;
/** Staff roles that may call numbers from the operator page. */
const QUEUE_OPERATOR_ROLES = ['admin', 'operator'];

/** settings.queue with defaults; creates the screen key on first use. */
function queueSettings(): array
{
    static $memo = null;
    if ($memo !== null) {
        return $memo;
    }
    $cfg = (array) getSetting('queue', []);
    $cfg += [
        'key'           => '',
        'brand'         => '',
        'totem_title'   => 'Prendi il tuo numero',
        'ticket_header' => '',
        'ticket_footer' => "Grazie per l'attesa",
        'weather_city'  => 'Napoli',
        'weather_lat'   => 40.8518,
        'weather_lon'   => 14.2681,
        'news_url'      => QUEUE_NEWS_DEFAULT,
        'slide_seconds' => 8,
        'printer_host'  => '',
        'printer_port'  => 9100,
        'printer_width' => 48,
    ];
    $cfg += ['op_key' => ''];
    if ($cfg['key'] === '' || $cfg['op_key'] === '') {
        if ($cfg['key'] === '') $cfg['key'] = bin2hex(random_bytes(12));
        if ($cfg['op_key'] === '') $cfg['op_key'] = bin2hex(random_bytes(16));
        setSetting('queue', $cfg);
    }
    return $memo = $cfg;
}

/** Does the URL key open the totem / monitor? */
function queueKeyOk(?string $key): bool
{
    $cfg = queueSettings();
    return $key !== null && $key !== '' && hash_equals((string) $cfg['key'], $key);
}

/** Does the operator key (?ok=, for another server) allow calling numbers without a login? */
function queueOpKeyOk(?string $key): bool
{
    $cfg = queueSettings();
    return $key !== null && $key !== '' && hash_equals((string) $cfg['op_key'], $key);
}

/** "A" + 7 → "A007" (a service without a letter shows just the number). */
function queueLabel(string $letter, int $number): string
{
    return $letter . str_pad((string) $number, 3, '0', STR_PAD_LEFT);
}

function queueServices(bool $activeOnly = true): array
{
    $sql = "SELECT * FROM queue_services" . ($activeOnly ? " WHERE active = 1" : "") . " ORDER BY sort_order, id";
    return getDBConnection()->query($sql)->fetchAll();
}

function queueService(int $id): ?array
{
    $st = getDBConnection()->prepare("SELECT * FROM queue_services WHERE id = ?");
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

/**
 * Take the next number of a service. Returns the ticket with its label and how
 * many people are ahead, or null when the service does not exist / is off.
 */
function queueTake(int $serviceId): ?array
{
    $pdo   = getDBConnection();
    $today = date('Y-m-d');
    $pdo->beginTransaction();
    try {
        // Locking the service row serialises numbering for that service.
        $st = $pdo->prepare("SELECT * FROM queue_services WHERE id = ? AND active = 1 FOR UPDATE");
        $st->execute([$serviceId]);
        $svc = $st->fetch();
        if (!$svc) {
            $pdo->rollBack();
            return null;
        }
        $st = $pdo->prepare("SELECT COALESCE(MAX(number), 0) + 1 FROM queue_tickets WHERE service_id = ? AND ticket_date = ?");
        $st->execute([$serviceId, $today]);
        $number = (int) $st->fetchColumn();
        $now = date('Y-m-d H:i:s');
        $pdo->prepare("INSERT INTO queue_tickets (service_id, ticket_date, number, created_at) VALUES (?, ?, ?, ?)")
            ->execute([$serviceId, $today, $number, $now]);
        $id = (int) $pdo->lastInsertId();
        // Daily count per service for the statistics; it survives a manual reset of the queue.
        $pdo->prepare("INSERT INTO queue_daily_counts (count_date, service_id, tickets) VALUES (?, ?, 1)
                       ON DUPLICATE KEY UPDATE tickets = tickets + 1")
            ->execute([$today, $serviceId]);
        $st = $pdo->prepare("SELECT COUNT(*) FROM queue_tickets WHERE service_id = ? AND ticket_date = ? AND status = 'waiting' AND number < ?");
        $st->execute([$serviceId, $today, $number]);
        $ahead = (int) $st->fetchColumn();
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return [
        'id'      => $id,
        'number'  => $number,
        'label'   => queueLabel((string) $svc['letter'], $number),
        'service' => (string) $svc['name'],
        'color'   => (string) $svc['color'],
        'ahead'   => $ahead,
        'time'    => date('d/m/Y H:i', strtotime($now)),
    ];
}

/**
 * Call a ticket of today's queue: the one being served is closed and $pick
 * ('next' = the first waiting one, or a ticket number) becomes the current one.
 * Returns the called ticket or null when nobody is waiting / no such number.
 */
function queueCall(int $serviceId, $pick, ?int $userId): ?array
{
    $pdo   = getDBConnection();
    $today = date('Y-m-d');
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare("SELECT * FROM queue_services WHERE id = ? FOR UPDATE");
        $st->execute([$serviceId]);
        $svc = $st->fetch();
        if (!$svc) {
            $pdo->rollBack();
            return null;
        }
        if ($pick === 'next') {
            $st = $pdo->prepare("SELECT id, number FROM queue_tickets WHERE service_id = ? AND ticket_date = ? AND status = 'waiting' ORDER BY number LIMIT 1");
            $st->execute([$serviceId, $today]);
        } else {
            $st = $pdo->prepare("SELECT id, number FROM queue_tickets WHERE service_id = ? AND ticket_date = ? AND number = ?");
            $st->execute([$serviceId, $today, (int) $pick]);
        }
        $t = $st->fetch();
        if (!$t) {
            $pdo->rollBack();
            return null;
        }
        $pdo->prepare("UPDATE queue_tickets SET status = 'served' WHERE service_id = ? AND ticket_date = ? AND status = 'called' AND id <> ?")
            ->execute([$serviceId, $today, $t['id']]);
        $pdo->prepare("UPDATE queue_tickets SET status = 'called', called_at = ?, call_count = call_count + 1, called_by = ? WHERE id = ?")
            ->execute([date('Y-m-d H:i:s'), $userId, $t['id']]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return ['id' => (int) $t['id'], 'label' => queueLabel((string) $svc['letter'], (int) $t['number'])];
}

/** Call the current number again (the monitor chimes and flashes it again). */
function queueRecall(int $serviceId, ?int $userId): ?array
{
    $pdo = getDBConnection();
    $st  = $pdo->prepare("SELECT t.id, t.number, s.letter FROM queue_tickets t JOIN queue_services s ON s.id = t.service_id
                          WHERE t.service_id = ? AND t.ticket_date = ? AND t.status = 'called' ORDER BY t.called_at DESC LIMIT 1");
    $st->execute([$serviceId, date('Y-m-d')]);
    $t = $st->fetch();
    if (!$t) {
        return null;
    }
    $pdo->prepare("UPDATE queue_tickets SET called_at = ?, call_count = call_count + 1, called_by = ? WHERE id = ?")
        ->execute([date('Y-m-d H:i:s'), $userId, $t['id']]);
    return ['id' => (int) $t['id'], 'label' => queueLabel((string) $t['letter'], (int) $t['number'])];
}

/**
 * One step back: calls the number before the current one (the highest of today's tickets below it)
 * and puts the current one back in the queue, so "next" calls it again. Null when the service has
 * no number being served or nothing before it.
 */
function queuePrev(int $serviceId, ?int $userId): ?array
{
    $pdo   = getDBConnection();
    $today = date('Y-m-d');
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare("SELECT letter FROM queue_services WHERE id = ? FOR UPDATE");
        $st->execute([$serviceId]);
        $letter = $st->fetchColumn();
        $st = $pdo->prepare("SELECT id, number FROM queue_tickets WHERE service_id = ? AND ticket_date = ? AND status = 'called' ORDER BY called_at DESC LIMIT 1");
        $st->execute([$serviceId, $today]);
        $cur = $st->fetch();
        if ($letter === false || !$cur) {
            $pdo->rollBack();
            return null;
        }
        $st = $pdo->prepare("SELECT id, number FROM queue_tickets WHERE service_id = ? AND ticket_date = ? AND number < ? ORDER BY number DESC LIMIT 1");
        $st->execute([$serviceId, $today, $cur['number']]);
        $t = $st->fetch();
        if (!$t) {
            $pdo->rollBack();
            return null;
        }
        $pdo->prepare("UPDATE queue_tickets SET status = 'waiting' WHERE service_id = ? AND ticket_date = ? AND status = 'called'")
            ->execute([$serviceId, $today]);
        $pdo->prepare("UPDATE queue_tickets SET status = 'called', called_at = ?, call_count = call_count + 1, called_by = ? WHERE id = ?")
            ->execute([date('Y-m-d H:i:s'), $userId, $t['id']]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return ['id' => (int) $t['id'], 'label' => queueLabel((string) $letter, (int) $t['number'])];
}

/** Clear today's queue of one service (or all): the next ticket is number 1 again. */
function queueReset(?int $serviceId): void
{
    $pdo = getDBConnection();
    if ($serviceId) {
        $pdo->prepare("DELETE FROM queue_tickets WHERE ticket_date = ? AND service_id = ?")->execute([date('Y-m-d'), $serviceId]);
    } else {
        $pdo->prepare("DELETE FROM queue_tickets WHERE ticket_date = ?")->execute([date('Y-m-d')]);
    }
}

/**
 * Midnight reset (bin/midnight-reset.php, cron at 00:00): tickets of the
 * previous days still waiting or being called are closed, so every counter
 * starts the new day empty and from 1. Kept as 'served' for the history.
 * Returns how many tickets were closed.
 */
function queueCloseDay(): int
{
    $st = getDBConnection()->prepare("UPDATE queue_tickets SET status = 'served' WHERE ticket_date < ? AND status IN ('waiting', 'called')");
    $st->execute([date('Y-m-d')]);
    return $st->rowCount();
}

/**
 * Live state for the monitor and the operator page: per active service the
 * number being served, how many wait and the last number taken; plus the last
 * numbers called. `stamp` changes on every call/recall so screens can chime.
 */
function queueState(): array
{
    $pdo   = getDBConnection();
    $today = date('Y-m-d');
    $out   = ['services' => [], 'recent' => [], 'stamp' => ''];
    $stamp = [];

    $st = $pdo->prepare("
        SELECT s.id, s.letter, s.name, s.color,
               (SELECT COUNT(*) FROM queue_tickets w WHERE w.service_id = s.id AND w.ticket_date = ? AND w.status = 'waiting') AS waiting,
               (SELECT MAX(number) FROM queue_tickets m WHERE m.service_id = s.id AND m.ticket_date = ?) AS last_taken
        FROM queue_services s WHERE s.active = 1 ORDER BY s.sort_order, s.id");
    $st->execute([$today, $today]);
    $cur = $pdo->prepare("SELECT id, number, called_at, call_count FROM queue_tickets
                          WHERE service_id = ? AND ticket_date = ? AND status = 'called' ORDER BY called_at DESC LIMIT 1");
    foreach ($st->fetchAll() as $s) {
        $cur->execute([$s['id'], $today]);
        $c = $cur->fetch();
        $out['services'][] = [
            'id'         => (int) $s['id'],
            'letter'     => (string) $s['letter'],
            'name'       => (string) $s['name'],
            'color'      => (string) $s['color'],
            'waiting'    => (int) $s['waiting'],
            'last_taken' => $s['last_taken'] !== null ? queueLabel((string) $s['letter'], (int) $s['last_taken']) : null,
            'current'    => $c ? queueLabel((string) $s['letter'], (int) $c['number']) : null,
            'called_at'  => $c ? date('H:i', strtotime((string) $c['called_at'])) : null,
            'stamp'      => $c ? $c['id'] . ':' . $c['call_count'] : '',
        ];
        if ($c) {
            $stamp[] = $c['id'] . ':' . $c['call_count'];
        }
    }

    $st = $pdo->prepare("SELECT t.number, t.called_at, s.letter, s.name, s.color FROM queue_tickets t
                         JOIN queue_services s ON s.id = t.service_id
                         WHERE t.ticket_date = ? AND t.called_at IS NOT NULL ORDER BY t.called_at DESC, t.id DESC LIMIT 8");
    $st->execute([$today]);
    foreach ($st->fetchAll() as $r) {
        $out['recent'][] = [
            'label' => queueLabel((string) $r['letter'], (int) $r['number']),
            'name'  => (string) $r['name'],
            'color' => (string) $r['color'],
            'time'  => date('H:i', strtotime((string) $r['called_at'])),
        ];
    }
    $out['stamp'] = implode(',', $stamp);
    return $out;
}

/** The totem's ESC/POS network printer, or null when none is configured. */
function queuePrinter(): ?ThermalPrinter
{
    $cfg = queueSettings();
    if (trim((string) $cfg['printer_host']) === '') {
        return null;
    }
    return new ThermalPrinter([
        'host'    => trim((string) $cfg['printer_host']),
        'port'    => (int) $cfg['printer_port'] ?: 9100,
        'width'   => (int) $cfg['printer_width'] ?: 48,
        'timeout' => 4,
    ]);
}

/** Print a taken ticket on the totem printer. Returns ThermalPrinter's result. */
function queuePrintTicket(array $ticket): array
{
    $p = queuePrinter();
    if (!$p) {
        return ['ok' => false, 'error' => 'printer_not_configured'];
    }
    $cfg = queueSettings();
    $res = $p->printQueueTicket([
        'brand'   => trim((string) $cfg['ticket_header']) ?: appName(),
        'service' => $ticket['service'],
        'number'  => $ticket['label'],
        'ahead'   => $ticket['ahead'] > 0 ? 'Persone prima di te: ' . $ticket['ahead'] : '',
        'time'    => $ticket['time'],
        'footer'  => (string) $cfg['ticket_footer'],
    ]);
    if ($res['ok']) {
        getDBConnection()->prepare("UPDATE queue_tickets SET printed = 1 WHERE id = ?")->execute([$ticket['id']]);
    }
    return $res;
}

/** Product tiles shown on the monitor. */
function queueSlides(bool $activeOnly = true): array
{
    $sql = "SELECT * FROM queue_slides" . ($activeOnly ? " WHERE active = 1" : "") . " ORDER BY sort_order, id";
    return getDBConnection()->query($sql)->fetchAll();
}

/** Largest tile video (also raised in .htaccess: upload_max_filesize / post_max_size). */
const QUEUE_VIDEO_MAX_MB = 200;

/** Save an uploaded tile photo to assets/uploads/queue; web path or null. */
function queueSaveImage(string $field): ?string
{
    return queueSaveUpload($field, ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'], 8, 'slide');
}

/** Save an uploaded tile video (MP4 / WebM / MOV); web path or null. */
function queueSaveVideo(string $field): ?string
{
    return queueSaveUpload($field, ['video/mp4' => 'mp4', 'video/x-m4v' => 'mp4', 'video/webm' => 'webm', 'video/quicktime' => 'mov'], QUEUE_VIDEO_MAX_MB, 'video');
}

function queueSaveUpload(string $field, array $extMap, int $maxMb, string $prefix): ?string
{
    if (empty($_FILES[$field]) || ($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return null;
    }
    $f = $_FILES[$field];
    if ($f['size'] > $maxMb * 1024 * 1024) {
        return null;
    }
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime  = finfo_file($finfo, $f['tmp_name']);
    finfo_close($finfo);
    if (!isset($extMap[$mime])) {
        return null;
    }
    $dir = __DIR__ . '/../assets/uploads/queue';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $name = $prefix . '_' . date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.' . $extMap[$mime];
    if (!move_uploaded_file($f['tmp_name'], $dir . '/' . $name)) {
        return null;
    }
    return '/assets/uploads/queue/' . $name;
}

/** Delete an uploaded tile file (videos are big: no leftovers on the disk). */
function queueDeleteUpload(?string $path): void
{
    if ($path && preg_match('#^/assets/uploads/queue/[\w.-]+$#', $path)) {
        @unlink(__DIR__ . '/..' . $path);
    }
}

/** A live TV / radio address for a tile: http(s) only; null when not valid. */
function queueStreamUrl(?string $url): ?string
{
    $url = trim((string) $url);
    return $url !== '' && strlen($url) <= 500 && preg_match('#^https?://#i', $url) && filter_var($url, FILTER_VALIDATE_URL) ? $url : null;
}

/**
 * Italian online TV channels from the public iptv-org list (HLS .m3u8), for the tile
 * channel picker. [{name, url, logo, group}], cached 1 day.
 */
function queueTvChannels(): array
{
    return queueCached('tv:it', 86400, static function () {
        $m3u = queueHttpGet('https://iptv-org.github.io/iptv/countries/it.m3u');
        if ($m3u === null) {
            return null;
        }
        $out = [];
        $info = null;
        foreach (preg_split('/\R/', $m3u) as $line) {
            $line = trim($line);
            if (str_starts_with($line, '#EXTINF')) {
                preg_match('/tvg-logo="([^"]*)"/', $line, $logo);
                preg_match('/group-title="([^"]*)"/', $line, $group);
                $info = ['name' => trim((string) substr($line, strrpos($line, ',') + 1)), 'logo' => $logo[1] ?? '', 'group' => $group[1] ?? ''];
            } elseif ($info && ($url = queueStreamUrl($line))) {
                $out[] = $info + ['url' => $url];
                $info = null;
            }
        }
        usort($out, static fn($a, $b) => strnatcasecmp($a['name'], $b['name']));
        return $out ?: null;
    }) ?? [];
}

/** Italian radio stations from radio-browser.info, most voted first. [{name, url, logo, group}], cached 1 day. */
function queueRadioStations(): array
{
    return queueCached('radio:it', 86400, static function () {
        foreach (['de1', 'fi1', 'nl1'] as $host) {
            $j = json_decode((string) queueHttpGet("https://$host.api.radio-browser.info/json/stations/bycountrycodeexact/IT?order=votes&reverse=true&limit=400&hidebroken=true"), true);
            if (is_array($j) && $j) {
                break;
            }
        }
        $out = [];
        foreach ((array) ($j ?? []) as $s) {
            $url = queueStreamUrl($s['url_resolved'] ?? $s['url'] ?? '');
            $name = trim((string) ($s['name'] ?? ''));
            if ($url && $name !== '') {
                $out[$name] ??= ['name' => $name, 'url' => $url, 'logo' => (string) (queueStreamUrl($s['favicon'] ?? '') ?? ''), 'group' => (string) ($s['codec'] ?? '')];
            }
        }
        return array_values($out) ?: null;
    }) ?? [];
}

/*
 * IP cameras ("Telecamera" tiles, LAN server only): go2rtc on 127.0.0.1:1984 (bin/install-go2rtc.sh)
 * turns the camera's RTSP / ONVIF stream into MSE for the browser. The camera address (with its
 * password) stays on the server: the monitor only gets a stream name, unguessable without the
 * monitor key, and Apache lets through only those names (bin/apache-go2rtc.conf).
 */
const QUEUE_GO2RTC = 'http://127.0.0.1:1984';

/** A camera address for a tile: rtsp(s):// or onvif://; null when not valid. */
function queueCameraUrl(?string $url): ?string
{
    $url = trim((string) $url);
    return $url !== '' && strlen($url) <= 500 && preg_match('#^(rtsps?|onvif)://[^\s\'"]+$#i', $url) ? $url : null;
}

/** go2rtc stream name of a camera tile ('c' + 16 hex), or of an admin preview ('t' + 16 hex). */
function queueCameraName(int|string $id, string $prefix = 'c'): string
{
    return $prefix . substr(hash_hmac('sha256', "camera:$id", (string) queueSettings()['key']), 0, 16);
}

/** Call the local go2rtc API; decoded JSON (or [] for an empty answer), null when go2rtc is not there. */
function queueGo2rtc(string $method, string $path, int $timeout = 4): ?array
{
    $ch = curl_init(QUEUE_GO2RTC . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_CONNECTTIMEOUT => 1,
        CURLOPT_TIMEOUT        => $timeout,
    ]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($body === false || $code < 200 || $code >= 300) {
        return null;
    }
    return json_decode((string) $body, true) ?: [];
}

/** True when go2rtc runs on this server (the LAN one): only there the "Telecamera" tile works. */
function queueCamerasAvailable(): bool
{
    static $ok = null;
    return $ok ??= queueGo2rtc('GET', '/api', 1) !== null;
}

/** Register a stream in go2rtc (replacing any older source of that name). */
function queueCameraPut(string $name, string $url): bool
{
    queueGo2rtc('DELETE', '/api/streams?src=' . rawurlencode($name));
    return queueGo2rtc('PUT', '/api/streams?name=' . rawurlencode($name) . '&src=' . rawurlencode($url)) !== null;
}

/**
 * Load the active camera tiles into go2rtc and drop the others (old tiles, admin previews).
 * Number of cameras loaded, or null when go2rtc is not reachable.
 */
function queueCamerasSync(): ?int
{
    $have = queueGo2rtc('GET', '/api/streams');
    if ($have === null) {
        return null;
    }
    $want = [];
    foreach (queueSlides() as $s) {
        if ($s['stream_type'] === 'camera' && queueCameraUrl($s['stream_url'])) {
            $want[queueCameraName($s['id'])] = $s['stream_url'];
        }
    }
    foreach (array_keys($have) as $name) {
        if (!isset($want[$name])) {
            queueGo2rtc('DELETE', '/api/streams?src=' . rawurlencode((string) $name));
        }
    }
    foreach ($want as $name => $url) {
        queueCameraPut($name, $url);
    }
    return count($want);
}

/** GET a URL (short timeouts: the monitor must never hang on a slow feed). */
function queueHttpGet(string $url): ?string
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 3,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (Eliminacode display)',
    ]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ($body !== false && $code >= 200 && $code < 300) ? (string) $body : null;
}

/**
 * Cached fetch: returns the fresh value for $ttl seconds; when the source fails
 * the last good value is kept, so the screen never goes blank.
 */
function queueCached(string $name, int $ttl, callable $fetch)
{
    $file = sys_get_temp_dir() . '/eliminacode_queue_' . md5($name) . '.json';
    $old  = is_file($file) ? json_decode((string) @file_get_contents($file), true) : null;
    if (is_array($old) && ($old['t'] ?? 0) > time() - $ttl) {
        return $old['v'];
    }
    try {
        $v = $fetch();
    } catch (Throwable $e) {
        $v = null;
    }
    if ($v !== null) {
        @file_put_contents($file, json_encode(['t' => time(), 'v' => $v], JSON_UNESCAPED_UNICODE));
        return $v;
    }
    return is_array($old) ? $old['v'] : null;
}

/** Current weather + next days from Open-Meteo (free, no key). Cached 15 min. */
function queueWeather(): ?array
{
    $cfg = queueSettings();
    $lat = (float) $cfg['weather_lat'];
    $lon = (float) $cfg['weather_lon'];
    return queueCached("weather:$lat,$lon", 900, static function () use ($lat, $lon, $cfg) {
        $url = 'https://api.open-meteo.com/v1/forecast?' . http_build_query([
            'latitude'      => $lat,
            'longitude'     => $lon,
            'current'       => 'temperature_2m,weather_code,wind_speed_10m,relative_humidity_2m',
            'daily'         => 'weather_code,temperature_2m_max,temperature_2m_min',
            'timezone'      => 'Europe/Rome',
            'forecast_days' => 4,
        ]);
        $j = json_decode((string) queueHttpGet($url), true);
        if (empty($j['current'])) {
            return null;
        }
        $days = [];
        foreach (($j['daily']['time'] ?? []) as $i => $d) {
            $days[] = [
                'date' => $d,
                'code' => (int) ($j['daily']['weather_code'][$i] ?? 0),
                'max'  => round((float) ($j['daily']['temperature_2m_max'][$i] ?? 0)),
                'min'  => round((float) ($j['daily']['temperature_2m_min'][$i] ?? 0)),
            ];
        }
        return [
            'city'     => (string) $cfg['weather_city'],
            'temp'     => round((float) $j['current']['temperature_2m']),
            'code'     => (int) $j['current']['weather_code'],
            'wind'     => round((float) ($j['current']['wind_speed_10m'] ?? 0)),
            'humidity' => (int) ($j['current']['relative_humidity_2m'] ?? 0),
            'days'     => $days,
        ];
    });
}

/** City name → [name, lat, lon] via Open-Meteo geocoding; null if not found. */
function queueGeocode(string $city): ?array
{
    $url = 'https://geocoding-api.open-meteo.com/v1/search?' . http_build_query([
        'name' => $city, 'count' => 1, 'language' => 'it', 'format' => 'json',
    ]);
    $j = json_decode((string) queueHttpGet($url), true);
    $r = $j['results'][0] ?? null;
    return $r ? ['name' => (string) $r['name'], 'lat' => (float) $r['latitude'], 'lon' => (float) $r['longitude']] : null;
}

/** Headlines from the configured RSS/Atom feed. Cached 10 min. */
function queueNews(): array
{
    $url = trim((string) queueSettings()['news_url']);
    if ($url === '') {
        return [];
    }
    return queueCached("news:$url", 600, static function () use ($url) {
        $body = queueHttpGet($url);
        if ($body === null) {
            return null;
        }
        $prev = libxml_use_internal_errors(true);
        $xml  = simplexml_load_string($body, 'SimpleXMLElement', LIBXML_NOCDATA);
        libxml_use_internal_errors($prev);
        if (!$xml) {
            return null;
        }
        $items = $xml->channel->item ?? $xml->entry ?? [];
        $out = [];
        foreach ($items as $it) {
            $title = trim(html_entity_decode(strip_tags((string) $it->title), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if ($title !== '') {
                $out[] = $title;
            }
            if (count($out) >= 20) {
                break;
            }
        }
        return $out ?: null;
    }) ?? [];
}
