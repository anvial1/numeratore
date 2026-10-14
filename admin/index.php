<?php
/**
 * Admin: Eliminacode (includes/queue.php) — the app's main admin page.
 * Links for the totem / monitor / operator page, the services customers pick
 * on the totem, the product tiles shown on the monitor, weather city, news
 * feed and the totem's network ticket printer.
 */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/queue.php';
requireRole(['admin']);

$pdo = getDBConnection();
$cfg = queueSettings();

const QUEUE_NEWS_PRESETS = [
    'https://www.ansa.it/sito/notizie/topnews/topnews_rss.xml'   => 'ANSA — Ultima ora',
    'https://www.ansa.it/campania/notizie/campania_rss.xml'      => 'ANSA — Campania',
    'https://www.ansa.it/sito/notizie/cronaca/cronaca_rss.xml'   => 'ANSA — Cronaca',
    'https://www.ansa.it/sito/notizie/sport/sport_rss.xml'       => 'ANSA — Sport',
    'https://www.ansa.it/sito/notizie/economia/economia_rss.xml' => 'ANSA — Economia',
];

$color = static fn($c) => preg_match('/^#[0-9a-fA-F]{6}$/', (string) $c) ? strtolower($c) : '#0284c7';
$back  = static function (string $msg, string $anchor = '') {
    $_SESSION['queue_flash'] = $msg;
    header('Location: /admin/index.php' . ($anchor ? '#' . $anchor : ''));
    exit;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    // An upload bigger than post_max_size arrives as an empty POST: tell why instead of doing nothing.
    if ($action === '' && !$_POST && !$_FILES && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        $back('File troppo grande: il video può essere al massimo di ' . QUEUE_VIDEO_MAX_MB . ' MB.', 'slides');
    }

    if ($action === 'add_service' || $action === 'save_service') {
        $letter = mb_strtoupper(mb_substr(preg_replace('/[^A-Za-z0-9]/', '', (string) ($_POST['letter'] ?? '')), 0, 3));
        $name   = mb_substr(trim((string) ($_POST['name'] ?? '')), 0, 80);
        if ($name === '') $back('Inserisci il nome del servizio.', 'services');
        $args = [$letter, $name, $color($_POST['color'] ?? ''), (int) ($_POST['sort_order'] ?? 0), isset($_POST['active']) ? 1 : 0];
        if ($action === 'add_service') {
            $pdo->prepare("INSERT INTO queue_services (letter, name, color, sort_order, active) VALUES (?, ?, ?, ?, ?)")->execute($args);
        } else {
            $args[] = (int) $_POST['id'];
            $pdo->prepare("UPDATE queue_services SET letter = ?, name = ?, color = ?, sort_order = ?, active = ? WHERE id = ?")->execute($args);
        }
        logActivity('queue_service_saved', 'queue_service', null, $name);
        $back('Servizio salvato.', 'services');
    }

    if ($action === 'save_slide' && !empty($_POST['delete'])) {
        $old = $pdo->prepare("SELECT video_path FROM queue_slides WHERE id = ?");
        $old->execute([(int) $_POST['id']]);
        queueDeleteUpload($old->fetchColumn() ?: null);
        $pdo->prepare("DELETE FROM queue_slides WHERE id = ?")->execute([(int) $_POST['id']]);
        if (queueCamerasAvailable()) queueCamerasSync();
        $back('Riquadro eliminato.', 'slides');
    }

    if ($action === 'add_slide' || $action === 'save_slide') {
        $id    = (int) ($_POST['id'] ?? 0);
        $cur   = ['image_path' => null, 'video_path' => null];
        if ($action === 'save_slide') {
            $st = $pdo->prepare("SELECT image_path, video_path FROM queue_slides WHERE id = ?");
            $st->execute([$id]);
            $cur = $st->fetch() ?: $cur;
        }
        // Tile type: photo, uploaded video, live TV channel or radio station.
        $kind   = in_array($_POST['kind'] ?? '', ['photo', 'video', 'tv', 'radio', 'camera'], true) ? $_POST['kind'] : 'photo';
        $live   = in_array($kind, ['tv', 'radio', 'camera'], true);
        $stream = $kind === 'camera' ? queueCameraUrl($_POST['camera_url'] ?? '') : ($live ? queueStreamUrl($_POST['stream_url'] ?? '') : null);
        if ($kind === 'camera' && !$stream) $back('Scegli una telecamera (Cerca telecamere) oppure scrivi il suo indirizzo rtsp:// o onvif://.', 'slides');
        if ($live && !$stream) $back('Scegli un canale dall\'elenco oppure scrivi l\'indirizzo dello streaming (http:// o https://).', 'slides');
        $title = mb_substr(trim((string) ($_POST['title'] ?? '')), 0, 120);
        $img = queueSaveImage('image');
        if (!empty($_FILES['image']['name']) && !$img) $back('Immagine non valida (JPG, PNG, WebP o GIF, massimo 8 MB).', 'slides');
        $vid = $kind === 'video' ? queueSaveVideo('video') : null;
        if ($kind === 'video' && !empty($_FILES['video']['name']) && !$vid) {
            $err = (int) ($_FILES['video']['error'] ?? 0);
            $back(in_array($err, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) || ($_FILES['video']['size'] ?? 0) > QUEUE_VIDEO_MAX_MB * 1048576
                ? 'Video troppo grande: massimo ' . QUEUE_VIDEO_MAX_MB . ' MB.'
                : 'Video non valido: usa un file MP4 (H.264) o WebM.', 'slides');
        }
        $image = $img ?: (isset($_POST['remove_image']) ? null : $cur['image_path']);
        $video = $kind !== 'video' ? null : ($vid ?: (isset($_POST['remove_video']) ? null : $cur['video_path']));
        if ($kind === 'video' && !$video) $back('Scegli il file del video.', 'slides');
        if ($title === '' && !$image && !$video && !$stream) $back('Inserisci un titolo, una foto o un video.', 'slides');
        if ($cur['video_path'] && $cur['video_path'] !== $video) queueDeleteUpload($cur['video_path']);
        $sub   = mb_substr(trim((string) ($_POST['subtitle'] ?? '')), 0, 255) ?: null;
        $price = mb_substr(trim((string) ($_POST['price'] ?? '')), 0, 40) ?: null;
        $sort  = (int) ($_POST['sort_order'] ?? 0);
        $act   = isset($_POST['active']) ? 1 : 0;
        $audio = isset($_POST['video_audio']) ? 1 : 0;
        $logo  = $live && $kind !== 'camera' ? queueStreamUrl($_POST['stream_logo'] ?? '') : null;
        $secs  = max(10, min(3600, (int) ($_POST['stream_seconds'] ?? 60)));
        $bg    = $kind === 'radio' && ($_POST['radio_mode'] ?? '') === 'bg' ? 1 : 0;
        $cols  = [$title, $sub, $price, $image, $video, $audio, $live ? $kind : null, $stream, $logo, $secs, $bg, $sort, $act];
        if ($action === 'add_slide') {
            $pdo->prepare("INSERT INTO queue_slides (title, subtitle, price, image_path, video_path, video_audio, stream_type, stream_url, stream_logo, stream_seconds, stream_background, sort_order, active)
                           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")->execute($cols);
            $id = (int) $pdo->lastInsertId();
        } else {
            $pdo->prepare("UPDATE queue_slides SET title = ?, subtitle = ?, price = ?, image_path = ?, video_path = ?, video_audio = ?,
                           stream_type = ?, stream_url = ?, stream_logo = ?, stream_seconds = ?, stream_background = ?, sort_order = ?, active = ? WHERE id = ?")
                ->execute([...$cols, $id]);
        }
        // Only one background radio at a time.
        if ($bg && $act) $pdo->prepare("UPDATE queue_slides SET stream_background = 0 WHERE id <> ? AND stream_background = 1")->execute([$id]);
        if (queueCamerasAvailable()) queueCamerasSync();
        $back($vid ? 'Video caricato: il monitor lo scarica e lo mostra entro 5 minuti.'
            : ($bg ? 'Radio di sottofondo salvata: il monitor la fa suonare mentre scorrono le foto, entro 5 minuti.'
            : ($live ? 'Riquadro salvato: il monitor lo mostra entro 5 minuti.' : 'Riquadro salvato.')), 'slides');
    }

    if ($action === 'save_settings') {
        $city = trim((string) ($_POST['weather_city'] ?? ''));
        $msg  = 'Impostazioni salvate.';
        if ($city !== '' && mb_strtolower($city) !== mb_strtolower((string) $cfg['weather_city'])) {
            $geo = queueGeocode($city);
            if ($geo) {
                $cfg['weather_city'] = $geo['name'];
                $cfg['weather_lat']  = $geo['lat'];
                $cfg['weather_lon']  = $geo['lon'];
            } else {
                $msg = "Impostazioni salvate, ma la città \"$city\" non è stata trovata: il meteo resta su {$cfg['weather_city']}.";
            }
        }
        $news = trim((string) ($_POST['news_custom'] ?? '')) ?: (string) ($_POST['news_url'] ?? '');
        $cfg['news_url']      = filter_var($news, FILTER_VALIDATE_URL) ? $news : ($news === '' ? '' : $cfg['news_url']);
        $cfg['brand']         = mb_substr(trim((string) ($_POST['brand'] ?? '')), 0, 60);
        $cfg['totem_title']   = mb_substr(trim((string) ($_POST['totem_title'] ?? '')), 0, 80) ?: 'Prendi il tuo numero';
        $cfg['ticket_header'] = mb_substr(trim((string) ($_POST['ticket_header'] ?? '')), 0, 40);
        $cfg['ticket_footer'] = mb_substr(trim((string) ($_POST['ticket_footer'] ?? '')), 0, 200);
        $cfg['slide_seconds'] = max(3, min(120, (int) ($_POST['slide_seconds'] ?? 8)));
        $cfg['printer_host']  = preg_replace('/[^A-Za-z0-9.\-:]/', '', (string) ($_POST['printer_host'] ?? ''));
        $cfg['printer_port']  = max(1, min(65535, (int) ($_POST['printer_port'] ?? 9100)));
        $cfg['printer_width'] = in_array((int) ($_POST['printer_width'] ?? 48), [32, 42, 48], true) ? (int) $_POST['printer_width'] : 48;
        setSetting('queue', $cfg);
        logActivity('queue_settings_updated', 'settings', null, null);
        $back($msg, 'settings');
    }

    if ($action === 'test_print') {
        $res = queuePrintTicket(['id' => 0, 'service' => 'Prova stampa', 'label' => 'A000', 'ahead' => 0, 'time' => date('d/m/Y H:i')]);
        $back($res['ok'] ? 'Biglietto di prova inviato alla stampante.' : 'Stampa non riuscita: ' . $res['error'], 'settings');
    }

    if ($action === 'new_key') {
        $cfg['key'] = bin2hex(random_bytes(12));
        setSetting('queue', $cfg);
        $back('Nuovi link generati: aggiorna l\'indirizzo sul totem e sul monitor.', 'links');
    }

    if ($action === 'new_op_key') {
        $cfg['op_key'] = bin2hex(random_bytes(16));
        setSetting('queue', $cfg);
        logActivity('queue_op_key_regenerated', 'settings', null, null);
        $back('Nuova chiave operatore generata: aggiorna i link e i comandi UDP sull\'altro server.', 'remote');
    }

    if ($action === 'reset_today') {
        queueReset((int) ($_POST['service_id'] ?? 0) ?: null);
        logActivity('queue_reset', 'queue_service', (int) ($_POST['service_id'] ?? 0) ?: null, null);
        $back('Coda di oggi azzerata: il prossimo biglietto riparte da 1.', 'services');
    }
}

$flash    = $_SESSION['queue_flash'] ?? null;
unset($_SESSION['queue_flash']);
$services = queueServices(false);
$slides   = queueSlides(false);
$state    = [];
foreach (queueState()['services'] as $s) $state[$s['id']] = $s;

$https = ($_SERVER['HTTPS'] ?? '') === 'on' || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
$base  = ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'eliminacode.upgradesrls.com');
$links = [
    ['Totem (clienti)', 'fa-hand-pointer', $base . '/queue/totem.php?k=' . $cfg['key'], 'Chrome a schermo intero: chrome --kiosk --kiosk-printing "<link>"', null],
    ['Monitor', 'fa-tv', $base . '/queue/monitor.php?k=' . $cfg['key'], 'Audio attivo da subito: sul PC Windows del monitor scarica e apri "Avvio monitor" (Chrome a schermo intero con l\'audio già attivo; per farlo partire all\'accensione copialo in shell:startup). Altri sistemi: chrome --kiosk --autoplay-policy=no-user-gesture-required "<link>". Aperto in un browser normale, l\'audio parte al primo tocco o tasto.', '/admin/monitor-launcher.php'],
    ['Pagina operatore', 'fa-user-tie', $base . '/queue/operator.php', 'Serve l\'accesso con un utente amministratore o operatore (Amministrazione › Utenti).', null],
];

$v = static fn($val) => htmlspecialchars((string) ($val ?? ''), ENT_QUOTES, 'UTF-8');
$pageTitle = 'Eliminacode';
include __DIR__ . '/../includes/header.php';
?>
<style>
.qa-link { display:flex; gap:10px; align-items:center; flex-wrap:wrap; padding:12px 0; border-bottom:1px solid var(--border, #eee); }
.qa-link:last-child { border-bottom:0; }
.qa-link .t { font-weight:700; min-width:170px; }
.qa-link input { flex:1; min-width:240px; font-family:monospace; font-size:.85rem; }
.qa-hint { font-size:.82rem; color:var(--text-secondary); width:100%; }
.qa-table { width:100%; border-collapse:collapse; }
.qa-table td, .qa-table th { padding:8px 6px; border-bottom:1px solid var(--border, #eee); vertical-align:middle; text-align:left; }
.qa-table input[type=text], .qa-table input[type=number] { width:100%; }
.qa-dot { display:inline-block; width:14px; height:14px; border-radius:50%; vertical-align:middle; }
.qa-thumb { width:84px; height:56px; object-fit:cover; border-radius:6px; background:#ddd; display:block; }
.qa-note { font-size:.9rem; color:var(--text-secondary); }
.qa-badge { display:inline-block; margin-top:4px; font-size:.75rem; color:var(--text-secondary); }
.qa-progress { font-size:.88rem; color:var(--text-secondary); } .qa-progress progress { width:160px; vertical-align:middle; }
.qa-slide { border:1px solid var(--border, #eee); border-radius:10px; padding:12px; margin-bottom:12px; display:grid; grid-template-columns:100px 1fr; gap:14px; }
@media (max-width:700px){ .qa-slide { grid-template-columns:1fr; } .qa-wide { overflow-x:auto; } }
.qa-kinds { display:flex; gap:8px; flex-wrap:wrap; margin-bottom:12px; }
.qa-kind { border:1px solid var(--border, #ddd); border-radius:8px; padding:6px 12px; cursor:pointer; }
.qa-kind:has(input:checked) { border-color:var(--primary, #0284c7); background:rgba(2,132,199,.08); font-weight:600; }
.qa-upload .kf { display:none; }
.qa-upload[data-kind=photo] .kf.photo, .qa-upload[data-kind=video] .kf.video, .qa-upload[data-kind=tv] .kf.tv, .qa-upload[data-kind=radio] .kf.radio, .qa-upload[data-kind=camera] .kf.camera { display:revert; }
.qa-upload[data-kind=camera] .qa-chan-row { display:none; }
.qa-cam-list { display:flex; flex-wrap:wrap; gap:6px; margin:6px 0; }
.qa-cam-list:empty { display:none; }
.qa-cam-list button { border:1px solid var(--border-color, #ddd); background:#fff; color:var(--text-primary, #0c3b56); border-radius:8px; padding:6px 10px; cursor:pointer; font:inherit; font-size:.85rem; }
.qa-cam-list button:hover { background:#e6f3fb; }
.qa-cam-list button.sel { background:var(--primary, #0284c7); color:#fff; }
.qa-cam-list .qa-cam-dev { flex-basis:100%; font-size:.8rem; color:var(--text-secondary); margin-top:4px; }
.qa-live { border-left:3px solid var(--primary, #0284c7); padding-left:12px; margin-bottom:8px; }
.qa-chan-info { font-size:.8rem; color:var(--text-secondary); }
.qa-radio-mode { display:flex; gap:8px; flex-wrap:wrap; margin:4px 0 10px; }.qa-upload[data-kind=radio]:has(input[name=radio_mode][value=bg]:checked) .qa-secs { display:none; }
.qa-chan-list { max-height:260px; overflow-y:auto; border:1px solid var(--border-color, #ddd); border-radius:8px; margin-top:6px; background:#fff; color:var(--text-primary, #0c3b56); }
.qa-chan-list:empty { display:none; }
.qa-chan-list button { display:flex; gap:10px; align-items:center; width:100%; padding:6px 10px; border:0; border-bottom:1px solid #eef2f5; background:#fff; text-align:left; cursor:pointer; color:var(--text-primary, #0c3b56); font:inherit; }
.qa-chan-list button:hover { background:#e6f3fb; }
.qa-chan-list button.sel { background:var(--primary, #0284c7); color:#fff; font-weight:600; }
.qa-chan-list button.sel small { color:#e0f2fe; }
.qa-chan-list img, .qa-chan-list .nl { width:40px; height:26px; object-fit:contain; flex:none; }
.qa-chan-list small { margin-left:auto; color:#64748b; font-size:.75rem; white-space:nowrap; }
.qa-chan-list button.blocked { color:#94a3b8; }
.qa-chan-list button.blocked img { opacity:.4; }
.qa-preview video, .qa-preview iframe { display:block; width:100%; max-width:480px; aspect-ratio:16/9; background:#000; border:0; border-radius:8px; margin-bottom:8px; }
.qa-preview video-camera { display:block; width:100%; max-width:480px; aspect-ratio:16/9; background:#000; border-radius:8px; overflow:hidden; margin-bottom:8px; }
.qa-preview audio { display:block; width:100%; max-width:480px; margin-bottom:8px; }
.qa-thumb.qa-logo { object-fit:contain; background:#fff; }
</style>

<div class="page-header">
    <h1><i class="fas fa-ticket"></i> Eliminacode</h1>
    <a href="/queue/operator.php" class="btn btn-primary"><i class="fas fa-forward"></i> Pagina operatore</a>
</div>

<?php if ($flash): ?>
    <div class="alert mb-lg" style="background:rgba(39,174,96,.1);color:var(--success);padding:14px;border-radius:8px;"><i class="fas fa-info-circle"></i> <?= $v($flash) ?></div>
<?php endif; ?>

<div class="card" id="links">
    <div class="card-header"><h2><i class="fas fa-link"></i> Link degli schermi</h2></div>
    <div class="card-body">
        <?php foreach ($links as [$title, $icon, $url, $hint, $launcher]): ?>
            <div class="qa-link">
                <span class="t"><i class="fas <?= $icon ?>"></i> <?= $v($title) ?></span>
                <input type="text" class="form-control" readonly value="<?= $v($url) ?>" onclick="this.select()">
                <button type="button" class="btn btn-outline" onclick="navigator.clipboard.writeText(this.previousElementSibling.value);this.textContent='Copiato'">Copia</button>
                <a class="btn btn-outline" href="<?= $v($url) ?>" target="_blank"><i class="fas fa-up-right-from-square"></i> Apri</a>
                <?php if ($launcher): ?><a class="btn btn-primary" href="<?= $v($launcher) ?>"><i class="fas fa-volume-high"></i> Avvio monitor (Windows)</a><?php endif; ?>
                <div class="qa-hint"><?= $v($hint) ?></div>
            </div>
        <?php endforeach; ?>
        <form method="POST" class="mt-md" onsubmit="return confirm('I link attuali del totem e del monitor smetteranno di funzionare. Continuare?')">
            <input type="hidden" name="action" value="new_key">
            <button class="btn btn-outline"><i class="fas fa-rotate"></i> Genera nuovi link</button>
        </form>
    </div>
</div>

<div class="card mt-lg" id="remote">
    <div class="card-header"><h2><i class="fas fa-server"></i> Comandi operatore per un altro server</h2></div>
    <div class="card-body">
        <?php
        $api  = $base . '/api/queue.php?ok=' . $cfg['op_key'];
        $udp  = $cfg['op_key'];
        $host = parse_url($base, PHP_URL_HOST);
        $row  = static function (string $icon, string $label, string $value) use ($v): void { ?>
            <div class="qa-link">
                <span class="t"><i class="fas <?= $icon ?>"></i> <?= $v($label) ?></span>
                <input type="text" class="form-control" readonly value="<?= $v($value) ?>" onclick="this.select()">
                <button type="button" class="btn btn-outline" onclick="navigator.clipboard.writeText(this.previousElementSibling.value);this.textContent='Copiato'">Copia</button>
            </div>
        <?php };
        $commands = static function (callable $make) use ($services, $row): void {
            foreach ($services as $s) {
                $sid  = (int) $s['id'];
                $name = trim($s['letter'] . ' ' . $s['name']);
                $row('fa-forward', "$name — Avanti", $make('next', $sid, null));
                $row('fa-rotate-left', "$name — Richiama", $make('recall', $sid, null));
                $row('fa-hashtag', "$name — Chiama numero (cambia 1 con il numero)", $make('call', $sid, 1));
                $row('fa-eraser', "$name — Azzera numerazione", $make('reset', $sid, null));
            }
            $row('fa-circle-info', 'Situazione (tutti i reparti)', $make('state', null, null));
            $row('fa-eraser', 'Azzera numerazione (tutti i reparti)', $make('reset', null, null));
        };
        ?>
        <p class="qa-note">Servono a un altro server per chiamare i numeri senza accesso, come i pulsanti della pagina operatore: <strong>Avanti</strong> chiama il prossimo in attesa,
            <strong>Richiama</strong> ripete il numero attuale sul monitor, <strong>Chiama numero</strong> chiama un numero preciso dei biglietti di oggi, <strong>Situazione</strong> dice i numeri in servizio e quanti aspettano,
            <strong>Azzera numerazione</strong> cancella la coda di oggi del reparto (o di tutti) e il prossimo biglietto riparte da 1, come il pulsante di azzeramento qui sotto (le statistiche restano).
            Il monitor suona e annuncia il numero come sempre. Contengono la chiave operatore: chi li ha può chiamare i numeri, quindi dalli solo al server che li usa.</p>

        <h3 class="mt-md"><i class="fas fa-globe"></i> Link web (HTTP)</h3>
        <p class="qa-note">L'altro server apre il link (GET) o lo invia (POST). Risponde in JSON: <code>{"success":true,"called":"A015"}</code>, oppure <code>"success":false</code> con il motivo (es. "Nessuno in attesa").</p>
        <?php $commands(static fn($a, $sid, $n) => "$api&action=$a" . ($sid ? "&service_id=$sid" : '') . ($n ? "&number=$n" : '')); ?>

        <h3 class="mt-lg"><i class="fas fa-satellite-dish"></i> Comandi UDP</h3>
        <p class="qa-note">L'altro server manda un pacchetto UDP con il testo del comando a <strong><?= $v($host) ?></strong>, porta <strong><?= QUEUE_UDP_PORT ?></strong>
            (servizio <code>eliminacode-udp</code>, deve essere attivo su questo server). Il formato è <code>chiave comando reparto [numero]</code>:
            al posto del numero del reparto va bene anche la sua lettera (<code>next A</code>), e le parole si possono separare con spazi, <code>;</code> o <code>,</code>.
            Si può mandare anche in JSON: <code>{"ok":"chiave","action":"next","service_id":1}</code>. La risposta torna al mittente in JSON, come per i link web.</p>
        <?php $row('fa-network-wired', 'Indirizzo e porta', $host . ':' . QUEUE_UDP_PORT); ?>
        <?php $commands(static fn($a, $sid, $n) => trim("$udp $a $sid $n")); ?>

        <form method="POST" class="mt-md" onsubmit="return confirm('I link e i comandi UDP attuali smetteranno di funzionare. Continuare?')">
            <input type="hidden" name="action" value="new_op_key">
            <button class="btn btn-outline"><i class="fas fa-rotate"></i> Genera nuova chiave operatore</button>
        </form>
    </div>
</div>

<div class="card mt-lg" id="services">
    <div class="card-header"><h2><i class="fas fa-list-ol"></i> Servizi (pulsanti del totem)</h2></div>
    <div class="card-body qa-wide">
        <p class="qa-note">Ogni servizio ha la sua numerazione (A001, B001…), che riparte da 1 ogni giorno: a mezzanotte tutti i contatori si azzerano da soli.</p>
        <table class="qa-table">
            <tr><th>Lettera</th><th>Nome</th><th>Colore</th><th>Ordine</th><th>Attivo</th><th>Oggi</th><th></th></tr>
            <?php foreach ($services as $s): $st = $state[$s['id']] ?? null; $f = 'svc' . (int) $s['id']; ?>
                <tr>
                    <td style="width:80px"><input form="<?= $f ?>" type="text" name="letter" maxlength="3" class="form-control" value="<?= $v($s['letter']) ?>"></td>
                    <td><input form="<?= $f ?>" type="text" name="name" maxlength="80" class="form-control" value="<?= $v($s['name']) ?>" required></td>
                    <td style="width:70px"><input form="<?= $f ?>" type="color" name="color" value="<?= $v($s['color']) ?>"></td>
                    <td style="width:80px"><input form="<?= $f ?>" type="number" name="sort_order" class="form-control" value="<?= (int) $s['sort_order'] ?>"></td>
                    <td style="width:60px"><input form="<?= $f ?>" type="checkbox" name="active" <?= $s['active'] ? 'checked' : '' ?>></td>
                    <td class="qa-note" style="white-space:nowrap"><?= $st ? 'Emessi fino a ' . $v($st['last_taken'] ?? '—') . '<br>In attesa: ' . (int) $st['waiting'] : '—' ?></td>
                    <td style="white-space:nowrap"><button form="<?= $f ?>" class="btn btn-primary btn-sm" title="Salva"><i class="fas fa-save"></i></button></td>
                </tr>
            <?php endforeach; ?>
            <tr>
                <td><input form="svcNew" type="text" name="letter" maxlength="3" class="form-control" placeholder="B"></td>
                <td><input form="svcNew" type="text" name="name" maxlength="80" class="form-control" placeholder="Nuovo servizio, es. Panetteria" required></td>
                <td><input form="svcNew" type="color" name="color" value="#0ea5e9"></td>
                <td><input form="svcNew" type="number" name="sort_order" class="form-control" value="<?= count($services) + 1 ?>"></td>
                <td><input form="svcNew" type="checkbox" name="active" checked></td>
                <td></td>
                <td><button form="svcNew" class="btn btn-success btn-sm"><i class="fas fa-plus"></i> Aggiungi</button></td>
            </tr>
        </table>
        <?php foreach ($services as $s): ?>
            <form method="POST" id="svc<?= (int) $s['id'] ?>"><input type="hidden" name="action" value="save_service"><input type="hidden" name="id" value="<?= (int) $s['id'] ?>"></form>
        <?php endforeach; ?>
        <form method="POST" id="svcNew"><input type="hidden" name="action" value="add_service"></form>
        <form method="POST" class="mt-md" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center" onsubmit="return confirm('Cancellare i biglietti di oggi? La numerazione ripartirà da 1.')">
            <input type="hidden" name="action" value="reset_today">
            <select name="service_id" class="form-control" style="width:auto">
                <option value="0">Tutti i servizi</option>
                <?php foreach ($services as $s): ?><option value="<?= (int) $s['id'] ?>"><?= $v($s['letter'] . ' · ' . $s['name']) ?></option><?php endforeach; ?>
            </select>
            <button class="btn btn-outline" style="color:var(--danger)"><i class="fas fa-eraser"></i> Azzera la coda di oggi</button>
        </form>
    </div>
</div>

<?php
// Tile form fields, shared by the existing tiles and "Nuovo riquadro". $s = the tile, or null for a new one.
$slideFields = static function (?array $s) use ($v, $slides): void {
    $kind = $s ? ($s['stream_type'] ?: ($s['video_path'] ? 'video' : 'photo')) : 'photo';
    $kinds = ['photo' => ['fa-image', 'Foto'], 'video' => ['fa-film', 'Video'], 'tv' => ['fa-tv', 'TV in diretta'], 'radio' => ['fa-radio', 'Radio']];
    // IP cameras only where go2rtc runs (the LAN server).
    if (queueCamerasAvailable() || $kind === 'camera') $kinds['camera'] = ['fa-video', 'Telecamera'];
    ?>
    <div class="qa-kinds">
        <?php foreach ($kinds as $k => [$icon, $label]): ?>
            <label class="qa-kind"><input type="radio" name="kind" value="<?= $k ?>" <?= $k === $kind ? 'checked' : '' ?>> <i class="fas <?= $icon ?>"></i> <?= $label ?></label>
        <?php endforeach; ?>
    </div>
    <div class="form-row">
        <div class="form-group"><label class="form-label">Titolo</label><input type="text" name="title" maxlength="120" class="form-control" value="<?= $v($s['title'] ?? '') ?>" placeholder="<?= $s ? '' : 'Focaccia del giorno' ?>"></div>
        <div class="form-group"><label class="form-label">Prezzo / etichetta</label><input type="text" name="price" maxlength="40" class="form-control" value="<?= $v($s['price'] ?? '') ?>" placeholder="€ 3,50 o NOVITÀ"></div>
    </div>
    <div class="form-group"><label class="form-label">Descrizione</label><input type="text" name="subtitle" maxlength="255" class="form-control" value="<?= $v($s['subtitle'] ?? '') ?>"></div>
    <div class="kf tv radio camera qa-live">
        <div class="kf camera">
            <div class="form-row" style="align-items:flex-end">
                <div class="form-group"><label class="form-label">Utente della telecamera</label><input type="text" class="form-control qa-cam-user" value="admin" autocomplete="off"></div>
                <div class="form-group"><label class="form-label">Password della telecamera</label><input type="password" class="form-control qa-cam-pass" autocomplete="new-password"></div>
                <div class="form-group"><button type="button" class="btn btn-outline qa-cam-find"><i class="fas fa-magnifying-glass"></i> Cerca telecamere</button></div>
            </div>
            <div class="qa-cam-list"></div>
            <small class="qa-chan-info qa-cam-info">Scrivi utente e password, premi "Cerca telecamere", poi tocca la telecamera e la qualità. Meglio lo stream secondario (più leggero) e in H.264.</small>
            <div class="form-group mt-md">
                <label class="form-label">Indirizzo della telecamera (rtsp:// o onvif://, resta sul server)</label>
                <input type="text" name="camera_url" maxlength="500" class="form-control" value="<?= $v(($s['stream_type'] ?? '') === 'camera' ? $s['stream_url'] : '') ?>" placeholder="rtsp://admin:password@192.168.1.101:554/cam/realmonitor?channel=1&amp;subtype=1" autocomplete="off">
            </div>
        </div>
        <div class="form-row qa-chan-row">
            <div class="form-group">
                <label class="form-label"><span class="kf tv">Canale TV</span><span class="kf radio">Stazione radio</span>: scegli dall'elenco (scrivi per filtrare)</label>
                <input type="search" class="form-control qa-chan" autocomplete="off" placeholder="Cerca: LA7, Rai News 24, Radio Italia…">
                <div class="qa-chan-list"></div>
                <small class="qa-chan-info"></small>
            </div>
            <div class="form-group">
                <label class="form-label">Indirizzo dello streaming</label>
                <input type="text" name="stream_url" maxlength="500" class="form-control" value="<?= $v($s['stream_url'] ?? '') ?>" placeholder="https://…/live.m3u8, un link YouTube o una radio .mp3">
            </div>
        </div>
        <input type="hidden" name="stream_logo" value="<?= $v($s['stream_logo'] ?? '') ?>">
        <?php $bg = $s ? !empty($s['stream_background']) : true; ?>
        <div class="kf radio qa-radio-mode">
            <label class="qa-kind"><input type="radio" name="radio_mode" value="bg" <?= $bg ? 'checked' : '' ?>> <i class="fas fa-music"></i> Musica di sottofondo: suona sempre mentre scorrono le foto</label>
            <label class="qa-kind"><input type="radio" name="radio_mode" value="tile" <?= $bg ? '' : 'checked' ?>> <i class="fas fa-radio"></i> Riquadro a sé (logo e scritta "In onda")</label>
        </div>
        <div class="form-row" style="align-items:flex-end">
            <div class="form-group qa-secs" style="max-width:190px"><label class="form-label">Secondi sul monitor</label><input type="number" name="stream_seconds" min="10" max="3600" class="form-control" value="<?= (int) ($s['stream_seconds'] ?? 60) ?>"></div>
            <div class="form-group"><button type="button" class="btn btn-outline qa-try"><i class="fas fa-play"></i> Prova</button></div>
        </div>
        <div class="qa-preview"></div>
    </div>
    <div class="form-row" style="align-items:center">
        <div class="form-group kf photo tv radio">
            <label class="form-label"><span class="kf photo"><?= !empty($s['image_path']) ? 'Nuova foto' : 'Foto' ?></span><span class="kf tv">Immagine mentre il canale parte (facoltativa)</span><span class="kf radio">Immagine di sfondo (facoltativa, altrimenti il logo)</span></label>
            <input type="file" name="image" accept="image/*" class="form-control">
        </div>
        <div class="form-group kf video"><label class="form-label"><?= !empty($s['video_path']) ? 'Nuovo video' : 'Video' ?> (max <?= QUEUE_VIDEO_MAX_MB ?> MB)</label><input type="file" name="video" accept="video/mp4,video/webm,video/quicktime,.mp4,.webm,.mov" class="form-control"></div>
        <div class="form-group" style="max-width:110px"><label class="form-label">Ordine</label><input type="number" name="sort_order" class="form-control" value="<?= (int) ($s['sort_order'] ?? count($slides) + 1) ?>"></div>
    </div>
    <?php if ($s): ?><label><input type="checkbox" name="active" <?= $s['active'] ? 'checked' : '' ?>> Attivo</label> &nbsp;
    <?php else: ?><input type="hidden" name="active" value="1"><?php endif; ?>
    <span class="kf video tv"><label title="Si azzera da solo quando viene chiamato un numero"><input type="checkbox" name="video_audio" <?= !empty($s['video_audio']) ? 'checked' : '' ?>> Audio</label> &nbsp;</span>
    <?php if (!empty($s['image_path'])): ?><label><input type="checkbox" name="remove_image"> Togli la foto</label> &nbsp;<?php endif; ?>
    <?php if (!empty($s['video_path'])): ?><span class="kf video"><label><input type="checkbox" name="remove_video"> Togli il video</label></span><?php endif; ?>
    <?php
};
?>
<div class="card mt-lg" id="slides">
    <div class="card-header"><h2><i class="fas fa-images"></i> Riquadri sul monitor</h2></div>
    <div class="card-body">
        <p class="qa-note">I riquadri attivi ruotano sul monitor. Per ogni riquadro scegli cosa mostrare:</p>
        <ul class="qa-note">
            <li><strong>Foto</strong>: orizzontale (es. 1600×1000), resta per i secondi impostati in "Monitor" più sotto.</li>
            <li><strong>Video</strong>: MP4 H.264 o WebM orizzontale, massimo <?= QUEUE_VIDEO_MAX_MB ?> MB. Il monitor lo scarica per intero e lo riproduce fino alla fine.</li>
            <li><strong>TV in diretta</strong>: scegli un canale dall'elenco dei canali italiani online, oppure incolla l'indirizzo di uno streaming (.m3u8) o un link YouTube di una diretta.</li>
            <li><strong>Radio</strong>: scegli una stazione dall'elenco delle radio italiane o incolla l'indirizzo dello streaming. Come <strong>musica di sottofondo</strong> suona sempre mentre scorrono le foto (si abbassa da sola durante le chiamate e sui video o TV con audio); come <strong>riquadro a sé</strong> sul monitor si vede il logo con la scritta "In onda".</li>
            <?php if (queueCamerasAvailable()): ?><li><strong>Telecamera</strong>: una telecamera IP della rete (ONVIF / RTSP). Scrivi utente e password, premi "Cerca telecamere" e scegli la qualità; meglio lo stream secondario in H.264. Funziona sui monitor aperti nella rete locale.</li><?php endif; ?>
        </ul>
        <p class="qa-note">TV, radio e telecamere restano sul monitor per i secondi che scegli, poi si passa al riquadro successivo; se c'è un solo riquadro restano sempre. Il loro audio si abbassa da solo quando viene chiamato un numero.
            Gli elenchi vengono da fonti pubbliche e alcuni canali possono essere spenti o non funzionare nel browser: premi <strong>Prova</strong> prima di salvare. Se un canale non parte, il monitor passa al riquadro successivo.
            <?php if ($https): ?><br><strong>Questo sito è in https</strong>: i canali con indirizzo <code>http://</code> vengono bloccati dal browser, usa quelli <code>https://</code>.<?php endif; ?></p>
        <?php foreach ($slides as $s): $kind = $s['stream_type'] ?: ($s['video_path'] ? 'video' : 'photo'); ?>
            <form method="POST" enctype="multipart/form-data" class="qa-slide qa-upload">
                <input type="hidden" name="action" value="save_slide"><input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
                <div>
                    <?php if ($kind === 'video'): ?>
                        <video class="qa-thumb" src="<?= $v($s['video_path']) ?>#t=1" muted preload="metadata" playsinline></video>
                    <?php elseif ($s['image_path'] || $s['stream_logo']): ?><img class="qa-thumb<?= $s['image_path'] ? '' : ' qa-logo' ?>" src="<?= $v($s['image_path'] ?: $s['stream_logo']) ?>" alt="">
                    <?php else: ?><span class="qa-thumb"></span><?php endif; ?>
                    <span class="qa-badge"><?php if ($kind === 'radio' && $s['stream_background']): ?><i class="fas fa-music"></i> Radio di sottofondo<?php else: ?><i class="fas <?= ['photo' => 'fa-image', 'video' => 'fa-film', 'tv' => 'fa-tv', 'radio' => 'fa-radio', 'camera' => 'fa-video'][$kind] ?>"></i> <?= ['photo' => 'Foto', 'video' => 'Video', 'tv' => 'TV in diretta', 'radio' => 'Radio', 'camera' => 'Telecamera'][$kind] ?><?php endif; ?></span>
                </div>
                <div>
                    <?php $slideFields($s); ?>
                    <div class="mt-md" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
                        <button class="btn btn-primary btn-sm"><i class="fas fa-save"></i> Salva</button>
                        <button class="btn btn-outline btn-sm" style="color:var(--danger)" name="delete" value="1" formnovalidate onclick="return confirm('Eliminare questo riquadro?')"><i class="fas fa-trash"></i> Elimina</button>
                        <span class="qa-progress"></span>
                    </div>
                </div>
            </form>
        <?php endforeach; ?>
        <h3 style="font-size:1rem" class="mt-lg">Nuovo riquadro</h3>
        <form method="POST" enctype="multipart/form-data" class="qa-upload">
            <input type="hidden" name="action" value="add_slide">
            <?php $slideFields(null); ?>
            <div class="mt-md" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
                <button class="btn btn-success"><i class="fas fa-plus"></i> Aggiungi riquadro</button>
                <span class="qa-progress"></span>
            </div>
        </form>
    </div>
</div>
<script src="/assets/js/live-player.js?v=<?= @filemtime(__DIR__ . '/../assets/js/live-player.js') ?>"></script>
<script>
const qaEsc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
// Channel lists (TV: iptv-org, radio: radio-browser), loaded from the server only when a TV / radio tile is open.
const qaLists = {}, qaLoading = {};
function qaLoadList(type) {
    if (qaLists[type]) return Promise.resolve(qaLists[type]);
    return qaLoading[type] || (qaLoading[type] = fetch('/admin/channels.php?type=' + type).then(r => r.json()).then(j => {
        qaLists[type] = j.channels || [];
        return qaLists[type];
    }).catch(() => { delete qaLoading[type]; return []; }));
}
const qaCamPost = data => fetch('/admin/cameras.php', { method: 'POST', body: new URLSearchParams(data) }).then(r => r.json());
// The visible channel list under the search box (a datalist stays hidden in Firefox until you type).
const qaFold = s => String(s).normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase();
function qaRenderList(form) {
    const box = form.querySelector('.qa-chan-list'), list = qaLists[form.dataset.kind] || [];
    const q = qaFold(form.querySelector('.qa-chan').value.trim()), cur = form.querySelector('input[name=stream_url]').value.trim();
    const https = location.protocol === 'https:';
    const why = c => https && /^http:/i.test(c.url) ? 'http: solo in rete locale' : (c.ok === false ? 'non risponde ora' : '');
    // Channels that cannot play from this browser go grey at the bottom.
    const hits = list.filter(c => !q || qaFold(c.name + ' ' + c.group).includes(q)).sort((a, b) => !!why(a) - !!why(b));
    const top = box.scrollTop;
    box.innerHTML = hits.map(c => {
        const blocked = why(c);
        const tag = blocked || (c.ok ? c.group : (/geo-blocked/i.test(c.name) ? 'può non funzionare' : c.group));
        return `<button type="button" data-i="${list.indexOf(c)}" class="${c.url === cur ? 'sel' : ''}${blocked ? ' blocked' : ''}">`
            + (c.logo ? `<img src="${qaEsc(c.logo)}" alt="" loading="lazy" onerror="this.style.visibility='hidden'">` : '<span class="nl"></span>')
            + `<span>${qaEsc(c.name)}</span><small>${qaEsc(tag)}</small></button>`;
    }).join('') || (list.length ? '<p class="qa-note" style="padding:8px 10px;margin:0">Nessun canale trovato: prova un altro nome.</p>' : '');
    box.scrollTop = top;
    return hits.length;
}
// TV: try every playlist from this browser (same network, same geo-blocks, same CORS as the monitor), 8 at a time.
const qaChecked = {};
function qaCheckList(type, done) {
    if (type !== 'tv' || qaChecked[type]) return;
    qaChecked[type] = true;
    const queue = (qaLists[type] || []).filter(c => !(location.protocol === 'https:' && /^http:/i.test(c.url)));
    let left = queue.length;
    const next = () => {
        const c = queue.shift();
        if (!c) return;
        const ctl = new AbortController(), t = setTimeout(() => ctl.abort(), 8000);
        fetch(c.url, { signal: ctl.signal, cache: 'no-store' })
            .then(r => r.ok ? r.text() : '').then(b => { c.ok = b.includes('#EXTM3U'); }, () => { c.ok = false; })
            .finally(() => { clearTimeout(t); if (--left === 0) done(); next(); });
    };
    for (let i = 0; i < 8; i++) next();
}
function qaStopPreview(form) {
    if (form._preview) { form._preview.stop(); form._preview = null; }
    const box = form.querySelector('.qa-preview'), btn = form.querySelector('.qa-try');
    if (box) box.innerHTML = '';
    if (btn) btn.innerHTML = '<i class="fas fa-play"></i> Prova';
}
function qaSetKind(form) {
    const k = form.querySelector('input[name=kind]:checked').value;
    form.dataset.kind = k;
    qaStopPreview(form);
    if (k !== 'tv' && k !== 'radio') return;
    const info = form.querySelector('.qa-chan-info');
    form.querySelector('.qa-chan-list').innerHTML = '';
    info.textContent = 'Carico l\'elenco…';
    qaLoadList(k).then(list => {
        if (form.dataset.kind !== k) return;
        qaRenderList(form);
        info.textContent = list.length ? list.length + (k === 'tv' ? ' canali TV' : ' radio') + ' nell\'elenco: tocca un nome per sceglierlo.' : 'Elenco non disponibile ora: puoi scrivere l\'indirizzo a mano.';
        if (k === 'tv' && list.length && !qaChecked.tv) {
            info.textContent += ' Controllo quali canali rispondono…';
            qaCheckList('tv', () => {
                const ok = list.filter(c => c.ok).length;
                document.querySelectorAll('form.qa-upload[data-kind=tv]').forEach(f => {
                    qaRenderList(f);
                    f.querySelector('.qa-chan-info').textContent = ok + ' canali TV funzionano da qui (in grigio in fondo quelli che non rispondono): tocca un nome per sceglierlo.';
                });
            });
        }
    });
}
document.querySelectorAll('form.qa-upload').forEach(form => {
    form.querySelectorAll('input[name=kind]').forEach(r => r.addEventListener('change', () => qaSetKind(form)));
    qaSetKind(form);
    const chan = form.querySelector('.qa-chan'), url = form.querySelector('input[name=stream_url]'), logo = form.querySelector('input[name=stream_logo]');
    chan.addEventListener('input', () => qaRenderList(form));
    chan.addEventListener('keydown', e => { if (e.key === 'Enter') e.preventDefault(); });
    form.querySelector('.qa-chan-list').addEventListener('click', e => {
        const b = e.target.closest('button[data-i]');
        const c = b && (qaLists[form.dataset.kind] || [])[+b.dataset.i];
        if (!c) return;
        url.value = c.url;
        logo.value = c.logo || '';
        const title = form.querySelector('input[name=title]');
        if (!title.value.trim()) title.value = c.name.replace(/\s*[\[(].*$/, '');
        form.querySelectorAll('.qa-chan-list button.sel').forEach(x => x.classList.remove('sel'));
        b.classList.add('sel');
        qaStopPreview(form);
    });
    url.addEventListener('input', () => { logo.value = ''; qaStopPreview(form); qaRenderList(form); });
    form.querySelector('.qa-try').addEventListener('click', () => {
        if (form._preview) return qaStopPreview(form);
        const box = form.querySelector('.qa-preview'), btn = form.querySelector('.qa-try');
        const cam = form.dataset.kind === 'camera', camUrl = form.querySelector('input[name=camera_url]').value.trim();
        if (!(cam ? camUrl : url.value.trim())) { box.innerHTML = '<p class="qa-note">Scegli prima ' + (cam ? 'una telecamera' : 'un canale') + ' o scrivi l\'indirizzo.</p>'; return; }
        box.innerHTML = '<p class="qa-note qa-wait">' + (cam ? 'Collegamento alla telecamera…' : 'Avvio del canale…') + '</p>';
        btn.innerHTML = '<i class="fas fa-stop"></i> Ferma';
        const holder = form._preview = { stop() { this.dead = true; if (this.p) this.p.stop(); } };
        const onFail = why => {
            if (holder.dead) return;
            form._preview = null;
            btn.innerHTML = '<i class="fas fa-play"></i> Prova';
            box.innerHTML = '<p class="qa-note" style="color:var(--danger)"><i class="fas fa-triangle-exclamation"></i> ' + (why === 'mixed'
                ? 'Questo canale è http:// e il sito è https://: il browser lo blocca. Scegli un canale https://.'
                : cam ? 'La telecamera non si vede (' + qaEsc(why) + '): controlla indirizzo, utente e password. Se è in H.265 impostala in H.264 o usa lo stream secondario.'
                : 'Il canale non parte (' + qaEsc(why) + '): potrebbe essere spento o non visibile da browser. Prova un altro canale.') + '</p>';
        };
        // A camera is played through go2rtc on the server: register a temporary preview stream first.
        const src = cam ? qaCamPost({ action: 'preview', url: camUrl }).then(j => j.stream) : Promise.resolve(url.value.trim());
        src.then(stream => {
            if (holder.dead) return;
            if (!stream) return onFail('il server non raggiunge la telecamera');
            holder.p = livePlay(box, stream, { radio: form.dataset.kind === 'radio', camera: cam, controls: true, timeout: 20000, onFail });
            box.addEventListener('playing', () => { const w = box.querySelector('.qa-wait'); if (w) w.remove(); }, true);
            if (box.querySelector('iframe')) { const w = box.querySelector('.qa-wait'); if (w) w.remove(); }
        }, () => onFail('errore di rete'));
    });
    // IP cameras: ONVIF search (go2rtc on the server), then the device's profiles (main / sub stream).
    const camFind = form.querySelector('.qa-cam-find');
    if (camFind) camFind.addEventListener('click', () => {
        const list = form.querySelector('.qa-cam-list'), info = form.querySelector('.qa-cam-info');
        info.textContent = 'Cerco le telecamere nella rete…';
        list.innerHTML = '';
        qaCamPost({ action: 'discover' }).then(j => {
            if (!j.success) { info.textContent = j.message || 'Ricerca non riuscita.'; return; }
            info.textContent = j.devices.length ? 'Tocca una telecamera per vedere le qualità disponibili.' : 'Nessuna telecamera ONVIF trovata: scrivi l\'indirizzo rtsp:// a mano.';
            list.innerHTML = j.devices.map(d => `<button type="button" data-host="${qaEsc(d.host)}"><i class="fas fa-video"></i> ${qaEsc(d.host)}</button>`).join('');
        }, () => { info.textContent = 'Ricerca non riuscita.'; });
    });
    form.querySelector('.qa-cam-list')?.addEventListener('click', e => {
        const b = e.target.closest('button');
        if (!b) return;
        const list = form.querySelector('.qa-cam-list'), info = form.querySelector('.qa-cam-info');
        if (b.dataset.url) { // a profile: that is the tile's address
            form.querySelector('input[name=camera_url]').value = b.dataset.url;
            const title = form.querySelector('input[name=title]');
            if (!title.value.trim()) title.value = b.dataset.name.replace(/\s*stream\d+$/, '');
            list.querySelectorAll('button[data-url]').forEach(x => x.classList.toggle('sel', x === b));
            info.textContent = 'Telecamera scelta: premi Prova per vederla, poi Salva.';
            qaStopPreview(form);
            return;
        }
        info.textContent = 'Leggo la telecamera ' + b.dataset.host + '…';
        list.querySelectorAll('button[data-host]').forEach(x => x.classList.toggle('sel', x === b));
        list.querySelectorAll('.qa-cam-dev, button[data-url]').forEach(x => x.remove());
        qaCamPost({ action: 'profiles', host: b.dataset.host, user: form.querySelector('.qa-cam-user').value, pass: form.querySelector('.qa-cam-pass').value }).then(j => {
            if (!j.success) { info.textContent = j.message || 'La telecamera non risponde.'; return; }
            list.insertAdjacentHTML('beforeend', '<div class="qa-cam-dev">' + qaEsc(b.dataset.host) + ':</div>' + j.profiles.map((p, i) =>
                `<button type="button" data-url="${qaEsc(p.url)}" data-name="${qaEsc(p.name)}">${qaEsc(p.name)}${i === 0 ? ' (principale)' : i === 1 ? ' (secondario, consigliato)' : ''}</button>`).join(''));
            info.textContent = 'Tocca la qualità da mostrare sul monitor.';
        }, () => { info.textContent = 'La telecamera non risponde.'; });
    });
});
// Videos are big: upload them with a progress bar instead of a page that looks frozen.
document.querySelectorAll('form.qa-upload').forEach(form => form.addEventListener('submit', e => {
    const vid = form.querySelector('input[name=video]');
    if ((e.submitter && e.submitter.name === 'delete') || form.dataset.kind !== 'video' || !vid || !vid.files.length) return;
    e.preventDefault();
    if (vid.files[0].size > <?= QUEUE_VIDEO_MAX_MB ?> * 1048576) {
        alert('Il video è troppo grande: massimo <?= QUEUE_VIDEO_MAX_MB ?> MB.');
        return;
    }
    const out = form.querySelector('.qa-progress');
    form.querySelectorAll('button').forEach(b => b.disabled = true);
    const xhr = new XMLHttpRequest();
    xhr.open('POST', location.pathname);
    xhr.upload.onprogress = ev => {
        if (!ev.lengthComputable) return;
        const p = Math.round(ev.loaded * 100 / ev.total);
        out.innerHTML = '<progress max="100" value="' + p + '"></progress> Caricamento video ' + p + '% — non chiudere la pagina';
    };
    xhr.onload = () => { location.href = '/admin/index.php#slides'; location.reload(); };
    xhr.onerror = () => {
        out.textContent = 'Caricamento non riuscito: controlla la connessione e riprova.';
        form.querySelectorAll('button').forEach(b => b.disabled = false);
    };
    out.textContent = 'Caricamento video…';
    xhr.send(new FormData(form));
}));
</script>

<div class="card mt-lg" id="settings">
    <div class="card-header"><h2><i class="fas fa-sliders-h"></i> Impostazioni</h2></div>
    <form method="POST" class="card-body">
        <input type="hidden" name="action" value="save_settings">
        <div class="form-group"><label class="form-label">Nome dell'attività (in alto nelle pagine, sul totem e sul monitor)</label><input type="text" name="brand" maxlength="60" class="form-control" value="<?= $v($cfg['brand']) ?>" placeholder="<?= $v(APP_DEFAULT_NAME) ?>"></div>
        <h3 style="font-size:1rem" class="mt-lg">Totem e biglietto</h3>
        <div class="form-row">
            <div class="form-group"><label class="form-label">Titolo sul totem</label><input type="text" name="totem_title" maxlength="80" class="form-control" value="<?= $v($cfg['totem_title']) ?>"></div>
            <div class="form-group"><label class="form-label">Intestazione del biglietto (vuoto = nome del locale)</label><input type="text" name="ticket_header" maxlength="40" class="form-control" value="<?= $v($cfg['ticket_header']) ?>"></div>
        </div>
        <div class="form-group"><label class="form-label">Messaggio in fondo al biglietto</label><textarea name="ticket_footer" maxlength="200" rows="2" class="form-control"><?= $v($cfg['ticket_footer']) ?></textarea></div>

        <h3 style="font-size:1rem" class="mt-lg">Stampante di rete del totem (ESC/POS)</h3>
        <p class="qa-note">Stampante termica raggiungibile dal server sulla porta 9100. Se lasci vuoto l'indirizzo, o la stampante non risponde, il totem stampa il biglietto dal browser.</p>
        <div class="form-row">
            <div class="form-group"><label class="form-label">Indirizzo IP</label><input type="text" name="printer_host" class="form-control" value="<?= $v($cfg['printer_host']) ?>" placeholder="192.168.1.50"></div>
            <div class="form-group" style="max-width:140px"><label class="form-label">Porta</label><input type="number" name="printer_port" class="form-control" value="<?= (int) $cfg['printer_port'] ?>"></div>
            <div class="form-group" style="max-width:200px"><label class="form-label">Larghezza carta</label>
                <select name="printer_width" class="form-control">
                    <?php foreach ([48 => '80 mm (48 caratteri)', 42 => '80 mm (42 caratteri)', 32 => '58 mm (32 caratteri)'] as $w => $l): ?>
                        <option value="<?= $w ?>" <?= (int) $cfg['printer_width'] === $w ? 'selected' : '' ?>><?= $l ?></option>
                    <?php endforeach; ?>
                </select></div>
        </div>

        <h3 style="font-size:1rem" class="mt-lg">Monitor</h3>
        <div class="form-row">
            <div class="form-group"><label class="form-label">Città del meteo</label><input type="text" name="weather_city" class="form-control" value="<?= $v($cfg['weather_city']) ?>"></div>
            <div class="form-group" style="max-width:220px"><label class="form-label">Secondi per riquadro</label><input type="number" min="3" max="120" name="slide_seconds" class="form-control" value="<?= (int) $cfg['slide_seconds'] ?>"></div>
        </div>
        <div class="form-row">
            <div class="form-group"><label class="form-label">Notizie</label>
                <select name="news_url" class="form-control">
                    <?php foreach (QUEUE_NEWS_PRESETS as $u => $l): ?>
                        <option value="<?= $v($u) ?>" <?= $cfg['news_url'] === $u ? 'selected' : '' ?>><?= $v($l) ?></option>
                    <?php endforeach; ?>
                    <option value="" <?= $cfg['news_url'] === '' ? 'selected' : '' ?>>Nessuna notizia</option>
                </select></div>
            <div class="form-group"><label class="form-label">Oppure indirizzo di un feed RSS</label>
                <input type="url" name="news_custom" class="form-control" placeholder="https://…/rss.xml" value="<?= isset(QUEUE_NEWS_PRESETS[$cfg['news_url']]) || $cfg['news_url'] === '' ? '' : $v($cfg['news_url']) ?>"></div>
        </div>
        <button class="btn btn-primary"><i class="fas fa-save"></i> Salva</button>
    </form>
    <?php if (trim((string) $cfg['printer_host']) !== ''): ?>
        <form method="POST" class="card-body" style="padding-top:0">
            <input type="hidden" name="action" value="test_print">
            <button class="btn btn-outline"><i class="fas fa-print"></i> Stampa un biglietto di prova</button>
        </form>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
