<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\Flash;
use App\Core\Url;
use App\Core\View;
use App\Models\LedgerRepo;
use App\Models\MemberRepo;

/** Buchhaltung (Kassabuch): Einnahmen/Ausgaben ansehen und erfassen. */
final class LedgerController
{
    public function index(): void
    {
        AuthController::requireRole('superuser', 'kassier');

        // Faellige Fixkosten (Miete, Internet ...) automatisch buchen.
        LedgerRepo::generateFixedCosts();

        $filters = [
            'type'     => query('type'),
            'category' => query('category'),
            'from'     => query('from'),
            'to'       => query('to'),
            'q'        => query('q'),
            'payment_method_id' => query('payment_method_id'),
        ];

        // Ohne Filter: das laufende Jahr anzeigen.
        if ($filters['from'] === '' && $filters['to'] === '' && $filters['q'] === ''
            && $filters['type'] === '' && $filters['category'] === ''
            && $filters['payment_method_id'] === '') {
            $filters['from'] = date('Y') . '-01-01';
        }

        View::display('admin/ledger/index', [
            'title'         => 'Buchhaltung',
            'filters'       => $filters,
            'entries'       => LedgerRepo::search($filters),
            'sums'          => LedgerRepo::sums($filters),
            'balance'       => LedgerRepo::balance(),
            'categoriesIn'  => LedgerRepo::categories('einnahme'),
            'categoriesOut' => LedgerRepo::categories('ausgabe'),
            'methods'       => LedgerRepo::paymentMethods(true),
            'errors'        => Flash::errors(),
        ], 'layouts/admin');
    }

    /** Manuelle Buchung anlegen. */
    public function store(): void
    {
        AuthController::requireRole('superuser', 'kassier');
        Csrf::verify();

        $type     = post('type') === 'ausgabe' ? 'ausgabe' : 'einnahme';
        $amount   = post_float('amount');
        $bookedOn = parse_date(post('booked_on')) ?? date('Y-m-d');
        $category = post('category');
        $text     = post('text');

        $errors = [];

        if ($amount <= 0) {
            $errors['amount'] = 'Bitte einen Betrag größer 0 angeben.';
        }

        if ($text === '' && $category === '') {
            $errors['text'] = 'Bitte einen Betreff oder eine Kategorie angeben.';
        }

        // Optional: Bezug zu einem Mitglied (Mitgliedsnummer oder "Vorname Zuname")
        $memberId  = null;
        $memberRef = trim(post('member_ref'));

        if ($memberRef !== '') {
            $memberId = MemberRepo::resolveRef($memberRef);

            if ($memberId === null) {
                $errors['member_ref'] = 'Mitglied nicht gefunden (Mitgliedsnummer oder "Vorname Zuname").';
            }
        }

        if ($errors !== []) {
            Flash::withInput($_POST, $errors);
            Flash::error('Bitte prüfen Sie die markierten Felder.');
            Url::redirect('/admin/buchhaltung');
        }

        $methodId = post_int('payment_method_id');

        $id = LedgerRepo::add([
            'booked_on'  => $bookedOn,
            'type'       => $type,
            'category'   => $category,
            'text'       => $text,
            'amount'     => $amount,
            'member_id'  => $memberId,
            'payment_method_id' => $methodId > 0 ? $methodId : LedgerRepo::defaultMethodId('bar'),
            'created_by' => Auth::id(),
        ]);

        Audit::log('ledger_created', 'ledger', $id, $type . ' ' . format_money($amount) . ' ' . $category);
        Flash::success(ucfirst($type) . ' über ' . format_money($amount) . ' gebucht.');
        Url::redirect('/admin/buchhaltung');
    }

