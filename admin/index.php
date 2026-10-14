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
        $title = mb_substr(trim((string) ($_POST['title'] ?? '')), 0, 120);
        $img = queueSaveImage('image');
        if (!empty($_FILES['image']['name']) && !$img) $back('Immagine non valida (JPG, PNG, WebP o GIF, massimo 8 MB).', 'slides');
        $vid = queueSaveVideo('video');
        if (!empty($_FILES['video']['name']) && !$vid) {
            $err = (int) ($_FILES['video']['error'] ?? 0);
            $back(in_array($err, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) || ($_FILES['video']['size'] ?? 0) > QUEUE_VIDEO_MAX_MB * 1048576
                ? 'Video troppo grande: massimo ' . QUEUE_VIDEO_MAX_MB . ' MB.'
                : 'Video non valido: usa un file MP4 (H.264) o WebM.', 'slides');
        }
        $image = $img ?: (isset($_POST['remove_image']) ? null : $cur['image_path']);
        $video = $vid ?: (isset($_POST['remove_video']) ? null : $cur['video_path']);
        if ($title === '' && !$image && !$video) $back('Inserisci un titolo, una foto o un video.', 'slides');
        if ($cur['video_path'] && $cur['video_path'] !== $video) queueDeleteUpload($cur['video_path']);
        $sub   = mb_substr(trim((string) ($_POST['subtitle'] ?? '')), 0, 255) ?: null;
        $price = mb_substr(trim((string) ($_POST['price'] ?? '')), 0, 40) ?: null;
        $sort  = (int) ($_POST['sort_order'] ?? 0);
        $act   = isset($_POST['active']) ? 1 : 0;
        $audio = isset($_POST['video_audio']) ? 1 : 0;
        if ($action === 'add_slide') {
            $pdo->prepare("INSERT INTO queue_slides (title, subtitle, price, image_path, video_path, video_audio, sort_order, active) VALUES (?, ?, ?, ?, ?, ?, ?, ?)")
                ->execute([$title, $sub, $price, $image, $video, $audio, $sort, $act]);
        } else {
            $pdo->prepare("UPDATE queue_slides SET title = ?, subtitle = ?, price = ?, image_path = ?, video_path = ?, video_audio = ?, sort_order = ?, active = ? WHERE id = ?")
                ->execute([$title, $sub, $price, $image, $video, $audio, $sort, $act, $id]);
        }
        $back($vid ? 'Video caricato: il monitor lo scarica e lo mostra entro 5 minuti.' : 'Riquadro salvato.', 'slides');
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
        $back('Nuova chiave operatore generata: aggiorna i link sull\'altro server.', 'remote');
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
    <div class="card-header"><h2><i class="fas fa-server"></i> Link operatore per un altro server</h2></div>
    <div class="card-body">
        <p class="qa-note">Chiamano i numeri senza accesso, come i pulsanti della pagina operatore: basta aprirli (GET) o inviarli (POST). Rispondono in JSON (<code>"success":true, "called":"A015"</code>). Chi ha questi link può chiamare i numeri: dalli solo al server che li usa.</p>
        <?php $api = $base . '/api/queue.php?ok=' . $cfg['op_key']; ?>
        <?php foreach ($services as $s): $sid = (int) $s['id']; ?>
            <?php foreach ([
                ['Avanti', 'fa-forward', "$api&action=next&service_id=$sid"],
                ['Richiama', 'fa-rotate-left', "$api&action=recall&service_id=$sid"],
                ['Chiama numero (cambia 1 con il numero)', 'fa-hashtag', "$api&action=call&service_id=$sid&number=1"],
            ] as [$what, $icon, $url]): ?>
                <div class="qa-link">
                    <span class="t"><i class="fas <?= $icon ?>"></i> <?= $v(trim($s['letter'] . ' ' . $s['name'])) ?> — <?= $v($what) ?></span>
                    <input type="text" class="form-control" readonly value="<?= $v($url) ?>" onclick="this.select()">
                    <button type="button" class="btn btn-outline" onclick="navigator.clipboard.writeText(this.previousElementSibling.value);this.textContent='Copiato'">Copia</button>
                </div>
            <?php endforeach; ?>
        <?php endforeach; ?>
        <div class="qa-link">
            <span class="t"><i class="fas fa-circle-info"></i> Situazione (tutti i reparti)</span>
            <input type="text" class="form-control" readonly value="<?= $v("$api&action=state") ?>" onclick="this.select()">
            <button type="button" class="btn btn-outline" onclick="navigator.clipboard.writeText(this.previousElementSibling.value);this.textContent='Copiato'">Copia</button>
        </div>
        <form method="POST" class="mt-md" onsubmit="return confirm('I link operatore attuali smetteranno di funzionare. Continuare?')">
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

