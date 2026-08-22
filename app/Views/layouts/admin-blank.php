<?php

/**
 * Minimales Verwaltungs-Layout ohne Kopfleiste und Navigation –
 * fuer Popups wie die Dateiauswahl.
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
    <title><?= e($title !== '' ? $title . ' | Verwaltung' : 'Verwaltung') ?></title>
    <link rel="stylesheet" href="<?= e(asset('css/site.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('css/admin.css')) ?>">
    <link rel="icon" href="<?= e(asset('img/favicon.svg')) ?>" type="image/svg+xml">
</head>
<body class="admin admin--blank">

<main class="admin-main" style="padding: 1rem 1.2rem">
    <?php if (!empty($flash)): ?>
        <div class="flash-stack">
            <?php foreach ($flash as $message): ?>
                <div class="flash flash--<?= e($message['type']) ?>" role="status">
                    <?= e($message['message']) ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?= $content ?>
</main>

<script src="<?= e(asset('js/admin.js')) ?>" defer></script>
</body>
</html>
