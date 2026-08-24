<?php

/**
 * Einbettbarer Wochenplan (läuft im <iframe> auf fremden Websites).
 * Eigenständiges Dokument ohne Site-Layout; meldet seine Höhe per
 * postMessage an die einbettende Seite (siehe public/embed.js).
 *
 * @var array<int,list<array<string,mixed>>> $week
 * @var bool                                 $planLinks
 */
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Wochenplan – <?= e((string) \App\Models\Setting::get('club_name', $appName ?? 'Gym141')) ?></title>
    <link rel="stylesheet" href="<?= e(asset('css/site.css')) ?>">
    <style>
        html, body { background: transparent; }
        body { margin: 0; padding: 4px; }
        .embed-quelle { margin-top: 0.6rem; font-size: 0.72rem; text-align: right; }
        .embed-quelle a { color: inherit; opacity: 0.45; text-decoration: none; }
        .embed-quelle a:hover { opacity: 0.8; }
    </style>
</head>
<body>
    <?php require __DIR__ . '/_plan-grid.php'; ?>

    <p class="embed-quelle"><a href="https://devworld-llc.com/de/gym141" target="_blank" rel="noopener">Wochenplan mit Gym141</a></p>

    <script>
    (function () {
        function melden() {
            parent.postMessage({ gym141Height: document.documentElement.scrollHeight }, '*');
        }
        window.addEventListener('load', melden);
        window.addEventListener('resize', melden);
        new ResizeObserver(melden).observe(document.body);
    })();
    </script>
</body>
</html>
