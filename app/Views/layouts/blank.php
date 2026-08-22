<?php

/**
 * Minimales Layout ohne Navigation – für die Anmeldeseite.
 *
 * @var string $content
 * @var string $title
 * @var string $appName
 */
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= e($title !== '' ? $title . ' | ' . $appName : $appName) ?></title>
    <link rel="stylesheet" href="<?= e(asset('css/site.css')) ?>">
    <link rel="stylesheet" href="<?= e(asset('css/admin.css')) ?>">
    <link rel="icon" href="<?= e(asset('img/favicon.svg')) ?>" type="image/svg+xml">
</head>
<body class="admin admin--blank">
    <?= $content ?>
</body>
</html>
