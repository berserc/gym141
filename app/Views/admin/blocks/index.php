<?php

use App\Models\BlockRepo;

/**
 * Inhaltsblöcke verwalten (page = 0: Startseite, s<id>: Sektionsseite).
 *
 * @var ?int                             $pageId
 * @var ?int                             $sectionId
 * @var ?array<string,mixed>             $page
 * @var ?array<string,mixed>             $section
 * @var list<array<string,mixed>>        $blocks
 * @var array<string,array{0:string,1:string}> $types
 */

$kontext = $pageId !== null ? (string) $pageId : ($sectionId !== null ? 's' . $sectionId : '0');
$ziel    = $page !== null
    ? url('/seite/' . $page['slug'])
    : ($section !== null ? url('/sektion/' . $section['slug']) : url('/'));
$istStartseite = $pageId === null && $sectionId === null;
?>

<div class="page-head">
    <div>
        <h1><?= e($page['title'] ?? $section['name'] ?? 'Startseite') ?> – Inhalt</h1>
        <p class="page-head__sub">
            Die Seite wird aus Blöcken aufgebaut – hinzufügen, befüllen, per
            Ziehen am ⠿-Griff (oder mit den Pfeilen) sortieren.
            <a href="<?= e($ziel) ?>" target="_blank" rel="noopener">Seite ansehen</a>
            <?php if ($page !== null): ?>
                · <a href="<?= e(url('/admin/seiten/' . (int) $page['id'])) ?>">Titel &amp; Textkörper bearbeiten</a>
            <?php elseif ($section !== null): ?>
                · <a href="<?= e(url('/admin/sektionen/' . (int) $section['id'])) ?>">Sektion bearbeiten</a>
            <?php endif; ?>
        </p>
    </div>
</div>

<?php if ($istStartseite): ?>
    <fieldset class="card">
        <legend>Aufbau der Startseite</legend>
        <form method="post" action="<?= e(url('/admin/inhalt/0/optionen')) ?>">
            <?= csrf_field() ?>
            <label class="check">
                <input type="checkbox" name="blocks_only" value="1"
                       <?= \App\Models\Setting::get('home_blocks_only', '0') === '1' ? 'checked' : '' ?>>
                Startseite besteht NUR aus den Blöcken unten
            </label>
            <p class="field__hint">
                Angehakt verschwindet der Standardaufbau (Hero mit Einleitung,
                Trainingsgruppen, Wochenplan) – baue ihn aus Blöcken nach:
                Hero-, Trainingsgruppen- und Wochenplan-Block gibt es unten.
                Abgehakt erscheinen die Blöcke zusätzlich nach der Einleitung.
            </p>
            <button class="btn" type="submit">Aufbau speichern</button>
        </form>
    </fieldset>
<?php endif; ?>

<?php if ($blocks === []): ?>
    <div class="card">
        <p>Noch keine Blöcke.
            <?php if ($istStartseite): ?>
                Die Startseite zeigt weiterhin ihren Standardaufbau (Hero, Trainingsgruppen,
                Wochenplan); Blöcke erscheinen zusätzlich nach dem Einleitungstext.
            <?php elseif ($sectionId !== null): ?>
                Blöcke erscheinen unter dem Standardinhalt der Sektionsseite.
            <?php else: ?>
                Blöcke erscheinen unter dem Textkörper der Seite.
            <?php endif; ?>
        </p>
    </div>
<?php endif; ?>

<div id="blocks-sortier"
     data-sortier-url="<?= e(url('/admin/inhalt/' . $kontext . '/sortieren')) ?>"
     data-csrf="<?= e(\App\Core\Csrf::token()) ?>">
