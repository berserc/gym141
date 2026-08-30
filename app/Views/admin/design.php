<?php

/**
 * Design-Baukasten: Farben per Farbwaehler, Schriftauswahl, Live-Vorschau.
 *
 * @var array<string, array{0:list<string>,1:string,2:string,3:string}> $colors
 * @var array<string, array{0:string,1:string}>                          $fonts
 * @var array<string,string>                                             $werte
 * @var string                                                           $font
 * @var bool                                                             $custom
 */
?>

<div class="page-head">
    <h1>Design</h1>
    <p class="muted">Farben und Schrift der öffentlichen Website – einfach zusammenklicken.
       Änderungen gelten sofort für Website und Mitgliederbereich; die Verwaltung bleibt unverändert.</p>
</div>

<form method="post" action="<?= e(url('/admin/design')) ?>" id="designform">
    <?= csrf_field() ?>

    <div class="grid-2" style="align-items:start">
        <div class="card">
            <h2>Farben</h2>

            <?php foreach ($colors as $key => [$vars, $label, $hint, $standard]): ?>
                <div class="form-row" style="display:flex;align-items:center;gap:.75rem;margin-bottom:.7rem">
                    <input type="color" id="<?= e($key) ?>" name="<?= e($key) ?>"
                           value="<?= e($werte[$key]) ?>" data-vars="<?= e(implode(',', $vars)) ?>"
                           style="width:3rem;height:2.4rem;padding:2px;border-radius:8px;cursor:pointer">
                    <div style="flex:1">
                        <label for="<?= e($key) ?>" style="margin:0"><strong><?= e($label) ?></strong></label>
                        <div class="muted" style="font-size:.85rem"><?= e($hint) ?></div>
                    </div>
                    <code class="hexwert" data-for="<?= e($key) ?>"><?= e($werte[$key]) ?></code>
                </div>
            <?php endforeach; ?>

            <h2 style="margin-top:1.4rem">Schrift</h2>
            <select name="theme_font" id="theme_font">
                <?php foreach ($fonts as $fkey => [$flabel, $fstack]): ?>
                    <option value="<?= e($fkey) ?>" data-stack="<?= e($fstack) ?>"
                            style="font-family:<?= e($fstack) ?>" <?= $font === $fkey ? 'selected' : '' ?>>
                        <?= e($flabel) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <div style="display:flex;gap:.6rem;margin-top:1.3rem;flex-wrap:wrap">
                <button type="submit" class="btn btn-primary">Design speichern</button>
                <?php if ($custom): ?>
                    <button type="submit" name="reset" value="1" class="btn"
                            onclick="return confirm('Wirklich alle Design-Anpassungen verwerfen und zum Standard zurückkehren?')">
                        Auf Standard zurücksetzen
                    </button>
                <?php endif; ?>
            </div>
        </div>

        <div class="card">
            <h2>Vorschau</h2>
            <p class="muted" style="font-size:.85rem">So wirkt die Startseite mit deinen Farben:</p>

            <div id="vorschau" style="border-radius:12px;overflow:hidden;border:1px solid var(--line)">
                <div data-el="kopf" style="padding:1.1rem 1.2rem;display:flex;justify-content:space-between;align-items:center">
                    <strong data-el="marke" style="letter-spacing:.05em"><?= e($settings['club_name'] ?? 'Mein Verein') ?></strong>
                    <span data-el="knopf" style="padding:.4rem .9rem;border-radius:8px;font-weight:700;font-size:.85rem">Training</span>
                </div>
                <div data-el="held" style="padding:1.6rem 1.2rem 1.4rem">
                    <div data-el="titel" style="font-size:1.35rem;font-weight:800;margin-bottom:.4rem">Willkommen beim Verein</div>
                    <div data-el="text" style="font-size:.92rem;line-height:1.5">
                        Komm zum kostenlosen Probetraining – alle Zeiten findest du im
                        <span data-el="verweis" style="text-decoration:underline">Wochenplan</span>.
                    </div>
                </div>
                <div style="display:flex;gap:.7rem;padding:0 1.2rem 1.3rem">
                    <div data-el="kachel1" style="flex:1;border-radius:10px;padding:.9rem;font-size:.85rem;font-weight:700">Kacheln</div>
                    <div data-el="kachel2" style="flex:1;border-radius:10px;padding:.9rem;font-size:.85rem">und Karten</div>
                </div>
            </div>
        </div>
    </div>
</form>

<script>
(function () {
    'use strict';

    var form = document.getElementById('designform');
    var vorschau = document.getElementById('vorschau');

    function wert(key) { return document.getElementById(key).value; }

    function malen() {
        var akzent = wert('theme_accent');
        var hell   = wert('theme_accent_bright');
        var bg     = wert('theme_bg');
        var weich  = wert('theme_bg_soft');
        var karte  = wert('theme_card');
        var linie  = wert('theme_line');
        var text   = wert('theme_text');
        var soft   = wert('theme_text_soft');
        var stack  = document.querySelector('#theme_font option:checked').dataset.stack;

        function setz(el, styles) {
            var ziel = vorschau.querySelector('[data-el="' + el + '"]');
            for (var k in styles) { ziel.style[k] = styles[k]; }
        }

        vorschau.style.background = bg;
        vorschau.style.fontFamily = stack;
        vorschau.style.borderColor = linie;
        setz('kopf',    { background: bg, borderBottom: '1px solid ' + linie });
        setz('marke',   { color: akzent });
        setz('knopf',   { background: akzent, color: bg });
        setz('held',    { background: weich });
        setz('titel',   { color: text });
        setz('text',    { color: soft });
        setz('verweis', { color: hell });
        setz('kachel1', { background: akzent, color: bg });
        setz('kachel2', { background: karte, color: text, border: '1px solid ' + linie });

        // Hex-Anzeigen aktualisieren
        form.querySelectorAll('.hexwert').forEach(function (code) {
            code.textContent = wert(code.dataset.for);
        });
    }

    form.addEventListener('input', malen);
    malen();
})();
</script>
