<?php

/**
 * Schlankes Layout fuer den Mitglieder-Login.
 *
 * @var string $content
 * @var string $title
 * @var list<array{type:string,message:string}> $flash
 */
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= e($title !== '' ? $title : 'Mitglieder-Login') ?></title>
    <meta name="theme-color" content="#101014">
    <link rel="stylesheet" href="<?= e(asset('css/site.css')) ?>">
    <link rel="icon" href="<?= e(asset('img/favicon.svg')) ?>" type="image/svg+xml">
</head>
<body class="page member-blank">

<main class="member-login-wrap">
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
</body>
</html>
