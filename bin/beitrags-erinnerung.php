<?php

/**
 * Erinnerung "offene Mitgliedsbeiträge" per E-Mail.
 *
 * Erzeugt zuerst alle fälligen Beitragszeilen (wie beim Aufruf der Seite
 * /admin/beitraege) und schickt dann eine Liste aller offenen, fälligen
 * Beiträge an die in den Einstellungen hinterlegte Adresse
 * (Einstellung "reminder_email", sonst wird nichts versendet).
 *
 * Gedacht als Cronjob, z. B. monatlich am Monatsersten um 07:00 Uhr:
 *
 *   php /usr/www/users/<benutzer>/gym141/bin/beitrags-erinnerung.php
 *
 * Optionen:
 *   --to=adresse@beispiel.at   abweichender Empfänger
 *   --dry-run                  nichts versenden, nur anzeigen
 */

declare(strict_types=1);

use App\Controllers\FeeController;
use App\Models\FeeRepo;
use App\Models\Setting;

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Dieses Skript darf nur über die Kommandozeile laufen.\n");
}

require dirname(__DIR__) . '/app/bootstrap.php';

$options = getopt('', ['to::', 'dry-run']);

$neu = FeeRepo::generateDue();

if ($neu > 0) {
    echo "$neu neue Beitragszeile(n) für fällige Perioden angelegt.\n";
}

$entries = FeeRepo::openEntries(['only_due' => 1], null);

if ($entries === []) {
    echo "Keine offenen Beiträge – keine Erinnerung nötig.\n";
    exit(0);
}

$to = is_string($options['to'] ?? null) && $options['to'] !== ''
    ? (string) $options['to']
    : trim(Setting::get('reminder_email'));

if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "FEHLER: Keine gültige Empfängeradresse (Einstellung reminder_email).\n");
    exit(1);
}

$summe = array_sum(array_map(static fn (array $e): float => (float) $e['amount'], $entries));

printf("%d offene Beiträge, gesamt %s – Empfänger: %s\n", count($entries), format_money($summe), $to);

if (array_key_exists('dry-run', $options)) {
    foreach ($entries as $e) {
        printf(
            "  %-30s %-20s fällig %s  %8s\n",
            $e['last_name'] . ', ' . $e['first_name'],
            $e['period_label'],
            format_date((string) $e['due_date']),
            format_money((float) $e['amount'])
        );
    }

    echo "(Probelauf – es wurde nichts versendet.)\n";
    exit(0);
}

if (FeeController::mailReminder($to, $entries)) {
    echo "Erinnerung versendet.\n";
    exit(0);
}

fwrite(STDERR, "FEHLER: mail() ist fehlgeschlagen – Mailversand am Server prüfen.\n");
exit(1);
