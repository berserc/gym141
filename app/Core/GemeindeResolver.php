<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Ordnet die Gemeindeangaben aus den Sektionslisten dem amtlichen
 * Gemeindeverzeichnis zu (STATISTIK AUSTRIA).
 *
 * Die Listen enthalten dieselbe Gemeinde in bis zu acht Schreibweisen
 * ("St. Ruprecht", "St.Ruprecht/Raab", "Sankt Rubrecht an der Raab"),
 * Tippfehler, Katastralgemeinden statt Gemeinden und leere Felder.
 * Aufgeloest wird in dieser Reihenfolge:
 *
 *   1. bekannte Sonderfaelle (Katastralgemeinden, Ausland)
 *   2. exakter Name
 *   3. Name nach Normalisierung ("St." -> "Sankt", "/Raab" -> "an der Raab")
 *   4. Postleitzahl – bei mehreren Gemeinden die namensaehnlichste
 *   5. Tippfehler ueber die Levenshtein-Distanz
 */
final class GemeindeResolver
{
    /** @var array<string,string> normalisierter Name => amtlicher Name */
    private array $nachName = [];

    /** @var array<string,list<string>> PLZ => amtliche Namen */
    private array $nachPlz = [];

    /** @var list<string> */
    private array $alle = [];

    /**
     * Ortsteile und Katastralgemeinden, die in den Listen als Gemeinde stehen,
     * sowie auslaendische Wohnorte. Von Hand geprueft.
     *
     * @var array<string,string>
     */
    private const SONDERFAELLE = [
        'gschwendt'         => 'Naas',
        'etzersdorf'        => 'Sankt Ruprecht an der Raab',
        'unterfladnitz'     => 'Sankt Ruprecht an der Raab',
        'oberfeistritz'     => 'Anger',
        'marienhof'         => 'Sankt Ruprecht an der Raab',
        'buechl thannhausen' => 'Thannhausen',
        'büchl thannhausen' => 'Thannhausen',
        'grosspesendorf'    => 'Ilztal',
        'großpesendorf'     => 'Ilztal',
        'lassnitzthal'      => 'Laßnitzhöhe',
        'laßnitzthal'       => 'Laßnitzhöhe',
        'lnitzhöhe'         => 'Laßnitzhöhe',
        'geidorf'           => 'Graz',
        'graz an der lend'  => 'Graz',
        'raaba'             => 'Raaba-Grambach',
        'grambach'          => 'Raaba-Grambach',
        'albersdorf'        => 'Albersdorf-Prebuch',
        'albersdorf prebuch' => 'Albersdorf-Prebuch',
        'prebuch'           => 'Albersdorf-Prebuch',
        'studenzen'         => 'Kirchberg an der Raab',
        'studenzen 148'     => 'Kirchberg an der Raab',
        'gu - nestelbach'   => 'Nestelbach bei Graz',
        'sge'               => '',
        'szombathely'       => 'Ausland (Ungarn)',
        'hamburg'           => 'Ausland (Deutschland)',
        'deutschland'       => 'Ausland (Deutschland)',
        // Mehrdeutige Kurzformen: im Bezirk Weiz ist die Zuordnung eindeutig.
        'pischelsdorf'      => 'Pischelsdorf am Kulm',
        'puch'              => 'Puch bei Weiz',
        'feldkirchen'       => 'Feldkirchen bei Graz',
        'feldkirchen an der graz' => 'Feldkirchen bei Graz',
        'krottendorf'       => 'Krottendorf-Gaisfeld',
        'krotendorf'        => 'Krottendorf-Gaisfeld',
        'kirchberg an der r.' => 'Kirchberg an der Raab',
        'gutenberg-stenzengreith' => 'Gutenberg',
        'stenzengreith'     => 'Gutenberg',
        'rollsdorf'         => 'Sankt Ruprecht an der Raab',
        'dietmannsdorf'     => 'Sankt Ruprecht an der Raab',
        'ruprecht'          => 'Sankt Ruprecht an der Raab',
        'wetzawinkel'       => 'Hofstätten an der Raab',
        // In einigen Listen steht die PLZ von Weiz (8160) statt der eigenen –
        // hier ist der Name die verlaesslichere Angabe.
        'sankt ruprecht'    => 'Sankt Ruprecht an der Raab',
        'mitterdorf'        => 'Mitterdorf an der Raab',
    ];

