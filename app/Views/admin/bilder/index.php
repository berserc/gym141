<?php

/**
 * Bildbibliothek: Upload, Suche/Tag-Filter, Bearbeiten je Bild, Sammelaktionen.
 *
 * @var list<array<string,mixed>> $images
 * @var int                        $total
 * @var array<string,int>          $tagCounts
 * @var string                     $q
 * @var string                     $tag
 */
?>
<div class="page-head">
    <div>
        <h1>Bilder</h1>
        <p class="page-head__sub">
            Bildbibliothek: einmal hochladen, mit Tags verschlagworten – dann in Galerien, Inhaltsblöcken und Seiten auswählen.
            <?= $total ?> Bilder, <?= count($tagCounts) ?> Tags.
        </p>
    </div>
    <div class="page-head__actions">
        <a class="btn" href="<?= e(url('/admin/galerien')) ?>">Galerien</a>
    </div>
</div>

<details class="card" <?= $total === 0 ? 'open' : '' ?>>
    <summary class="linklike">⬆ Bilder hochladen</summary>
    <form method="post" action="<?= e(url('/admin/bilder/hochladen')) ?>" enctype="multipart/form-data" class="form">
        <?= csrf_field() ?>
        <div class="field">
            <label for="up-files">Dateien (JPG, PNG, GIF, WebP – Mehrfachauswahl; werden am Server auf max. <?= \App\Core\ImageTool::MAX_EDGE ?> px verkleinert, Handyfotos richtig gedreht)</label>
            <input id="up-files" type="file" name="files[]" accept="image/*" multiple required>
        </div>
        <div class="field-row">
            <div class="field field--grow">
                <label for="up-tags">Tags (mit Komma trennen)</label>
                <input id="up-tags" name="tags" list="taglist" value="<?= e($tag) ?>" placeholder="z. B. sommerfest 2026, jugend, training">
            </div>
            <div class="field field--grow">
                <label for="up-caption">Bildtext (optional, gilt für alle hochgeladenen Bilder)</label>
                <input id="up-caption" name="caption" maxlength="200" placeholder="z. B. Sommerfest 2026">
            </div>
        </div>
        <div class="form-actions">
            <button class="btn btn--primary" type="submit">Hochladen</button>
            <span class="muted">Tipp: Tags wie Anlass, Jahr oder Gruppe – danach lassen sich Bilder überall gezielt finden.</span>
        </div>
    </form>
</details>

<datalist id="taglist"><?php foreach ($tagCounts as $t => $n): ?><option value="<?= e((string) $t) ?>"><?php endforeach; ?></datalist>

<div class="card">
    <form method="get" action="<?= e(url('/admin/bilder')) ?>" class="filters">
        <div class="filters__row">
            <div class="field field--grow">
                <input type="search" name="q" value="<?= e($q) ?>" placeholder="Suchen: Tag, Bildtext, Dateiname … (mehrere Wörter = alle müssen passen)">
            </div>
            <?php if ($tag !== ''): ?><input type="hidden" name="tag" value="<?= e($tag) ?>"><?php endif; ?>
            <button class="btn" type="submit">Suchen</button>
            <?php if ($q !== '' || $tag !== ''): ?><a class="btn btn--ghost" href="<?= e(url('/admin/bilder')) ?>">Zurücksetzen</a><?php endif; ?>
        </div>
    </form>
    <?php if ($tagCounts !== []): ?>
        <div class="tag-cloud">
            <?php foreach ($tagCounts as $t => $n): ?>
                <a class="tag<?= $tag === (string) $t ? ' is-on' : '' ?>" href="<?= e(url('/admin/bilder', ['q' => $q, 'tag' => $tag === (string) $t ? '' : (string) $t])) ?>"><?= e((string) $t) ?> <small><?= $n ?></small></a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php if ($images === []): ?>
    <div class="card"><p class="empty"><?= $total === 0 ? 'Noch keine Bilder – oben hochladen.' : 'Kein Bild passt zur Suche.' ?></p></div>