    /** @param array<string,string> $args */
    public function destroy(array $args): void
    {
        AuthController::requireRole('superuser');
        Csrf::verify();

        $id    = (int) ($args['id'] ?? 0);
        $entry = Database::one('SELECT * FROM ledger_entries WHERE id = ?', [$id]);

        if ($entry === null) {
            Flash::error('Buchung nicht gefunden.');
            Url::redirect('/admin/buchhaltung');
        }

        if ($entry['fee_entry_id'] !== null) {
            Flash::error('Diese Buchung stammt aus einer Beitragszahlung. Bitte den Beitrag beim Mitglied wieder öffnen – die Buchung verschwindet dann automatisch.');
            Url::redirect('/admin/buchhaltung');
        }

        if ($entry['fixed_cost_id'] !== null) {
            // Wuerde beim naechsten Aufruf ohnehin neu gebucht werden.
            Flash::error('Diese Buchung stammt aus den Fixkosten. Zum Beenden den Fixkosten-Eintrag inaktiv setzen; zum Korrigieren dort Betrag/Kategorie ändern (gilt ab der nächsten Buchung).');
            Url::redirect('/admin/buchhaltung');
        }

        Database::run('DELETE FROM ledger_entries WHERE id = ?', [$id]);

        Audit::log('ledger_deleted', 'ledger', $id, (string) $entry['text']);
        Flash::success('Buchung gelöscht.');
        Url::redirect('/admin/buchhaltung');
    }

    // --------------------------------------------------------------- Fixkosten --

    public function fixedCosts(): void
    {
        AuthController::requireRole('superuser', 'kassier');

        $costs     = LedgerRepo::fixedCosts();
        $histories = [];

        foreach ($costs as $cost) {
            $histories[(int) $cost['id']] = \App\Models\FeeRepo::amountHistory('fixed_cost', (int) $cost['id']);
        }

        $files = [];

        foreach (Database::all('SELECT * FROM fixed_cost_files ORDER BY id') as $file) {
            $files[(int) $file['fixed_cost_id']][] = $file;
        }

        View::display('admin/ledger/fixed-costs', [
            'title'         => 'Fixkosten',
            'costs'         => $costs,
            'categoriesIn'  => LedgerRepo::categories('einnahme'),
            'categoriesOut' => LedgerRepo::categories('ausgabe'),
            'methods'       => LedgerRepo::paymentMethods(true),
            'amountHistories' => $histories,
            'files'         => $files,
            'errors'        => Flash::errors(),
        ], 'layouts/admin');
    }

    public function storeFixedCost(): void
    {
        $this->saveFixedCost(0);
    }

    /** @param array<string,string> $args */
    public function updateFixedCost(array $args): void
    {
        $this->saveFixedCost((int) ($args['id'] ?? 0));
    }

    private function saveFixedCost(int $id): void
    {
        AuthController::requireRole('superuser', 'kassier');
        Csrf::verify();

        $name     = post('name');
        $amount   = post_float('amount');
        $dueDay   = post_int('due_day', 1);
        $since    = parse_date(post('since')) ?? date('Y-m-01');
        $interval = post('interval', 'monatlich');

        $errors = [];

        if ($name === '') {
            $errors['name'] = 'Bitte eine Bezeichnung angeben.';
        }

        if ($amount <= 0) {
            $errors['amount'] = 'Bitte einen Betrag größer 0 angeben.';
        }

        if ($dueDay < 1 || $dueDay > 28) {
            $errors['due_day'] = 'Der Buchungstag muss zwischen 1 und 28 liegen.';
        }

        if (!isset(\App\Models\FeeRepo::INTERVALS[$interval])) {
            $errors['interval'] = 'Bitte eine gültige Periode wählen.';
        }

        if ($errors !== []) {
            Flash::withInput($_POST, $errors);
            Flash::error('Bitte prüfen Sie die markierten Felder.');
            Url::redirect('/admin/buchhaltung/fixkosten');
        }

        $methodId = post_int('payment_method_id');

        $data = [
            'name'       => $name,
            'type'       => post('type') === 'einnahme' ? 'einnahme' : 'ausgabe',
            'category'   => post('category'),
            'amount'     => $amount,
            'interval'   => $interval,
            'due_day'    => $dueDay,
            'payment_method_id' => $methodId > 0 ? $methodId : LedgerRepo::defaultMethodId('bank'),
            'since'      => substr($since, 0, 7) . '-01',
            'active'     => post_bool('active'),
            'note'       => post('note'),
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ];

        if ($id > 0) {
            if (Database::one('SELECT id FROM fixed_costs WHERE id = ?', [$id]) === null) {
                Flash::error('Fixkosten-Eintrag nicht gefunden.');
                Url::redirect('/admin/buchhaltung/fixkosten');
            }

            Database::update('fixed_costs', $id, $data);
            Audit::log('fixed_cost_updated', 'ledger', $id, $name);
            Flash::success('Fixkosten-Eintrag gespeichert. Bereits erstellte Buchungen bleiben unverändert.');
        } else {
            unset($data['updated_at']);
            $id = Database::insert('fixed_costs', $data);
            Audit::log('fixed_cost_created', 'ledger', $id, $name);
            Flash::success('Fixkosten-Eintrag angelegt – fällige Monate werden beim nächsten Aufruf der Buchhaltung gebucht.');
        }

        Url::redirect('/admin/buchhaltung/fixkosten');
    }

