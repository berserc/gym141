<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;
use ZipArchive;

/**
 * Ein-Klick-Updater für Shared Webspace.
 *
 * Ablauf: Manifest vom Update-Server laden -> Versionen vergleichen -> auf
 * Wunsch das Release-ZIP laden (SHA-256-geprüft), die Datenbank sichern, die
 * Anwendungsdateien ersetzen (Nutzerdaten bleiben unangetastet, siehe
 * PROTECTED) und die Datenbank-Migrationen des Installers ausführen.
 *
 * Manifest-Format (JSON):
 *   { "version": "1.1.0", "zip_url": "https://.../gym141-1.1.0.zip",
 *     "sha256": "...", "min_php": "8.1", "changelog": "..." }
 */
final class Updater
{
    /** Pfade (relativ zum Projektstamm), die ein Update NIE anfasst. */
    private const PROTECTED = [
        'data/',
        'public/uploads/',
        'app/config.php',
        'deploy/deploy.config.json',
        '.git/',
    ];

    public static function currentVersion(): string
    {
        $datei = self::root() . '/VERSION';

        return is_file($datei) ? trim((string) file_get_contents($datei)) : '0.0.0';
    }

    public static function manifestUrl(): string
    {
        return (string) Config::get(
            'update_manifest_url',
            'https://devworld-llc.com/updates/gym141/manifest.json'
        );
    }

    /**
     * Manifest laden; null wenn nicht erreichbar/ungültig.
     *
     * @return array{version:string,zip_url:string,sha256:string,min_php:string,changelog:string}|null
     */
    public static function manifest(): ?array
    {
        $json = self::httpGet(self::manifestUrl());

        if ($json === null) {
            return null;
        }

        $daten = json_decode($json, true);

        if (!is_array($daten) || !isset($daten['version'], $daten['zip_url'], $daten['sha256'])) {
            return null;
        }

        return [
            'version'   => (string) $daten['version'],
            'zip_url'   => (string) $daten['zip_url'],
            'sha256'    => strtolower((string) $daten['sha256']),
            'min_php'   => (string) ($daten['min_php'] ?? '8.1'),
            'changelog' => (string) ($daten['changelog'] ?? ''),
        ];
    }

    /** Steht laut Manifest eine neuere Version bereit? */
    public static function updateAvailable(?array $manifest): bool
    {
        return $manifest !== null
            && version_compare($manifest['version'], self::currentVersion(), '>');
    }

    /**
     * Update einspielen. Liefert das Protokoll als Zeilenliste;
     * wirft RuntimeException bei Fehlern (vor dem Kopieren = gefahrlos).
     *
     * @param array{version:string,zip_url:string,sha256:string,min_php:string,changelog:string} $manifest
     * @return list<string>
     */
    public static function apply(array $manifest): array
    {
        $log  = [];
        $root = self::root();

        if (version_compare(PHP_VERSION, $manifest['min_php'], '<')) {
            throw new RuntimeException(sprintf(
                'Version %s benötigt PHP %s – dieser Server hat %s.',
                $manifest['version'],
                $manifest['min_php'],
                PHP_VERSION
            ));
        }

        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('Die PHP-Erweiterung "zip" fehlt auf diesem Server.');
        }

        if (!is_writable($root . '/app') || !is_writable($root . '/public')) {
            throw new RuntimeException('Keine Schreibrechte auf app/ bzw. public/ – Update nicht möglich.');
        }

        $tmp = $root . '/data/tmp';

        if (!is_dir($tmp) && !mkdir($tmp, 0775, true) && !is_dir($tmp)) {
            throw new RuntimeException('data/tmp konnte nicht angelegt werden.');
        }

        // 1. ZIP laden und Prüfsumme kontrollieren
        $zipDatei = $tmp . '/update-' . $manifest['version'] . '.zip';
        $inhalt   = self::httpGet($manifest['zip_url'], 300);

        if ($inhalt === null || file_put_contents($zipDatei, $inhalt) === false) {
            throw new RuntimeException('Download des Updates fehlgeschlagen.');
        }

        $log[] = sprintf('Update-Paket geladen (%.1f MB).', strlen($inhalt) / 1048576);

        if (hash_file('sha256', $zipDatei) !== $manifest['sha256']) {
            unlink($zipDatei);
            throw new RuntimeException('Prüfsumme des Update-Pakets stimmt nicht – Abbruch.');
        }

        $log[] = 'SHA-256-Prüfsumme in Ordnung.';

        // 2. Datenbank sichern
        $dbPfad = (string) Config::get('db_path');

