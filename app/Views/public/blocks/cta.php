<?php
/** @var array<string,mixed> $cfg */
$titel  = trim((string) ($cfg['title'] ?? ''));
$text   = trim((string) ($cfg['text'] ?? ''));
$bLabel = trim((string) ($cfg['button_label'] ?? ''));
$bUrl   = trim((string) ($cfg['button_url'] ?? ''));

// Option "WhatsApp-Button": Ziel kommt aus der hinterlegten WhatsApp-Nummer.
if (($cfg['whatsapp'] ?? 0) === 1 || ($cfg['whatsapp'] ?? '0') === '1') {
    $wa = whatsapp_link('Hallo! Ich möchte gerne ein Probetraining vereinbaren.');
    if ($wa !== '') {
        $bUrl   = $wa;
        $bLabel = $bLabel !== '' ? $bLabel : 'Probetraining per WhatsApp';
    }
}

if ($titel === '' && $text === '' && ($bLabel === '' || $bUrl === '')) {
    return;
}
?>
<section class="wrap block block--cta">
    <div class="cta-box">
        <?php if ($titel !== ''): ?><h2><?= e($titel) ?></h2><?php endif; ?>
        <?php if ($text !== ''): ?><p><?= e($text) ?></p><?php endif; ?>
        <?php if ($bLabel !== '' && $bUrl !== ''): ?>
            <p class="cta-box__action">
                <a class="btn btn--primary" href="<?= e($bUrl) ?>"
                   <?= str_starts_with($bUrl, 'http') ? 'target="_blank" rel="noopener"' : '' ?>><?= e($bLabel) ?></a>
            </p>
        <?php endif; ?>
    </div>
</section>