    /**
     * Betragsaenderung ab Stichtag fuer eine Fixkostenzeile.
     *
     * @param array<string,string> $args
     */
    public function changeFixedCostAmount(array $args): void
    {
        AuthController::requireRole('superuser', 'kassier');
        Csrf::verify();

        $id   = (int) ($args['id'] ?? 0);
        $cost = Database::one('SELECT * FROM fixed_costs WHERE id = ?', [$id]);

        if ($cost === null) {
            Flash::error('Fixkosten-Eintrag nicht gefunden.');
            Url::redirect('/admin/buchhaltung/fixkosten');
        }

        $errors = \App\Models\FeeRepo::addAmountChange('fixed_cost', $id, post('valid_from'), post_float('amount'), post('note'), Auth::id());

        if ($errors !== []) {
            Flash::error(implode(' ', $errors));
            Url::redirect('/admin/buchhaltung/fixkosten');
        }

        Audit::log('fixed_cost_amount_change', 'ledger', $id, format_money(post_float('amount')) . ' ab ' . post('valid_from'));
        Flash::success(sprintf(
            '"%s": %s gilt ab %s. Bereits erstellte Buchungen bleiben unverändert.',
            (string) $cost['name'],
            format_money(post_float('amount')),
            format_date(parse_date(post('valid_from')))
        ));
        Url::redirect('/admin/buchhaltung/fixkosten');
    }

    /** @param array<string,string> $args */
    public function deleteFixedCostAmountChange(array $args): void
    {
        AuthController::requireRole('superuser', 'kassier');
        Csrf::verify();

        Database::run(
            "DELETE FROM amount_history WHERE id = ? AND entity = 'fixed_cost' AND entity_id = ?",
            [post_int('history_id'), (int) ($args['id'] ?? 0)]
        );

        Flash::success('Betragsänderung entfernt.');
        Url::redirect('/admin/buchhaltung/fixkosten');
    }

    /** @param array<string,string> $args */
    public function deleteFixedCost(array $args): void
    {
        AuthController::requireRole('superuser');
        Csrf::verify();

        $id   = (int) ($args['id'] ?? 0);
        $cost = Database::one('SELECT * FROM fixed_costs WHERE id = ?', [$id]);

        if ($cost === null) {
            Flash::error('Fixkosten-Eintrag nicht gefunden.');
            Url::redirect('/admin/buchhaltung/fixkosten');
        }

        Database::run('DELETE FROM fixed_costs WHERE id = ?', [$id]);

        Audit::log('fixed_cost_deleted', 'ledger', $id, (string) $cost['name']);
        Flash::success('Fixkosten-Eintrag gelöscht. Bereits erstellte Buchungen bleiben in der Buchhaltung.');
        Url::redirect('/admin/buchhaltung/fixkosten');
    }

    // ----------------------------------------------------- Fixkosten-Dokumente --

    private const FC_FILE_EXTENSIONS = [
        'jpg', 'jpeg', 'png', 'webp', 'gif', 'pdf',
        'doc', 'docx', 'xls', 'xlsx', 'odt', 'ods', 'txt', 'csv',
    ];

    private const FC_FILE_MAX_BYTES = 25 * 1024 * 1024;

    private function fixedCostFileDir(): string
    {
        return BASE_ROOT . '/data/verein/fixkosten';
    }

