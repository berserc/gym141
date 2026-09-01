<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\BankStatementParser;
use App\Core\Config;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\Flash;
use App\Core\Url;
use App\Core\View;
use App\Core\XlsxWriter;
use App\Models\MemberRepo;

/**
 * Bankimport: Konto-Exports (CSV/XLSX) verschiedener Banken einspielen,
 * Zahlungen Mitgliedern oder Kategorien zuordnen, Belege anhaengen,
 * excel-artige Zahlungsansicht mit Filtern und XLSX-Export.
 *
 * Duplikatschutz: jede Buchungszeile bekommt einen Hash aus Datum, Betrag,
 * IBAN, Gegenpartei und Verwendungszweck - bereits eingespielte Zeilen
 * werden beim erneuten Hochladen still uebersprungen.
 *
 * Ablauf: Import -> Auto-Zuordnung als "vorgeschlagen" (Mitgliedsnummer im
 * Verwendungszweck, bekannte IBAN, exakter Name) oder "unbestimmt" ->
 * Bearbeitung (Mitglied ODER Kategorie) -> "uebernommen" (endgueltig).
 */
final class BankController
{
    /** Kategorien fuer Zahlungen ohne Mitgliedsbezug (und fuer Beitraege). */
    public const CATEGORIES = [
        'mitgliedsbeitrag'      => 'Mitgliedsbeitrag',
        'spende'                => 'Spende',
        'foerderung'            => 'Förderung/Subvention',
        'verkauf'               => 'Einnahme aus Verkauf',
        'veranstaltung'         => 'Veranstaltung',
        'sonderbetriebskosten'  => 'Sonderbetriebskosten',
        'betriebskosten'        => 'Betriebskosten',
        'sonstige_einnahme'     => 'Sonstige Einnahme',
        'sonstige_ausgabe'      => 'Sonstige Ausgabe',
    ];

    /** Erlaubte Belegtypen (Endung => MIME). */
    public const FILE_TYPES = [
        'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
        'gif' => 'image/gif', 'webp' => 'image/webp', 'heic' => 'image/heic',
        'pdf' => 'application/pdf', 'txt' => 'text/plain', 'csv' => 'text/csv',
        'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls' => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'zip' => 'application/zip',
    ];

    private const MAX_FILE_BYTES = 25 * 1024 * 1024;

    // ------------------------------------------------------------- Ansicht --

    public function index(): void
    {
        AuthController::requireRole('superuser', 'kassier');

        [$where, $params, $filters] = $this->filterQuery();

        $rows = Database::all(
            "SELECT t.*, m.first_name, m.last_name, m.member_no,
                    (SELECT COUNT(*) FROM bank_transaction_files f WHERE f.transaction_id = t.id) AS n_files
               FROM bank_transactions t
               LEFT JOIN members m ON m.id = t.member_id
              WHERE $where
              ORDER BY t.booked_on DESC, t.id DESC
              LIMIT 1000",
            $params
        );

        $files = [];

        foreach ($rows as $r) {
            if ((int) $r['n_files'] > 0) {
                $files[(int) $r['id']] = Database::all(
                    'SELECT id, filename FROM bank_transaction_files WHERE transaction_id = ? ORDER BY id',
                    [(int) $r['id']]
                );
            }
        }

        View::display('admin/bank/index', [
            'title'      => 'Zahlungen',
            'rows'       => $rows,
            'files'      => $files,
            'filters'    => $filters,
            'categories' => self::CATEGORIES,
            'stats'      => [
                'summe'       => array_sum(array_map(static fn (array $r): float => (float) $r['amount'], $rows)),
                'unbestimmt'  => (int) Database::value("SELECT COUNT(*) FROM bank_transactions WHERE status = 'unbestimmt'"),
                'vorschlaege' => (int) Database::value("SELECT COUNT(*) FROM bank_transactions WHERE status = 'vorgeschlagen'"),
            ],
            'mitglieder' => Database::all(
                "SELECT id, first_name, last_name, member_no FROM members
                  WHERE deleted_at IS NULL ORDER BY last_name COLLATE NOCASE, first_name COLLATE NOCASE"
            ),
        ], 'layouts/admin');
    }

    // -------------------------------------------------------------- Import --