<?php foreach ($blocks as $i => $block): ?>
    <?php $cfg = $block['config']; ?>
    <fieldset class="card block-card" id="block-<?= (int) $block['id'] ?>" data-block-id="<?= (int) $block['id'] ?>">
        <legend>
            <span class="block-griff" draggable="true" title="Zum Sortieren ziehen">⠿</span>
            <?= e($types[$block['type']][0] ?? $block['type']) ?>-Block
            <?php if ((int) $block['published'] === 0): ?>
                <span class="badge badge--muted">ausgeblendet</span>
            <?php endif; ?>
        </legend>

        <form method="post" action="<?= e(url('/admin/block/' . (int) $block['id'])) ?>" enctype="multipart/form-data">
            <?= csrf_field() ?>

            <?php if ($block['type'] === 'text'): ?>
                <div class="field">
                    <label for="html-<?= (int) $block['id'] ?>">Text</label>
                    <textarea id="html-<?= (int) $block['id'] ?>" name="html" rows="8"
                              class="js-richtext"><?= e((string) ($cfg['html'] ?? '')) ?></textarea>
                    <p class="field__hint">Erlaubt: Überschriften, Absätze, Listen, Links, fett/kursiv.</p>
                </div>

            <?php elseif ($block['type'] === 'hero'): ?>
                <div class="grid-2">
                    <div class="field">
                        <label>Titel</label>
                        <input name="title" value="<?= e((string) ($cfg['title'] ?? '')) ?>" maxlength="150">
                    </div>
                    <div class="field">
                        <label>Untertitel</label>
                        <input name="text" value="<?= e((string) ($cfg['text'] ?? '')) ?>" maxlength="300">
                    </div>
                    <div class="field">
                        <label>Button-Beschriftung (optional)</label>
                        <input name="button_label" value="<?= e((string) ($cfg['button_label'] ?? '')) ?>" maxlength="60">
                    </div>
                    <div class="field">
                        <label>Button-Ziel (URL oder /pfad)</label>
                        <input name="button_url" value="<?= e((string) ($cfg['button_url'] ?? '')) ?>" maxlength="300">
                    </div>
                </div>
                <div class="grid-2">
                    <div class="field">
                        <label>Hintergrundbild (auch Vorschaubild fürs Video)</label>
                        <?php if (($cfg['image'] ?? '') !== ''): ?>
                            <p><img src="<?= e(upload_url((string) $cfg['image'])) ?>" alt="" style="max-width:260px;border-radius:8px"></p>
                            <label class="check"><input type="checkbox" name="image_clear" value="1"> Bild entfernen</label>
                        <?php endif; ?>
                        <input type="file" name="image" accept="image/*">
                    </div>
                    <div class="field">
                        <label>Hintergrundvideo (MP4/WebM, optional – läuft stumm in Schleife)</label>
                        <?php if (($cfg['video'] ?? '') !== ''): ?>
                            <p class="field__hint">Aktuell: <code><?= e((string) $cfg['video']) ?></code></p>
                            <label class="check"><input type="checkbox" name="video_clear" value="1"> Video entfernen</label>
                        <?php endif; ?>
                        <input type="file" name="video" accept="video/mp4,video/webm">
                    </div>
                </div>
                <div class="field">
                    <label>Höhe</label>
                    <select name="size">
                        <option value="normal" <?= ($cfg['size'] ?? 'normal') !== 'gross' ? 'selected' : '' ?>>Normal</option>
                        <option value="gross" <?= ($cfg['size'] ?? '') === 'gross' ? 'selected' : '' ?>>Groß (ganzer Bildschirm – als Seitenauftakt)</option>
                    </select>
                </div>

            <?php elseif ($block['type'] === 'image'): ?>
                <div class="field">
                    <label>Bild</label>
                    <?php if (($cfg['image'] ?? '') !== ''): ?>
                        <p><img src="<?= e(upload_url((string) $cfg['image'])) ?>" alt="" style="max-width:260px;border-radius:8px"></p>
                        <label class="check"><input type="checkbox" name="image_clear" value="1"> Bild entfernen</label>
                    <?php endif; ?>
                    <input type="file" name="image" accept="image/*">
                </div>
                <div class="grid-2">
                    <div class="field">
                        <label>Bildunterschrift (optional)</label>
                        <input name="caption" value="<?= e((string) ($cfg['caption'] ?? '')) ?>" maxlength="200">
                    </div>
                    <div class="field">
                        <label>Breite</label>
                        <select name="width">
                            <?php foreach (['normal' => 'Normal', 'schmal' => 'Schmal', 'voll' => 'Volle Breite'] as $wert => $label): ?>
                                <option value="<?= $wert ?>" <?= ($cfg['width'] ?? 'normal') === $wert ? 'selected' : '' ?>><?= $label ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            <?php elseif ($block['type'] === 'gallery'): ?>
                <?php $bilder = is_array($cfg['images'] ?? null) ? $cfg['images'] : []; ?>
                <?php if ($bilder !== []): ?>
                    <div class="gallery-admin">
                        <?php foreach ($bilder as $gi => $gBild): ?>
                            <div class="gallery-admin__item">
                                <img src="<?= e(upload_url((string) $gBild['file'])) ?>" alt="">
                                <input name="captions[<?= $gi ?>]" value="<?= e((string) ($gBild['caption'] ?? '')) ?>"
                                       placeholder="Bildunterschrift" maxlength="200">
                                <label class="check">
                                    <input type="checkbox" name="remove[<?= $gi ?>]" value="1"> entfernen
                                </label>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                <div class="grid-2">
                    <div class="field">
                        <label>Bilder hinzufügen (Mehrfachauswahl möglich)</label>
                        <input type="file" name="images[]" accept="image/*" multiple>
                    </div>
                    <div class="field">
                        <label>Spalten</label>
                        <select name="columns">
                            <?php foreach ([2, 3, 4] as $sp): ?>
                                <option value="<?= $sp ?>" <?= (int) ($cfg['columns'] ?? 3) === $sp ? 'selected' : '' ?>><?= $sp ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

            <?php elseif ($block['type'] === 'video'): ?>
                <div class="grid-2">
                    <div class="field">
                        <label>YouTube-Adresse oder Video-Id (optional)</label>
                        <input name="youtube" value="<?= e((string) ($cfg['youtube'] ?? '')) ?>"
                               placeholder="https://www.youtube.com/watch?v=…">
                        <p class="field__hint">Wird datenschutzfreundlich erst nach einem Klick geladen (youtube-nocookie.com).</p>
                    </div>
                    <div class="field">
                        <label>Bildunterschrift (optional)</label>
                        <input name="caption" value="<?= e((string) ($cfg['caption'] ?? '')) ?>" maxlength="200">
                    </div>
                </div>
                <div class="grid-2">
                    <div class="field">
                        <label>Eigenes Video (MP4/WebM, max. 100 MB)</label>
                        <?php if (($cfg['file'] ?? '') !== ''): ?>
                            <p class="field__hint">Aktuell: <code><?= e((string) $cfg['file']) ?></code></p>
                            <label class="check"><input type="checkbox" name="file_clear" value="1"> Video entfernen</label>
                        <?php endif; ?>
                        <input type="file" name="file" accept="video/mp4,video/webm">
                        <p class="field__hint">Ein eigenes Video hat Vorrang vor YouTube.</p>
                    </div>
                    <div class="field">
                        <label>Vorschaubild (optional)</label>
                        <?php if (($cfg['poster'] ?? '') !== ''): ?>
                            <p><img src="<?= e(upload_url((string) $cfg['poster'])) ?>" alt="" style="max-width:200px;border-radius:8px"></p>
                            <label class="check"><input type="checkbox" name="poster_clear" value="1"> Vorschaubild entfernen</label>
                        <?php endif; ?>
                        <input type="file" name="poster" accept="image/*">
                    </div>
                </div>

            <?php elseif ($block['type'] === 'schedule' || $block['type'] === 'sections'): ?>
                <div class="field">
                    <label>Überschrift (leer = <?= $block['type'] === 'schedule' ? '„Wochenplan“ bzw. ohne' : 'ohne' ?>)</label>
                    <input name="title" value="<?= e((string) ($cfg['title'] ?? ($block['type'] === 'schedule' ? 'Wochenplan' : ''))) ?>" maxlength="120">
                    <p class="field__hint">
                        <?= $block['type'] === 'schedule'
                            ? 'Zeigt den Wochenplan aus der Terminverwaltung (Pflege unter Verein → Wochenplan).'
                            : 'Zeigt die Kacheln aller veröffentlichten Trainingsgruppen (Pflege unter Verein → Sektionen).' ?>
                    </p>
                </div>

            <?php elseif ($block['type'] === 'cta'): ?>
                <div class="grid-2">
                    <div class="field">
                        <label>Titel</label>
                        <input name="title" value="<?= e((string) ($cfg['title'] ?? '')) ?>" maxlength="150">
                    </div>
                    <div class="field">
                        <label>Text</label>
                        <input name="text" value="<?= e((string) ($cfg['text'] ?? '')) ?>" maxlength="300">
                    </div>
                    <div class="field">
                        <label>Button-Beschriftung</label>
                        <input name="button_label" value="<?= e((string) ($cfg['button_label'] ?? '')) ?>" maxlength="60">
                    </div>
                    <div class="field">
                        <label>Button-Ziel (URL oder /pfad)</label>
                        <input name="button_url" value="<?= e((string) ($cfg['button_url'] ?? '')) ?>" maxlength="300">
                    </div>
                </div>
                <label class="check">
                    <input type="checkbox" name="whatsapp" value="1" <?= (int) ($cfg['whatsapp'] ?? 0) === 1 ? 'checked' : '' ?>>
                    WhatsApp-Button (nutzt die WhatsApp-Nummer aus den Einstellungen; Button-Ziel wird ignoriert)
                </label>

            <?php endif; ?>

            <button class="btn btn--primary" type="submit">Speichern</button>
        </form>

        <div class="block-tools">
            <form method="post" action="<?= e(url('/admin/block/' . (int) $block['id'] . '/verschieben')) ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="richtung" value="hoch">
                <button class="btn btn--small" <?= $i === 0 ? 'disabled' : '' ?> title="nach oben">▲</button>
            </form>
            <form method="post" action="<?= e(url('/admin/block/' . (int) $block['id'] . '/verschieben')) ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="richtung" value="runter">
                <button class="btn btn--small" <?= $i === count($blocks) - 1 ? 'disabled' : '' ?> title="nach unten">▼</button>
            </form>
            <form method="post" action="<?= e(url('/admin/block/' . (int) $block['id'] . '/umschalten')) ?>">
                <?= csrf_field() ?>
                <button class="btn btn--small"><?= (int) $block['published'] === 1 ? 'Ausblenden' : 'Einblenden' ?></button>
            </form>
            <form method="post" action="<?= e(url('/admin/block/' . (int) $block['id'] . '/duplizieren')) ?>">
                <?= csrf_field() ?>
                <button class="btn btn--small">Duplizieren</button>
            </form>
            <form method="post" action="<?= e(url('/admin/block/' . (int) $block['id'] . '/loeschen')) ?>"
                  onsubmit="return confirm('Diesen Block wirklich löschen?')">
                <?= csrf_field() ?>
                <button class="btn btn--small btn--danger">Löschen</button>
            </form>
        </div>
    </fieldset>
<?php endforeach; ?>
</div>

<fieldset class="card">
    <legend>Block hinzufügen</legend>
    <div class="block-add">
        <?php foreach ($types as $typ => [$label, $beschreibung]): ?>
            <form method="post" action="<?= e(url('/admin/inhalt/' . $kontext . '/neu')) ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="type" value="<?= e($typ) ?>">
                <button class="btn" type="submit" title="<?= e($beschreibung) ?>">+ <?= e($label) ?></button>
            </form>
        <?php endforeach; ?>
    </div>
    <p class="field__hint">
        <?php foreach ($types as $typ => [$label, $beschreibung]): ?>
            <strong><?= e($label) ?>:</strong> <?= e($beschreibung) ?><br>
        <?php endforeach; ?>
    </p>
</fieldset>
