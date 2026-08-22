<?php

/**
 * Legt Testdaten an, um die Verwaltung mit realistischer Datenmenge zu prüfen.
 * NICHT auf dem Produktivsystem ausführen.
 *
 *   php bin/seed-demo.php            (300 Mitglieder)
 *   php bin/seed-demo.php --count=100
 *   php bin/seed-demo.php --purge    (vorher alle Mitglieder löschen)
 */

declare(strict_types=1);

use App\Core\Database;
use App\Models\FeeRepo;
use App\Models\SectionRepo;

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Dieses Skript darf nur über die Kommandozeile laufen.\n");
}

require dirname(__DIR__) . '/app/bootstrap.php';

$options = getopt('', ['count::', 'purge']);
$count   = max(1, (int) ($options['count'] ?? 300));

$sections = SectionRepo::all();

if ($sections === []) {
    exit("Es gibt noch keine Sektionen. Bitte zuerst bin/install.php ausführen.\n");
}

if (array_key_exists('purge', $options)) {
    Database::run('DELETE FROM fee_entries');
    Database::run('DELETE FROM members');
    echo "Alle bisherigen Mitglieder gelöscht.\n";
}

// Beitragsarten anlegen, falls noch keine existieren.
if ((int) Database::value('SELECT COUNT(*) FROM fee_plans') === 0) {
    foreach ([
        ['Monatsbeitrag Erwachsene', 55.0, 'monatlich', 5],
        ['Monatsbeitrag Jugend', 40.0, 'monatlich', 5],
        ['Quartalsbeitrag', 150.0, 'quartal', 10],
        ['Halbjahresbeitrag', 280.0, 'halbjahr', 15],
        ['Jahresbeitrag', 520.0, 'jahr', 15],
    ] as [$name, $amount, $interval, $dueDay]) {
        Database::insert('fee_plans', [
            'name'     => $name,
            'amount'   => $amount,
            'interval' => $interval,
            'due_day'  => $dueDay,
            'active'   => 1,
        ]);
    }

    echo "5 Beitragsarten angelegt.\n";
}

$plans = Database::all('SELECT * FROM fee_plans WHERE active = 1');

$firstNamesW = ['Anna', 'Lena', 'Sophie', 'Marie', 'Julia', 'Laura', 'Sarah', 'Elisabeth', 'Katharina', 'Magdalena', 'Verena', 'Christina', 'Petra', 'Sabine', 'Andrea'];
$firstNamesM = ['Lukas', 'Maximilian', 'Tobias', 'Florian', 'Michael', 'Thomas', 'Andreas', 'Stefan', 'Johannes', 'Christoph', 'Manfred', 'Herbert', 'Gerhard', 'Josef', 'Martin'];
$lastNames   = ['Gruber', 'Huber', 'Bauer', 'Wagner', 'Müller', 'Pichler', 'Steiner', 'Moser', 'Mayer', 'Hofer', 'Leitner', 'Berger', 'Fuchs', 'Eder', 'Fischer', 'Schmid', 'Winkler', 'Weber', 'Schwarz', 'Maier', 'Brunner', 'Lang', 'Baumgartner', 'Auer', 'Wallner'];
$streets     = ['Hauptstraße', 'Bahnhofstraße', 'Marburgerstrasse', 'Feldgasse', 'Schulgasse', 'Kirchweg', 'Am Anger', 'Gartenweg', 'Weizbachweg', 'Industriestraße'];

$gemeinden = array_map(
    static fn (array $row): string => (string) $row['name'],
    Database::all('SELECT name FROM gemeinden ORDER BY sort_order LIMIT 12')
);

if ($gemeinden === []) {
    $gemeinden = ['Weiz', 'Gleisdorf', 'Passail', 'Anger'];
}

$categories = ['Kind', 'Jugend', 'Erwachsen', 'Familie', 'Ermäßigt'];

$created = 0;

Database::transaction(static function () use (
    $count, $sections, $plans, $firstNamesW, $firstNamesM, $lastNames, $streets,
    $gemeinden, $categories, &$created
): void {
    for ($i = 1; $i <= $count; $i++) {
        $section  = $sections[array_rand($sections)];
        $isFemale = random_int(0, 1) === 1;
        $gender   = $isFemale ? 'w' : 'm';

        $first = $isFemale
            ? $firstNamesW[array_rand($firstNamesW)]
            : $firstNamesM[array_rand($firstNamesM)];

        $last     = $lastNames[array_rand($lastNames)];
        $age      = random_int(6, 60);
        $birth    = date('Y-m-d', strtotime('-' . $age . ' years -' . random_int(0, 364) . ' days'));
        $category = $age < 15 ? 'Kind' : ($age < 19 ? 'Jugend' : $categories[array_rand($categories)]);
        $gemeinde = $gemeinden[array_rand($gemeinden)];
        $status   = random_int(1, 10) === 1 ? 'inaktiv' : 'aktiv';
        $plan     = $plans !== [] && random_int(1, 10) > 1 ? $plans[array_rand($plans)] : null;
        $joined   = date('Y-m-d', strtotime('-' . random_int(0, 700) . ' days'));

        $memberId = Database::insert('members', [
            'section_id'   => (int) $section['id'],
            'member_no'    => sprintf('M%05d', $i),
            'first_name'   => $first,
            'last_name'    => $last,
            'birthdate'    => $birth,
            'gender'       => $gender,
            'street'       => $streets[array_rand($streets)] . ' ' . random_int(1, 99),
            'zip'          => (string) random_int(8160, 8181),
            'city'         => $gemeinde,
            'gemeinde'     => $gemeinde,
            'country'      => 'AT',
            'email'        => strtolower(
                str_replace(['ä', 'ö', 'ü', 'ß'], ['ae', 'oe', 'ue', 'ss'], $first . '.' . $last)
            ) . $i . '@example.at',
            'phone'        => '0664 ' . random_int(1000000, 9999999),
            'fee_amount'   => 0,
            'fee_category' => $category,
            'fee_plan_id'  => $plan === null ? null : (int) $plan['id'],
            // Beitragspflicht beginnt fuer die Demo hoechstens 6 Monate zurueck.
            'fee_since'    => max($joined, date('Y-m-d', strtotime('-' . random_int(0, 180) . ' days'))),
            'status'       => $status,
            'joined_on'    => $joined,
            'notes'        => '',
        ]);

        Database::run(
            'INSERT OR IGNORE INTO member_sections (member_id, section_id, fee_amount, fee_category, status, joined_on)
             VALUES (?, ?, 0, ?, ?, ?)',
            [$memberId, (int) $section['id'], $category, $status, $joined]
        );

        $created++;
    }
});

echo "$created Testmitglieder angelegt.\n";

// Faellige Beitragszeilen erzeugen und einen Teil als bezahlt markieren.
$zeilen = FeeRepo::generateDue();
echo "$zeilen Beitragszeilen für fällige Perioden erzeugt.\n";

$bezahlt = Database::run(
    "UPDATE fee_entries
        SET paid = 1, paid_on = due_date, paid_amount = amount
      WHERE id IN (SELECT id FROM fee_entries WHERE paid = 0 ORDER BY RANDOM()
                    LIMIT (SELECT COUNT(*) * 2 / 3 FROM fee_entries))"
)->rowCount();

echo "$bezahlt davon als bezahlt markiert (Rest bleibt offen).\n";
echo "Zum Entfernen: php bin/seed-demo.php --purge --count=1\n";
