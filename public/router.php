<?php

/**
 * Router für den eingebauten PHP-Entwicklungsserver.
 *
 *   php -S localhost:8123 -t public public/router.php
 *
 * Auf dem Produktivsystem übernimmt Apache diese Aufgabe per .htaccess;
 * diese Datei wird dort nicht verwendet.
 */

declare(strict_types=1);

$path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$file = __DIR__ . $path;

// Vorhandene Dateien (CSS, Bilder …) direkt ausliefern
if ($path !== '/' && is_file($file)) {
    return false;
}

require __DIR__ . '/index.php';
