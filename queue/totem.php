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
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no, viewport-fit=cover">
<title>Totem eliminacode</title>
<!-- Tablet: "Aggiungi a schermata Home" opens the totem full screen, like an app. -->
<link rel="manifest" href="/queue/totem-manifest.php?k=<?= $h(rawurlencode($key)) ?>">
<meta name="theme-color" content="#e0f2fe">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<meta name="apple-mobile-web-app-title" content="Totem">
<?= brandHeadTags() ?>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;600;700;800&display=swap" rel="stylesheet">
<style>
/* Made for a tablet, portrait or landscape: sizes follow the short side of the screen (vmin) and the
   service buttons always share the space left, so every service fits without scrolling. */
:root { --bg:#e0f2fe; --card:#fff; --text:#0c4a6e; --muted:#3b7ea6; --gap:clamp(10px, 2.2vmin, 24px); }
* { box-sizing:border-box; -webkit-tap-highlight-color:transparent; -webkit-touch-callout:none; }
html,body { margin:0; height:100%; background:linear-gradient(160deg,#f0f9ff 0%,#bae6fd 100%) fixed var(--bg); color:var(--text); font-family:'DM Sans',system-ui,sans-serif;
            user-select:none; -webkit-user-select:none; overflow:hidden; overscroll-behavior:none; touch-action:manipulation; }
.wrap { height:100vh; height:100dvh; display:flex; flex-direction:column; align-items:center; gap:var(--gap);
        padding:max(var(--gap), env(safe-area-inset-top)) max(var(--gap), env(safe-area-inset-right)) max(10px, env(safe-area-inset-bottom)) max(var(--gap), env(safe-area-inset-left)); }
.head { text-align:center; flex:none; }
.head img { max-height:12vmin; max-width:60vw; }
.head .brand { font-size:clamp(20px, 4.4vmin, 44px); font-weight:800; }
h1 { font-size:clamp(26px, 6.2vmin, 64px); margin:.5vmin 0 0; font-weight:800; line-height:1.1; }
.sub { color:var(--muted); font-size:clamp(15px, 2.8vmin, 28px); margin-top:.6vmin; }
.services { flex:1; min-height:0; width:100%; max-width:1400px; display:grid; gap:var(--gap);
            grid-template-columns:1fr; grid-auto-rows:minmax(0, 1fr); align-items:center; }
/* Columns by orientation and number of services (data-count). */
@media (orientation:portrait) { .services[data-count="5"], .services[data-count="6"], .services.many { grid-template-columns:repeat(2, 1fr); } }
@media (orientation:landscape) {
    .services:not([data-count="1"]) { grid-template-columns:repeat(2, 1fr); }
    .services[data-count="5"], .services[data-count="6"], .services.many { grid-template-columns:repeat(3, 1fr); }
}
.svc { border:0; border-radius:clamp(16px, 3vmin, 28px); color:#fff; cursor:pointer; width:100%; height:100%; max-height:34vmin; min-height:0;
       padding:0 clamp(14px, 3vmin, 36px); display:flex; align-items:center; gap:clamp(12px, 3vmin, 32px); text-align:left; font-family:inherit;
       box-shadow:0 8px 24px rgba(3,105,161,.28); transition:transform .1s; overflow:hidden; }
.svc:active { transform:scale(.97); }
.svc .letter { font-size:clamp(34px, 8vmin, 88px); font-weight:800; width:clamp(56px, 13vmin, 140px); height:clamp(56px, 13vmin, 140px); border-radius:50%;
               background:rgba(255,255,255,.2); display:flex; align-items:center; justify-content:center; flex:none; }
.services.many .svc .letter, .services[data-count="5"] .svc .letter, .services[data-count="6"] .svc .letter { font-size:clamp(28px, 6vmin, 64px); width:clamp(46px, 9.5vmin, 100px); height:clamp(46px, 9.5vmin, 100px); }
.svc > span:last-child { min-width:0; }
.svc .name { font-size:clamp(20px, 4.6vmin, 50px); font-weight:800; line-height:1.1; overflow-wrap:normal; hyphens:auto; -webkit-hyphens:auto; display:block; }
@media (orientation:portrait) { .services.many .svc .name, .services[data-count="5"] .svc .name, .services[data-count="6"] .svc .name { font-size:clamp(18px, 3.8vmin, 40px); } }
.services[data-count="1"] .svc { max-height:46vmin; }
.svc .wait { font-size:clamp(14px, 2.6vmin, 26px); opacity:.9; margin-top:.6vmin; }
.svc[disabled] { opacity:.6; }
.empty { color:var(--muted); font-size:clamp(18px, 3vmin, 30px); text-align:center; align-self:center; }
.powered { display:flex; align-items:center; justify-content:center; flex:none; color:var(--muted); }
.powered img { height:clamp(20px, 3.4vmin, 34px); width:auto; display:block; }
.powered-mark { display:flex; flex-direction:column; align-items:center; gap:3px; }
.powered-contact { font-size:clamp(11px, 1.6vmin, 15px); line-height:1; white-space:nowrap; }
.overlay { position:fixed; inset:0; background:rgba(8,47,73,.9); display:none; align-items:center; justify-content:center; z-index:10; padding:var(--gap); }
.overlay.show { display:flex; }
.ticket { background:#fff; color:#111; border-radius:clamp(18px, 3vmin, 28px); padding:clamp(20px, 5vmin, 56px) clamp(24px, 6vmin, 72px); text-align:center;
          width:min(92vw, 640px); max-height:100%; overflow:hidden; }
.ticket .svcname { font-size:clamp(20px, 3.6vmin, 36px); font-weight:700; }
.ticket .num { font-size:clamp(72px, 22vmin, 220px); font-weight:800; line-height:1; margin:2vmin 0; letter-spacing:.02em; }
.ticket .ahead { font-size:clamp(18px, 3.2vmin, 32px); }
.ticket .wait { font-size:clamp(15px, 2.6vmin, 26px); opacity:.85; margin-top:.6vmin; }
.ticket .wait:empty { display:none; }
.ticket .take { font-size:clamp(18px, 3.2vmin, 32px); font-weight:700; margin-top:3vmin; color:#16a34a; }
.ticket .err { font-size:clamp(16px, 2.6vmin, 26px); margin-top:2vmin; color:#dc2626; }
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
    <div class="services<?= count($services) > 6 ? ' many' : '' ?>" id="services" data-count="<?= count($services) ?>">
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

/* Tablet: the screen never goes dark (Wake Lock, needs https or localhost) and the first touch
   hides the browser bars (full screen); both are asked again when the page comes back. */
let wakeLock = null;
async function keepAwake() {
    try { if ('wakeLock' in navigator && !wakeLock) { wakeLock = await navigator.wakeLock.request('screen'); wakeLock.addEventListener('release', () => { wakeLock = null; }); } } catch (e) {}
}
function goFullscreen() {
    const d = document.documentElement, req = d.requestFullscreen || d.webkitRequestFullscreen;
    if (req && !(document.fullscreenElement || document.webkitFullscreenElement)) { try { const p = req.call(d); if (p && p.catch) p.catch(() => {}); } catch (e) {} }
}
document.addEventListener('pointerdown', () => { goFullscreen(); keepAwake(); });
document.addEventListener('visibilitychange', () => { if (!document.hidden) keepAwake(); });
keepAwake();
refresh();
setInterval(refresh, 10000);
// Pick up new/renamed services from the admin.
setTimeout(() => location.reload(), 30 * 60 * 1000);
</script>
</body>
</html>
