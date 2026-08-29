<?php

/**
 * Schema-Aktualisierung einer bestehenden Datenbank.
 *
 * Auf einem Hosting-Paket ohne SSH laesst sich das Schema nicht direkt am
 * Server aktualisieren. Der Weg ist: Datenbank per FTP herunterladen, dieses
 * Skript darauf anwenden, Datenbank zurueckspielen.
 *
 *   php bin/migrate.php --db=data/download/live-....sqlite
 */

declare(strict_types=1);

use App\Core\Database;
use App\Core\Installer;

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Nur ueber die Kommandozeile.\n");
}

require dirname(__DIR__) . '/app/bootstrap.php';

$options = getopt('', ['db::']);
$dbPath  = is_string($options['db'] ?? null) && $options['db'] !== ''
    ? $options['db']
    : (string) App\Core\Config::get('db_path');

if (!is_file($dbPath)) {
    fwrite(STDERR, "Datenbank nicht gefunden: $dbPath\n");
    exit(1);
}

echo "Aktualisiere: $dbPath\n\n";

$oeffne = static function () use ($dbPath): PDO {
    $pdo = new PDO('sqlite:' . $dbPath, null, null, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA foreign_keys = ON');

    return $pdo;
};

$pdo = $oeffne();

$vorher = [];
foreach (['sections', 'members', 'users', 'gemeinden', 'pages'] as $t) {
    $vorher[$t] = (int) $pdo->query("SELECT COUNT(*) FROM $t")->fetchColumn();
}

$spaltenVorher = count($pdo->query('PRAGMA table_info(sections)')->fetchAll());

// Der Installer bringt Migration, Schema und fehlende Einstellungen mit.
// Der Pfad muss ausdruecklich mitgegeben werden – sonst arbeitet er auf der
// Datenbank aus app/config.php statt auf der heruntergeladenen Kopie.
$installer = new Installer();

foreach ($installer->run('', '', false, $dbPath) as $zeile) {
    echo '  ' . $zeile . "\n";
}

// Zum Pruefen frisch oeffnen, damit wirklich die Datei gelesen wird.
$pdo = $oeffne();

echo "\nBestand vorher / nachher:\n";

foreach ($vorher as $t => $n) {
    $nachher = (int) $pdo->query("SELECT COUNT(*) FROM $t")->fetchColumn();
    printf("  %-12s %5d -> %5d%s\n", $t, $n, $nachher, $n === $nachher ? '' : '  (verändert)');
}

$spalten = array_column($pdo->query('PRAGMA table_info(sections)')->fetchAll(), 'name');

printf(
    "  %-12s %5d -> %5d Spalten\n",
    'sections',
    $spaltenVorher,
    count($spalten)
);

// Kontrolle, dass die erwarteten Strukturen wirklich in DIESER Datei stehen.
$memberSpalten = array_column($pdo->query('PRAGMA table_info(members)')->fetchAll(), 'name');
$fehlend       = array_diff(['fee_plan_id', 'fee_since'], $memberSpalten);

$feeTables = $pdo->query(
    "SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name IN ('fee_plans', 'fee_entries')"
)->fetchColumn();

if ($fehlend !== [] || (int) $feeTables !== 2) {
    echo "\nFEHLER: Beitragsverwaltung unvollständig (fehlend: "
        . implode(', ', $fehlend)
        . ((int) $feeTables !== 2 ? ', Tabellen fee_plans/fee_entries' : '')
        . ")\n";
    exit(1);
}

echo "  Beitragsverwaltung (fee_plans, fee_entries, members.fee_plan_id) ist vorhanden.\n";

echo "\nFertig. Datenbank jetzt zurückspielen:\n";
echo "  deploy\\deploy.ps1 -Target live -Action upload-db -Path \"$dbPath\" -Confirm\n";
