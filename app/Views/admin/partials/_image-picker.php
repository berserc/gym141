<?php

/**
 * Bild-Auswahl aus der Bildbibliothek mit Suche, Tag-Filter und Reihenfolge (Drag & Drop).
 * Schickt <feld>[] (Checkboxen) und <feld>_order (Reihenfolge, kommagetrennt); auslesen mit
 * GalleryAdminController::pickedIds($feld). JS: admin.js (.js-image-picker).
 *
 * @var string                     $pickerField    z. B. 'image_ids'
 * @var list<int>                  $pickerSelected bereits gewaehlte IDs in Reihenfolge
 * @var list<array<string,mixed>> $pickerImages   alle Bilder (ImageRepo::search())
 * @var bool                       $pickerSingle   optional: nur ein Bild (Radio-Verhalten)
 */
$pickerSingle = $pickerSingle ?? false;
$pickerUid    = 'pick-' . preg_replace('/[^a-z0-9]/i', '', $pickerField) . '-' . substr(uniqid(), -4);
?>
<div class="image-picker js-image-picker<?= $pickerSingle ? ' image-picker--single' : '' ?>" id="<?= e($pickerUid) ?>" data-field="<?= e($pickerField) ?>" data-selected="<?= e(implode(',', $pickerSelected)) ?>">
    <?php if ($pickerImages === []): ?>
        <p class="muted">Noch keine Bilder in der Bildbibliothek – unter <a href="<?= e(url('/admin/bilder')) ?>">Bilder</a> hochladen.</p>
    <?php else: ?>
        <div class="image-picker__bar">
            <input type="search" class="image-picker__q" placeholder="Suchen: Tag, Bildtext, Dateiname … (mehrere Wörter = alle müssen passen)" autocomplete="off">
            <label class="check"><input type="checkbox" class="image-picker__only"> nur Ausgewählte</label>
            <span class="muted image-picker__count"></span>
        </div>
        <div class="image-picker__tags"></div>
        <div class="image-picker__grid">
            <?php foreach ($pickerImages as $img): ?>
                <?php $on = in_array((int) $img['id'], $pickerSelected, true); ?>
                <label class="image-picker__item<?= $on ? ' is-on' : '' ?>"
                       data-id="<?= (int) $img['id'] ?>"
                       data-tags="<?= e(implode('|', $img['tags'])) ?>"
                       data-text="<?= e(mb_strtolower((string) $img['caption'] . ' ' . (string) $img['orig_name'] . ' ' . implode(' ', $img['tags']), 'UTF-8')) ?>">
                    <input type="<?= $pickerSingle ? 'radio' : 'checkbox' ?>" name="<?= e($pickerField) ?><?= $pickerSingle ? '' : '[]' ?>" value="<?= (int) $img['id'] ?>" <?= $on ? 'checked' : '' ?>>
                    <img src="<?= e(upload_url((string) $img['thumb'])) ?>" alt="" loading="lazy">
                    <?php if ((string) $img['caption'] !== ''): ?><span class="image-picker__cap"><?= e((string) $img['caption']) ?></span><?php endif; ?>
                </label>
            <?php endforeach; ?>
        </div>
        <?php if (!$pickerSingle): ?>
            <div class="image-picker__order">
                <span class="muted">Reihenfolge – ziehen zum Sortieren, ✕ entfernt:</span>
                <div class="image-picker__sel"></div>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>
