<?php

/**
 * Konfiguration.
 *
 * Diese Datei nach app/config.php kopieren und anpassen.
 * app/config.php wird nicht versioniert (siehe .gitignore).
 *
 * Die Anwendung läuft unter mehreren Hostnamen gleichzeitig:
 *
 *   example.org       -> Produktivbetrieb  (data/gym141.sqlite)
 *   dev.example.org   -> Testumgebung      (data/gym141-dev.sqlite)
 *   localhost          -> lokale Entwicklung
 *
 * Ob beide Hostnamen auf dasselbe Verzeichnis zeigen oder auf zwei getrennte
 * Installationen, ist der Anwendung egal – sie arbeitet ausschließlich mit
 * relativen Pfaden. Zeigen beide auf dasselbe Verzeichnis, sorgt die
 * Fallunterscheidung unten dafür, dass die Testumgebung eine eigene
 * Datenbank und eine eigene Sitzung bekommt.
 */

$host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));

// Port abschneiden (localhost:8123)
if (str_contains($host, ':')) {
    $host = (string) strstr($host, ':', true);
}

$isLocal = $host === '' || $host === 'localhost' || $host === '127.0.0.1'
    || str_ends_with($host, '.local') || str_ends_with($host, '.test');

$isDev = $isLocal || str_starts_with($host, 'dev.') || str_starts_with($host, 'test.');

// Auf der Kommandozeile (Installer, Backup, Cronjob) entscheidet die
// Umgebungsvariable GYM141_ENV; ohne Angabe wird der Produktivbetrieb bedient.
if (PHP_SAPI === 'cli') {
    $cliEnv  = strtolower((string) (getenv('GYM141_ENV') ?: 'live'));
    $isDev   = in_array($cliEnv, ['dev', 'test', 'local'], true);
    $isLocal = $cliEnv === 'local';
}

return [
    // 'dev' oder 'live' – steuert Datenbank, Fehlerausgabe und Suchmaschinen
    'env' => $isDev ? 'dev' : 'live',

    // Anzeigename in Titel und Kopfzeile
    'app_name' => 'Gym141',

    // Basis-Pfad, falls die Anwendung NICHT direkt im Docroot liegt.
    // Docroot zeigt auf public/  -> ''
    // Anwendung liegt in /gym141/  -> '/gym141'
    'base_path' => '',

    // Getrennte Datenbanken, damit Tests niemals Echtdaten berühren.
    'db_path' => dirname(__DIR__) . '/data/' . ($isDev ? 'gym141-dev.sqlite' : 'gym141.sqlite'),

    // Uploads (Sektionsbilder)
    'upload_dir' => dirname(__DIR__) . '/public/uploads',
    'upload_url' => '/uploads',

    'timezone' => 'Europe/Vienna',

    // Detaillierte Fehlermeldungen nur in der Testumgebung.
    'debug' => $isDev,

    // Testumgebung aus Suchmaschinen heraushalten (robots.txt + Meta-Tag).
    'noindex' => $isDev,

    // Deutlich sichtbarer Hinweis, dass man nicht auf der Echtseite ist.
    'show_env_banner' => $isDev,

    // Eigener Sitzungsname je Umgebung: sonst überschreiben sich die
    // Anmeldungen von example.org und dev.example.org gegenseitig.
    'session_name' => $isDev ? 'gym141_dev_sess' : 'gym141_sess',

    // Kanonische Adresse (nur Hostname) für Sitemap und robots.txt.
    // Leer lassen = wird automatisch aus der aufgerufenen Adresse übernommen.
    'canonical_host' => '',

    // Nach wie vielen Fehlversuchen pro IP der Login für 15 Minuten sperrt.
    'login_max_attempts' => 10,

    // Ländervorwahl für klickbare Telefonnummern ohne Vorwahl.
    'country_code' => '43',

    // Absenderadresse für System-Mails (aktuell nur Platzhalter)
    'mail_from' => '',

    // Einmal-Schlüssel für den Web-Installer unter /setup.php.
    // Nach der Einrichtung auf '' setzen, dann ist der Installer gesperrt.
    'setup_key' => '',

    // Optional: DevWorld-Lizenzschlüssel fest hinterlegen statt ihn in den
    // Einstellungen zu pflegen (z. B. für verwaltete Installationen).
    // 'devworld_license_key' => 'DW-XXXX-XXXX-XXXX-XXXX',

    // Optional: zusätzliche Pfade, die der Ein-Klick-Updater NIE überschreibt
    // (eigene Startseite, Logos, Videos …) – relativ zum Projektstamm.
    // 'update_protected' => ['app/Views/public/home.php', 'public/assets/img/logo.jpg'],
];
