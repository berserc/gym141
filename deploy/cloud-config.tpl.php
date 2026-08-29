<?php

/**
 * Konfigurations-Vorlage fuer verwaltete Installationen (DevWorld Cloud).
 *
 * Der Provisioner ersetzt die {{platzhalter}} und legt das Ergebnis als
 * app/config.php ab. Die Datei steht in der preserve-Liste des Manifests
 * (product.json) und ueberlebt damit Produkt-Updates.
 *
 * Im Gegensatz zu app/config.example.php gibt es hier keine Host-Erkennung:
 * Ein Cloud-Tenant laeuft immer als Produktivumgebung unter genau einem
 * kanonischen Hostnamen.
 */

return [
    'env' => 'live',

    'app_name' => '{{app_name}}',

    'base_path' => '',

    'db_path' => dirname(__DIR__) . '/data/gym141.sqlite',

    'upload_dir' => dirname(__DIR__) . '/public/uploads',
    'upload_url' => '/uploads',

    'timezone' => 'Europe/Vienna',

    'debug' => false,
    'noindex' => false,
    'show_env_banner' => false,

    'session_name' => 'gym141_sess',

    'canonical_host' => '{{primary_host}}',

    'login_max_attempts' => 10,

    'country_code' => '{{country_code}}',

    'mail_from' => '',

    // Web-Installer bleibt in der Cloud dauerhaft gesperrt –
    // die Einrichtung uebernimmt der Provisioner via bin/install.php.
    'setup_key' => '',

    // Cloud-Tenants zahlen fuers Hosting – das Gratis-Mitgliederlimit der
    // Open-Source-Version (25) gilt hier nicht.
    'free_member_limit' => 100000,
];
