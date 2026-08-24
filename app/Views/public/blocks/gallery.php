<?php
/** @var array<string,mixed> $cfg */
$bilder  = is_array($cfg['images'] ?? null) ? $cfg['images'] : [];
$spalten = (int) ($cfg['columns'] ?? 3);

if ($bilder === []) {
    return;
}
?>
<section class="wrap block block--gallery" style="--gallery-spalten: <?= $spalten ?>">
    <div class="gallery-grid">
        <?php foreach ($bilder as $gBild): ?>
            <?php $datei = (string) ($gBild['file'] ?? ''); if ($datei === '') { continue; } ?>
            <figure class="gallery-item">
                <a href="<?= e(upload_url($datei)) ?>" class="js-lightbox"
                   data-caption="<?= e((string) ($gBild['caption'] ?? '')) ?>">
                    <img src="<?= e(upload_url($datei)) ?>" alt="<?= e((string) ($gBild['caption'] ?? '')) ?>" loading="lazy">
                </a>
                <?php if ((string) ($gBild['caption'] ?? '') !== ''): ?>
                    <figcaption><?= e((string) $gBild['caption']) ?></figcaption>
                <?php endif; ?>
            </figure>
        <?php endforeach; ?>
    </div>
</section>
