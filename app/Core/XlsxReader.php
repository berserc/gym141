<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/**
 * Liest .xlsx-Dateien (erstes Arbeitsblatt) ohne externe Bibliothek und ohne
 * die zip-Erweiterung: das ZIP-Archiv wird selbst geparst (Central Directory),
 * deflate-komprimierte Eintraege entpackt gzinflate (zlib ist immer da).
 *
 * Gegenstueck zum XlsxWriter; gedacht fuer Bank-Kontoexports und aehnliche
 * einfache Tabellen. Formeln werden ueber ihren gespeicherten Wert gelesen.
 */
final class XlsxReader
{
    /**
     * @return list<list<string>> Zeilen des ersten Arbeitsblatts (Zellen als Text)
     */
    public static function read(string $path): array
    {
        $entries = self::zipEntries($path);

        // Erstes Arbeitsblatt finden (ueblich: xl/worksheets/sheet1.xml)
        $sheetName = null;

        foreach (array_keys($entries) as $name) {
            if (preg_match('#^xl/worksheets/sheet\d+\.xml$#', $name)) {
                $sheetName ??= $name;

                if ($name === 'xl/worksheets/sheet1.xml') {
                    $sheetName = $name;
                }
            }
        }

        if ($sheetName === null) {
            throw new RuntimeException('Kein Arbeitsblatt in der xlsx-Datei gefunden.');
        }

        $shared = [];

        if (isset($entries['xl/sharedStrings.xml'])) {
            $sst = @simplexml_load_string($entries['xl/sharedStrings.xml']);

            foreach ($sst === false ? [] : $sst->si as $si) {
                // Ein Eintrag kann aus mehreren formatierten Teilstuecken bestehen.
                $text = '';

                if (isset($si->t)) {
                    $text = (string) $si->t;
                } else {
                    foreach ($si->r as $run) {
                        $text .= (string) $run->t;
                    }
                }

                $shared[] = $text;
            }
        }

        $sheet = @simplexml_load_string($entries[$sheetName]);

        if ($sheet === false) {
            throw new RuntimeException('Arbeitsblatt konnte nicht gelesen werden.');
        }

        $rows = [];

        foreach ($sheet->sheetData->row as $row) {
            $cells = [];

            foreach ($row->c as $cell) {
                $ref  = (string) $cell['r'];              // z. B. "C7"
                $col  = self::columnIndex($ref);
                $type = (string) $cell['t'];

                $value = match ($type) {
                    's'         => $shared[(int) $cell->v] ?? '',
                    'inlineStr' => (string) ($cell->is->t ?? ''),
                    'b'         => (string) $cell->v === '1' ? '1' : '0',
                    default     => (string) $cell->v,      // Zahl, Datum (seriell), Formelwert
                };

                $cells[$col] = $value;
            }

            if ($cells === []) {
                $rows[] = [];

                continue;
            }

            // Luecken auffuellen, damit die Spaltenindizes stabil sind.
            $line = array_fill(0, max(array_keys($cells)) + 1, '');

            foreach ($cells as $i => $v) {
                $line[$i] = $v;
            }

            $rows[] = $line;
        }

        return $rows;
    }

    /** Excel-Seriendatum (Tage seit 1900) in YYYY-MM-DD. */
    public static function serialToDate(float $serial): string
    {
        // Excel-Epoche 1899-12-30 (inkl. des bekannten 1900-Schaltjahr-Fehlers).
        return gmdate('Y-m-d', (int) round(($serial - 25569) * 86400));
    }

    /** "C7" -> 2 */
    private static function columnIndex(string $ref): int
    {
        $col = 0;

        foreach (str_split(strtoupper((string) preg_replace('/\d+/', '', $ref))) as $chr) {
            $col = $col * 26 + (ord($chr) - 64);
        }

        return max(0, $col - 1);
    }

    /**
     * Minimaler ZIP-Leser: Central Directory parsen, Eintraege entpacken.
     *
     * @return array<string,string> Dateiname => Inhalt
     */
    private static function zipEntries(string $path): array
    {
        $data = @file_get_contents($path);

        if ($data === false || strlen($data) < 22) {
            throw new RuntimeException('Datei konnte nicht gelesen werden.');
        }

        // End of Central Directory (EOCD) von hinten suchen.
        $eocd = strrpos($data, "PK\x05\x06");

        if ($eocd === false) {
            throw new RuntimeException('Keine gültige xlsx-/zip-Datei.');
        }

        $cdOffset = (int) unpack('V', substr($data, $eocd + 16, 4))[1];
        $cdCount  = (int) unpack('v', substr($data, $eocd + 10, 2))[1];

        $entries = [];
        $pos     = $cdOffset;

        for ($i = 0; $i < $cdCount; $i++) {
            if (substr($data, $pos, 4) !== "PK\x01\x02") {
                break;
            }

            $method      = (int) unpack('v', substr($data, $pos + 10, 2))[1];
            $compSize    = (int) unpack('V', substr($data, $pos + 20, 4))[1];
            $nameLen     = (int) unpack('v', substr($data, $pos + 28, 2))[1];
            $extraLen    = (int) unpack('v', substr($data, $pos + 30, 2))[1];
            $commentLen  = (int) unpack('v', substr($data, $pos + 32, 2))[1];
            $localOffset = (int) unpack('V', substr($data, $pos + 42, 4))[1];
            $name        = substr($data, $pos + 46, $nameLen);

            // Lokaler Header: eigene Namens-/Extra-Laengen (koennen abweichen).
            $lNameLen  = (int) unpack('v', substr($data, $localOffset + 26, 2))[1];
            $lExtraLen = (int) unpack('v', substr($data, $localOffset + 28, 2))[1];
            $raw       = substr($data, $localOffset + 30 + $lNameLen + $lExtraLen, $compSize);

            $entries[$name] = match ($method) {
                0       => $raw,                       // gespeichert
                8       => (string) @gzinflate($raw),  // deflate
                default => '',
            };

            $pos += 46 + $nameLen + $extraLen + $commentLen;
        }

        if ($entries === []) {
            throw new RuntimeException('xlsx-Datei ist leer oder nicht lesbar.');
        }

        return $entries;
    }
}
