<?php
/** @var array<string,mixed> $cfg */
$bild    = (string) ($cfg['image'] ?? '');
$caption = (string) ($cfg['caption'] ?? '');
$breite  = (string) ($cfg['width'] ?? 'normal');

if ($bild === '') {
    return;
}
?>
<section class="wrap block block--image block--image-<?= e($breite) ?>">
    <figure>
        <img src="<?= e(upload_url($bild)) ?>" alt="<?= e($caption) ?>" loading="lazy">
        <?php if ($caption !== ''): ?><figcaption><?= e($caption) ?></figcaption><?php endif; ?>
    </figure>
</section>