    public function import(): void
    {
        AuthController::requireRole('superuser', 'kassier');

        View::display('admin/bank/import', [
            'title'   => 'Kontoauszug einspielen',
            'imports' => Database::all(
                'SELECT b.*, u.username FROM bank_imports b LEFT JOIN users u ON u.id = b.imported_by
                  ORDER BY b.id DESC LIMIT 20'
            ),
        ], 'layouts/admin');
    }

    public function runImport(): void
    {
        AuthController::requireRole('superuser', 'kassier');
        Csrf::verify();

        $file = $_FILES['datei'] ?? null;

        if ($file === null || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            Flash::error('Bitte eine CSV- oder XLSX-Datei auswählen.');
            Url::redirect('/admin/bank/import');
        }

        $name = (string) $file['name'];
        $ext  = strtolower((string) pathinfo($name, PATHINFO_EXTENSION));

        if (!in_array($ext, ['csv', 'txt', 'xlsx'], true)) {
            Flash::error('Nur CSV- oder XLSX-Exports werden unterstützt.');
            Url::redirect('/admin/bank/import');
        }

        try {
            $ergebnis = BankStatementParser::parse((string) $file['tmp_name'], $name);
        } catch (\Throwable $e) {
            Flash::error('Import fehlgeschlagen: ' . $e->getMessage());
            Url::redirect('/admin/bank/import');
        }

        $importId = Database::insert('bank_imports', [
            'filename'    => mb_substr($name, 0, 150),
            'imported_by' => Auth::id(),
            'row_count'   => count($ergebnis['rows']),
        ]);

        $neu = 0;
        $dup = 0;

        Database::transaction(function () use ($ergebnis, $importId, &$neu, &$dup): void {
            foreach ($ergebnis['rows'] as $row) {
                $hash = hash('sha256', implode('|', [
                    $row['booked_on'], number_format((float) $row['amount'], 2, '.', ''),
                    $row['iban'], $row['counterpart'], $row['reference'],
                ]));

                if (Database::one('SELECT id FROM bank_transactions WHERE hash = ?', [$hash]) !== null) {
                    $dup++;

                    continue;
                }

                [$memberId, $status] = $this->autoMatch($row);

                Database::insert('bank_transactions', $row + [
                    'import_id' => $importId,
                    'hash'      => $hash,
                    'status'    => $status,
                    'member_id' => $memberId,
                    'category'  => $memberId !== null ? 'mitgliedsbeitrag' : '',
                ]);
                $neu++;
            }
        });

        Database::update('bank_imports', $importId, ['new_count' => $neu, 'duplicate_count' => $dup]);
        Audit::log('bank_import', 'bank_import', $importId, "$name: $neu neu, $dup Duplikate");

        Flash::success(sprintf(
            'Import „%s“: %d Zeilen gelesen, %d neu übernommen, %d bereits vorhanden (übersprungen).',
            $name,
            count($ergebnis['rows']),
            $neu,
            $dup
        ));
        Url::redirect('/admin/bank' . ($neu > 0 ? '?status=offen' : ''));
    }

    /**
     * Auto-Zuordnung: Mitgliedsnummer im Verwendungszweck, bereits bekannte
     * IBAN oder exakter Namens-Treffer -> Vorschlag; sonst unbestimmt.
     *
     * @param array<string,mixed> $row
     * @return array{0: ?int, 1: string}
     */
    private function autoMatch(array $row): array
    {
        // 1) IBAN, die frueher schon einem Mitglied zugeordnet wurde.
        if ((string) $row['iban'] !== '') {
            $bekannt = Database::value(
                "SELECT member_id FROM bank_transactions
                  WHERE iban = ? AND member_id IS NOT NULL AND status = 'uebernommen'
                  ORDER BY id DESC LIMIT 1",
                [(string) $row['iban']]
            );

            if ($bekannt !== null) {
                return [(int) $bekannt, 'vorgeschlagen'];
            }
        }

        $text = mb_strtolower((string) $row['reference'] . ' ' . (string) $row['counterpart']);

        // 2) Mitgliedsnummer im Verwendungszweck.
        foreach (Database::all(
            "SELECT id, member_no FROM members
              WHERE deleted_at IS NULL AND member_no <> '' AND length(member_no) >= 3"
        ) as $m) {
            if (str_contains($text, mb_strtolower((string) $m['member_no']))) {
                return [(int) $m['id'], 'vorgeschlagen'];
            }
        }

        // 3) Exakter Name (Vorname Zuname oder Zuname Vorname) in Gegenpartei/Zweck.
        $partner = mb_strtolower(trim((string) $row['counterpart']));

        if ($partner !== '') {
            $treffer = Database::all(
                "SELECT id FROM members
                  WHERE deleted_at IS NULL
                    AND (lower(first_name || ' ' || last_name) = ? OR lower(last_name || ' ' || first_name) = ?)",
                [$partner, $partner]
            );

            if (count($treffer) === 1) {
                return [(int) $treffer[0]['id'], 'vorgeschlagen'];
            }
        }

        return [null, 'unbestimmt'];
    }

