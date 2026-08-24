/**
 * Gym141-Embed: bindet den Wochenplan dieser Installation in eine beliebige
 * bestehende Website ein. Einfach dort einfuegen, wo der Plan erscheinen soll:
 *
 *   <script src="https://DEINE-GYM141-ADRESSE/embed.js" defer></script>
 *
 * Das Skript erzeugt an seiner Stelle ein <iframe> mit dem Wochenplan und
 * passt dessen Hoehe automatisch an den Inhalt an.
 */
(function () {
    'use strict';

    var script = document.currentScript;
    if (!script) { return; }

    // https://instanz/pfad/embed.js -> https://instanz/pfad
    var base = script.src.replace(/\/embed\.js(\?.*)?$/, '');

    var frame = document.createElement('iframe');
    frame.src = base + '/embed/wochenplan';
    frame.title = 'Wochenplan';
    frame.loading = 'lazy';
    frame.style.cssText = 'width:100%;border:0;display:block;min-height:200px';
    frame.setAttribute('scrolling', 'no');

    script.parentNode.insertBefore(frame, script.nextSibling);

    window.addEventListener('message', function (event) {
        if (event.source === frame.contentWindow
            && event.data && typeof event.data.gym141Height === 'number') {
            frame.style.height = Math.ceil(event.data.gym141Height) + 'px';
        }
    });
})();
