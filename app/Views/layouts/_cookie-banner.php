<?php

use App\Models\Setting;

/**
 * Cookie-Banner (Teilstueck fuer public/member/member-blank-Layouts).
 *
 * Konfigurierbar unter Einstellungen -> "Cookie-Banner": an/aus und Text.
 * Die Wahl (alle / nur notwendige) landet als Cookie "gym141_consent"
 * (365 Tage) - der Banner erscheint erst wieder, wenn es fehlt/ablaeuft.
 */
if (Setting::get('cookie_banner', '1') === '0') {
    return;
}

$bannerText = trim(Setting::get('cookie_banner_text'));

if ($bannerText === '') {
    $bannerText = 'Diese Website verwendet nur technisch notwendige Cookies '
        . '(z. B. für die Anmeldung). Eingebettete Inhalte Dritter werden erst '
        . 'nach einem Klick geladen.';
}
?>
<div id="cookie-banner" class="cookie-banner" role="dialog" aria-live="polite"
     aria-label="Cookie-Hinweis" hidden>
    <div class="cookie-banner__inner">
        <p class="cookie-banner__text">
            <?= e($bannerText) ?>
            <a href="<?= e(url('/datenschutz')) ?>">Mehr in der Datenschutzerklärung</a>
        </p>
        <div class="cookie-banner__actions">
            <button type="button" class="btn btn--primary" data-consent="all">Alle akzeptieren</button>
            <button type="button" class="btn btn--ghost" data-consent="necessary">Nur notwendige</button>
        </div>
    </div>
</div>
<script>
(function () {
    var banner = document.getElementById('cookie-banner');

    if (!banner || /(?:^|;\s*)gym141_consent=/.test(document.cookie)) {
        return;
    }

    banner.hidden = false;

    banner.querySelectorAll('[data-consent]').forEach(function (knopf) {
        knopf.addEventListener('click', function () {
            var ablauf = new Date(Date.now() + 365 * 864e5).toUTCString();
            document.cookie = 'gym141_consent=' + knopf.dataset.consent
                + '; expires=' + ablauf + '; path=/; SameSite=Lax'
                + (location.protocol === 'https:' ? '; Secure' : '');
            banner.hidden = true;
        });
    });
})();
</script>
