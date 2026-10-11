/* Gym141 – Wiedergabe einer Trainingseinheit: Karte (Leaflet + Geoapify-Kacheln),
   Herzfrequenz-Kurve (Canvas) und Zeitleiste wie in einem Schnittprogramm.
   Aufruf aus Blazor: workout.init(elementId, data, options) / play / pause / seek / speed / dispose */
window.workout = (function () {
    "use strict";

    var LEAFLET_JS  = "https://unpkg.com/leaflet@1.9.4/dist/leaflet.js";
    var LEAFLET_CSS = "https://unpkg.com/leaflet@1.9.4/dist/leaflet.css";

    var S = null; // aktueller Zustand (eine Wiedergabe je Seite)

    function loadLeaflet() {
        if (window.L) { return Promise.resolve(); }
        return new Promise(function (resolve, reject) {
            if (!document.querySelector('link[href="' + LEAFLET_CSS + '"]')) {
                var css = document.createElement("link"); css.rel = "stylesheet"; css.href = LEAFLET_CSS; document.head.appendChild(css);
            }
            var s = document.createElement("script"); s.src = LEAFLET_JS; s.onload = resolve; s.onerror = reject; document.head.appendChild(s);
        });
    }

    function fmt(t) {
        t = Math.max(0, Math.round(t));
        var h = Math.floor(t / 3600), m = Math.floor((t % 3600) / 60), s = t % 60;
        return (h > 0 ? h + ":" + (m < 10 ? "0" : "") : "") + m + ":" + (s < 10 ? "0" : "") + s;
    }

    /* Wert zum Zeitpunkt t: letzter Messwert davor (Index-Suche binär) */
    function at(list, t, key) {
        if (!list.length) { return null; }
        var lo = 0, hi = list.length - 1;
        if (t < list[0].t) { return null; }
        while (lo < hi) { var mid = (lo + hi + 1) >> 1; if (list[mid].t <= t) { lo = mid; } else { hi = mid - 1; } }
        return key ? list[lo][key] : list[lo];
    }

    function posAt(t) {
        var tr = S.track; if (tr.length < 2) { return tr.length ? [tr[0].lat, tr[0].lon] : null; }
        if (t <= tr[0].t) { return [tr[0].lat, tr[0].lon]; }
        var last = tr[tr.length - 1]; if (t >= last.t) { return [last.lat, last.lon]; }
        var lo = 0, hi = tr.length - 1;
        while (lo < hi) { var mid = (lo + hi + 1) >> 1; if (tr[mid].t <= t) { lo = mid; } else { hi = mid - 1; } }
        var a = tr[lo], b = tr[lo + 1], f = (t - a.t) / Math.max(0.001, b.t - a.t);
        return [a.lat + (b.lat - a.lat) * f, a.lon + (b.lon - a.lon) * f];
    }

    function distAt(t) { // Meter bis t (vorberechnete Summen)
        var tr = S.track; if (tr.length < 2) { return 0; }
        var i = 0, lo = 0, hi = tr.length - 1;
        if (t <= tr[0].t) { return 0; }
        if (t >= tr[hi].t) { return S.cum[hi]; }
        while (lo < hi) { var mid = (lo + hi + 1) >> 1; if (tr[mid].t <= t) { lo = mid; } else { hi = mid - 1; } }
        i = lo; if (i >= tr.length - 1) { return S.cum[i]; }
        var f = (t - tr[i].t) / Math.max(0.001, tr[i + 1].t - tr[i].t);
        return S.cum[i] + (S.cum[i + 1] - S.cum[i]) * f;
    }

    function haversine(a, b) {
        var R = 6371000, dLat = (b.lat - a.lat) * Math.PI / 180, dLon = (b.lon - a.lon) * Math.PI / 180;
        var h = Math.sin(dLat / 2) * Math.sin(dLat / 2) + Math.cos(a.lat * Math.PI / 180) * Math.cos(b.lat * Math.PI / 180) * Math.sin(dLon / 2) * Math.sin(dLon / 2);
        return 2 * R * Math.asin(Math.sqrt(h));
    }

    /* ---------------------------------------------------------------- Karte --- */
    function initMap(root) {
        var el = root.querySelector(".wo-map");
        if (!el || !S.track.length) { if (el) { el.style.display = "none"; } return; }
        var map = L.map(el, { zoomControl: true, attributionControl: true });
        var key = S.opt.geoapifyKey || "";
        var layer = key
            ? L.tileLayer("https://maps.geoapify.com/v1/tile/osm-bright/{z}/{x}/{y}.png?apiKey=" + encodeURIComponent(key), { maxZoom: 20, attribution: 'Powered by <a href="https://www.geoapify.com/">Geoapify</a> | © OpenStreetMap' })
            : L.tileLayer("https://tile.openstreetmap.org/{z}/{x}/{y}.png", { maxZoom: 19, attribution: "© OpenStreetMap" });
        layer.addTo(map);

        var latlngs = S.track.map(function (p) { return [p.lat, p.lon]; });
        L.polyline(latlngs, { color: "#8a93a0", weight: 5, opacity: 0.6 }).addTo(map);
        S.done = L.polyline([], { color: S.opt.color || "#f2c14e", weight: 5, opacity: 0.95 }).addTo(map);
        L.circleMarker(latlngs[0], { radius: 6, color: "#7bd88f", fillColor: "#7bd88f", fillOpacity: 1 }).addTo(map).bindTooltip("Start");
        L.circleMarker(latlngs[latlngs.length - 1], { radius: 6, color: "#ff8a8a", fillColor: "#ff8a8a", fillOpacity: 1 }).addTo(map).bindTooltip("Ziel");
        S.head = L.circleMarker(latlngs[0], { radius: 9, color: "#111417", weight: 2, fillColor: S.opt.color || "#f2c14e", fillOpacity: 1 }).addTo(map);
        var bounds = L.latLngBounds(latlngs);
        map.fitBounds(bounds, { padding: [24, 24] });
        // Container bekommt seine Groesse oft erst nach dem Layout (Blazor/Kartenkachel) – dann neu einpassen
        setTimeout(function () { map.invalidateSize(); map.fitBounds(bounds, { padding: [24, 24] }); }, 150);
        S.map = map;
    }

    /* ------------------------------------------------------------- HR-Kurve --- */
    function drawChart() {
        var c = S.canvas; if (!c) { return; }
        var dpr = window.devicePixelRatio || 1, W = c.clientWidth, H = c.clientHeight;
        if (c.width !== W * dpr || c.height !== H * dpr) { c.width = W * dpr; c.height = H * dpr; }
        var ctx = c.getContext("2d"); ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
        ctx.clearRect(0, 0, W, H);
        var padL = 34, padR = 8, padT = 8, padB = 18, w = W - padL - padR, h = H - padT - padB;
        var dur = S.duration || 1;
        var hr = S.hr;
        var minV = 60, maxV = 180;
        if (hr.length) {
            minV = Math.min.apply(null, hr.map(function (s) { return s.bpm; })) - 10;
            maxV = Math.max.apply(null, hr.map(function (s) { return s.bpm; })) + 10;
            minV = Math.max(30, Math.floor(minV / 10) * 10); maxV = Math.ceil(maxV / 10) * 10;
        }
        var x = function (t) { return padL + (t / dur) * w; };
        var y = function (v) { return padT + h - ((v - minV) / (maxV - minV)) * h; };

        // Gitter + Achsen
        ctx.strokeStyle = "#2a3138"; ctx.lineWidth = 1; ctx.fillStyle = "#9aa0a6"; ctx.font = "11px sans-serif"; ctx.textAlign = "right";
        for (var v = minV; v <= maxV; v += (maxV - minV) > 80 ? 40 : 20) {
            ctx.beginPath(); ctx.moveTo(padL, y(v)); ctx.lineTo(W - padR, y(v)); ctx.stroke();
            ctx.fillText(String(v), padL - 4, y(v) + 4);
        }
        ctx.textAlign = "center";
        var step = dur > 3600 ? 900 : dur > 1200 ? 300 : dur > 300 ? 60 : 30;
        for (var t = 0; t <= dur; t += step) { ctx.fillText(fmt(t), x(t), H - 4); }

        if (hr.length) {
            // Fläche
            ctx.beginPath(); ctx.moveTo(x(hr[0].t), y(hr[0].bpm));
            hr.forEach(function (s) { ctx.lineTo(x(s.t), y(s.bpm)); });
            ctx.lineTo(x(hr[hr.length - 1].t), padT + h); ctx.lineTo(x(hr[0].t), padT + h); ctx.closePath();
            ctx.fillStyle = "rgba(255,110,110,0.12)"; ctx.fill();
            // Linie
            ctx.beginPath(); ctx.moveTo(x(hr[0].t), y(hr[0].bpm));
            hr.forEach(function (s) { ctx.lineTo(x(s.t), y(s.bpm)); });
            ctx.strokeStyle = "#ff6e6e"; ctx.lineWidth = 2; ctx.stroke();
        } else {
            ctx.fillStyle = "#9aa0a6"; ctx.fillText("keine Herzfrequenz aufgezeichnet", padL + w / 2, padT + h / 2);
        }

        // Abspielposition
        var px = x(S.t);
        ctx.strokeStyle = S.opt.color || "#f2c14e"; ctx.lineWidth = 2;
        ctx.beginPath(); ctx.moveTo(px, padT); ctx.lineTo(px, padT + h); ctx.stroke();
        var bpm = at(hr, S.t, "bpm");
        if (bpm !== null) {
            ctx.fillStyle = "#ff6e6e"; ctx.beginPath(); ctx.arc(px, y(bpm), 5, 0, Math.PI * 2); ctx.fill();
        }
        S.chartGeom = { padL: padL, w: w };
    }

    /* ------------------------------------------------------------ Anzeige ---- */
    function render() {
        var t = S.t;
        drawChart();
        if (S.map && S.head) {
            var p = posAt(t);
            if (p) {
                S.head.setLatLng(p);
                var pts = [];
                for (var i = 0; i < S.track.length && S.track[i].t <= t; i++) { pts.push([S.track[i].lat, S.track[i].lon]); }
                pts.push(p);
                S.done.setLatLngs(pts);
                if (S.follow && S.playing) { S.map.panTo(p, { animate: false }); }
            }
        }
        var bpm = at(S.hr, t, "bpm");
        set(".wo-time", fmt(t) + " / " + fmt(S.duration));
        set(".wo-bpm", bpm === null ? "–" : bpm + " bpm");
        set(".wo-dist", S.track.length > 1 ? (distAt(t) / 1000).toFixed(2) + " km" : "–");
        var sp = at(S.track, t); var v = sp && sp.v != null ? sp.v : null;
        set(".wo-pace", v === null ? "–" : (v * 3.6).toFixed(1) + " km/h");
        if (S.slider && !S.dragging) { S.slider.value = String(t); }
    }
    function set(sel, txt) { var el = S.root.querySelector(sel); if (el) { el.textContent = txt; } }

    /* Wiedergabe-Schleife per Timer (requestAnimationFrame pausiert in unsichtbaren WebViews) */
    function loop() {
        if (!S || !S.playing) { return; }
        var now = performance.now(), dt = (now - S.lastFrame) / 1000; S.lastFrame = now;
        S.t += dt * S.speed;
        if (S.t >= S.duration) { S.t = S.duration; S.playing = false; setPlayIcon(); }
        render();
        if (!S.playing && S.timer) { clearInterval(S.timer); S.timer = null; }
    }
    function setPlayIcon() {
        var b = S.root.querySelector(".wo-play"); if (b) { b.textContent = S.playing ? "⏸" : "▶"; }
    }

    return {
        init: async function (rootId, data, opt) {
            this.dispose();
            var root = document.getElementById(rootId); if (!root) { return; }
            var track = (data.track || []).slice().sort(function (a, b) { return a.t - b.t; });
            var cum = [0];
            for (var i = 1; i < track.length; i++) { cum.push(cum[i - 1] + haversine(track[i - 1], track[i])); }
            S = { root: root, track: track, cum: cum, hr: (data.hr || []).slice().sort(function (a, b) { return a.t - b.t; }),
                  duration: Math.max(1, data.durationS || (track.length ? track[track.length - 1].t : 0)),
                  t: 0, playing: false, speed: 1, follow: true, opt: opt || {}, canvas: root.querySelector(".wo-chart"), slider: root.querySelector(".wo-slider") };
            if (S.slider) {
                S.slider.max = String(S.duration); S.slider.step = "0.5"; S.slider.value = "0";
                S.slider.addEventListener("input", function () { S.dragging = true; S.t = parseFloat(S.slider.value); render(); });
                S.slider.addEventListener("change", function () { S.dragging = false; });
            }
            if (S.canvas) {
                var seekFromEvent = function (ev) {
                    var r = S.canvas.getBoundingClientRect(), g = S.chartGeom || { padL: 34, w: r.width - 42 };
                    var cx = (ev.touches ? ev.touches[0].clientX : ev.clientX) - r.left;
                    S.t = Math.min(S.duration, Math.max(0, (cx - g.padL) / g.w * S.duration)); render();
                };
                var down = false;
                S.canvas.addEventListener("mousedown", function (e) { down = true; seekFromEvent(e); });
                window.addEventListener("mousemove", function (e) { if (down) { seekFromEvent(e); } });
                window.addEventListener("mouseup", function () { down = false; });
                S.canvas.addEventListener("touchstart", function (e) { seekFromEvent(e); }, { passive: true });
                S.canvas.addEventListener("touchmove", function (e) { seekFromEvent(e); }, { passive: true });
                S.resize = function () { drawChart(); }; window.addEventListener("resize", S.resize);
            }
            var play = root.querySelector(".wo-play");
            if (play) { play.addEventListener("click", function () { window.workout.toggle(); }); }
            if (track.length) {
                try { await loadLeaflet(); initMap(root); }
                catch (e) { var m = root.querySelector(".wo-map"); if (m) { m.innerHTML = "<p class='wo-mapfail'>Karte konnte nicht geladen werden (offline?).</p>"; } }
            } else {
                var me = root.querySelector(".wo-map"); if (me) { me.style.display = "none"; }
            }
            render();
        },
        toggle: function () { if (!S) { return; } if (S.playing) { this.pause(); } else { this.play(); } },
        play: function () { if (!S) { return; } if (S.t >= S.duration) { S.t = 0; } S.playing = true; S.lastFrame = performance.now(); setPlayIcon(); if (!S.timer) { S.timer = setInterval(loop, 40); } },
        pause: function () { if (!S) { return; } S.playing = false; setPlayIcon(); if (S.timer) { clearInterval(S.timer); S.timer = null; } },
        seek: function (t) { if (!S) { return; } S.t = Math.min(S.duration, Math.max(0, t)); render(); },
        speed: function (f) { if (S) { S.speed = f; } },
        follow: function (on) { if (S) { S.follow = !!on; } },
        debug: function () { return S && S.map ? { zoom: S.map.getZoom(), size: S.map.getSize(), bounds: S.map.getBounds(), n: S.track.length, first: S.track[0], last: S.track[S.track.length - 1] } : null; },
        dispose: function () {
            if (!S) { return; }
            S.playing = false;
            if (S.timer) { clearInterval(S.timer); }
            if (S.resize) { window.removeEventListener("resize", S.resize); }
            if (S.map) { try { S.map.remove(); } catch (e) { /* egal */ } }
            S = null;
        }
    };
})();
