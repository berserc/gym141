/**
 * Bindet TinyMCE (selbst gehostet unter assets/vendor/tinymce) an alle
 * Textfelder mit der Klasse "js-richtext".
 *
 * Der Werkzeugkasten ist bewusst auf die Tags beschränkt, die der Server
 * ohnehin zulässt (siehe safe_html() in app/helpers.php) – was hier nicht
 * eingegeben werden kann, wird dort auch nicht verworfen.
 *
 * Lädt TinyMCE nicht (Datei fehlt, altes Gerät), bleibt das normale
 * Textfeld voll funktionsfähig.
 */
(function () {
    'use strict';

    if (typeof window.tinymce === 'undefined') {
        return;
    }

    var felder = document.querySelectorAll('textarea.js-richtext');

    if (felder.length === 0) {
        return;
    }

    // Jedes Feld einzeln initialisieren, damit die Hoehe (data-height)
    // schon beim Aufbau feststeht.
    felder.forEach(function (feld) {
        window.tinymce.init({
            target: feld,
            license_key: 'gpl',
            language: 'de',

            plugins: 'lists link table code autolink searchreplace',
            menubar: false,
            toolbar: 'undo redo | blocks | bold italic underline | ' +
                     'bullist numlist | link table hr | searchreplace code',
            block_formats: 'Absatz=p; Überschrift 2=h2; Überschrift 3=h3; Überschrift 4=h4',

            // Spiegelt die Server-Whitelist aus safe_html()
            valid_elements: 'p,br,strong/b,em/i,u,ul,ol,li,h2,h3,h4,' +
                            'a[href|target|rel],blockquote,hr,' +
                            'table,thead,tbody,tr,th,td,small',

            height: parseInt(feld.dataset.height || '340', 10),
            resize: true,
            branding: false,
            promotion: false,
            convert_urls: false,
            entity_encoding: 'raw'
        });
    });
})();
