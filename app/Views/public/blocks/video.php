<?php
/** @var array<string,mixed> $cfg */
$datei   = (string) ($cfg['file'] ?? '');
$youtube = (string) ($cfg['youtube'] ?? '');
$poster  = (string) ($cfg['poster'] ?? '');
$caption = (string) ($cfg['caption'] ?? '');

if ($datei === '' && $youtube === '') {
    return;
}
?>
<section class="wrap block block--video">
    <figure>
        <?php if ($datei !== ''): ?>
            <video controls preload="metadata" playsinline
                   <?= $poster !== '' ? 'poster="' . e(upload_url($poster)) . '"' : '' ?>>
                <source src="<?= e(upload_url($datei)) ?>">
            </video>
        <?php else: ?>
            <?php // YouTube erst nach Klick laden (kein Kontakt zu Google vorher) ?>
            <button type="button" class="video-facade js-youtube" data-video="<?= e($youtube) ?>"
                    <?= $poster !== '' ? 'style="background-image:url(\'' . e(upload_url($poster)) . '\')"' : '' ?>>
                <span class="video-facade__play" aria-hidden="true">▶</span>
                <span class="video-facade__hint">Video abspielen – lädt Inhalte von YouTube</span>
            </button>
        <?php endif; ?>
        <?php if ($caption !== ''): ?><figcaption><?= e($caption) ?></figcaption><?php endif; ?>
    </figure>
</section>