        if (is_file($dbPfad)) {
            $backupDir = $root . '/data/backups';

            if (!is_dir($backupDir)) {
                mkdir($backupDir, 0775, true);
            }

            $backup = $backupDir . '/vor-update-' . $manifest['version'] . '-' . date('Ymd-His') . '.sqlite';
            Database::run('VACUUM INTO ?', [$backup]);
            $log[] = 'Datenbank gesichert: data/backups/' . basename($backup);
        }

        // 3. Entpacken nach data/tmp
        $entpackt = $tmp . '/update-' . $manifest['version'];
        self::rrmdir($entpackt);
        mkdir($entpackt, 0775, true);

        $zip = new ZipArchive();

        if ($zip->open($zipDatei) !== true) {
            throw new RuntimeException('Update-Paket konnte nicht geöffnet werden.');
        }

        $zip->extractTo($entpackt);
        $zip->close();

        // ZIPs mit einem einzelnen Wurzelordner (gym141-1.1.0/...) auspacken.
        $eintraege = array_values(array_diff(scandir($entpackt) ?: [], ['.', '..']));

        if (count($eintraege) === 1 && is_dir($entpackt . '/' . $eintraege[0])) {
            $entpackt .= '/' . $eintraege[0];
        }

        if (!is_file($entpackt . '/VERSION') || !is_dir($entpackt . '/app')) {
            throw new RuntimeException('Das Update-Paket sieht nicht wie ein Gym141-Release aus.');
        }

        // 4. Dateien übernehmen (ab hier kein Abbruch mehr)
        $kopiert = self::copyTree($entpackt, $root);
        $log[]   = $kopiert . ' Dateien aktualisiert (Nutzerdaten unangetastet).';

        // 5. Datenbank-Migrationen (idempotent, legt keinen Benutzer an)
        foreach ((new Installer())->run('', '', false, $dbPfad) as $zeile) {
            $log[] = 'Migration: ' . $zeile;
        }

        // 6. Aufräumen
        unlink($zipDatei);
        self::rrmdir($tmp . '/update-' . $manifest['version']);

        $log[] = 'Update auf Version ' . self::currentVersion() . ' abgeschlossen.';

        return $log;
    }

    // --------------------------------------------------------------- Intern --

    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    private static function isProtected(string $relativ): bool
    {
        foreach (self::PROTECTED as $schutz) {
            if ($relativ === rtrim($schutz, '/') || str_starts_with($relativ, $schutz)) {
                return true;
            }
        }

        return false;
    }

    /** Kopiert den entpackten Stand über die Installation; liefert die Anzahl. */
    private static function copyTree(string $quelle, string $ziel): int
    {
        $anzahl   = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($quelle, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        /** @var \SplFileInfo $eintrag */
        foreach ($iterator as $eintrag) {
            $relativ = str_replace('\\', '/', substr((string) $eintrag->getPathname(), strlen($quelle) + 1));

            if (self::isProtected($relativ)) {
                continue;
            }

            $zielPfad = $ziel . '/' . $relativ;

            if ($eintrag->isDir()) {
                if (!is_dir($zielPfad)) {
                    mkdir($zielPfad, 0775, true);
                }

                continue;
            }

            $ordner = dirname($zielPfad);

            if (!is_dir($ordner)) {
                mkdir($ordner, 0775, true);
            }

            if (copy((string) $eintrag->getPathname(), $zielPfad)) {
                $anzahl++;
            }
        }

        return $anzahl;
    }

    private static function rrmdir(string $pfad): void
    {
        if (!is_dir($pfad)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($pfad, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        /** @var \SplFileInfo $eintrag */
        foreach ($iterator as $eintrag) {
            $eintrag->isDir() ? rmdir((string) $eintrag->getPathname()) : unlink((string) $eintrag->getPathname());
        }

        rmdir($pfad);
    }

    private static function httpGet(string $url, int $timeout = 20): ?string
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS      => 3,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT        => $timeout,
            ]);

            $antwort = curl_exec($ch);
            $status  = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

            return $antwort === false || $status !== 200 ? null : (string) $antwort;
        }

        $kontext = stream_context_create(['http' => ['timeout' => $timeout, 'ignore_errors' => true]]);
        $antwort = @file_get_contents($url, false, $kontext);

        $status = 0;
        foreach ($http_response_header ?? [] as $zeile) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $zeile, $m) === 1) {
                $status = (int) $m[1];
            }
        }

        return $antwort === false || $status !== 200 ? null : $antwort;
    }
}
