<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/**
 * Liest Konto-Exports verschiedener Banken (CSV oder XLSX) und normalisiert
 * sie auf: booked_on, amount, currency, counterpart, iban, reference.
 *
 * Die Spalten werden anhand der Kopfzeile erkannt (deutsche und englische
 * Bezeichnungen der gaengigen Banken: Raiffeisen, Erste/George, BAWAG,
 * Sparkasse, N26, Revolut ...). Exporte OHNE Kopfzeile (z. B. Raiffeisen
 * Umsatzliste: Datum;Text;Betrag;...) werden ueber eine Struktur-Heuristik
 * erkannt. Trennzeichen (;, ,, Tab) und Kodierung (UTF-8, ISO-8859-1)
 * werden automatisch bestimmt.
 */
final class BankStatementParser
{
    /** Erkennungsmuster je Zielfeld (Reihenfolge = Prioritaet). */
    private const HEADER_PATTERNS = [
        'booked_on'   => ['buchungsdatum', 'buchungstag', 'buchung', 'valutadatum', 'valuta', 'datum', 'booking date', 'date', 'started date', 'completed date'],
        'amount'      => ['betrag', 'amount', 'umsatz', 'soll/haben'],
        'currency'    => ['währung', 'waehrung', 'currency', 'iso-code'],
        'counterpart' => ['auftraggebername', 'name des partners', 'partnername', 'empfänger/auftraggeber', 'empfaenger', 'empfänger', 'auftraggeber/empfänger', 'zahlungsempfänger', 'auftraggeber', 'name', 'payee', 'partner', 'description'],
        'iban'        => ['iban des partners', 'partner iban', 'iban/kontonummer', 'kontonummer des partners', 'iban', 'account'],
        'reference'   => ['verwendungszweck', 'zahlungsreferenz', 'buchungstext', 'umsatztext', 'buchungs-details', 'text', 'reference', 'zahlungsgrund', 'beschreibung'],
    ];

    /**
     * @return array{rows: list<array<string,string|float>>, mapping: array<string,int>}
     */
    public static function parse(string $path, string $originalName): array
    {
        $ext = strtolower((string) pathinfo($originalName, PATHINFO_EXTENSION));

        $table = $ext === 'xlsx'
            ? XlsxReader::read($path)
            : self::readCsv($path);

        // Leere Zeilen entfernen
        $table = array_values(array_filter(
            $table,
            static fn (array $r): bool => trim(implode('', $r)) !== ''
        ));

        if ($table === []) {
            throw new RuntimeException('Die Datei enthält keine Datenzeilen.');
        }

        [$mapping, $dataStart] = self::detectColumns($table);

        if (!isset($mapping['booked_on'], $mapping['amount'])) {
            throw new RuntimeException(
                'Spalten nicht erkannt – die Datei braucht zumindest ein Buchungsdatum und einen Betrag. '
                . 'Erkannt wurde: ' . ($mapping === [] ? 'nichts' : implode(', ', array_keys($mapping)))
            );
        }

        $rows = [];

        foreach (array_slice($table, $dataStart) as $line) {
            $datum  = self::parseDate((string) ($line[$mapping['booked_on']] ?? ''), $ext === 'xlsx');
            $betrag = self::parseAmount((string) ($line[$mapping['amount']] ?? ''));

            if ($datum === null || $betrag === null) {
                continue; // Summen-/Leerzeilen still ueberspringen
            }

            $rows[] = [
                'booked_on'   => $datum,
                'amount'      => $betrag,
                'currency'    => strtoupper(trim((string) ($line[$mapping['currency'] ?? -1] ?? ''))) ?: 'EUR',
                'counterpart' => mb_substr(trim((string) ($line[$mapping['counterpart'] ?? -1] ?? '')), 0, 200),
                'iban'        => strtoupper((string) preg_replace('/\s+/', '', (string) ($line[$mapping['iban'] ?? -1] ?? ''))),
                'reference'   => mb_substr(trim((string) ($line[$mapping['reference'] ?? -1] ?? '')), 0, 500),
            ];
        }

        if ($rows === []) {
            throw new RuntimeException('Keine verwertbaren Buchungszeilen gefunden (Datum/Betrag nicht lesbar).');
        }

        return ['rows' => $rows, 'mapping' => $mapping];
    }

    // ------------------------------------------------------------------ CSV --