<?php else: ?>
<form method="post" action="<?= e(url('/admin/bilder/sammel')) ?>" data-confirm-bulk="Aktion für die ausgewählten Bilder ausführen?">
    <?= csrf_field() ?>
    <input type="hidden" name="q" value="<?= e($q) ?>"><input type="hidden" name="tag" value="<?= e($tag) ?>">
    <p class="muted"><?= count($images) ?> Bild(er) · <label class="check"><input type="checkbox" data-check-all> alle auswählen</label></p>
    <div class="image-lib">
        <?php foreach ($images as $img): ?>
            <div class="image-lib__item card">
                <label class="image-lib__pic">
                    <input type="checkbox" name="ids[]" value="<?= (int) $img['id'] ?>">
                    <img src="<?= e(upload_url((string) $img['thumb'])) ?>" alt="" loading="lazy">
                    <a class="image-lib__size" href="<?= e(upload_url((string) $img['file'])) ?>" target="_blank" rel="noopener" title="Bild öffnen"><?= (int) $img['width'] ?> × <?= (int) $img['height'] ?></a>
                </label>
                <div class="image-lib__body">
                    <div class="tag-cloud tag-cloud--sm"><?php foreach ($img['tags'] as $t): ?><span class="tag"><?= e($t) ?></span><?php endforeach; ?></div>
                    <?php if ((string) $img['caption'] !== ''): ?><div class="image-lib__cap"><?= e((string) $img['caption']) ?></div><?php endif; ?>
                    <div class="muted image-lib__meta"><?= e(substr((string) $img['created_at'], 0, 10)) ?> · <?= e((string) $img['orig_name']) ?></div>
                    <details>
                        <summary class="linklike">✏️ Tags / Bildtext</summary>
                        <div class="image-lib__edit" data-id="<?= (int) $img['id'] ?>" data-url="<?= e(url('/admin/bilder/' . (int) $img['id'])) ?>">
                            <input class="js-edit-tags" list="taglist" value="<?= e(implode(', ', $img['tags'])) ?>" placeholder="Tags, mit Komma">
                            <input class="js-edit-caption" maxlength="200" value="<?= e((string) $img['caption']) ?>" placeholder="Bildtext">
                            <button type="button" class="btn btn--sm js-edit-save">Speichern</button>
                        </div>
                    </details>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
    <div class="bulkbar">
        <strong>Ausgewählte Bilder:</strong>
        <select name="action">
            <option value="addtags">Tags hinzufügen</option>
            <option value="deltags">Tags entfernen</option>
            <option value="delete">Löschen</option>
        </select>
        <input name="bulk_tags" list="taglist" placeholder="Tags (Komma)">
        <button class="btn btn--primary" type="submit">Ausführen</button>
        <span class="muted">Löschen entfernt die Bilder auch aus Galerien und Blöcken.</span>
    </div>
</form>
<?php endif; ?>

<script>
/* Bild einzeln speichern: POST wie ein Formular, dann Seite neu laden */
document.querySelectorAll('.js-edit-save').forEach(function (btn) {
    btn.addEventListener('click', function () {
        var box = btn.closest('.image-lib__edit');
        var fd  = new FormData();
        fd.append('csrf_token', <?= json_encode(\App\Core\Csrf::token()) ?>);
        fd.append('tags', box.querySelector('.js-edit-tags').value);
        fd.append('caption', box.querySelector('.js-edit-caption').value);
        fd.append('q', <?= json_encode($q) ?>);
        fd.append('tag', <?= json_encode($tag) ?>);
        btn.disabled = true; btn.textContent = '…';
        fetch(box.getAttribute('data-url'), { method: 'POST', body: fd, credentials: 'same-origin' }).then(function () { location.reload(); });
    });
});
</script>