<div class="card mt-lg" id="slides">
    <div class="card-header"><h2><i class="fas fa-images"></i> Riquadri prodotti sul monitor</h2></div>
    <div class="card-body">
        <p class="qa-note">I riquadri attivi ruotano sul monitor. Usa foto orizzontali (es. 1600×1000) oppure un video orizzontale
            (MP4 H.264 o WebM, 16:9, massimo <?= QUEUE_VIDEO_MAX_MB ?> MB). Il monitor scarica il video per intero prima di mostrarlo,
            poi lo riproduce fino alla fine prima di passare al riquadro successivo. Sui video titolo, prezzo e descrizione sono facoltativi.</p>
        <?php foreach ($slides as $s): ?>
            <form method="POST" enctype="multipart/form-data" class="qa-slide qa-upload">
                <input type="hidden" name="action" value="save_slide"><input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
                <div>
                    <?php if ($s['video_path']): ?>
                        <video class="qa-thumb" src="<?= $v($s['video_path']) ?>#t=1" muted preload="metadata" playsinline></video>
                        <span class="qa-badge"><i class="fas fa-film"></i> Video</span>
                    <?php elseif ($s['image_path']): ?><img class="qa-thumb" src="<?= $v($s['image_path']) ?>" alt="">
                    <?php else: ?><span class="qa-thumb"></span><?php endif; ?>
                </div>
                <div>
                    <div class="form-row">
                        <div class="form-group"><label class="form-label">Titolo</label><input type="text" name="title" maxlength="120" class="form-control" value="<?= $v($s['title']) ?>"></div>
                        <div class="form-group"><label class="form-label">Prezzo / etichetta</label><input type="text" name="price" maxlength="40" class="form-control" value="<?= $v($s['price']) ?>" placeholder="€ 3,50"></div>
                    </div>
                    <div class="form-group"><label class="form-label">Descrizione</label><input type="text" name="subtitle" maxlength="255" class="form-control" value="<?= $v($s['subtitle']) ?>"></div>
                    <div class="form-row" style="align-items:center">
                        <div class="form-group"><label class="form-label"><?= $s['image_path'] ? 'Nuova foto' : 'Foto' ?></label><input type="file" name="image" accept="image/*" class="form-control"></div>
                        <div class="form-group"><label class="form-label"><?= $s['video_path'] ? 'Nuovo video' : 'Video' ?></label><input type="file" name="video" accept="video/mp4,video/webm,video/quicktime,.mp4,.webm,.mov" class="form-control"></div>
                        <div class="form-group" style="max-width:110px"><label class="form-label">Ordine</label><input type="number" name="sort_order" class="form-control" value="<?= (int) $s['sort_order'] ?>"></div>
                    </div>
                    <label><input type="checkbox" name="active" <?= $s['active'] ? 'checked' : '' ?>> Attivo</label>
                    &nbsp; <label title="Si azzera da solo quando viene chiamato un numero"><input type="checkbox" name="video_audio" <?= $s['video_audio'] ? 'checked' : '' ?>> Audio del video</label>
                    <?php if ($s['image_path']): ?>&nbsp; <label><input type="checkbox" name="remove_image"> Togli la foto</label><?php endif; ?>
                    <?php if ($s['video_path']): ?>&nbsp; <label><input type="checkbox" name="remove_video"> Togli il video</label><?php endif; ?>
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
            <div class="form-row">
                <div class="form-group"><label class="form-label">Titolo</label><input type="text" name="title" maxlength="120" class="form-control" placeholder="Focaccia del giorno"></div>
                <div class="form-group"><label class="form-label">Prezzo / etichetta</label><input type="text" name="price" maxlength="40" class="form-control" placeholder="€ 3,50 o NOVITÀ"></div>
            </div>
            <div class="form-group"><label class="form-label">Descrizione</label><input type="text" name="subtitle" maxlength="255" class="form-control" placeholder="Pomodorini, origano e olio extravergine"></div>
            <div class="form-row">
                <div class="form-group"><label class="form-label">Foto</label><input type="file" name="image" accept="image/*" class="form-control"></div>
                <div class="form-group"><label class="form-label">oppure Video (max <?= QUEUE_VIDEO_MAX_MB ?> MB)</label><input type="file" name="video" accept="video/mp4,video/webm,video/quicktime,.mp4,.webm,.mov" class="form-control"></div>
                <div class="form-group" style="max-width:110px"><label class="form-label">Ordine</label><input type="number" name="sort_order" class="form-control" value="<?= count($slides) + 1 ?>"></div>
            </div>
            <input type="hidden" name="active" value="1">
            <label title="Si azzera da solo quando viene chiamato un numero"><input type="checkbox" name="video_audio"> Audio del video</label>
            <div class="mt-md" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
                <button class="btn btn-success"><i class="fas fa-plus"></i> Aggiungi riquadro</button>
                <span class="qa-progress"></span>
            </div>
        </form>
    </div>
</div>
<script>
// Videos are big: upload them with a progress bar instead of a page that looks frozen.
document.querySelectorAll('form.qa-upload').forEach(form => form.addEventListener('submit', e => {
    const vid = form.querySelector('input[name=video]');
    if ((e.submitter && e.submitter.name === 'delete') || !vid || !vid.files.length) return;
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
