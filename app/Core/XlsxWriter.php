<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Erzeugt echte .xlsx-Dateien ohne externe Bibliothek.
 *
 * Eine xlsx-Datei ist ein ZIP-Archiv mit XML-Dateien darin. Der ZIP-Teil ist
 * hier selbst geschrieben (Eintraege unkomprimiert gespeichert), damit die
 * PHP-Erweiterung "zip" nicht vorausgesetzt werden muss – auf einem Managed
 * Server laesst sie sich nicht ohne Weiteres nachinstallieren.
 *
 * Unterstuetzt mehrere Arbeitsblaetter, Text- und Zahlenzellen, Datumswerte
 * sowie eine fette Kopfzeile mit Fixierung und Autofilter.
 */
final class XlsxWriter
{
    /** @var list<array{name:string, rows:list<list<mixed>>, widths:list<int>}> */
    private array $sheets = [];

    /**
     * Fuegt ein Arbeitsblatt hinzu.
     *
     * @param list<list<mixed>> $rows    Erste Zeile = Kopfzeile
     * @param list<int>         $widths  Spaltenbreiten in Zeichen
     */
    public function addSheet(string $name, array $rows, array $widths = []): void
    {
        // Excel erlaubt maximal 31 Zeichen und keine Sonderzeichen im Blattnamen.
        $name = preg_replace('#[\\\\/?*\[\]:]#', '-', $name) ?? $name;
        $name = mb_substr(trim($name), 0, 31);

        if ($name === '') {
            $name = 'Tabelle' . (count($this->sheets) + 1);
        }

        $this->sheets[] = ['name' => $name, 'rows' => $rows, 'widths' => $widths];
    }

    /** Liefert die fertige Datei als Zeichenkette. */
    public function build(): string
    {
        if ($this->sheets === []) {
            $this->addSheet('Tabelle1', [['']]);
        }

        $files = [
            '[Content_Types].xml' => $this->contentTypes(),
            '_rels/.rels'         => $this->rootRels(),
            'xl/workbook.xml'     => $this->workbook(),
            'xl/_rels/workbook.xml.rels' => $this->workbookRels(),
            'xl/styles.xml'       => $this->styles(),
        ];

        foreach ($this->sheets as $i => $sheet) {
            $files['xl/worksheets/sheet' . ($i + 1) . '.xml'] = $this->sheet($sheet);
        }

        return $this->zip($files);
    }

    /** Schickt die Datei als Download an den Browser. */
    public function download(string $filename): never
    {
        $data = $this->build();

        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($data));
        header('Cache-Control: no-store');