    public function __construct(string $csvPath)
    {
        $handle = fopen($csvPath, 'rb');

        if ($handle === false) {
            return;
        }

        fgetcsv($handle, 0, ';', '"', '\\');

        while (($row = fgetcsv($handle, 0, ';', '"', '\\')) !== false) {
            if (count($row) < 4) {
                continue;
            }

            $name = trim((string) $row[1]);
            $plz  = trim((string) $row[2]);

            if ($name === '') {
                continue;
            }

            $this->nachName[mb_strtolower($name)] = $name;
            $this->alle[]                         = $name;

            if (preg_match('/^\d{4}$/', $plz)) {
                $this->nachPlz[$plz][] = $name;
            }
        }

        fclose($handle);
    }

    /** Vereinheitlicht Schreibweisen vor dem Vergleich. */
    public static function normalisiere(string $wert): string
    {
        $wert = trim($wert);
        $wert = preg_replace('/^\d{4}\s+/', '', $wert) ?? $wert;
        $wert = preg_replace('/\bSt\.?\s*/iu', 'Sankt ', $wert) ?? $wert;
        $wert = preg_replace('/\ba\.?\s*d\.?\s*/iu', 'an der ', $wert) ?? $wert;
        $wert = preg_replace('#\s*/\s*#', ' an der ', $wert) ?? $wert;
        $wert = preg_replace('/\s+/', ' ', $wert) ?? $wert;

        return trim($wert);
    }

    /**
     * @return array{name:string, art:string}
     */
    public function finde(string $gemeinde, string $ort, string $plz): array
    {
        foreach ([$gemeinde, $ort] as $kandidat) {
            $kandidat = trim($kandidat);

            if ($kandidat === '') {
                continue;
            }

            $klein = mb_strtolower($kandidat);

            if (array_key_exists($klein, self::SONDERFAELLE)) {
                $ziel = self::SONDERFAELLE[$klein];

                return ['name' => $ziel, 'art' => $ziel === '' ? 'unklar' : 'Sonderfall'];
            }

            if (isset($this->nachName[$klein])) {
                return ['name' => $this->nachName[$klein], 'art' => 'exakt'];
            }

            $norm = mb_strtolower(self::normalisiere($kandidat));

            if (array_key_exists($norm, self::SONDERFAELLE)) {
                return ['name' => self::SONDERFAELLE[$norm], 'art' => 'Sonderfall'];
            }

            if (isset($this->nachName[$norm])) {
                return ['name' => $this->nachName[$norm], 'art' => 'Schreibweise'];
            }
        }

        $referenz = self::normalisiere($gemeinde !== '' ? $gemeinde : $ort);

        // Postleitzahl als Entscheider
        if (preg_match('/^\d{4}$/', $plz) && isset($this->nachPlz[$plz])) {
            $kandidaten = $this->nachPlz[$plz];

            if (count($kandidaten) === 1) {
                return ['name' => $kandidaten[0], 'art' => 'über PLZ'];
            }

            $treffer = $this->aehnlichster($referenz, $kandidaten, 4);

            if ($treffer !== null) {
                return ['name' => $treffer, 'art' => 'PLZ + Name'];
            }

            return ['name' => $kandidaten[0], 'art' => 'PLZ, Name unklar'];
        }

        // Tippfehler
        $treffer = $this->aehnlichster($referenz, $this->alle, 2);

        if ($treffer !== null) {
            return ['name' => $treffer, 'art' => 'Tippfehler'];
        }

        return ['name' => '', 'art' => 'ungelöst'];
    }

    /** @param list<string> $kandidaten */
    private function aehnlichster(string $referenz, array $kandidaten, int $maxAbstand): ?string
    {
        if ($referenz === '') {
            return null;
        }

        // Zuerst der Praefix-Fall: "Sankt Ruprecht" gehoert zu
        // "Sankt Ruprecht an der Raab" und nicht zur namensaehnlichsten
        // anderen Gemeinde mit derselben Postleitzahl.
        $klein = mb_strtolower($referenz);

        foreach ($kandidaten as $kandidat) {
            if (str_starts_with(mb_strtolower($kandidat), $klein . ' ')) {
                return $kandidat;
            }
        }

        // Umgekehrt: "Gutenberg-Stenzengreith" gehoert zu "Gutenberg".
        foreach ($kandidaten as $kandidat) {
            $k = mb_strtolower($kandidat);

            if (mb_strlen($k) >= 5 && (str_starts_with($klein, $k . '-') || str_starts_with($klein, $k . ' '))) {
                return $kandidat;
            }
        }

        $bester  = null;
        $abstand = PHP_INT_MAX;

        foreach ($kandidaten as $kandidat) {
            if (abs(mb_strlen($kandidat) - mb_strlen($referenz)) > $maxAbstand) {
                continue;
            }

            $d = levenshtein(mb_strtolower($referenz), mb_strtolower($kandidat));

            if ($d < $abstand) {
                $abstand = $d;
                $bester  = $kandidat;
            }
        }

        return $abstand <= $maxAbstand ? $bester : null;
    }
}