    /** Dokument (Vertrag, Rechnung ...) zu einem Fixkosten-Eintrag ablegen. */
    public function uploadFixedCostFile(array $args): void
    {
        AuthController::requireRole('superuser', 'kassier');
        Csrf::verify();

        $id = (int) ($args['id'] ?? 0);

        if (Database::one('SELECT id FROM fixed_costs WHERE id = ?', [$id]) === null) {
            Flash::error('Fixkosten-Eintrag nicht gefunden.');
            Url::redirect('/admin/buchhaltung/fixkosten');
        }

        $file    = $_FILES['file'] ?? null;
        $hasFile = is_array($file) && (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;

        // Datei aus der zentralen Ablage verknuepfen statt neu hochzuladen.
        $mediaFileId = post_int('media_file_id');

        if (!$hasFile && $mediaFileId > 0) {
            $zentral = FileController::find($mediaFileId);

            if ($zentral === null) {
                Flash::error('Die Datei aus der Ablage wurde nicht gefunden.');
                Url::redirect('/admin/buchhaltung/fixkosten');
            }

            Database::insert('fixed_cost_files', [
                'fixed_cost_id' => $id,
                'filename'      => $zentral['filename'],
                'stored_name'   => '',
                'mime'          => $zentral['mime'],
                'size'          => (int) $zentral['size'],
                'uploaded_by'   => Auth::id(),
                'media_file_id' => (int) $zentral['id'],
            ]);

            Flash::success('Datei aus der Ablage verknüpft: ' . $zentral['filename']);
            Url::redirect('/admin/buchhaltung/fixkosten');
        }

        if (!$hasFile) {
            Flash::error('Bitte eine Datei auswählen oder eine Datei aus der Ablage wählen.');
            Url::redirect('/admin/buchhaltung/fixkosten');
        }

        if ((int) $file['error'] !== UPLOAD_ERR_OK) {
            Flash::error('Upload fehlgeschlagen (Fehler ' . (int) $file['error'] . ') – ist die Datei zu groß?');
            Url::redirect('/admin/buchhaltung/fixkosten');
        }

        if ((int) $file['size'] > self::FC_FILE_MAX_BYTES) {
            Flash::error('Die Datei ist größer als 25 MB.');
            Url::redirect('/admin/buchhaltung/fixkosten');
        }

        $original  = (string) $file['name'];
        $extension = strtolower((string) pathinfo($original, PATHINFO_EXTENSION));

        if (!in_array($extension, self::FC_FILE_EXTENSIONS, true)) {
            Flash::error('Dateityp .' . $extension . ' ist nicht erlaubt (Bilder, PDF und Office-Dokumente).');
            Url::redirect('/admin/buchhaltung/fixkosten');
        }

        $dir = $this->fixedCostFileDir();

        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            Flash::error('Ablageverzeichnis konnte nicht angelegt werden.');
            Url::redirect('/admin/buchhaltung/fixkosten');
        }

        $storedName = bin2hex(random_bytes(16)) . '.' . $extension;

        if (!move_uploaded_file((string) $file['tmp_name'], $dir . '/' . $storedName)) {
            Flash::error('Die Datei konnte nicht gespeichert werden.');
            Url::redirect('/admin/buchhaltung/fixkosten');
        }

        $mime = class_exists(\finfo::class)
            ? ((string) (new \finfo(FILEINFO_MIME_TYPE))->file($dir . '/' . $storedName) ?: 'application/octet-stream')
            : 'application/octet-stream';

        Database::insert('fixed_cost_files', [
            'fixed_cost_id' => $id,
            'filename'      => $original,
            'stored_name'   => $storedName,
            'mime'          => $mime,
            'size'          => (int) $file['size'],
            'uploaded_by'   => Auth::id(),
        ]);

        Flash::success('Dokument gespeichert: ' . $original);
        Url::redirect('/admin/buchhaltung/fixkosten');
    }

    public function deleteFixedCostFile(array $args): void
    {
        AuthController::requireRole('superuser', 'kassier');
        Csrf::verify();

        $id   = (int) ($args['id'] ?? 0);
        $file = Database::one(
            'SELECT * FROM fixed_cost_files WHERE id = ? AND fixed_cost_id = ?',
            [post_int('file_id'), $id]
        );

        if ($file !== null) {
            $pfad = $this->fixedCostFileDir() . '/' . $file['stored_name'];

            if (is_file($pfad)) {
                @unlink($pfad);
            }

            Database::run('DELETE FROM fixed_cost_files WHERE id = ?', [(int) $file['id']]);
            Flash::success('Dokument gelöscht.');
        }

        Url::redirect('/admin/buchhaltung/fixkosten');
    }

