<?php
/**
 * Eliminacode — customer totem (touch screen + ticket printer).
 *
 * Opened without a login: /queue/totem.php?k=<settings.queue.key>.
 * One big button per active service. The ticket is printed by the server on the
 * network ESC/POS printer (Amministrazione › Eliminacode); when none is configured or it
 * fails, the page prints it itself (run Chrome with --kiosk --kiosk-printing so
 * there is no print dialog).
 */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/queue.php';

$key = (string) ($_GET['k'] ?? '');
if (!queueKeyOk($key)) {
    http_response_code(403);
    exit('Link del totem non valido. Copialo da Amministrazione › Eliminacode.');
}
$cfg      = queueSettings();
$services = queueServices();
$brand    = appName();
$logo     = brandLogoUrl();
$h        = static fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no">
<title>Totem eliminacode</title>
<?= brandHeadTags() ?>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;600;700;800&display=swap" rel="stylesheet">
<style>
:root { --bg:#e0f2fe; --card:#fff; --text:#0c4a6e; --muted:#3b7ea6; }
* { box-sizing:border-box; -webkit-tap-highlight-color:transparent; }
html,body { margin:0; height:100%; background:linear-gradient(160deg,#f0f9ff 0%,#bae6fd 100%) fixed var(--bg); color:var(--text); font-family:'DM Sans',system-ui,sans-serif; user-select:none; overflow:hidden; }
.wrap { height:100%; display:flex; flex-direction:column; align-items:center; padding:4vh 4vw; gap:3vh; }
.head { text-align:center; }
.head img { max-height:14vh; max-width:60vw; }
.head .brand { font-size:5vh; font-weight:800; }
.powered { display:flex; align-items:center; justify-content:center; gap:8px; margin-top:auto; color:var(--muted); font-size:1.6vh; }
.powered img { height:3.2vh; width:auto; display:block; }
.powered-mark { display:flex; flex-direction:column; align-items:center; gap:.3vh; }
.powered-contact { font-size:1.3vh; line-height:1; white-space:nowrap; }
h1 { font-size:5.5vh; margin:1vh 0 0; font-weight:800; }
.sub { color:var(--muted); font-size:2.6vh; margin-top:.8vh; }
.services { flex:1; width:100%; max-width:1400px; display:grid; gap:2.5vh; align-content:center;
            grid-template-columns:repeat(auto-fit, minmax(min(100%, 420px), 1fr)); }
.svc { border:0; border-radius:28px; color:#fff; cursor:pointer; padding:5vh 3vw; min-height:20vh;
       display:flex; align-items:center; gap:3vw; text-align:left; font-family:inherit;
       box-shadow:0 10px 30px rgba(3,105,161,.3); transition:transform .1s; }
.svc:active { transform:scale(.97); }
.svc .letter { font-size:9vh; font-weight:800; width:13vh; height:13vh; border-radius:50%; background:rgba(255,255,255,.2);
               display:flex; align-items:center; justify-content:center; flex:none; }
.svc > span:last-child { min-width:0; }
.svc .name { font-size:min(5vh, 3.4vw); font-weight:800; line-height:1.1; overflow-wrap:break-word; }
.svc .wait { font-size:2.4vh; opacity:.9; margin-top:.6vh; }
.svc[disabled] { opacity:.6; }
.empty { color:var(--muted); font-size:3vh; text-align:center; }
.overlay { position:fixed; inset:0; background:rgba(8,47,73,.9); display:none; align-items:center; justify-content:center; z-index:10; }
.overlay.show { display:flex; }
.ticket { background:#fff; color:#111; border-radius:28px; padding:5vh 6vw; text-align:center; min-width:min(90vw,560px); }
.ticket .svcname { font-size:3.4vh; font-weight:700; }
.ticket .num { font-size:20vh; font-weight:800; line-height:1; margin:2vh 0; letter-spacing:.02em; }
.ticket .ahead { font-size:3vh; }
.ticket .wait { font-size:2.4vh; opacity:.85; margin-top:.6vh; }
.ticket .wait:empty { display:none; }
.ticket .take { font-size:3vh; font-weight:700; margin-top:3vh; color:#16a34a; }
.ticket .err { font-size:2.4vh; margin-top:2vh; color:#dc2626; }
#printArea { display:none; }
@media print {
    @page { size:80mm auto; margin:0; }
    body * { visibility:hidden; }
    html,body { background:#fff; height:auto; overflow:visible; }
    #printArea, #printArea * { visibility:visible; }
    #printArea { display:block; position:absolute; left:0; top:0; width:72mm; padding:3mm; color:#000; font-family:Arial,sans-serif; text-align:center; }
    #printArea .p-brand { font-size:16pt; font-weight:800; }
    #printArea .p-svc { font-size:14pt; font-weight:700; margin-top:3mm; border-top:1px dashed #000; padding-top:3mm; }
    #printArea .p-num { font-size:54pt; font-weight:800; line-height:1.1; margin:3mm 0; }
    #printArea .p-small { font-size:10pt; }
    #printArea .p-foot { font-size:10pt; margin-top:3mm; border-top:1px dashed #000; padding-top:3mm; }
    #printArea .p-credit { font-size:7pt; margin-top:2mm; }
}
</style>
</head>
<body>
<div class="wrap">
    <div class="head">
        <?php if ($logo): ?><img src="<?= $h($logo) ?>" alt="<?= $h($brand) ?>"><?php else: ?><div class="brand"><?= $h($brand) ?></div><?php endif; ?>
        <h1><?= $h($cfg['totem_title']) ?></h1>
        <div class="sub">Tocca il servizio che ti interessa</div>
    </div>
    <div class="services" id="services">
        <?php foreach ($services as $s): ?>
            <button class="svc" style="background:<?= $h($s['color']) ?>" data-id="<?= (int) $s['id'] ?>">
                <span class="letter"><?= $h($s['letter']) ?></span>
                <span><span class="name"><?= $h($s['name']) ?></span><span class="wait" data-wait="<?= (int) $s['id'] ?>" style="display:block"></span></span>
            </button>
        <?php endforeach; ?>
        <?php if (!$services): ?><div class="empty">Nessun servizio attivo.</div><?php endif; ?>
    </div>
    <?= poweredBy() ?>
</div>

<div class="overlay" id="overlay">
    <div class="ticket">
        <div class="svcname" id="tSvc"></div>
        <div class="num" id="tNum"></div>
        <div class="ahead" id="tAhead"></div>
        <div class="ahead wait" id="tWait"></div>
        <div class="take" id="tTake">Ritira il tuo biglietto</div>
        <div class="err" id="tErr" style="display:none"></div>
    </div>
</div>

<div id="printArea">
    <div class="p-brand"><?= $h(trim((string) $cfg['ticket_header']) ?: $brand) ?></div>
    <div class="p-svc" id="pSvc"></div>
    <div class="p-num" id="pNum"></div>
    <div class="p-small" id="pAhead"></div>
    <div class="p-small" id="pWait"></div>
    <div class="p-small" id="pTime"></div>
    <div class="p-foot"><?= nl2br($h($cfg['ticket_footer'])) ?></div>
    <div class="p-credit"><?= $h(UPGRADE_CONTACT) ?></div>
</div>

<script>
const KEY = <?= json_encode($key) ?>;
let busy = false, hideTimer = null;

async function take(id) {
    if (busy) return;
    busy = true;
    document.querySelectorAll('.svc').forEach(b => b.disabled = true);
    const err = document.getElementById('tErr');
    err.style.display = 'none';
    try {
        const r = await fetch('/api/queue.php', { method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'take', k: KEY, service_id: id }) });
        const j = await r.json();
        if (!j.success) throw new Error(j.message || 'Errore');
        const t = j.ticket;
        document.getElementById('tSvc').textContent = t.service;
        document.getElementById('tNum').textContent = t.label;
        document.getElementById('tAhead').textContent = t.ahead_text;
        document.getElementById('tWait').textContent = t.wait_text || '';
        document.getElementById('tTake').textContent = 'Ritira il tuo biglietto';
        document.getElementById('overlay').classList.add('show');
        if (!j.printed) {
            // No network printer (or it failed): print from the browser.
            document.getElementById('pSvc').textContent = t.service;
            document.getElementById('pNum').textContent = t.label;
            document.getElementById('pAhead').textContent = t.ahead_text;
            document.getElementById('pWait').textContent = t.wait_text || '';
            document.getElementById('pTime').textContent = t.time;
            setTimeout(() => window.print(), 150);
        }
    } catch (e) {
        document.getElementById('tSvc').textContent = '';
        document.getElementById('tNum').textContent = '—';
        document.getElementById('tAhead').textContent = '';
        document.getElementById('tWait').textContent = '';
        document.getElementById('tTake').textContent = '';
        err.textContent = 'Non è stato possibile emettere il biglietto. Riprova o chiedi al personale.';
        err.style.display = '';
        document.getElementById('overlay').classList.add('show');
    }
    clearTimeout(hideTimer);
    hideTimer = setTimeout(close, 6000);
}

function close() {
    document.getElementById('overlay').classList.remove('show');
    document.querySelectorAll('.svc').forEach(b => b.disabled = false);
    busy = false;
    refresh();
}

async function refresh() {
    try {
        const r = await fetch('/api/queue.php?action=state&k=' + encodeURIComponent(KEY), { cache: 'no-store' });
        const j = await r.json();
        (j.services || []).forEach(s => {
            const el = document.querySelector('[data-wait="' + s.id + '"]');
            if (el) el.textContent = s.waiting > 0 ? 'In attesa: ' + s.waiting : 'Nessuna attesa';
        });
    } catch (e) {}
}

document.querySelectorAll('.svc').forEach(b => b.addEventListener('click', () => take(+b.dataset.id)));
document.getElementById('overlay').addEventListener('click', close);
document.addEventListener('contextmenu', e => e.preventDefault());
refresh();
setInterval(refresh, 10000);
// Pick up new/renamed services from the admin.
setTimeout(() => location.reload(), 30 * 60 * 1000);
</script>
</body>
</html>