    /** @return list<list<string>> */
    private static function readCsv(string $path): array
    {
        $raw = (string) file_get_contents($path);

        if ($raw === '') {
            throw new RuntimeException('Die Datei ist leer.');
        }

        // BOM entfernen; Kodierung vereinheitlichen.
        $raw = (string) preg_replace('/^\xEF\xBB\xBF/', '', $raw);

        if (!mb_check_encoding($raw, 'UTF-8')) {
            $raw = (string) mb_convert_encoding($raw, 'UTF-8', 'Windows-1252');
        }

        // Trennzeichen bestimmen: das haeufigste in der ersten nicht leeren Zeile.
        $erste = '';

        foreach (preg_split('/\r\n|\r|\n/', $raw) ?: [] as $zeile) {
            if (trim($zeile) !== '') {
                $erste = $zeile;
                break;
            }
        }

        $kandidaten = [';' => substr_count($erste, ';'), ',' => substr_count($erste, ','), "\t" => substr_count($erste, "\t")];
        arsort($kandidaten);
        $trenner = (string) array_key_first($kandidaten);

        $rows = [];

        foreach (preg_split('/\r\n|\r|\n/', $raw) ?: [] as $zeile) {
            if (trim($zeile) === '') {
                continue;
            }

            $rows[] = array_map('strval', str_getcsv($zeile, $trenner, '"', '\\'));
        }

        return $rows;
    }

    // ------------------------------------------------------ Spaltenerkennung --

    /**
     * @param list<list<string>> $table
     * @return array{0: array<string,int>, 1: int} [Zuordnung Feld=>Spalte, erste Datenzeile]
     */
    private static function detectColumns(array $table): array
    {
        // 1) Kopfzeile suchen (in den ersten 10 Zeilen - manche Exporte haben
        //    Vorspann mit Kontoinhaber/Zeitraum).
        foreach (array_slice($table, 0, 10) as $i => $zeile) {
            $mapping = [];

            foreach ($zeile as $spalte => $wert) {
                $wert = mb_strtolower(trim((string) $wert));

                if ($wert === '') {
                    continue;
                }

                foreach (self::HEADER_PATTERNS as $feld => $muster) {
                    if (isset($mapping[$feld])) {
                        continue;
                    }

                    foreach ($muster as $m) {
                        if ($wert === $m || str_contains($wert, $m)) {
                            $mapping[$feld] = $spalte;
                            break;
                        }
                    }
                }
            }

            if (isset($mapping['booked_on'], $mapping['amount'])) {
                return [$mapping, $i + 1];
            }
        }

        // 2) Ohne Kopfzeile (z. B. Raiffeisen: Datum;Text;Betrag;Valuta;...):
        //    Struktur der ersten Datenzeile analysieren.
        $probe   = $table[0];
        $mapping = [];

        foreach ($probe as $spalte => $wert) {
            $wert = trim((string) $wert);

            if (!isset($mapping['booked_on']) && self::parseDate($wert, false) !== null) {
                $mapping['booked_on'] = $spalte;

                continue;
            }

            if (!isset($mapping['amount']) && isset($mapping['booked_on']) && self::parseAmount($wert) !== null) {
                $mapping['amount'] = $spalte;

                continue;
            }

            if (!isset($mapping['iban']) && preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{8,30}$/', str_replace(' ', '', $wert))) {
                $mapping['iban'] = $spalte;

                continue;
            }

            if (!isset($mapping['currency']) && preg_match('/^[A-Z]{3}$/', $wert)) {
                $mapping['currency'] = $spalte;

                continue;
            }

            if (!isset($mapping['reference']) && mb_strlen($wert) > 5) {
                $mapping['reference'] = $spalte;
            }
        }

        return [$mapping, 0];
    }

    // ------------------------------------------------------------ Feldparser --

    /** Datum in vielen Schreibweisen (auch Excel-Seriennummer) -> YYYY-MM-DD. */
    public static function parseDate(string $wert, bool $excelSerial): ?string
    {
        $wert = trim($wert);

        if ($wert === '') {
            return null;
        }

        if ($excelSerial && preg_match('/^\d{4,6}(\.0+)?$/', $wert)) {
            return XlsxReader::serialToDate((float) $wert);
        }

        foreach (['d.m.Y', 'd.m.y', 'Y-m-d', 'd/m/Y', 'm/d/Y', 'Y-m-d H:i:s', 'd.m.Y H:i', 'd.m.Y H:i:s'] as $format) {
            $dt = \DateTimeImmutable::createFromFormat('!' . $format, $wert);

            if ($dt !== false && (int) $dt->format('Y') >= 1990 && (int) $dt->format('Y') <= 2100) {
                return $dt->format('Y-m-d');
            }
        }

        return null;
    }

    /** Betrag mit Komma/Punkt/Tausendertrennern; leere/Nicht-Zahlen -> null. */
    public static function parseAmount(string $wert): ?float
    {
        $wert = trim(str_replace(["\u{a0}", ' ', '€', 'EUR'], '', $wert));

        if ($wert === '' || preg_match('/^[+-]?[\d.,]+$/', $wert) !== 1) {
            return null;
        }

        $komma = strrpos($wert, ',');
        $punkt = strrpos($wert, '.');

        if ($komma !== false && ($punkt === false || $komma > $punkt)) {
            // deutsches Format: 1.234,56
            $wert = str_replace('.', '', $wert);
            $wert = str_replace(',', '.', $wert);
        } else {
            // englisches Format: 1,234.56
            $wert = str_replace(',', '', $wert);
        }

        return is_numeric($wert) ? round((float) $wert, 2) : null;
    }
}