    // ----------------------------------------------------------- Zuordnung --

    /** Eine Zahlung bearbeiten: Mitglied und/oder Kategorie setzen, uebernehmen. */
    public function assign(array $args): void
    {
        AuthController::requireRole('superuser', 'kassier');
        Csrf::verify();

        $tx = $this->find((int) ($args['id'] ?? 0));
        $id = (int) $tx['id'];

        if (post('aktion') === 'zuruecksetzen') {
            Database::update('bank_transactions', $id, [
                'status' => 'unbestimmt', 'member_id' => null, 'category' => '',
                'assigned_by' => null, 'assigned_at' => null,
            ]);
            Flash::success('Zuordnung aufgehoben.');
            $this->back();
        }

        $memberId  = null;
        $memberRef = trim(post('member_ref'));

        if ($memberRef !== '') {
            $memberId = MemberRepo::resolveRef($memberRef);

            if ($memberId === null) {
                Flash::error('Mitglied nicht gefunden (Mitgliedsnummer oder "Zuname Vorname").');
                $this->back();
            }
        }

        $category = post('category');

        if (!isset(self::CATEGORIES[$category])) {
            $category = $memberId !== null ? 'mitgliedsbeitrag' : '';
        }

        if ($memberId === null && $category === '') {
            Flash::error('Bitte ein Mitglied zuordnen ODER eine Kategorie wählen.');
            $this->back();
        }

        Database::update('bank_transactions', $id, [
            'member_id'   => $memberId,
            'category'    => $category,
            'note'        => mb_substr(trim(post('note')), 0, 300),
            'status'      => 'uebernommen',
            'assigned_by' => Auth::id(),
            'assigned_at' => gmdate('Y-m-d H:i:s'),
        ]);

        Audit::log('bank_assign', 'bank_transaction', $id, sprintf(
            '%s %.2f: %s',
            (string) $tx['booked_on'],
            (float) $tx['amount'],
            $memberId !== null ? 'Mitglied #' . $memberId : self::CATEGORIES[$category]
        ));

        Flash::success('Zahlung übernommen.');
        $this->back();
    }

    /** Alle Auto-Vorschlaege in einem Schritt uebernehmen. */
    public function confirmSuggestions(): void
    {
        AuthController::requireRole('superuser', 'kassier');
        Csrf::verify();

        $anzahl = Database::run(
            "UPDATE bank_transactions
                SET status = 'uebernommen', assigned_by = ?, assigned_at = ?
              WHERE status = 'vorgeschlagen'",
            [Auth::id(), gmdate('Y-m-d H:i:s')]
        )->rowCount();

        Audit::log('bank_confirm_all', 'bank_transaction', null, $anzahl . ' Vorschläge übernommen');
        Flash::success($anzahl . ' vorgeschlagene Zuordnungen übernommen.');
        $this->back();
    }

    // --------------------------------------------------------------- Belege --

    public function uploadFile(array $args): void
    {
        AuthController::requireRole('superuser', 'kassier');
        Csrf::verify();

        $tx   = $this->find((int) ($args['id'] ?? 0));
        $file = $_FILES['datei'] ?? null;

        if ($file === null || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            Flash::error('Upload fehlgeschlagen.');
            $this->back();
        }

        if ((int) $file['size'] > self::MAX_FILE_BYTES) {
            Flash::error('Datei zu groß (max. 25 MB).');
            $this->back();
        }

        $ext = strtolower((string) pathinfo((string) $file['name'], PATHINFO_EXTENSION));

        if (!isset(self::FILE_TYPES[$ext])) {
            Flash::error('Dateityp .' . $ext . ' ist nicht erlaubt.');
            $this->back();
        }

        $dir = self::fileDir((int) $tx['id']);

        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            Flash::error('Ablage nicht beschreibbar.');
            $this->back();
        }

