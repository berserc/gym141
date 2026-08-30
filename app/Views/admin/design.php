<?php

/**
 * Design-Baukasten: Template-Galerie, Farben per Farbwaehler, Schriftauswahl,
 * Live-Vorschau, eigene Templates speichern.
 *
 * @var array<string, array{0:list<string>,1:string,2:string,3:string}> $colors
 * @var array<string, array{0:string,1:string,2:?string}>               $fonts
 * @var array<string, array{0:string,1:list<string>,2:string}>          $builtins
 * @var list<array<string,mixed>>                                       $eigene
 * @var array<string,string>                                            $werte
 * @var string                                                          $font
 * @var bool                                                            $custom
 */

$farbKeys = array_keys($colors);
?>

<style>
<?php foreach ($fonts as $fdef): if ($fdef[2] !== null): ?>
@font-face { font-family: '<?= e(trim(explode(',', $fdef[1])[0], " '\"")) ?>';
             src: url('<?= e(asset('fonts/' . $fdef[2] . '.woff2')) ?>') format('woff2');
             font-weight: 100 900; font-display: swap; }
<?php endif; endforeach; ?>
.tpl-galerie { display: grid; grid-template-columns: repeat(auto-fill, minmax(13.5rem, 1fr)); gap: .7rem; }
.tpl-karte { border: 1px solid var(--line); border-radius: 10px; padding: .7rem .8rem; cursor: pointer;
             background: var(--card, #fff); transition: border-color .15s; position: relative; }
.tpl-karte:hover { border-color: var(--gold, #d4a437); }
.tpl-chips { display: flex; gap: .25rem; margin: .45rem 0 .3rem; }
.tpl-chips i { display: block; width: 1.5rem; height: 1.1rem; border-radius: 4px; border: 1px solid rgba(0,0,0,.15); }
.tpl-loeschen { position: absolute; top: .4rem; right: .5rem; background: none; border: 0;
                color: inherit; opacity: .5; cursor: pointer; font-size: 1rem; }
.tpl-loeschen:hover { opacity: 1; color: #c0392b; }
</style>

<div class="page-head">
    <h1>Design</h1>
    <p class="muted">Farben und Schrift der öffentlichen Website – Template wählen oder selbst zusammenklicken.
       Änderungen gelten nach dem Speichern sofort; die Verwaltung bleibt unverändert.</p>
</div>

<div class="card" style="margin-bottom:1rem">
    <h2>Templates</h2>
    <p class="muted" style="font-size:.85rem;margin-top:0">
        Klick übernimmt das Template in die Regler unten – dort anpassen, dann <strong>speichern</strong>
        (fürs Aktivieren) oder unter eigenem Namen als Kopie ablegen. Mitgelieferte Templates sind nicht löschbar.
    </p>

    <div class="tpl-galerie">
        <?php foreach ($builtins as $tkey => [$tname, $tfarben, $tfont]): ?>
            <div class="tpl-karte" data-colors='<?= e(json_encode($tfarben)) ?>' data-font="<?= e($tfont) ?>">
                <strong style="font-size:.9rem"><?= e($tname) ?></strong>
                <div class="tpl-chips">
                    <?php foreach ([0, 1, 2, 4, 6] as $ci): ?>
                        <i style="background:<?= e($tfarben[$ci]) ?>"></i>
                    <?php endforeach; ?>
                </div>
                <span class="muted" style="font-size:.78rem"><?= e($fonts[$tfont][0] ?? 'Standard') ?> · mitgeliefert</span>
            </div>
        <?php endforeach; ?>

        <?php foreach ($eigene as $tpl): ?>
            <?php $cfg = json_decode((string) $tpl['config'], true) ?: ['colors' => [], 'font' => '']; ?>
            <?php if (count($cfg['colors'] ?? []) !== 8) { continue; } ?>
            <div class="tpl-karte" data-colors='<?= e(json_encode($cfg['colors'])) ?>' data-font="<?= e((string) $cfg['font']) ?>">
                <strong style="font-size:.9rem"><?= e((string) $tpl['name']) ?></strong>
                <div class="tpl-chips">
                    <?php foreach ([0, 1, 2, 4, 6] as $ci): ?>
                        <i style="background:<?= e($cfg['colors'][$ci]) ?>"></i>
                    <?php endforeach; ?>
                </div>
                <span class="muted" style="font-size:.78rem"><?= e($fonts[$cfg['font']][0] ?? 'Standard') ?> · eigenes</span>
                <form method="post" action="<?= e(url('/admin/design/template-loeschen')) ?>"
                      onsubmit="return confirm('Template &quot;<?= e((string) $tpl['name']) ?>&quot; wirklich löschen?')"
                      onclick="event.stopPropagation()">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= (int) $tpl['id'] ?>">
                    <button type="submit" class="tpl-loeschen" title="Template löschen">✕</button>
                </form>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<form method="post" action="<?= e(url('/admin/design')) ?>" id="designform">
    <?= csrf_field() ?>

    <div class="grid-2" style="align-items:start">
        <div class="card">
            <h2>Farben</h2>

            <?php foreach ($colors as $key => [$vars, $label, $hint, $standard]): ?>
                <div class="form-row" style="display:flex;align-items:center;gap:.75rem;margin-bottom:.7rem">
                    <input type="color" id="<?= e($key) ?>" name="<?= e($key) ?>"
                           value="<?= e($werte[$key]) ?>"
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
                <button type="submit" class="btn btn-primary">Design speichern &amp; aktivieren</button>
                <?php if ($custom): ?>
                    <button type="submit" name="reset" value="1" class="btn"
                            onclick="return confirm('Wirklich alle Design-Anpassungen verwerfen und zum Standard zurückkehren?')">
                        Auf Standard zurücksetzen
                    </button>
                <?php endif; ?>
            </div>

            <div style="display:flex;gap:.6rem;margin-top:1rem;align-items:center;flex-wrap:wrap;border-top:1px solid var(--line);padding-top:1rem">
                <input type="text" name="template_name" placeholder="Name für eigenes Template" maxlength="40" style="flex:1;min-width:12rem">
                <button type="submit" class="btn"
                        formaction="<?= e(url('/admin/design/template')) ?>">Als Template speichern</button>
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
    var farbKeys = <?= json_encode($farbKeys) ?>;

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

        form.querySelectorAll('.hexwert').forEach(function (code) {
            code.textContent = wert(code.dataset.for);
        });
    }

    // Template-Karte anklicken -> Werte in die Regler uebernehmen.
    document.querySelectorAll('.tpl-karte').forEach(function (karte) {
        karte.addEventListener('click', function () {
            var farben = JSON.parse(karte.dataset.colors);

            farbKeys.forEach(function (key, i) {
                document.getElementById(key).value = farben[i];
            });

            document.getElementById('theme_font').value = karte.dataset.font || '';
            malen();
            document.getElementById('designform').scrollIntoView({ behavior: 'smooth', block: 'start' });
        });
    });

    form.addEventListener('input', malen);
    malen();
})();
</script>
