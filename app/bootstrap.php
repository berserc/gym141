<?php

declare(strict_types=1);

use App\Core\Config;

if (PHP_VERSION_ID < 80100) {
    http_response_code(500);
    exit('Diese Anwendung benötigt PHP 8.1 oder neuer. Gefunden: ' . PHP_VERSION);
}

const APP_ROOT = __DIR__;
define('BASE_ROOT', dirname(__DIR__));

// ------------------------------------------------------------- Autoloader --
spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';

    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
    $file     = APP_ROOT . '/' . $relative . '.php';

    if (is_file($file)) {
        require $file;
    }
});

require APP_ROOT . '/helpers.php';

// ---------------------------------------------------------- Konfiguration --
$configFile = APP_ROOT . '/config.php';

if (!is_file($configFile)) {
    // Erststart: Beispielkonfiguration verwenden, damit der Installer laufen kann.
    $configFile = APP_ROOT . '/config.example.php';
}

Config::load($configFile);

date_default_timezone_set((string) Config::get('timezone', 'Europe/Vienna'));
mb_internal_encoding('UTF-8');
setlocale(LC_ALL, 'de_AT.UTF-8', 'de_AT', 'German_Austria', 'de');

// ------------------------------------------------------------ Fehlerausgabe --
$debug = (bool) Config::get('debug', false);

ini_set('display_errors', $debug ? '1' : '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);