        $intern = bin2hex(random_bytes(16)) . '.' . $ext;

        if (!move_uploaded_file((string) $file['tmp_name'], $dir . '/' . $intern)) {
            Flash::error('Datei konnte nicht gespeichert werden.');
            $this->back();
        }

        Database::insert('bank_transaction_files', [
            'transaction_id' => (int) $tx['id'],
            'filename'       => mb_substr((string) $file['name'], 0, 150),
            'stored_as'      => $intern,
            'mime'           => self::FILE_TYPES[$ext],
            'size'           => (int) $file['size'],
        ]);

        Flash::success('Beleg „' . $file['name'] . '“ angehängt.');
        $this->back();
    }

    public function file(array $args): void
    {
        AuthController::requireRole('superuser', 'kassier');

        $f = Database::one('SELECT * FROM bank_transaction_files WHERE id = ?', [(int) ($args['id'] ?? 0)]);
        $pfad = $f !== null ? self::fileDir((int) $f['transaction_id']) . '/' . $f['stored_as'] : '';

        if ($f === null || !is_file($pfad)) {
            http_response_code(404);
            exit('Beleg nicht gefunden.');
        }

        header('Content-Type: ' . $f['mime']);
        header('Content-Length: ' . (string) filesize($pfad));
        header('Content-Disposition: inline; filename="' . rawurlencode((string) $f['filename']) . '"');
        readfile($pfad);
        exit;
    }

    public function deleteFile(array $args): void
    {
        AuthController::requireRole('superuser', 'kassier');
        Csrf::verify();

        $f = Database::one('SELECT * FROM bank_transaction_files WHERE id = ?', [(int) ($args['id'] ?? 0)]);

        if ($f !== null) {
            @unlink(self::fileDir((int) $f['transaction_id']) . '/' . $f['stored_as']);
            Database::run('DELETE FROM bank_transaction_files WHERE id = ?', [(int) $f['id']]);
            Flash::success('Beleg gelöscht.');
        }

        $this->back();
    }

    // --------------------------------------------------------------- Export --

    /** XLSX-Export der aktuellen Filterung, inkl. Links zu den Belegen. */
    public function exportXlsx(): void
    {
        AuthController::requireRole('superuser', 'kassier');

        [$where, $params] = $this->filterQuery();

        $rows = Database::all(
            "SELECT t.*, m.first_name, m.last_name, m.member_no
               FROM bank_transactions t
               LEFT JOIN members m ON m.id = t.member_id
              WHERE $where
              ORDER BY t.booked_on DESC, t.id DESC",
            $params
        );

        $schema = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $basis  = $schema . '://' . (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');

        $daten = [[
            'Datum', 'Betrag', 'Währung', 'Gegenpartei', 'IBAN', 'Verwendungszweck',
            'Status', 'Mitglied', 'Mitgl.-Nr.', 'Kategorie', 'Notiz', 'Belege',
        ]];

        foreach ($rows as $r) {
            $belege = Database::all(
                'SELECT id, filename FROM bank_transaction_files WHERE transaction_id = ? ORDER BY id',
                [(int) $r['id']]
            );

            $daten[] = [
                (string) $r['booked_on'],
                (float) $r['amount'],
                (string) $r['currency'],
                (string) $r['counterpart'],
                (string) $r['iban'],
                (string) $r['reference'],
                (string) $r['status'],
                $r['member_id'] !== null ? trim($r['last_name'] . ' ' . $r['first_name']) : '',
                (string) ($r['member_no'] ?? ''),
                self::CATEGORIES[(string) $r['category']] ?? (string) $r['category'],
                (string) $r['note'],
                implode(' | ', array_map(
                    static fn (array $f): string => $f['filename'] . ': ' . $basis . url('/admin/bank/beleg/' . $f['id']),
                    $belege
                )),
            ];
        }

        $xlsx = new XlsxWriter();
        $xlsx->addSheet('Zahlungen', $daten, [11, 11, 8, 26, 24, 40, 13, 24, 10, 20, 24, 50]);
        $xlsx->download('zahlungen-' . date('Y-m-d') . '.xlsx');
    }

    // --------------------------------------------------------------- Intern --

    /**
     * Filter aus dem Query-String: Zeitraum (Presets + von/bis), Betrag
     * von/bis, Volltext, Mitglied, Status, Kategorie.
     *
     * @return array{0: string, 1: list<mixed>, 2: array<string,string>}
     */
    private function filterQuery(): array
    {
        $filters = [
            'zeitraum'   => query('zeitraum'),   // monat|quartal|jahr|'' (frei)
            'von'        => query('von'),
            'bis'        => query('bis'),
            'betrag_von' => query('betrag_von'),
            'betrag_bis' => query('betrag_bis'),
            'q'          => query('q'),
            'mitglied'   => query('mitglied'),
            'status'     => query('status'),
            'kategorie'  => query('kategorie'),
        ];

        // Zeitraum-Presets setzen von/bis, solange nichts Eigenes gewaehlt ist.
        if ($filters['von'] === '' && $filters['bis'] === '') {
            [$von, $bis] = match ($filters['zeitraum']) {
                'monat'   => [date('Y-m-01'), date('Y-m-t')],
                'quartal' => [
                    date('Y-m-01', mktime(0, 0, 0, (int) (floor(((int) date('n') - 1) / 3) * 3 + 1), 1)),
                    date('Y-m-t', mktime(0, 0, 0, (int) (floor(((int) date('n') - 1) / 3) * 3 + 3), 1)),
                ],
                'jahr'    => [date('Y-01-01'), date('Y-12-31')],
                default   => ['', ''],
            };

            $filters['von'] = $von;
            $filters['bis'] = $bis;
        }

        $where  = ['1 = 1'];
        $params = [];

        if ($filters['von'] !== '') {
            $where[]  = 't.booked_on >= ?';
            $params[] = $filters['von'];
        }

        if ($filters['bis'] !== '') {
            $where[]  = 't.booked_on <= ?';
            $params[] = $filters['bis'];
        }

        foreach (['betrag_von' => '>=', 'betrag_bis' => '<='] as $feld => $op) {
            if ($filters[$feld] !== '') {
                $betrag = BankStatementParser::parseAmount($filters[$feld]);

                if ($betrag !== null) {
                    // Vorzeichenunabhaengig vergleichen (Ausgaben sind negativ).
                    // CAST noetig: PDO bindet als TEXT, und in SQLite ist
                    // REAL < TEXT - ohne CAST matcht der Vergleich nie.
                    $where[]  = "abs(t.amount) $op CAST(? AS REAL)";
                    $params[] = abs($betrag);
                }
            }
        }

        if ($filters['q'] !== '') {
            $where[] = '(t.reference LIKE ? OR t.counterpart LIKE ? OR t.note LIKE ? OR t.iban LIKE ?)';
            $like    = '%' . $filters['q'] . '%';
            array_push($params, $like, $like, $like, $like);
        }

        if ($filters['mitglied'] !== '') {
            $memberId = MemberRepo::resolveRef($filters['mitglied']);
            $where[]  = 't.member_id = ?';
            $params[] = $memberId ?? -1;
        }

        if ($filters['status'] === 'offen') {
            $where[] = "t.status IN ('unbestimmt', 'vorgeschlagen')";
        } elseif (in_array($filters['status'], ['unbestimmt', 'vorgeschlagen', 'uebernommen'], true)) {
            $where[]  = 't.status = ?';
            $params[] = $filters['status'];
        }

        if ($filters['kategorie'] !== '' && isset(self::CATEGORIES[$filters['kategorie']])) {
            $where[]  = 't.category = ?';
            $params[] = $filters['kategorie'];
        }

        return [implode(' AND ', $where), $params, $filters];
    }

    /** @return array<string,mixed> */
    private function find(int $id): array
    {
        $tx = Database::one('SELECT * FROM bank_transactions WHERE id = ?', [$id]);

        if ($tx === null) {
            Flash::error('Zahlung nicht gefunden.');
            Url::redirect('/admin/bank');
        }

        return $tx;
    }

    public static function fileDir(int $transactionId): string
    {
        return dirname((string) Config::get('db_path')) . '/bank/' . $transactionId;
    }

    /** Zurueck zur Liste, Filter aus dem Referer erhalten. */
    private function back(): never
    {
        $ref = (string) ($_SERVER['HTTP_REFERER'] ?? '');
        $ziel = str_contains($ref, '/admin/bank') ? $ref : Url::to('/admin/bank');

        header('Location: ' . $ziel);
        exit;
    }
}
