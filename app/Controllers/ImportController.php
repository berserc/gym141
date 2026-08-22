<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Config;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\DryRunAbort;
use App\Core\Flash;
use App\Core\Url;
use App\Core\View;
use App\Models\MemberRepo;
use App\Models\SectionRepo;

/**
 * CSV-Import fuer den Mitgliederbestand.
 *
 * Ablauf: Datei hochladen -> Spalten zuordnen und Vorschau pruefen -> importieren.
 * Die Datei liegt zwischen den Schritten in data/tmp und wird danach geloescht.
 */
final class ImportController
{
    /**
     * Zielfelder mit Beschriftung und Erkennungsmustern fuer die Auto-Zuordnung.
     *
     * Die Reihenfolge ist bedeutsam: das erste passende Zielfeld belegt eine Spalte.
     * "Vorname" muss deshalb vor "Zuname" stehen, sonst greift dort das Muster "name".
     */
    public const TARGETS = [
        'first_name'   => ['label' => 'Vorname *',          'match' => ['vorname', 'first']],
        'last_name'    => ['label' => 'Zuname *',           'match' => ['zuname', 'nachname', 'familienname', 'name']],
        'birthdate'    => ['label' => 'Geburtsdatum',       'match' => ['geburt', 'gebdat', 'birth']],
        'gender'       => ['label' => 'Geschlecht',         'match' => ['geschlecht', 'sex', 'gender']],
        'street'       => ['label' => 'Strasse',            'match' => ['strasse', 'straße', 'adresse', 'street']],
        'zip'          => ['label' => 'PLZ',                'match' => ['plz', 'postleit', 'zip']],
        'city'         => ['label' => 'Ort',                'match' => ['ort', 'stadt', 'city']],
        'gemeinde'     => ['label' => 'Gemeinde',           'match' => ['gemeinde']],
        'section'      => ['label' => 'Sektion',            'match' => ['sektion', 'sparte', 'sportart', 'abteilung']],
        'member_no'    => ['label' => 'Mitgliedsnummer',    'match' => ['mitgliedsnummer', 'mitgl', 'nummer', 'nr']],
        'email'        => ['label' => 'E-Mail',             'match' => ['mail', 'email']],
        'phone'        => ['label' => 'Telefon',            'match' => ['telefon', 'tel', 'handy', 'mobil']],
        'fee_amount'   => ['label' => 'Mitgliedsbeitrag',   'match' => ['beitrag', 'betrag']],
        'fee_category' => ['label' => 'Beitragskategorie',  'match' => ['kategorie', 'tarif']],
        'status'       => ['label' => 'Status',             'match' => ['status', 'aktiv']],
        'joined_on'    => ['label' => 'Eintritt',           'match' => ['eintritt', 'beitritt', 'seit']],
        'left_on'      => ['label' => 'Austritt',           'match' => ['austritt', 'ende']],
        'notes'        => ['label' => 'Notizen',            'match' => ['notiz', 'bemerkung', 'anmerkung']],
    ];

    public function form(): void
    {
        AuthController::requireRole('superuser');

        View::display('admin/import/upload', [
            'title'    => 'Mitglieder importieren',
            'sections' => SectionRepo::all(),
        ], 'layouts/admin');
    }

    public function preview(): void
    {
        AuthController::requireRole('superuser');
        Csrf::verify();

        $file = $_FILES['csv'] ?? null;

        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            Flash::error('Bitte eine CSV-Datei auswählen.');
            Url::redirect('/admin/import');
        }

        $tmp = (string) $file['tmp_name'];

        if (!is_uploaded_file($tmp)) {
            Flash::error('Die Datei konnte nicht gelesen werden.');
            Url::redirect('/admin/import');
        }

        if ((int) $file['size'] > 20 * 1024 * 1024) {
            Flash::error('Die Datei ist größer als 20 MB.');
            Url::redirect('/admin/import');
        }

        $this->cleanupOldFiles();

        $token  = bin2hex(random_bytes(16));
        $target = $this->tmpDir() . '/' . $token . '.csv';

        if (!move_uploaded_file($tmp, $target)) {
            Flash::error('Die Datei konnte nicht zwischengespeichert werden.');
            Url::redirect('/admin/import');
        }

