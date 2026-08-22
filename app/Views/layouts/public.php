<?php

/**
 * Öffentliches Layout.
 *
 * @var string                        $content
 * @var string                        $title
 * @var string                        $metaDesc
 * @var string                        $appName
 * @var string                        $activePage
 * @var list<array<string,mixed>>     $footerPages
 * @var array<string,string>          $settings
 * @var array<string,mixed>|null      $authUser
 */
$pageTitle = $title !== '' ? $title . ' | ' . $appName : $appName;
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($pageTitle) ?></title>
    <?php if (($metaDesc ?? '') !== ''): ?>
        <meta name="description" content="<?= e($metaDesc) ?>">
    <?php endif; ?>
    <?php if (!empty($noindex)): ?>
        <meta name="robots" content="noindex, nofollow">
    <?php endif; ?>
    <meta name="theme-color" content="#101014">
    <link rel="stylesheet" href="<?= e(asset('css/site.css')) ?>">
    <link rel="icon" href="<?= e(asset('img/favicon.svg')) ?>" type="image/svg+xml">
</head>
<body class="page page--<?= e($activePage) ?>">
<a class="skip-link" href="#inhalt">Direkt zum Inhalt</a>

<?php if (!empty($showEnvBanner)): ?>
    <div class="env-banner" role="status">
        Testumgebung – Änderungen hier wirken sich <strong>nicht</strong> auf die Produktivseite aus.
    </div>
<?php endif; ?>

<header class="site-header">
    <div class="wrap site-header__inner">
        <a class="site-brand" href="<?= e(url('/')) ?>">
            <?php if (site_logo() !== ''): ?>
                <img src="<?= e(site_logo()) ?>" alt="" width="200" height="200">
            <?php endif; ?>
            <span class="site-brand__name">
                <?= e($settings['club_name'] ?? $appName) ?>
                <?php if (($settings['club_tagline'] ?? '') !== ''): ?>
                    <small><?= e($settings['club_tagline']) ?></small>
                <?php endif; ?>
            </span>
        </a>

        <nav class="site-nav" aria-label="Hauptnavigation">
            <a href="<?= e(url('/')) ?>"<?= $activePage === 'home' ? ' aria-current="page"' : '' ?>>Training</a>
        </nav>
    </div>
</header>

<main id="inhalt" class="site-main">
    <?= $content ?>
</main>

<?php if (whatsapp_link() !== ''): ?>
<a class="wa-fab" target="_blank" rel="noopener"
   href="<?= e(whatsapp_link('Hallo! Ich möchte gerne ein Probetraining vereinbaren.')) ?>"
   aria-label="Probetraining per WhatsApp vereinbaren">
    <svg viewBox="0 0 32 32" width="26" height="26" aria-hidden="true" fill="currentColor">
        <path d="M16 3C9.4 3 4 8.3 4 14.9c0 2.5.8 4.9 2.2 6.9L4.4 28l6.4-1.7c1.7.9 3.5 1.4 5.3 1.4 6.6 0 12-5.3 12-11.9C28 8.3 22.6 3 16 3zm0 21.6c-1.6 0-3.2-.5-4.6-1.3l-.5-.3-3.8 1 1-3.6-.4-.6c-1.2-1.7-1.9-3.7-1.9-5.9 0-5.5 4.6-10 10.2-10s10.2 4.5 10.2 10-4.6 9.7-10.2 9.7zm5.6-7.4c-.3-.2-1.8-.9-2.1-1-.3-.1-.5-.2-.7.2-.2.3-.8 1-.9 1.2-.2.2-.3.2-.6.1-.3-.2-1.3-.5-2.4-1.5-.9-.8-1.5-1.8-1.7-2.1-.2-.3 0-.5.1-.6l.5-.5c.1-.2.2-.3.3-.5.1-.2 0-.4 0-.6-.1-.2-.7-1.7-1-2.3-.2-.6-.5-.5-.7-.5h-.6c-.2 0-.5.1-.8.4-.3.3-1.1 1-1.1 2.5s1.1 2.9 1.3 3.1c.2.2 2.2 3.4 5.4 4.7.8.3 1.4.5 1.8.7.8.2 1.5.2 2 .1.6-.1 1.8-.7 2.1-1.5.3-.7.3-1.3.2-1.5-.1-.1-.3-.2-.6-.4z"/>
    </svg>
    <span>Probetraining</span>
</a>
<?php endif; ?>

<footer class="site-footer">
    <div class="wrap site-footer__inner">
        <div class="site-footer__col">
            <p class="site-footer__name"><?= e($settings['club_name'] ?? 'Mein Verein') ?></p>
            <?php if (($settings['club_street'] ?? '') !== ''): ?>
                <p><?= e($settings['club_street']) ?><br>
                   <?= e(trim(($settings['club_zip'] ?? '') . ' ' . ($settings['club_city'] ?? ''))) ?></p>
            <?php endif; ?>
            <?php if (($settings['club_zvr'] ?? '') !== ''): ?>
                <p>ZVR: <?= e($settings['club_zvr']) ?></p>
            <?php endif; ?>
        </div>

        <div class="site-footer__col">
            <?php if (($settings['club_email'] ?? '') !== ''): ?>
                <p><?= mail_link($settings['club_email'], 'contact-link contact-link--invert') ?></p>
            <?php endif; ?>
            <?php if (($settings['club_phone'] ?? '') !== ''): ?>
                <p><?= tel_link($settings['club_phone'], 'contact-link contact-link--invert') ?></p>
            <?php endif; ?>
        </div>

        <?php // Der Verwaltungsbereich wird auf der öffentlichen Seite bewusst nicht verlinkt. ?>
        <nav class="site-footer__col site-footer__nav" aria-label="Rechtliches">
            <?php foreach ($footerPages as $footerPage): ?>
                <a href="<?= e(url('/seite/' . $footerPage['slug'])) ?>"><?= e($footerPage['title']) ?></a>
            <?php endforeach; ?>
        </nav>
    </div>
</footer>
</body>
</html>