    /** Liefert ein Fixkosten-Dokument aus – nur fuer Superuser und Kassier. */
    public function serveFixedCostFile(array $args): void
    {
        AuthController::requireRole('superuser', 'kassier');

        $file = Database::one(
            'SELECT * FROM fixed_cost_files WHERE id = ? AND fixed_cost_id = ?',
            [(int) ($args['fid'] ?? 0), (int) ($args['id'] ?? 0)]
        );

        // Referenz auf die zentrale Ablage? Dann von dort ausliefern.
        if ($file !== null && (int) ($file['media_file_id'] ?? 0) > 0) {
            $zentral = FileController::find((int) $file['media_file_id']);
            $file    = $zentral === null ? null : [
                'filename'    => $zentral['filename'],
                'mime'        => $zentral['mime'],
                'stored_name' => null,
            ];
            $pfad = $zentral === null ? '' : FileController::path($zentral);
        } else {
            $pfad = $file === null ? '' : $this->fixedCostFileDir() . '/' . $file['stored_name'];
        }

        if ($file === null || !is_file($pfad)) {
            http_response_code(404);
            View::display('errors/404-admin', ['title' => 'Nicht gefunden'], 'layouts/admin');
            exit;
        }

        $mime   = (string) ($file['mime'] ?: 'application/octet-stream');
        $inline = str_starts_with($mime, 'image/') || $mime === 'application/pdf';

        header('Content-Type: ' . $mime);
        header('Content-Length: ' . (string) filesize($pfad));
        header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment')
            . '; filename="' . rawurlencode((string) $file['filename']) . '"');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, max-age=300');

