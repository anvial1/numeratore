<?php
/**
 * Eliminacode — staff/customer monitor (TV, 16:9).
 *
 * Opened without a login: /queue/monitor.php?k=<settings.queue.key>.
 * Left: the number being served per service (flashes with a chime and a voice
 * on every call) and the last numbers called. Right: tiles in rotation
 * (Amministrazione › Eliminacode: photo, video, live TV channel or radio) and the weather. Bottom: news headlines.
 * Sound is on by default when Chrome is started with --autoplay-policy=no-user-gesture-required
 * (admin/monitor-launcher.php downloads a Windows file that does it); otherwise one touch/key turns it on.
 */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/queue.php';

$key = (string) ($_GET['k'] ?? '');
if (!queueKeyOk($key)) {
    http_response_code(403);
    exit('Link del monitor non valido. Copialo da Amministrazione › Eliminacode.');
}
$brand = appName();
$logo  = brandLogoUrl();
$h     = static fn($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Monitor eliminacode</title>
<?= brandHeadTags() ?>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;600;700;800&display=swap" rel="stylesheet">
<style>
:root { --bg:#082f49; --panel:#0c4a6e; --panel2:#075985; --text:#fff; --muted:rgba(224,242,254,.75); --accent:#facc15; }
* { box-sizing:border-box; }
html,body { margin:0; height:100%; background:linear-gradient(160deg,#0c4a6e 0%,#082f49 100%) var(--bg); color:var(--text); font-family:'DM Sans',system-ui,sans-serif; overflow:hidden; cursor:none; }
.screen { height:100vh; display:grid; grid-template-rows:11vh 1fr 8vh; gap:1.4vh; padding:1.4vh 1.4vw; }

/* Top bar */
.top { display:flex; align-items:center; gap:2vw; background:var(--panel); border-radius:18px; padding:0 2vw; }
.top img { max-height:8vh; max-width:22vw; }
.top .brand { font-size:4vh; font-weight:800; }
.top .clock { margin-left:auto; text-align:right; }
/* Upgrade logo on a white plate: its grey lettering needs a light background */
.top .powered { display:flex; flex-direction:column; align-items:center; gap:.3vh; background:#fff; border-radius:12px; padding:.8vh 1vw; color:#64748b; font-size:1.2vh; }
.top .powered img { height:3.6vh; max-width:none; width:auto; display:block; }
.top .time { font-size:5.4vh; font-weight:800; line-height:1; font-variant-numeric:tabular-nums; }
.top .date { font-size:2vh; color:var(--muted); text-transform:capitalize; }
.top .wnow { display:flex; align-items:center; gap:1vw; padding-left:2vw; border-left:2px solid rgba(255,255,255,.12); }
.top .wnow .ic { font-size:6vh; line-height:1; }
.top .wnow .t { font-size:4.6vh; font-weight:800; line-height:1; }
.top .wnow .c { font-size:1.9vh; color:var(--muted); }

/* Middle */
.mid { display:grid; grid-template-columns:42% 1fr; gap:1.4vw; min-height:0; }
.left { display:flex; flex-direction:column; gap:1.4vh; min-height:0; }
.serving { flex:1; display:grid; gap:1.4vh; min-height:0; }
.svc { border-radius:18px; padding:1.5vh 1.6vw; display:flex; flex-direction:column; justify-content:center; min-height:0; overflow:hidden; position:relative; }
.svc .lbl { font-size:2.4vh; font-weight:700; text-transform:uppercase; letter-spacing:.06em; opacity:.92; }
.svc .num { font-weight:800; line-height:1; font-variant-numeric:tabular-nums; }
.svc .meta { font-size:2vh; opacity:.9; }
.svc .num .none { font-size:3.4vh; font-weight:600; opacity:.75; }
.svc.flash { animation:flash 1s ease-in-out 4; }
@keyframes flash { 50% { filter:brightness(1.6); transform:scale(1.02); } }
.recent { background:var(--panel); border-radius:18px; padding:1.4vh 1.4vw; }
.recent h3 { margin:0 0 1vh; font-size:2vh; color:var(--muted); text-transform:uppercase; letter-spacing:.08em; }
.recent .list { display:flex; gap:.8vw; flex-wrap:wrap; }
.recent .chip { background:var(--panel2); border-radius:10px; padding:.7vh .9vw; font-size:3vh; font-weight:800; border-left:6px solid #888; }
.recent .chip small { display:block; font-size:1.5vh; font-weight:600; color:var(--muted); }

.right { display:grid; grid-template-rows:1fr 17vh; gap:1.4vh; min-height:0; }
.slides { position:relative; border-radius:18px; overflow:hidden; background:var(--panel); min-height:0; }
.slide { position:absolute; inset:0; opacity:0; transition:opacity 1s; display:flex; flex-direction:column; justify-content:flex-end; }
.slide.on { opacity:1; }
.slide .img { position:absolute; inset:0; background-size:cover; background-position:center; }
.slide video { position:absolute; inset:0; width:100%; height:100%; object-fit:cover; background:#000; }
.slide video:not([src]) { display:none; }
.slide .livebox { position:absolute; inset:0; }
.slide .livebox video, .slide .livebox iframe { position:absolute; inset:0; width:100%; height:100%; object-fit:contain; background:#000; border:0; }
.slide .livebox audio { display:none; }
.slide .livebox video-camera { position:absolute; inset:0; }
.slide.tv .img, .slide.camera .img { background-color:#000; }
.onair { position:absolute; inset:0 0 22% 0; display:flex; flex-direction:column; align-items:center; justify-content:center; gap:2.4vh; }
.onair img { max-width:46%; max-height:44%; object-fit:contain; background:#fff; border-radius:18px; padding:1.6vh; box-shadow:0 10px 40px rgba(0,0,0,.35); }
.onair .lbl { display:flex; align-items:center; gap:1vw; font-weight:800; font-size:2.6vh; letter-spacing:.14em; background:rgba(220,38,38,.92); padding:.8vh 1.4vw; border-radius:999px; }
.onair .eq { display:flex; align-items:flex-end; gap:.25vw; height:2.4vh; }
.onair .eq i { width:.35vw; background:#fff; border-radius:2px; animation:eq 1s ease-in-out infinite; }
.onair .eq i:nth-child(2) { animation-delay:-.3s; } .onair .eq i:nth-child(3) { animation-delay:-.6s; } .onair .eq i:nth-child(4) { animation-delay:-.15s; }
@keyframes eq { 0%,100% { height:25%; } 50% { height:100%; } }
.slide .cap { position:relative; padding:3vh 2vw 2.6vh; background:linear-gradient(transparent, rgba(0,0,0,.85) 45%); }
.slide .ttl { font-size:5.4vh; font-weight:800; line-height:1.05; }
.slide .stl { font-size:2.6vh; color:rgba(255,255,255,.88); margin-top:.6vh; }
.slide .price { position:absolute; top:2.4vh; right:1.6vw; background:var(--accent); color:#111; font-weight:800; font-size:4.4vh; padding:.8vh 1.4vw; border-radius:14px; }
.slide.noimg .img { background:linear-gradient(135deg,#0369a1,#38bdf8); }
.slides .dots { position:absolute; bottom:1.2vh; right:1.4vw; display:flex; gap:.5vw; }
.slides .dots i { width:1vh; height:1vh; border-radius:50%; background:rgba(255,255,255,.35); }
.slides .dots i.on { background:#fff; }
.bgradio { position:absolute; top:1.6vh; left:1.2vw; z-index:2; display:flex; align-items:center; gap:.7vw; max-width:60%; font-size:2vh; font-weight:700; background:rgba(0,0,0,.55); padding:.6vh 1.1vw; border-radius:999px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
.bgradio .eq { display:flex; align-items:flex-end; gap:.2vw; height:1.8vh; flex:none; }
.bgradio .eq i { width:.3vw; background:#fff; border-radius:2px; animation:eq 1s ease-in-out infinite; }
.bgradio .eq i:nth-child(2) { animation-delay:-.3s; } .bgradio .eq i:nth-child(3) { animation-delay:-.6s; }
.bgradio.quiet .eq i { animation:none; height:30%; }
.placeholder { position:absolute; inset:0; display:flex; align-items:center; justify-content:center; color:var(--muted); font-size:3vh; text-align:center; padding:2vw; }

.forecast { display:grid; grid-template-columns:repeat(4,1fr); gap:1vw; }
.day { background:var(--panel); border-radius:18px; display:flex; flex-direction:column; align-items:center; justify-content:center; }
.day .d { font-size:2vh; color:var(--muted); text-transform:capitalize; }
.day .ic { font-size:5vh; line-height:1.2; }
.day .mm { font-size:2.4vh; font-weight:700; }
.day .mm span { color:var(--muted); font-weight:600; }

/* News ticker */
.news { display:flex; align-items:center; background:var(--panel); border-radius:18px; overflow:hidden; }
.news .tag { background:#0ea5e9; height:100%; display:flex; align-items:center; padding:0 1.6vw; font-weight:800; font-size:2.4vh; letter-spacing:.06em; flex:none; }
.news .track { flex:1; overflow:hidden; white-space:nowrap; position:relative; height:100%; }
.news .run { position:absolute; top:50%; transform:translateY(-50%); white-space:nowrap; font-size:3vh; font-weight:600; will-change:left; }
.news .run span::after { content:'•'; color:var(--accent); margin:0 2vw; }

/* Full-screen call */
.call { position:fixed; inset:0; display:none; align-items:center; justify-content:center; flex-direction:column; z-index:20; background:rgba(8,47,73,.93); }
.call.show { display:flex; }
.call .box { border-radius:36px; padding:5vh 6vw; text-align:center; min-width:50vw; box-shadow:0 20px 80px rgba(0,0,0,.6); animation:pop .4s ease-out; }
.call .who { font-size:4.4vh; font-weight:700; text-transform:uppercase; letter-spacing:.06em; }
.call .big { font-size:26vh; font-weight:800; line-height:1; margin:2vh 0; }
.call .go { font-size:3.6vh; font-weight:600; }
@keyframes pop { from { transform:scale(.7); opacity:0; } }

.sound { position:fixed; bottom:11vh; right:2vw; background:#f59e0b; color:#111; padding:1.2vh 1.4vw; border-radius:12px; font-weight:700; font-size:2vh; cursor:pointer; z-index:30; display:none; }
.offline { position:fixed; top:1vh; left:50%; transform:translateX(-50%); background:#dc2626; padding:.6vh 1.2vw; border-radius:10px; font-weight:700; display:none; z-index:30; }
</style>
</head>
<body>
<div class="screen">
    <div class="top">
        <?= poweredBy() ?>
        <?php if ($logo): ?><img src="<?= $h($logo) ?>" alt="<?= $h($brand) ?>"><?php else: ?><div class="brand"><?= $h($brand) ?></div><?php endif; ?>
        <div class="clock"><div class="time" id="time">--:--</div><div class="date" id="date"></div></div>
        <div class="wnow" id="wnow" style="display:none"><div class="ic" id="wIc"></div><div><div class="t" id="wT"></div><div class="c" id="wC"></div></div></div>
    </div>

    <div class="mid">
        <div class="left">
            <div class="serving" id="serving"></div>
            <div class="recent"><h3>Ultimi chiamati</h3><div class="list" id="recent"></div></div>
        </div>
        <div class="right">
            <div class="slides" id="slides"><div class="placeholder">Aggiungi i prodotti da mostrare in Amministrazione › Eliminacode</div></div>
            <div class="forecast" id="forecast"></div>
        </div>
    </div>

    <div class="news"><div class="tag">NOTIZIE</div><div class="track" id="track"><div class="run" id="run"></div></div></div>
</div>

<div class="call" id="call"><div class="box" id="callBox"><div class="who" id="callWho"></div><div class="big" id="callNum"></div><div class="go">È il tuo turno!</div></div></div>
<div class="sound" id="sound">🔊 Tocca per attivare l'audio</div>
<div class="offline" id="offline">Connessione assente</div>

<script src="/assets/js/live-player.js?v=<?= @filemtime(__DIR__ . '/../assets/js/live-player.js') ?>"></script>
<script>
const KEY = <?= json_encode($key) ?>;
const API = '/api/queue.php';
const q = s => document.getElementById(s);
const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));

/* ---------- Clock ---------- */
function tick() {
    const d = new Date();
    q('time').textContent = d.toLocaleTimeString('it-IT', { hour: '2-digit', minute: '2-digit' });
    q('date').textContent = d.toLocaleDateString('it-IT', { weekday: 'long', day: 'numeric', month: 'long' });
}
tick(); setInterval(tick, 1000);

/* ---------- Sound ---------- */
let audio = null;
// Sound is on from the start when the browser allows it (the launcher in Amministrazione ›
// Eliminacode starts Chrome with --autoplay-policy=no-user-gesture-required); otherwise the
// first touch, click or key press (also a TV remote) turns it on.
function initAudio() {
    try {
        if (!audio) {
            audio = new (window.AudioContext || window.webkitAudioContext)();
            audio.onstatechange = soundBanner;
        }
        audio.resume().then(soundBanner, soundBanner);
    } catch (e) {}
    setTimeout(soundBanner, 300);
    try { unmuteTile(); } catch (e) {} // not ready yet on the first call, at page load
}
function soundBanner() {
    q('sound').style.display = (audio && audio.state === 'running') ? 'none' : 'block';
}
['pointerdown', 'touchstart', 'keydown', 'click'].forEach(ev => document.addEventListener(ev, initAudio, { passive: true }));
initAudio();
function chime() {
    if (audio && audio.state !== 'running') audio.resume().catch(() => {});
    if (!audio || audio.state !== 'running') return;
    [[880, 0], [660, .45]].forEach(([f, t]) => {
        const o = audio.createOscillator(), g = audio.createGain();
        o.type = 'sine'; o.frequency.value = f;
        g.gain.setValueAtTime(0, audio.currentTime + t);
        g.gain.linearRampToValueAtTime(.5, audio.currentTime + t + .03);
        g.gain.exponentialRampToValueAtTime(.001, audio.currentTime + t + .9);
        o.connect(g).connect(audio.destination);
        o.start(audio.currentTime + t); o.stop(audio.currentTime + t + 1);
    });
}
function speak(label, service) {
    if (!('speechSynthesis' in window) || !audio || audio.state !== 'running') return;
    const m = label.match(/^([A-Za-z]*)0*(\d+)$/);
    const text = 'Numero ' + (m ? (m[1] ? m[1] + ' ' : '') + m[2] : label) + (service ? ', ' + service : '');
    const u = new SpeechSynthesisUtterance(text);
    u.lang = 'it-IT'; u.rate = .95;
    setTimeout(() => speechSynthesis.speak(u), 1100);
}

/* ---------- Queue state ---------- */
let lastStamp = null, callTimer = null;
const lastCalled = {};
function renderServing(services) {
    const box = q('serving');
    const n = Math.max(1, services.length);
    box.style.gridTemplateRows = 'repeat(' + n + ', 1fr)';
    const size = n === 1 ? 30 : n === 2 ? 17 : n === 3 ? 12 : 9;
    box.innerHTML = services.map(s => `
        <div class="svc" data-id="${s.id}" style="background:${esc(s.color)}">
            <div class="lbl">${esc(s.name)} · ora serviamo</div>
            <div class="num" style="font-size:min(${size}vh, ${(36 / (0.68 * Math.max(4, (s.current || '').length))).toFixed(1)}vw)">${s.current ? esc(s.current) : '<span class="none">In attesa del primo numero</span>'}</div>
            <div class="meta">${s.waiting > 0 ? 'In attesa: ' + s.waiting : 'Nessuno in attesa'}</div>
        </div>`).join('') || '<div class="placeholder">Nessun servizio attivo</div>';
}
function showCall(s) {
    q('callBox').style.background = s.color;
    q('callWho').textContent = s.name;
    q('callNum').textContent = s.current;
    q('call').classList.add('show');
    muteVideosForCall();
    chime(); speak(s.current, s.name);
    const el = document.querySelector('.svc[data-id="' + s.id + '"]');
    if (el) el.classList.add('flash');
    clearTimeout(callTimer);
    callTimer = setTimeout(() => q('call').classList.remove('show'), 6000);
}
async function poll() {
    try {
        const r = await fetch(API + '?action=state&k=' + encodeURIComponent(KEY), { cache: 'no-store' });
        const j = await r.json();
        if (!j.success) throw new Error();
        q('offline').style.display = 'none';
        renderServing(j.services);
        const serving = j.services.map(s => s.current);
        q('recent').innerHTML = j.recent.filter(c => !serving.includes(c.label)).slice(0, 7).map(c =>
            `<div class="chip" style="border-left-color:${esc(c.color)}">${esc(c.label)}<small>${esc(c.time)}</small></div>`).join('');
        if (lastStamp !== null && j.stamp !== lastStamp) {
            // Which service changed: its id:count differs from the last poll.
            const changed = j.services.find(s => s.current && lastCalled[s.id] !== s.stamp);
            const pick = changed || j.services.find(s => s.current);
            if (pick) showCall(pick);
        }
        lastStamp = j.stamp;
        j.services.forEach(s => lastCalled[s.id] = s.stamp);
    } catch (e) {
        q('offline').style.display = 'block';
    }
}

/* ---------- Product tiles ---------- */
let slides = [], slideIdx = 0, slideSecs = 8, slideTimer = null, slidesSig = '';
// Tile videos are downloaded in full first (blob in memory), then played from there:
// no buffering on the TV and no traffic on every loop. A failed download retries in a minute.
const videoBlobs = {};
let callMuteUntil = 0;
function preloadVideo(url) {
    if (videoBlobs[url]) return;
    const e = videoBlobs[url] = { url: null };
    fetch(url).then(r => { if (!r.ok) throw new Error(r.status); return r.blob(); })
        .then(b => { e.url = URL.createObjectURL(b); attachVideo(url); })
        .catch(() => { delete videoBlobs[url]; setTimeout(() => { if (slides.some(s => s.video === url)) preloadVideo(url); }, 60000); });
}
function attachVideo(url) {
    document.querySelectorAll('.slide video[data-src]').forEach(v => {
        if (v.dataset.src !== url || v.src) return;
        v.src = videoBlobs[url].url;
        const els = [...document.querySelectorAll('.slide')];
        if (els.indexOf(v.closest('.slide')) === slideIdx) showSlide(slideIdx);
    });
}
function renderSlides() {
    const box = q('slides');
    clearTimeout(slideTimer);
    const keep = new Set(slides.map(s => s.video).filter(Boolean));
    Object.keys(videoBlobs).forEach(u => { if (!keep.has(u)) { if (videoBlobs[u].url) URL.revokeObjectURL(videoBlobs[u].url); delete videoBlobs[u]; } });
    stopLive();
    if (!slides.length) { box.innerHTML = '<div class="placeholder">Aggiungi i prodotti da mostrare in Amministrazione › Eliminacode</div>'; return; }
    box.innerHTML = slides.map((s, i) => `
        <div class="slide${s.image || s.video || s.live === 'tv' || s.live === 'camera' ? '' : ' noimg'}${s.live ? ' ' + s.live : ''}${i === 0 ? ' on' : ''}">
            <div class="img" ${s.image ? `style="background-image:url('${esc(s.image)}')"` : ''}></div>
            ${s.video ? `<video class="vid" data-src="${esc(s.video)}" muted playsinline preload="auto"${slides.length === 1 ? ' loop' : ''}></video>` : ''}
            ${s.live ? '<div class="livebox"></div>' : ''}
            ${s.live === 'radio' ? `<div class="onair">${s.logo && !s.image ? `<img src="${esc(s.logo)}" alt="" onerror="this.remove()">` : ''}<div class="lbl"><span class="eq"><i></i><i></i><i></i><i></i></span>IN ONDA</div></div>` : ''}
            ${s.price ? `<div class="price">${esc(s.price)}</div>` : ''}
            ${s.title || s.subtitle ? `<div class="cap">${s.title ? `<div class="ttl">${esc(s.title)}</div>` : ''}${s.subtitle ? `<div class="stl">${esc(s.subtitle)}</div>` : ''}</div>` : ''}
        </div>`).join('') + (slides.length > 1 ? '<div class="dots">' + slides.map((_, i) => `<i class="${i === 0 ? 'on' : ''}"></i>`).join('') + '</div>' : '')
        + (bgRadio ? `<div class="bgradio" id="bgradio"><span class="eq"><i></i><i></i><i></i></span>${esc(bgRadio.title || 'Radio')}</div>` : '');
    slides.forEach(s => { if (s.video) { if (videoBlobs[s.video] && videoBlobs[s.video].url) attachVideo(s.video); else preloadVideo(s.video); } });
    showSlide(0);
}
/**
 * A video tile still downloading is skipped while there is something else to show,
 * and so is a TV / radio channel that failed to start in the last 2 minutes.
 */
function ready(el, i) {
    const s = slides[i];
    if (s && s.live) return !(liveFailed[s.stream] > Date.now() - 120000);
    const v = el.querySelector('video.vid');
    return !v || !!v.src;
}
/* Live TV / radio (assets/js/live-player.js): only the tile on screen streams. */
let live = null;
const liveFailed = {};
function stopLive() { if (live) { live.stop(); live = null; } }
/** After the first touch / key (sound unlocked) the tile on screen gets its sound back. */
function unmuteTile() {
    bgSync();
    const s = slides[slideIdx];
    if (!s || !s.audio || Date.now() < callMuteUntil) return;
    if (live) live.mute(false);
    const v = document.querySelectorAll('.slide')[slideIdx]?.querySelector('video.vid');
    if (v && v.src) { v.muted = false; v.play().catch(() => {}); }
}
/*
 * Background radio (a radio tile set as "musica di sottofondo"): it plays all the time while the
 * tiles rotate, quiet during a call and while the tile on screen has its own sound.
 */
let bgRadio = null, bgLive = null, bgUrl = '', bgRetry = null;
const bgBox = document.createElement('div');
bgBox.style.display = 'none';
document.body.appendChild(bgBox);
function bgQuiet() {
    const s = slides[slideIdx];
    return Date.now() < callMuteUntil || !!(s && s.audio);
}
function bgSync() {
    const quiet = bgQuiet(), badge = document.getElementById('bgradio');
    if (badge) badge.classList.toggle('quiet', quiet);
    if (bgLive) bgLive.mute(quiet);
}
function bgStart() {
    clearTimeout(bgRetry);
    if (bgLive) { bgLive.stop(); bgLive = null; }
    bgUrl = bgRadio ? bgRadio.stream : '';
    if (!bgUrl) return;
    bgLive = livePlay(bgBox, bgUrl, {
        radio: true, muted: bgQuiet(), timeout: 30000,
        onFail: () => { bgLive = null; bgRetry = setTimeout(bgStart, 60000); }, // try again in a minute
    });
}
function showSlide(i) {
    clearTimeout(slideTimer);
    const els = [...document.querySelectorAll('.slide')], dots = document.querySelectorAll('.dots i');
    if (!els.length) return;
    for (let n = 0; n < els.length && !ready(els[i], i); n++) i = (i + 1) % els.length;
    slideIdx = i;
    stopLive();
    bgSync();
    els.forEach((el, k) => {
        el.classList.toggle('on', k === i);
        if (dots[k]) dots[k].classList.toggle('on', k === i);
        const v = el.querySelector('video.vid');
        if (v && k !== i) { v.pause(); v.onended = null; }
    });
    const next = () => showSlide((i + 1) % els.length);
    const s = slides[i];
    if (s && s.live) {
        const box = els[i].querySelector('.livebox');
        live = livePlay(box, s.stream, {
            radio: s.live === 'radio',
            camera: s.live === 'camera',
            muted: !s.audio || Date.now() < callMuteUntil,
            onFail: () => {
                live = null;
                liveFailed[s.stream] = Date.now();
                // A channel that does not start: on to the next tile, or try again in a minute when it is the only one.
                if (slideIdx !== i) return;
                clearTimeout(slideTimer);
                slideTimer = setTimeout(els.length > 1 ? next : () => showSlide(i), els.length > 1 ? 500 : 60000);
            },
        });
        if (els.length > 1) slideTimer = setTimeout(next, Math.max(10, s.live_secs || 60) * 1000);
        return;
    }
    const v = els[i].querySelector('video.vid');
    if (v && v.src) {
        try { v.currentTime = 0; } catch (e) {}
        playVideo(v, slides[i]);
        if (els.length > 1) {
            v.onended = next;
            // Safety net if "ended" never comes (stalled or broken file).
            slideTimer = setTimeout(next, ((isFinite(v.duration) && v.duration > 0 ? v.duration : 600) + 5) * 1000);
        }
        return;
    }
    if (els.length > 1) slideTimer = setTimeout(next, slideSecs * 1000);
}
function playVideo(v, s) {
    v.muted = !(s && s.audio) || Date.now() < callMuteUntil;
    // Sound needs the autoplay permission (monitor launcher); without it the video still plays, muted.
    v.play().catch(() => { v.muted = true; v.play().catch(() => {}); });
}
/** The video's own sound goes quiet while a number is called (chime + voice). */
function muteVideosForCall() {
    callMuteUntil = Date.now() + 7000;
    document.querySelectorAll('.slide video.vid').forEach(v => v.muted = true);
    if (live) live.mute(true);
    bgSync();
    setTimeout(() => {
        if (Date.now() < callMuteUntil) return;
        bgSync();
        const s = slides[slideIdx];
        if (!s || !s.audio) return;
        if (live) live.mute(false);
        const v = document.querySelectorAll('.slide')[slideIdx]?.querySelector('video.vid');
        if (v) v.muted = false;
    }, 7100);
}

/* ---------- Weather ---------- */
const WMO = c => c === 0 ? ['☀️', 'Sereno'] : c <= 2 ? ['🌤️', 'Poco nuvoloso'] : c === 3 ? ['☁️', 'Nuvoloso']
    : c <= 48 ? ['🌫️', 'Nebbia'] : c <= 57 ? ['🌦️', 'Pioviggine'] : c <= 67 ? ['🌧️', 'Pioggia']
    : c <= 77 ? ['🌨️', 'Neve'] : c <= 82 ? ['🌦️', 'Rovesci'] : c <= 86 ? ['🌨️', 'Neve'] : ['⛈️', 'Temporale'];
function renderWeather(w) {
    if (!w) { q('wnow').style.display = 'none'; q('forecast').innerHTML = ''; return; }
    const [ic, txt] = WMO(w.code);
    q('wIc').textContent = ic; q('wT').textContent = w.temp + '°';
    q('wC').textContent = w.city + ' · ' + txt;
    q('wnow').style.display = 'flex';
    q('forecast').innerHTML = w.days.map((d, i) => {
        const name = i === 0 ? 'Oggi' : new Date(d.date + 'T12:00').toLocaleDateString('it-IT', { weekday: 'long' });
        return `<div class="day"><div class="d">${esc(name)}</div><div class="ic">${WMO(d.code)[0]}</div><div class="mm">${d.max}° <span>${d.min}°</span></div></div>`;
    }).join('');
}

/* ---------- News ticker ---------- */
let newsSig = '', tickerX = 0, tickerW = 0;
function renderNews(list) {
    const sig = list.join('|');
    if (sig === newsSig) return;
    newsSig = sig;
    q('run').innerHTML = list.length ? list.map(t => `<span>${esc(t)}</span>`).join('') : '';
    tickerW = q('run').scrollWidth;
    tickerX = q('track').clientWidth;
}
let lastT = 0;
function animate(t) {
    const dt = lastT ? Math.min(100, t - lastT) : 0; lastT = t;
    if (tickerW) {
        tickerX -= dt * 0.11 * (window.innerWidth / 1920);
        if (tickerX < -tickerW) tickerX = q('track').clientWidth;
        q('run').style.left = tickerX + 'px';
    }
    requestAnimationFrame(animate);
}
requestAnimationFrame(animate);

async function feed() {
    try {
        const r = await fetch(API + '?action=feed&k=' + encodeURIComponent(KEY), { cache: 'no-store' });
        const j = await r.json();
        if (!j.success) return;
        renderWeather(j.weather);
        renderNews(j.news || []);
        const sig = JSON.stringify(j.slides) + j.seconds;
        if (sig !== slidesSig) {
            slidesSig = sig;
            bgRadio = j.slides.find(s => s.bg) || null;
            slides = j.slides.filter(s => !s.bg);
            slideSecs = j.seconds;
            renderSlides();
            if ((bgRadio ? bgRadio.stream : '') !== bgUrl) bgStart();
        }
    } catch (e) {}
}

poll(); setInterval(poll, 2000);
feed(); setInterval(feed, 5 * 60 * 1000);
// A daily reload keeps a 24/7 screen fresh.
setTimeout(() => location.reload(), 6 * 60 * 60 * 1000);
</script>
</body>
</html>
