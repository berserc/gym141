<?php

/**
 * @var array<string,mixed>        $gallery
 * @var bool                       $isNew
 * @var array<string,string>       $errors
 * @var list<int>                  $selected
 * @var list<array<string,mixed>> $images
 */
$id     = (int) ($gallery['id'] ?? 0);
$action = $isNew ? url('/admin/galerien') : url('/admin/galerien/' . $id);
$err    = static fn (string $f): string => isset($errors[$f]) ? '<p class="field__error">' . e($errors[$f]) . '</p>' : '';
?>
<div class="page-head">
    <div>
        <h1><?= $isNew ? 'Neue Galerie' : e((string) $gallery['title']) ?></h1>
        <?php if (!$isNew): ?>
            <p class="page-head__sub"><a href="<?= e(url('/galerie/' . $gallery['slug'])) ?>" target="_blank" rel="noopener">Galerie ansehen: /galerie/<?= e((string) $gallery['slug']) ?></a></p>
        <?php endif; ?>
    </div>
    <div class="page-head__actions"><a class="btn btn--ghost" href="<?= e(url('/admin/galerien')) ?>">Zur Liste</a></div>
</div>

<form method="post" action="<?= e($action) ?>" class="form">
    <?= csrf_field() ?>
    <div class="card">
        <div class="field-row">
            <div class="field field--grow">
                <label for="title">Titel *</label>
                <input id="title" name="title" required value="<?= e((string) $gallery['title']) ?>">
                <?= $err('title') ?>
            </div>
            <div class="field field--grow">
                <label for="slug">URL-Kürzel</label>
                <input id="slug" name="slug" value="<?= e((string) $gallery['slug']) ?>" placeholder="wird aus dem Titel gebildet">
            </div>
            <div class="field field--xs">
                <label for="sort_order">Reihung</label>
                <input id="sort_order" name="sort_order" type="number" value="<?= (int) $gallery['sort_order'] ?>">
            </div>
        </div>
        <div class="field">
            <label for="text">Text (optional – Leerzeile = neuer Absatz)</label>
            <textarea id="text" name="text" rows="4"><?= e((string) $gallery['text']) ?></textarea>
        </div>
        <label class="check"><input type="checkbox" name="published" value="1" <?= (int) $gallery['published'] === 1 ? 'checked' : '' ?>> Auf der Website sichtbar</label>
    </div>

    <div class="card">
        <div class="card__head"><h2>Bilder</h2></div>
        <p class="muted">Erst suchen oder nach Tag filtern, dann anhaken. Unten die Reihenfolge festlegen. Das erste Bild ist das Titelbild der Galerie – oder in der Reihenfolge-Leiste auf ★ klicken, um ein anderes zu wählen.</p>
        <?php
        $pickerField = 'image_ids'; $pickerSelected = $selected; $pickerImages = $images; $pickerSingle = false;
        require dirname(__DIR__) . '/partials/_image-picker.php';
        ?>
    </div>

    <input type="hidden" name="cover_image_id" value="<?= (int) ($gallery['cover_image_id'] ?? 0) ?: '' ?>" data-cover-input>

    <div class="form-actions">
        <button class="btn btn--primary" type="submit"><?= $isNew ? 'Galerie anlegen' : 'Speichern' ?></button>
    </div>
</form>
