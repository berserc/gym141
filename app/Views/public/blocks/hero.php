<?php
/** @var array<string,mixed> $cfg */
$titel  = (string) ($cfg['title'] ?? '');
$text   = (string) ($cfg['text'] ?? '');
$bild   = (string) ($cfg['image'] ?? '');
$bLabel = (string) ($cfg['button_label'] ?? '');
$bUrl   = (string) ($cfg['button_url'] ?? '');

if ($titel === '' && $text === '' && $bild === '') {
    return;
}
?>
<section class="block block--hero<?= $bild !== '' ? '' : ' block--hero-plain' ?>"
         <?= $bild !== '' ? 'style="--hero-bild: url(\'' . e(upload_url($bild)) . '\')"' : '' ?>>
    <div class="block--hero__shade" aria-hidden="true"></div>
    <div class="wrap block--hero__content">
        <?php if ($titel !== ''): ?><h2 class="block--hero__title"><?= e($titel) ?></h2><?php endif; ?>
        <?php if ($text !== ''): ?><p class="block--hero__text"><?= e($text) ?></p><?php endif; ?>
        <?php if ($bLabel !== '' && $bUrl !== ''): ?>
            <p><a class="btn btn--primary" href="<?= e($bUrl) ?>"><?= e($bLabel) ?></a></p>
        <?php endif; ?>
    </div>
</section>
