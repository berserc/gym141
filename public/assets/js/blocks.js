/**
 * Frontend-Verhalten der Inhaltsblöcke: Galerie-Lightbox und
 * YouTube-Fassade (Video wird erst nach Klick von YouTube geladen).
 * Ohne Abhängigkeiten; wird nur aktiv, wenn passende Elemente existieren.
 */
(function () {
    'use strict';

    // ------------------------------------------------------------ Lightbox --
    var links = Array.prototype.slice.call(document.querySelectorAll('.js-lightbox'));

    if (links.length > 0) {
        var overlay = document.createElement('div');
        overlay.className = 'lightbox';
        overlay.innerHTML =
            '<button class="lightbox__close" aria-label="Schließen">×</button>' +
            '<button class="lightbox__nav lightbox__nav--prev" aria-label="Vorheriges Bild">‹</button>' +
            '<figure class="lightbox__figure"><img class="lightbox__img" alt="">' +
            '<figcaption class="lightbox__caption"></figcaption></figure>' +
            '<button class="lightbox__nav lightbox__nav--next" aria-label="Nächstes Bild">›</button>';
        document.body.appendChild(overlay);

        var img     = overlay.querySelector('.lightbox__img');
        var caption = overlay.querySelector('.lightbox__caption');
        var aktuell = -1;

        function zeige(index) {
            aktuell = (index + links.length) % links.length;
            img.src = links[aktuell].getAttribute('href');
            var text = links[aktuell].getAttribute('data-caption') || '';
            caption.textContent = text;
            caption.style.display = text === '' ? 'none' : '';
            overlay.classList.add('lightbox--offen');
            document.body.style.overflow = 'hidden';
        }

        function schliesse() {
            overlay.classList.remove('lightbox--offen');
            document.body.style.overflow = '';
            img.src = '';
        }

        links.forEach(function (link, index) {
            link.addEventListener('click', function (event) {
                event.preventDefault();
                zeige(index);
            });
        });

        overlay.addEventListener('click', function (event) {
            if (event.target === overlay || event.target.classList.contains('lightbox__close')) {
                schliesse();
            }
        });
        overlay.querySelector('.lightbox__nav--prev').addEventListener('click', function () { zeige(aktuell - 1); });
        overlay.querySelector('.lightbox__nav--next').addEventListener('click', function () { zeige(aktuell + 1); });

        document.addEventListener('keydown', function (event) {
            if (!overlay.classList.contains('lightbox--offen')) { return; }
            if (event.key === 'Escape') { schliesse(); }
            if (event.key === 'ArrowLeft') { zeige(aktuell - 1); }
            if (event.key === 'ArrowRight') { zeige(aktuell + 1); }
        });
    }

    // ----------------------------------------------------- YouTube-Fassade --
    document.querySelectorAll('.js-youtube').forEach(function (facade) {
        facade.addEventListener('click', function () {
            var id = facade.getAttribute('data-video') || '';
            if (id === '') { return; }

            var frame = document.createElement('iframe');
            frame.src = 'https://www.youtube-nocookie.com/embed/' + encodeURIComponent(id) + '?autoplay=1';
            frame.className = 'video-embed';
            frame.setAttribute('allow', 'autoplay; encrypted-media; picture-in-picture');
            frame.setAttribute('allowfullscreen', '');
            frame.setAttribute('title', 'YouTube-Video');

            facade.replaceWith(frame);
        });
    });
})();
