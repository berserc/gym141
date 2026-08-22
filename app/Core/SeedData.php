<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Grunddaten der Erstinstallation.
 *
 * Neutrale Beispieldaten – werden nur beim allerersten Einrichten verwendet,
 * danach pflegt der Verein alles selbst im Verwaltungsbereich
 * (Sektionen, Wochenplan, Einstellungen, Seiten).
 */
final class SeedData
{
    /**
     * Beispiel-Sektionen (Trainingsgruppen). Aufbau je Eintrag:
     *   [slug, Name, Verein, Kurzbeschreibung, Kontakte]
     * Kontakte je Eintrag:
     *   [Funktion, Name, Telefon, Mobil, Fax, E-Mail]
     *
     * @return list<array{0:string,1:string,2:string,3:string,4:list<array{0:string,1:string,2:string,3:string,4:string,5:string}>}>
     */
    public static function sections(): array
    {
        return [
            ['beispiel-training', 'Beispiel-Training', '', 'Diese Beispiel-Gruppe kannst du im Verwaltungsbereich umbenennen oder löschen.', []],
        ];
    }

    /** @return array<string,string> */
    public static function trainingInfo(): array
    {
        return [];
    }

    /** @return array<string,string> */
    public static function settings(): array
    {
        return [
            'club_name'   => 'Mein Verein',
            'club_street' => '',
            'club_zip'    => '',
            'club_city'   => '',
            'club_zvr'    => '',
            'club_email'  => '',
            'club_phone'  => '',
            'club_iban'   => '',
            'club_bank'   => '',
            'home_title'  => 'Willkommen',
            'home_text'   => '<p>Diese Website wurde soeben mit Gym141 eingerichtet. '
                           . 'Texte, Trainingsgruppen, Wochenplan und Bilder pflegst du im '
                           . 'Verwaltungsbereich unter <strong>/admin</strong>.</p>',
            'fee_year'    => date('Y'),
            // Beitragsvarianten fuer die Sektionsmitgliedschaften (optional)
            'fee_options' => '0;10;20;30',

            // Erinnerung "offene Beitraege": Empfaenger des Cron-Mails
            'reminder_email' => '',

            // Einschreibegebuehr (Vorschlag beim Anlegen/Wiedereintritt)
            'enrollment_fee' => '0',

            // Buchhaltung: Kategorien (mit Strichpunkt getrennt, anpassbar)
            'ledger_categories_in'  => 'Mitgliedsbeitrag;Einschreibegebühr;Kursbeitrag;Verkauf;Veranstaltung;Spende;Sonstige Einnahme',
            'ledger_categories_out' => 'Miete/Betriebskosten;Internet/Telefon;Versicherung;Trainer/Prämie;Ausstattung/Geräte;Getränke/Verbrauch;Veranstaltung;Bank;Sonstige Ausgabe',
        ];
    }
}
