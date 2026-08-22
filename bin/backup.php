<?php

/**
 * Sicherung der Anwendung – geeignet für einen nächtlichen Cronjob:
 *
 *   php /pfad/zu/gym141/bin/backup.php --keep=30
 *
 * Gesichert werden:
 *   1. die SQLite-Datenbank (konsistente Kopie per VACUUM INTO, auch im
 *      laufenden Betrieb) – die letzten --keep Stände bleiben erhalten
 *   2. alle Datei-Ablagen als inkrementelle Spiegel unter data/backups/:
 *      - data/mitglieder/  (Profilbilder, Formulare)   -> mitglieder-dateien/
 *      - data/verein/      (Vereinsdokumente, Fixkosten) -> verein-dateien/
 *      - data/dateien/     (zentrale Dateiablage)      -> ablage-dateien/
 *      Die Dateien werden nie verändert, nur ergänzt – kopiert wird daher
 *      nur, was im Spiegel noch fehlt.
 *
 * Optionen:
 *   --keep=N   Anzahl aufzubewahrender Datenbank-Sicherungen (Vorgabe 14)
 *   --dir=...  abweichendes Sicherungsverzeichnis
 *   --prune    entfernt aus dem Datei-Spiegel, was im Original gelöscht wurde
 *              (z. B. nach DSGVO-Löschungen – sonst bleibt Gelöschtes liegen)
 */

declare(strict_types=1);

use App\Core\Config;
use App\Core\Database;

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Dieses Skript darf nur über die Kommandozeile laufen.\n");
}

require dirname(__DIR__) . '/app/bootstrap.php';

$options = getopt('', ['keep::', 'dir::', 'prune']);
$keep    = max(1, (int) ($options['keep'] ?? 14));
$prune   = array_key_exists('prune', $options);

$dbPath    = (string) Config::get('db_path');
$backupDir = is_string($options['dir'] ?? null) && $options['dir'] !== ''
    ? rtrim($options['dir'], '/\\')
    : dirname($dbPath) . '/backups';

if (!is_dir($backupDir) && !mkdir($backupDir, 0775, true) && !is_dir($backupDir)) {
    exit("FEHLER: Sicherungsverzeichnis $backupDir konnte nicht angelegt werden.\n");
}

// ------------------------------------------------------------- 1. Datenbank --

$target = $backupDir . '/gym141-' . date('Y-m-d_His') . '.sqlite';

// Falls in derselben Sekunde schon gesichert wurde: eindeutigen Namen finden.
for ($i = 2; is_file($target); $i++) {
    $target = $backupDir . '/gym141-' . date('Y-m-d_His') . '-' . $i . '.sqlite';
}

// VACUUM INTO erzeugt eine konsistente Kopie, ohne den Betrieb zu blockieren.
Database::run('VACUUM INTO ?', [$target]);
@chmod($target, 0640);

printf("Datenbank gesichert: %s (%.1f MB)\n", basename($target), filesize($target) / 1048576);

// Alte Datenbank-Sicherungen aufräumen
$files = glob($backupDir . '/gym141-*.sqlite') ?: [];
rsort($files);

foreach (array_slice($files, $keep) as $old) {
    unlink($old);
    echo 'Alte Datenbank-Sicherung entfernt: ' . basename($old) . "\n";
}

// -------------------------------------------------------- 2. Datei-Ablagen --

/** @return array<string,string> relativer Pfad => absoluter Pfad */
$listeDateien = static function (string $basis): array {
    $result = [];

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($basis, FilesystemIterator::SKIP_DOTS)
    );

    /** @var SplFileInfo $eintrag */
    foreach ($iterator as $eintrag) {
        if ($eintrag->isFile()) {
            $relativ = ltrim(str_replace('\\', '/', substr($eintrag->getPathname(), strlen($basis))), '/');
            $result[$relativ] = $eintrag->getPathname();
        }
    }

    return $result;
};

/** Spiegelt eine Ablage inkrementell nach $mirrorDir. */
$spiegleAblage = static function (string $label, string $sourceDir, string $mirrorDir) use ($listeDateien, $prune): void {
    if (!is_dir($sourceDir)) {
        echo "$label: Quelle fehlt (noch keine Dateien) – übersprungen.\n";
        return;
    }

    if (!is_dir($mirrorDir) && !mkdir($mirrorDir, 0775, true) && !is_dir($mirrorDir)) {
        echo "FEHLER: Spiegelverzeichnis $mirrorDir konnte nicht angelegt werden.\n";
        return;
    }

    $quelle  = $listeDateien($sourceDir);
    $spiegel = $listeDateien($mirrorDir);

    $kopiert     = 0;
    $kopierBytes = 0;

    foreach ($quelle as $relativ => $absolut) {
        $ziel = $mirrorDir . '/' . $relativ;

        // Dateien sind unveraenderlich (Zufallsnamen): vorhandene mit gleicher
        // Groesse muessen nicht erneut kopiert werden.
        if (isset($spiegel[$relativ]) && filesize($spiegel[$relativ]) === filesize($absolut)) {
            continue;
        }

        $zielDir = dirname($ziel);

        if (!is_dir($zielDir) && !mkdir($zielDir, 0775, true) && !is_dir($zielDir)) {
            echo "WARNUNG: $zielDir konnte nicht angelegt werden – Datei übersprungen.\n";
            continue;
        }

        if (copy($absolut, $ziel)) {
            @chmod($ziel, 0640);
            $kopiert++;
            $kopierBytes += (int) filesize($absolut);
        } else {
            echo "WARNUNG: $relativ konnte nicht kopiert werden.\n";
        }
    }

    printf(
        "%s: %d insgesamt, %d neu gespiegelt (%.1f MB).\n",
        $label,
        count($quelle),
        $kopiert,
        $kopierBytes / 1048576
    );

    // Auf Wunsch: im Original geloeschte Dateien auch aus dem Spiegel entfernen.
    if ($prune) {
        $entfernt = 0;

        foreach ($spiegel as $relativ => $absolut) {
            if (!isset($quelle[$relativ])) {
                unlink($absolut);
                $entfernt++;
            }
        }

        // Leer gewordene Unterordner aufraeumen
        foreach (glob($mirrorDir . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
            if (count(scandir($dir) ?: []) === 2) {
                @rmdir($dir);
            }
        }

        if ($entfernt > 0) {
            echo "$label: $entfernt gelöschte Datei(en) aus dem Spiegel entfernt (--prune).\n";
        }
    } else {
        $verwaist = count(array_diff_key($spiegel, $quelle));

        if ($verwaist > 0) {
            echo "$label: $verwaist Datei(en) im Spiegel existieren im Original nicht mehr – mit --prune entfernen.\n";
        }
    }
};

$spiegleAblage('Mitglieder-Dateien', BASE_ROOT . '/data/mitglieder', $backupDir . '/mitglieder-dateien');
$spiegleAblage('Vereins-Dateien', BASE_ROOT . '/data/verein', $backupDir . '/verein-dateien');
$spiegleAblage('Dateiablage', BASE_ROOT . '/data/dateien', $backupDir . '/ablage-dateien');
