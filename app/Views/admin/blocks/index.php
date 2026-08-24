<?php

use App\Models\BlockRepo;

/**
 * Inhaltsblöcke einer Seite verwalten (page = 0: Startseite).
 *
 * @var ?int                             $pageId
 * @var ?array<string,mixed>             $page
 * @var list<array<string,mixed>>        $blocks
 * @var array<string,array{0:string,1:string}> $types
 */

$kontext = $pageId === null ? 0 : $pageId;
$ziel    = $page === null
    ? url('/')
    : url('/seite/' . $page['slug']);
?>

<div class="page-head">
    <div>
        <h1><?= e($page['title'] ?? 'Startseite') ?> – Inhalt</h1>
        <p class="page-head__sub">
            Die Seite wird aus Blöcken aufgebaut – hinzufügen, befüllen, sortieren.
            <a href="<?= e($ziel) ?>" target="_blank" rel="noopener">Seite ansehen</a>
            <?php if ($page !== null): ?>
                · <a href="<?= e(url('/admin/seiten/' . (int) $page['id'])) ?>">Titel &amp; Textkörper bearbeiten</a>
            <?php endif; ?>
        </p>
    </div>
</div>

<?php if ($blocks === []): ?>
    <div class="card">
        <p>Noch keine Blöcke.
            <?php if ($pageId === null): ?>
                Die Startseite zeigt weiterhin ihren Standardaufbau (Hero, Trainingsgruppen,
                Wochenplan); Blöcke erscheinen zusätzlich nach dem Einleitungstext.
            <?php else: ?>
                Blöcke erscheinen unter dem Textkörper der Seite.
            <?php endif; ?>
        </p>
    </div>
<?php endif; ?>

<?php foreach ($blocks as $i => $block): ?>
    <?php $cfg = $block['config']; ?>
    <fieldset class="card" id="block-<?= (int) $block['id'] ?>">
        <legend>
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
                <div class="field">
                    <label>Hintergrundbild</label>
                    <?php if (($cfg['image'] ?? '') !== ''): ?>
                        <p><img src="<?= e(upload_url((string) $cfg['image'])) ?>" alt="" style="max-width:260px;border-radius:8px"></p>
                        <label class="check"><input type="checkbox" name="image_clear" value="1"> Bild entfernen</label>
                    <?php endif; ?>
                    <input type="file" name="image" accept="image/*">
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
            <form method="post" action="<?= e(url('/admin/block/' . (int) $block['id'] . '/loeschen')) ?>"
                  onsubmit="return confirm('Diesen Block wirklich löschen?')">
                <?= csrf_field() ?>
                <button class="btn btn--small btn--danger">Löschen</button>
            </form>
        </div>
    </fieldset>
<?php endforeach; ?>

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
