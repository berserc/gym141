<?php

/**
 * Tickets-Block: Ticketshop aus Ticket141 – als eingebettetes Widget
 * (embed.js, Höhe passt sich automatisch an) oder als Button zum Shop.
 * Ohne hinterlegte Ticket141-Adresse wird nichts ausgegeben.
 *
 * @var array<string,mixed> $cfg
 */
$slug  = trim((string) ($cfg['slug'] ?? ''));
$modus = ($cfg['mode'] ?? 'embed') === 'button' ? 'button' : 'embed';
$code  = trim((string) ($cfg['code'] ?? ''));
$titel = trim((string) ($cfg['title'] ?? ''));
$hoehe = max(200, min(3000, (int) ($cfg['height'] ?? 640)));

if ($slug === '' || !\App\Core\Ticket141Client::urlConfigured()) {
    return;
}

$t141 = new \App\Core\Ticket141Client();
?>
<section class="wrap block block--tickets">
    <?php if ($titel !== ''): ?><h2><?= e($titel) ?></h2><?php endif; ?>

    <?php if ($modus === 'button'): ?>
        <p class="block--tickets__action">
            <a class="btn btn--primary" href="<?= e($t141->shopUrl($slug, $code)) ?>" target="_blank" rel="noopener">Tickets kaufen</a>
        </p>
    <?php else: ?>
        <div data-ticket141="<?= e($slug) ?>"<?= $code !== '' ? ' data-code="' . e($code) . '"' : '' ?> data-height="<?= (int) $hoehe ?>"></div>
        <noscript>
            <p><a class="btn btn--primary" href="<?= e($t141->shopUrl($slug, $code)) ?>" target="_blank" rel="noopener">Tickets kaufen</a></p>
        </noscript>
        <?php if (empty($GLOBALS['gym141Ticket141Js'])): $GLOBALS['gym141Ticket141Js'] = true; ?>
            <script src="<?= e($t141->embedScriptUrl()) ?>" defer></script>
        <?php endif; ?>
    <?php endif; ?>
</section>
