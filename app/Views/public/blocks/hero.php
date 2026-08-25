<?php
/** @var array<string,mixed> $cfg */
$titel  = (string) ($cfg['title'] ?? '');
$text   = (string) ($cfg['text'] ?? '');
$bild   = (string) ($cfg['image'] ?? '');
$video  = (string) ($cfg['video'] ?? '');
$bLabel = (string) ($cfg['button_label'] ?? '');
$bUrl   = (string) ($cfg['button_url'] ?? '');
$gross  = ($cfg['size'] ?? '') === 'gross';

if ($titel === '' && $text === '' && $bild === '' && $video === '') {
    return;
}
?>
<section class="block block--hero<?= $bild === '' && $video === '' ? ' block--hero-plain' : '' ?><?= $gross ? ' block--hero-gross' : '' ?>"
         <?= $bild !== '' && $video === '' ? 'style="--hero-bild: url(\'' . e(upload_url($bild)) . '\')"' : '' ?>>
    <?php if ($video !== ''): ?>
        <video class="block--hero__video" autoplay muted loop playsinline
               <?= $bild !== '' ? 'poster="' . e(upload_url($bild)) . '"' : '' ?>
               aria-hidden="true" tabindex="-1">
            <source src="<?= e(upload_url($video)) ?>">
        </video>
    <?php endif; ?>
    <div class="block--hero__shade" aria-hidden="true"></div>
    <div class="wrap block--hero__content">
        <?php if ($titel !== ''): ?><h2 class="block--hero__title"><?= e($titel) ?></h2><?php endif; ?>
        <?php if ($text !== ''): ?><p class="block--hero__text"><?= e($text) ?></p><?php endif; ?>
        <?php if ($bLabel !== '' && $bUrl !== ''): ?>
            <p><a class="btn btn--primary" href="<?= e($bUrl) ?>"><?= e($bLabel) ?></a></p>
        <?php endif; ?>
    </div>
</section>
