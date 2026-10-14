/**
 * Live TV / radio player of the monitor tiles (queue/monitor.php) and of the
 * "Prova" button in Amministrazione › Eliminacode.
 *
 *   const p = livePlay(box, url, { radio, muted, controls, timeout, onFail(why) });
 *   p.mute(true|false); p.stop();
 *
 * YouTube links play in the YouTube player (embed); HLS (.m3u8) through hls.js, loaded
 * only when needed; anything else (MP3 / AAC radio, MP4) straight in <video>/<audio>.
 * onFail fires when the stream does not start within `timeout` ms or breaks.
 */
(function () {
    const HLS_JS = 'https://cdnjs.cloudflare.com/ajax/libs/hls.js/1.5.13/hls.min.js';
    let hlsLoading = null;

    function loadHls() {
        if (window.Hls) return Promise.resolve(window.Hls);
        return hlsLoading || (hlsLoading = new Promise((ok, ko) => {
            const s = document.createElement('script');
            s.src = HLS_JS;
            s.onload = () => ok(window.Hls);
            s.onerror = () => { hlsLoading = null; ko(new Error('hls.js')); };
            document.head.appendChild(s);
        }));
    }

    /** YouTube video / live id, or "channel:UC…" for a channel's live stream; null when not YouTube. */
    function youtube(url) {
        let m = url.match(/(?:youtube\.com\/(?:watch\?(?:.*&)?v=|embed\/|live\/|shorts\/)|youtu\.be\/)([\w-]{11})/);
        if (m) return m[1];
        m = url.match(/youtube\.com\/channel\/(UC[\w-]{22})/);
        return m ? 'channel:' + m[1] : null;
    }

    /**
     * IP camera through go2rtc on the server (LAN only): its own player (MSE over a websocket),
     * proxied by Apache at /go2rtc/. url = "/go2rtc/api/ws?src=<name>".
     */
    let camLoading = null;
    function loadCamera() {
        return camLoading || (camLoading = import('/go2rtc/video-rtc.js').then(m => {
            if (!customElements.get('video-camera')) {
                customElements.define('video-camera', class extends m.VideoRTC {
                    constructor() {
                        super();
                        this.mode = 'mse';          // no WebRTC ports to open: MSE over the websocket
                        this.media = 'video';       // cameras: picture only
                        this.visibilityCheck = false;
                    }
                    oninit() {
                        super.oninit();
                        this.video.controls = false;
                        this.video.muted = true;
                        this.video.style.objectFit = 'contain';
                        this.video.style.background = '#000';
                    }
                });
            }
        }).catch(e => { camLoading = null; throw e; }));
    }
    function playCamera(box, url, opt) {
        let dead = false, el = null;
        const failTimer = setTimeout(() => fail('timeout'), opt.timeout || 20000);
        const stop = () => {
            if (dead) return;
            dead = true;
            clearTimeout(failTimer);
            if (el) { try { el.ondisconnect(); } catch (e) {} el.remove(); }
        };
        const fail = why => { if (dead) return; stop(); if (opt.onFail) opt.onFail(why); };
        loadCamera().then(() => {
            if (dead) return;
            el = document.createElement('video-camera');
            el.className = 'live';
            el.style.display = 'block';
            box.appendChild(el);
            el.video.addEventListener('playing', () => clearTimeout(failTimer));
            el.src = url;
        }, () => fail('go2rtc'));
        return { stop, mute() {} };
    }

    window.livePlay = function (box, url, opt = {}) {
        if (opt.camera) return playCamera(box, url, opt);
        let dead = false, hls = null, failTimer = null, el;
        const stop = () => {
            if (dead) return;
            dead = true;
            clearTimeout(failTimer);
            if (hls) { try { hls.destroy(); } catch (e) {} }
            if (el) {
                if (el.pause) { try { el.pause(); el.removeAttribute('src'); el.load(); } catch (e) {} }
                el.remove();
            }
        };
        const fail = why => { if (dead) return; stop(); if (opt.onFail) opt.onFail(why); };

        if (location.protocol === 'https:' && /^http:/i.test(url)) {
            setTimeout(() => fail('mixed'), 0); // a https page may not load an http stream
            return { stop, mute() {} };
        }

        const yt = youtube(url);
        if (yt) {
            el = document.createElement('iframe');
            el.className = 'live';
            el.allow = 'autoplay; encrypted-media';
            el.src = 'https://www.youtube.com/embed/' + (yt.startsWith('channel:') ? 'live_stream?channel=' + yt.slice(8) + '&' : yt + '?')
                + 'autoplay=1&mute=' + (opt.muted ? 1 : 0) + '&controls=' + (opt.controls ? 1 : 0) + '&enablejsapi=1&playsinline=1&rel=0';
            box.appendChild(el);
            const cmd = func => { try { el.contentWindow.postMessage(JSON.stringify({ event: 'command', func, args: [] }), '*'); } catch (e) {} };
            return { stop, mute: m => cmd(m ? 'mute' : 'unMute') };
        }

        el = document.createElement(opt.radio ? 'audio' : 'video');
        el.className = 'live';
        el.autoplay = true;
        el.playsInline = true;
        el.muted = !!opt.muted;
        if (opt.controls) el.controls = true;
        box.appendChild(el);
        el.addEventListener('error', () => fail('error'));
        el.addEventListener('playing', () => clearTimeout(failTimer));
        failTimer = setTimeout(() => fail('timeout'), opt.timeout || 20000);
        // Sound needs the autoplay permission (monitor launcher); without it the stream still plays, muted.
        const start = () => el.play().catch(() => { if (dead) return; el.muted = true; el.play().catch(() => {}); });
        const native = () => { el.src = url; start(); };

        if (/\.m3u8|m3u8\?|format=m3u8|relinker/i.test(url)) {
            loadHls().then(Hls => {
                if (dead) return;
                if (!Hls.isSupported()) return el.canPlayType('application/vnd.apple.mpegurl') ? native() : fail('hls');
                hls = new Hls({ maxBufferLength: 20 });
                hls.on(Hls.Events.ERROR, (e, d) => { if (d.fatal) fail('hls: ' + d.details); });
                hls.on(Hls.Events.MANIFEST_PARSED, start);
                hls.loadSource(url);
                hls.attachMedia(el);
            }, () => (el.canPlayType('application/vnd.apple.mpegurl') ? native() : fail('hls.js')));
        } else {
            native();
        }
        return { stop, mute(m) { if (dead) return; el.muted = m; if (!m) el.play().catch(() => {}); } };
    };
})();