        $_SESSION['import_token'] = $token;

        $delimiter = $this->delimiter(post('delimiter', 'auto'), $target);
        $rows      = $this->readRows($target, $delimiter, 21);

        if (count($rows) < 2) {
            Flash::error('Die Datei enthält keine Datenzeilen.');
            Url::redirect('/admin/import');
        }

        $header = array_shift($rows);

        View::display('admin/import/mapping', [
            'title'          => 'Spalten zuordnen',
            'token'          => $token,
            'delimiter'      => $delimiter,
            'header'         => $header,
            'rows'           => array_slice($rows, 0, 20),
            'mapping'        => $this->autoMap($header),
            'sections'       => SectionRepo::all(),
            'defaultSection' => post_int('default_section_id'),
        ], 'layouts/admin');
    }

    public function run(): void
    {
        AuthController::requireRole('superuser');
        Csrf::verify();

        $token = post('token');

        if ($token === '' || $token !== ($_SESSION['import_token'] ?? null) || !preg_match('/^[a-f0-9]{32}$/', $token)) {
            Flash::error('Die Import-Sitzung ist abgelaufen. Bitte die Datei erneut hochladen.');
            Url::redirect('/admin/import');
        }

        $file = $this->tmpDir() . '/' . $token . '.csv';

        if (!is_file($file)) {
            Flash::error('Die hochgeladene Datei wurde nicht gefunden. Bitte erneut hochladen.');
            Url::redirect('/admin/import');
        }

        /** @var array<string,string> $mapping Zielfeld => Spaltenindex */
        $mapping        = array_filter((array) ($_POST['mapping'] ?? []), static fn ($v): bool => $v !== '');
        $defaultSection = post_int('default_section_id');
        $mode           = post('mode', 'insert');   // insert | upsert
        $dryRun         = post('dry_run') === '1';
        $delimiter      = post('delimiter', ';');

        if (!isset($mapping['last_name'], $mapping['first_name'])) {
            Flash::error('Vorname und Zuname müssen zugeordnet werden.');
            Url::redirect('/admin/import');
        }

        if ($defaultSection <= 0 && !isset($mapping['section'])) {
            Flash::error('Bitte eine Sektionsspalte zuordnen oder eine Standard-Sektion wählen.');
            Url::redirect('/admin/import');
        }

        $rows = $this->readRows($file, $delimiter, 0);
        array_shift($rows); // Kopfzeile

        // Gratis-Limit: ein Import darf das Mitgliederlimit nicht sprengen.
        if (($limitFehler = \App\Core\License::memberLimitError(count($rows))) !== null) {
            Flash::error('Import abgebrochen – ' . $limitFehler);
            Url::redirect('/admin/import');
        }

        $sectionLookup = $this->sectionLookup();
        $result        = ['inserted' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => []];
        $lineNo        = 1;

        try {
            Database::transaction(function () use (
                $rows,
                $mapping,
                $defaultSection,
                $mode,
                $sectionLookup,
                $dryRun,
                &$result,
                &$lineNo
            ): void {
                foreach ($rows as $row) {
                    $lineNo++;

                    $values = [];
                    foreach ($mapping as $field => $index) {
                        $values[$field] = trim((string) ($row[(int) $index] ?? ''));
                    }

                    $firstName = $values['first_name'] ?? '';
                    $lastName  = $values['last_name'] ?? '';

                    if ($firstName === '' && $lastName === '') {
                        $result['skipped']++;
                        continue;
                    }

                    if ($lastName === '') {
                        $result['errors'][] = "Zeile $lineNo: Zuname fehlt.";
                        $result['skipped']++;
                        continue;
                    }

                    $sectionId = $defaultSection;

                    if (($values['section'] ?? '') !== '') {
                        $key = mb_strtolower($values['section']);

                        if (!isset($sectionLookup[$key])) {
                            $result['errors'][] = sprintf(
                                'Zeile %d: Sektion "%s" ist unbekannt.',
                                $lineNo,
                                $values['section']
                            );
                            $result['skipped']++;
                            continue;
                        }

                        $sectionId = $sectionLookup[$key];
                    }

                    if ($sectionId <= 0) {
                        $result['errors'][] = "Zeile $lineNo: keine Sektion zuordenbar.";
                        $result['skipped']++;
                        continue;
                    }

                    $birthdate = ($values['birthdate'] ?? '') !== '' ? parse_date($values['birthdate']) : null;
                    $email     = $values['email'] ?? '';
                    $gemeinde  = ($values['gemeinde'] ?? '') !== '' ? $values['gemeinde'] : ($values['city'] ?? '');

                    $data = [
                        'section_id'   => $sectionId,
                        'member_no'    => $values['member_no'] ?? '',
                        'first_name'   => $firstName,
                        'last_name'    => $lastName,
                        'birthdate'    => $birthdate,
                        'gender'       => $this->normalizeGender($values['gender'] ?? ''),
                        'street'       => $values['street'] ?? '',
                        'zip'          => $values['zip'] ?? '',
                        'city'         => $values['city'] ?? '',
                        'gemeinde'     => $gemeinde,
                        'country'      => 'AT',
                        'email'        => filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '',
                        'phone'        => $values['phone'] ?? '',
                        'fee_amount'   => $this->normalizeAmount($values['fee_amount'] ?? ''),
                        'fee_category' => $values['fee_category'] ?? '',
                        'status'       => $this->normalizeStatus($values['status'] ?? ''),
                        'joined_on'    => ($values['joined_on'] ?? '') !== '' ? parse_date($values['joined_on']) : null,
                        'left_on'      => ($values['left_on'] ?? '') !== '' ? parse_date($values['left_on']) : null,
                        'notes'        => $values['notes'] ?? '',
                    ];

                    $existing = null;

                    if ($mode === 'upsert') {
                        if ($data['member_no'] !== '') {
                            $existing = Database::one(
                                'SELECT * FROM members WHERE member_no = ? AND deleted_at IS NULL',
                                [$data['member_no']]
                            );
                        }

                        $existing ??= MemberRepo::findDuplicate($firstName, $lastName, $birthdate);
                    }

                    if ($existing !== null) {
                        $data['updated_at'] = gmdate('Y-m-d H:i:s');
                        Database::update('members', (int) $existing['id'], $data);
                        $result['updated']++;
                    } else {
                        Database::insert('members', $data);
                        $result['inserted']++;
                    }
                }

                if ($dryRun) {
                    // Probelauf: Änderungen zurücknehmen, Zusammenfassung behalten.
                    throw new DryRunAbort();
                }
            });
        } catch (DryRunAbort) {
            // Erwartet: die Transaktion wurde absichtlich zurückgerollt.
        }

        $this->finish($token, $file, $dryRun, $result);
    }

    /** @param array{inserted:int,updated:int,skipped:int,errors:list<string>} $result */
    private function finish(string $token, string $file, bool $dryRun, array $result): void
    {
        if (!$dryRun) {
            @unlink($file);
            unset($_SESSION['import_token']);

            Audit::log('member_import', 'member', null, sprintf(
                '%d neu, %d aktualisiert, %d übersprungen',
                $result['inserted'],
                $result['updated'],
                $result['skipped']
            ));
        }

        View::display('admin/import/result', [
            'title'  => $dryRun ? 'Probelauf' : 'Import abgeschlossen',
            'result' => $result,
            'dryRun' => $dryRun,
            'token'  => $token,
            // Erlaubt es, denselben Lauf direkt echt auszuführen.
            'mapping'        => (array) ($_POST['mapping'] ?? []),
            'defaultSection' => post_int('default_section_id'),
            'mode'           => post('mode', 'insert'),
            'delimiter'      => post('delimiter', ';'),
        ], 'layouts/admin');
    }

    // -------------------------------------------------------------- Hilfen --

    private function tmpDir(): string
    {
        $dir = dirname((string) Config::get('db_path')) . '/tmp';

        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        return $dir;
    }

    /** Entfernt liegengebliebene Zwischendateien aelter als eine Stunde. */
    private function cleanupOldFiles(): void
    {
        foreach (glob($this->tmpDir() . '/*.csv') ?: [] as $old) {
            if (filemtime($old) < time() - 3600) {
                @unlink($old);
            }
        }
    }

    /**
     * Liest die CSV-Datei und liefert die Zeilen als Arrays.
     *
     * @return list<list<string>>
     */
    private function readRows(string $file, string $delimiter, int $limit): array
    {
        $handle = fopen($file, 'rb');

        if ($handle === false) {
            return [];
        }

        $rows  = [];
        $first = true;

        while (($row = fgetcsv($handle, 0, $delimiter, '"', '\\')) !== false) {
            if ($row === [null]) {
                continue; // Leerzeile
            }

            $row = array_map(fn ($v): string => $this->toUtf8((string) ($v ?? '')), $row);

            if ($first) {
                // Byte Order Mark aus der ersten Zelle entfernen.
                $row[0] = preg_replace('/^\xEF\xBB\xBF/', '', $row[0]) ?? $row[0];
                $first  = false;
            }

            $rows[] = $row;

            if ($limit > 0 && count($rows) >= $limit) {
                break;
            }
        }

        fclose($handle);

        return $rows;
    }

    private function toUtf8(string $value): string
    {
        if ($value === '' || mb_check_encoding($value, 'UTF-8')) {
            return $value;
        }

        return mb_convert_encoding($value, 'UTF-8', 'Windows-1252');
    }

    /** Ermittelt das Trennzeichen, wenn "automatisch" gewaehlt wurde. */
    private function delimiter(string $choice, string $file): string
    {
        if (in_array($choice, [';', ',', "\t"], true)) {
            return $choice;
        }

        $handle = fopen($file, 'rb');
        $line   = $handle === false ? '' : (string) fgets($handle);

        if ($handle !== false) {
            fclose($handle);
        }

        $counts = [
            ';'  => substr_count($line, ';'),
            ','  => substr_count($line, ','),
            "\t" => substr_count($line, "\t"),
        ];

        arsort($counts);

        return (string) array_key_first($counts);
    }

    /**
     * Schlaegt anhand der Kopfzeile eine Zuordnung vor.
     *
     * @param list<string> $header
     * @return array<string,int>
     */
    private function autoMap(array $header): array
    {
        $mapping = [];
        $used    = [];

        foreach (self::TARGETS as $field => $definition) {
            foreach ($header as $index => $column) {
                if (in_array($index, $used, true)) {
                    continue;
                }

                $normalized = mb_strtolower(trim($column));

                if ($normalized === '') {
                    continue;
                }

                foreach ($definition['match'] as $needle) {
                    if (str_contains($normalized, $needle)) {
                        $mapping[$field] = $index;
                        $used[]          = $index;
                        continue 3;
                    }
                }
            }
        }

        return $mapping;
    }

    /** @return array<string,int> Sektionsname/Slug (kleingeschrieben) => ID */
    private function sectionLookup(): array
    {
        $lookup = [];

        foreach (SectionRepo::all() as $section) {
            $lookup[mb_strtolower((string) $section['name'])]      = (int) $section['id'];
            $lookup[mb_strtolower((string) $section['slug'])]      = (int) $section['id'];
            $lookup[mb_strtolower((string) $section['club_name'])] = (int) $section['id'];
        }

        unset($lookup['']);

        return $lookup;
    }

    private function normalizeGender(string $value): string
    {
        $value = mb_strtolower(trim($value));

        return match (true) {
            in_array($value, ['m', 'male', 'mann', 'männlich', 'maennlich', 'herr', '1'], true) => 'm',
            in_array($value, ['w', 'f', 'female', 'frau', 'weiblich', '2'], true)               => 'w',
            in_array($value, ['d', 'divers', 'x'], true)                                        => 'd',
            default                                                                             => 'unbekannt',
        };
    }

    private function normalizeStatus(string $value): string
    {
        $value = mb_strtolower(trim($value));

        if ($value === '') {
            return 'aktiv';
        }

        return in_array($value, ['inaktiv', 'passiv', 'ruhend', 'nein', '0', 'false'], true) ? 'inaktiv' : 'aktiv';
    }

    private function normalizeAmount(string $value): float
    {
        $value = str_replace(['€', ' ', "\u{00a0}"], '', trim($value));
        $value = str_replace(',', '.', $value);

        return is_numeric($value) ? (float) $value : 0.0;
    }
}