        echo $data;
        exit;
    }

    // ------------------------------------------------------------ XML-Teile --

    private function esc(string $s): string
    {
        // Steuerzeichen sind in XML nicht erlaubt.
        $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $s) ?? $s;

        return htmlspecialchars($s, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    private function contentTypes(): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';

        foreach (array_keys($this->sheets) as $i) {
            $xml .= '<Override PartName="/xl/worksheets/sheet' . ($i + 1)
                . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        }

        return $xml . '</Types>';
    }

    private function rootRels(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>';
    }

    private function workbook(): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
            . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>';

        foreach ($this->sheets as $i => $sheet) {
            $xml .= '<sheet name="' . $this->esc($sheet['name']) . '" sheetId="' . ($i + 1)
                . '" r:id="rId' . ($i + 1) . '"/>';
        }

        return $xml . '</sheets></workbook>';
    }

    private function workbookRels(): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';

        foreach (array_keys($this->sheets) as $i) {
            $xml .= '<Relationship Id="rId' . ($i + 1)
                . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet"'
                . ' Target="worksheets/sheet' . ($i + 1) . '.xml"/>';
        }

        // Stile bekommen die naechste freie Id.
        return $xml . '<Relationship Id="rId' . (count($this->sheets) + 1)
            . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '</Relationships>';
    }

    /** Stil 0 = normal, 1 = fett (Kopfzeile), 2 = Datum, 3 = Euro-Betrag. */
    private function styles(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<numFmts count="2">'
            . '<numFmt numFmtId="164" formatCode="DD.MM.YYYY"/>'
            . '<numFmt numFmtId="165" formatCode="#,##0.00"/>'
            . '</numFmts>'
            . '<fonts count="2">'
            . '<font><sz val="11"/><name val="Calibri"/></font>'
            . '<font><b/><sz val="11"/><name val="Calibri"/></font>'
            . '</fonts>'
            . '<fills count="3">'
            . '<fill><patternFill patternType="none"/></fill>'
            . '<fill><patternFill patternType="gray125"/></fill>'
            . '<fill><patternFill patternType="solid"><fgColor rgb="FFE8F2EC"/><bgColor indexed="64"/></patternFill></fill>'
            . '</fills>'
            . '<borders count="2">'
            . '<border><left/><right/><top/><bottom/><diagonal/></border>'
            . '<border><left/><right/><top/><bottom style="thin"><color rgb="FF16714A"/></bottom><diagonal/></border>'
            . '</borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="4">'
            . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            . '<xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1"/>'
            . '<xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            . '<xf numFmtId="165" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            . '</cellXfs>'
            . '<cellStyles count="1"><cellStyle name="Standard" xfId="0" builtinId="0"/></cellStyles>'
            . '</styleSheet>';
    }

    /** @param array{name:string, rows:list<list<mixed>>, widths:list<int>} $sheet */
    private function sheet(array $sheet): string
    {
        $rows      = $sheet['rows'];
        $spalten   = 0;
        foreach ($rows as $r) {
            $spalten = max($spalten, count($r));
        }

        $letzte = $this->columnName(max(1, $spalten) - 1);

        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';

        if ($sheet['widths'] !== []) {
            $xml .= '<cols>';
            foreach ($sheet['widths'] as $i => $w) {
                $xml .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . max(4, $w) . '" customWidth="1"/>';
            }
            $xml .= '</cols>';
        }

        $xml .= '<sheetData>';

        foreach ($rows as $ri => $row) {
            $xml .= '<row r="' . ($ri + 1) . '">';

            foreach (array_values($row) as $ci => $value) {
                $ref   = $this->columnName($ci) . ($ri + 1);
                $style = $ri === 0 ? 1 : 0;

                if ($value === null || $value === '') {
                    continue;
                }

                if ($ri > 0 && $value instanceof \DateTimeInterface) {
                    $xml .= '<c r="' . $ref . '" s="2"><v>' . $this->excelDate($value) . '</v></c>';
                    continue;
                }

                if ($ri > 0 && is_float($value)) {
                    $xml .= '<c r="' . $ref . '" s="3"><v>' . rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.') . '</v></c>';
                    continue;
                }

                if ($ri > 0 && is_int($value)) {
                    $xml .= '<c r="' . $ref . '"><v>' . $value . '</v></c>';
                    continue;
                }

                $xml .= '<c r="' . $ref . '" s="' . $style . '" t="inlineStr"><is><t xml:space="preserve">'
                    . $this->esc((string) $value) . '</t></is></c>';
            }

            $xml .= '</row>';
        }

        $xml .= '</sheetData>';

        // Kopfzeile fixieren und filtern lassen
        if (count($rows) > 1) {
            $xml .= '<autoFilter ref="A1:' . $letzte . count($rows) . '"/>';
        }

        return $xml . '</worksheet>';
    }

    private function columnName(int $index): string
    {
        $name = '';

        for ($i = $index; $i >= 0; $i = intdiv($i, 26) - 1) {
            $name = chr(65 + $i % 26) . $name;
        }

        return $name;
    }

    /** Excel zaehlt Tage ab dem 30.12.1899. */
    private function excelDate(\DateTimeInterface $date): int
    {
        $basis = new \DateTimeImmutable('1899-12-30 00:00:00');

        return (int) $basis->diff($date)->days;
    }

    // ---------------------------------------------------------- ZIP-Teil --

    /** @param array<string,string> $files Pfad im Archiv => Inhalt */
    private function zip(array $files): string
    {
        $lokal     = '';
        $zentral   = '';
        $offset    = 0;
        $anzahl    = 0;
        [$zeit, $datum] = $this->dosTime();

        foreach ($files as $pfad => $inhalt) {
            $crc   = crc32($inhalt);
            $laenge = strlen($inhalt);

            // Lokaler Dateikopf, Methode 0 (gespeichert)
            $kopf = "\x50\x4b\x03\x04"
                . pack('v', 20) . pack('v', 0) . pack('v', 0)
                . pack('v', $zeit) . pack('v', $datum)
                . pack('V', $crc) . pack('V', $laenge) . pack('V', $laenge)
                . pack('v', strlen($pfad)) . pack('v', 0)
                . $pfad;

            $lokal .= $kopf . $inhalt;

            $zentral .= "\x50\x4b\x01\x02"
                . pack('v', 20) . pack('v', 20) . pack('v', 0) . pack('v', 0)
                . pack('v', $zeit) . pack('v', $datum)
                . pack('V', $crc) . pack('V', $laenge) . pack('V', $laenge)
                . pack('v', strlen($pfad)) . pack('v', 0) . pack('v', 0)
                . pack('v', 0) . pack('v', 0) . pack('V', 32)
                . pack('V', $offset)
                . $pfad;

            $offset += strlen($kopf) + $laenge;
            $anzahl++;
        }

        $ende = "\x50\x4b\x05\x06"
            . pack('v', 0) . pack('v', 0)
            . pack('v', $anzahl) . pack('v', $anzahl)
            . pack('V', strlen($zentral)) . pack('V', $offset)
            . pack('v', 0);

        return $lokal . $zentral . $ende;
    }

    /** @return array{0:int,1:int} MS-DOS-Zeit und -Datum */
    private function dosTime(): array
    {
        $t = getdate();

        return [
            ($t['hours'] << 11) | ($t['minutes'] << 5) | (intdiv($t['seconds'], 2)),
            (($t['year'] - 1980) << 9) | ($t['mon'] << 5) | $t['mday'],
        ];
    }
}