        readfile($pfad);
        exit;
    }

    // ------------------------------------------------------------ Zahlungsarten --

    public function paymentMethods(): void
    {
        AuthController::requireRole('superuser', 'kassier');

        View::display('admin/ledger/payment-methods', [
            'title'   => 'Zahlungsarten',
            'methods' => LedgerRepo::paymentMethods(),
            'errors'  => Flash::errors(),
        ], 'layouts/admin');
    }

    public function storePaymentMethod(): void
    {
        $this->savePaymentMethod(0);
    }

    /** @param array<string,string> $args */
    public function updatePaymentMethod(array $args): void
    {
        $this->savePaymentMethod((int) ($args['id'] ?? 0));
    }

    private function savePaymentMethod(int $id): void
    {
        AuthController::requireRole('superuser', 'kassier');
        Csrf::verify();

        $name = post('name');
        $kind = in_array(post('kind'), ['bar', 'bank', 'online', 'sonstig'], true) ? post('kind') : 'sonstig';

        if ($name === '') {
            Flash::error('Bitte eine Bezeichnung angeben.');
            Url::redirect('/admin/buchhaltung/zahlungsarten');
        }

        $data = [
            'name'           => $name,
            'kind'           => $kind,
            'account_holder' => post('account_holder'),
            'iban'           => strtoupper(str_replace(' ', '', post('iban'))),
            'bic'            => strtoupper(str_replace(' ', '', post('bic'))),
            'bank_name'      => post('bank_name'),
            'active'         => post_bool('active'),
            'note'           => post('note'),
        ];

        if ($id > 0) {
            $method = Database::one('SELECT * FROM payment_methods WHERE id = ?', [$id]);

            if ($method === null) {
                Flash::error('Zahlungsart nicht gefunden.');
                Url::redirect('/admin/buchhaltung/zahlungsarten');
            }

            // Geschuetzte Zahlungsarten (Bank, Barkassa) bleiben aktiv und behalten den Namen.
            if ((int) $method['protected'] === 1) {
                $data['name']   = (string) $method['name'];
                $data['kind']   = (string) $method['kind'];
                $data['active'] = 1;
            }

            Database::update('payment_methods', $id, $data);
            Audit::log('payment_method_updated', 'ledger', $id, $data['name']);
            Flash::success('Zahlungsart gespeichert.');
        } else {
            $existiert = Database::one('SELECT id FROM payment_methods WHERE name = ? COLLATE NOCASE', [$name]);

            if ($existiert !== null) {
                Flash::error('Eine Zahlungsart mit diesem Namen gibt es bereits.');
                Url::redirect('/admin/buchhaltung/zahlungsarten');
            }

            $data['sort_order'] = 100;
            $id = Database::insert('payment_methods', $data);
            Audit::log('payment_method_created', 'ledger', $id, $name);
            Flash::success('Zahlungsart angelegt.');
        }

        Url::redirect('/admin/buchhaltung/zahlungsarten');
    }

    /** @param array<string,string> $args */
    public function deletePaymentMethod(array $args): void
    {
        AuthController::requireRole('superuser');
        Csrf::verify();

        $id     = (int) ($args['id'] ?? 0);
        $method = Database::one('SELECT * FROM payment_methods WHERE id = ?', [$id]);

        if ($method === null) {
            Flash::error('Zahlungsart nicht gefunden.');
            Url::redirect('/admin/buchhaltung/zahlungsarten');
        }

        if ((int) $method['protected'] === 1) {
            Flash::error('„' . $method['name'] . '“ ist eine Standard-Zahlungsart und kann nicht gelöscht werden.');
            Url::redirect('/admin/buchhaltung/zahlungsarten');
        }

        // Bestehende Buchungen behalten ihre Zuordnung nicht (SET NULL) –
        // deshalb nur zulassen, wenn nichts mehr daran haengt.
        $inUse = (int) Database::value(
            'SELECT COUNT(*) FROM ledger_entries WHERE payment_method_id = ?',
            [$id]
        );

        if ($inUse > 0) {
            Flash::error(sprintf(
                '„%s“ ist %d Buchung(en) zugeordnet und kann nicht gelöscht werden. Tipp: inaktiv setzen.',
                (string) $method['name'],
                $inUse
            ));
            Url::redirect('/admin/buchhaltung/zahlungsarten');
        }

        Database::run('DELETE FROM payment_methods WHERE id = ?', [$id]);

        Audit::log('payment_method_deleted', 'ledger', $id, (string) $method['name']);
        Flash::success('Zahlungsart gelöscht.');
        Url::redirect('/admin/buchhaltung/zahlungsarten');
    }

    // -------------------------------------------------------------- Auswertung --

    public function report(): void
    {
        AuthController::requireRole('superuser', 'kassier');

        LedgerRepo::generateFixedCosts();

        $from = parse_date(query('from')) ?? date('Y') . '-01-01';
        $to   = parse_date(query('to')) ?? date('Y-m-d');

        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }

        $sums    = LedgerRepo::sums(['from' => $from, 'to' => $to]);
        $planned = LedgerRepo::plannedByMonth($from, $to);

        $plannedIn  = array_sum(array_column($planned, 'in'));
        $plannedOut = array_sum(array_column($planned, 'out'));

        View::display('admin/ledger/report', [
            'title'      => 'Auswertung',
            'from'       => $from,
            'to'         => $to,
            'sums'       => $sums,
            'monthly'    => LedgerRepo::monthlySums($from, $to),
            'byCategory' => LedgerRepo::categorySums($from, $to),
            'balance'    => LedgerRepo::balance(),
            'planned'    => $planned,
            'plannedIn'  => $plannedIn,
            'plannedOut' => $plannedOut,
        ], 'layouts/admin');
    }

    public function exportCsv(): void
    {
        AuthController::requireRole('superuser', 'kassier');

        $filters = [
            'type'     => query('type'),
            'category' => query('category'),
            'from'     => query('from'),
            'to'       => query('to'),
            'q'        => query('q'),
            'payment_method_id' => query('payment_method_id'),
        ];

        $rows = LedgerRepo::search($filters, 100000);

        Audit::log('ledger_export', 'ledger', null, count($rows) . ' Buchungen');

        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="kassabuch-' . date('Y-m-d') . '.csv"');

        $out = fopen('php://output', 'wb');

        if ($out === false) {
            return;
        }

        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['Datum', 'Art', 'Kategorie', 'Betreff', 'Zahlungsart', 'Betrag', 'Mitglied', 'erfasst von'], ';');

        foreach ($rows as $row) {
            fputcsv($out, [
                format_date((string) $row['booked_on']),
                $row['type'] === 'ausgabe' ? 'Ausgabe' : 'Einnahme',
                $row['category'],
                $row['text'],
                $row['payment_method_name'] ?? '',
                number_format((float) $row['amount'] * ($row['type'] === 'ausgabe' ? -1 : 1), 2, ',', ''),
                trim((string) ($row['first_name'] ?? '') . ' ' . (string) ($row['last_name'] ?? '')),
                $row['created_by_name'] ?? '',
            ], ';');
        }

        fclose($out);
        exit;
    }

}
