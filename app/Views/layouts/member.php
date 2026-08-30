<?php

use App\Core\MemberAuth;

/**
 * Layout des Mitgliederbereichs (/mitglied) – dunkles Design wie die Website.
 *
 * @var string $content
 * @var string $title
 * @var string $appName
 * @var list<array{type:string,message:string}> $flash
 */
$aktiv   = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$mitglied = MemberAuth::member();
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= e($title !== '' ? $title . ' | Mitgliederbereich' : 'Mitgliederbereich') ?></title>
    <meta name="theme-color" content="#101014">
    <link rel="stylesheet" href="<?= e(asset('css/site.css')) ?>">
    <?= theme_css() ?>
    <link rel="icon" href="<?= e(asset('img/favicon.svg')) ?>" type="image/svg+xml">
</head>
<body class="page">

<header class="site-header">
    <div class="wrap site-header__inner">
        <a class="site-brand" href="<?= e(url('/mitglied')) ?>">
            <?php if (site_logo() !== ''): ?>
                <img src="<?= e(site_logo()) ?>" alt="" width="200" height="200">
            <?php endif; ?>
            <span class="site-brand__name">
                <?= e(\App\Models\Setting::get('club_name', $appName ?? 'Gym141')) ?>
                <small>Mitgliederbereich</small>
            </span>
        </a>

        <nav class="site-nav" aria-label="Mitgliedernavigation">
            <a href="<?= e(url('/mitglied')) ?>"<?= $aktiv === url('/mitglied') ? ' aria-current="page"' : '' ?>>Übersicht</a>
            <a href="<?= e(url('/mitglied/termine')) ?>"<?= $aktiv === url('/mitglied/termine') ? ' aria-current="page"' : '' ?>>Termine</a>
            <a href="<?= e(url('/mitglied/entwicklung')) ?>"<?= $aktiv === url('/mitglied/entwicklung') ? ' aria-current="page"' : '' ?>>Entwicklung</a>
            <a href="<?= e(url('/mitglied/passwort')) ?>"<?= $aktiv === url('/mitglied/passwort') ? ' aria-current="page"' : '' ?>>Passwort</a>
        </nav>

        <?php if ($mitglied !== null): ?>
            <form method="post" action="<?= e(url('/mitglied/logout')) ?>" class="member-logout">
                <?= csrf_field() ?>
                <?php if (!empty($_SESSION['member_login_admin'])): ?>
                    <a class="btn btn--ghost btn--on-dark btn--sm" href="<?= e(url('/admin')) ?>">Zur Verwaltung</a>
                <?php endif; ?>
                <button class="btn btn--ghost btn--on-dark btn--sm" type="submit">
                    Abmelden (<?= e($mitglied['first_name']) ?>)
                </button>
            </form>
        <?php endif; ?>
    </div>
</header>

<main class="site-main wrap member-main">
    <?php if (!empty($flash)): ?>
        <div class="member-flash-stack">
            <?php foreach ($flash as $message): ?>
                <div class="member-flash member-flash--<?= e($message['type']) ?>" role="status">
                    <?= e($message['message']) ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?= $content ?>
</main>

<footer class="site-footer">
    <div class="wrap">
        <p class="site-footer__name"><?= e(\App\Models\Setting::get('club_name', 'Gym141')) ?></p>
        <p><a href="<?= e(url('/')) ?>">Zur Website</a></p>
    </div>
</footer>
</body>
</html>
