<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Ai;
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
use App\Models\FeeRepo;
use App\Models\LedgerRepo;
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
            Flash::error('Bitte eine Datei auswählen (CSV/XLSX, mit KI auch PDF oder Foto).');
            Url::redirect('/admin/bank/import');
        }

        $name = (string) $file['name'];
        $ext  = strtolower((string) pathinfo($name, PATHINFO_EXTENSION));

        $strukturiert = in_array($ext, ['csv', 'txt', 'xlsx'], true);
        $kiFormate    = ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'gif'];

        if (!$strukturiert && !in_array($ext, $kiFormate, true)) {
            Flash::error('Nur CSV-/XLSX-Exports sowie – mit KI – PDF-Auszüge und Fotos werden unterstützt.');
            Url::redirect('/admin/bank/import');
        }

        if (!$strukturiert && !Ai::configured()) {
            Flash::error('PDF-Auszüge und Fotos liest die KI – dafür in den Einstellungen einen Anthropic API-Schlüssel hinterlegen (CSV/XLSX geht auch ohne).');
            Url::redirect('/admin/bank/import');
        }

        try {
            $ergebnis = $strukturiert
                ? BankStatementParser::parse((string) $file['tmp_name'], $name)
                : $this->aiParse((string) $file['tmp_name'], $name);
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

    /**
     * KI-Import: Claude liest einen beliebigen Kontoauszug (PDF, Foto,
     * Abrechnungsliste) und liefert die Buchungszeilen im Format von
     * BankStatementParser::parse. Danach laeuft alles wie beim CSV-Import
     * (Hash-Duplikatschutz, Auto-Zuordnung).
     *
     * @return array{rows: list<array<string,mixed>>}
     * @throws \RuntimeException mit anzeigbarer Meldung
     */
    private function aiParse(string $pfad, string $name): array
    {
        if ((int) filesize($pfad) > 20 * 1024 * 1024) {
            throw new \RuntimeException('Die Datei ist zu groß (maximal 20 MB).');
        }

        $mime = (string) (mime_content_type($pfad) ?: '');

        if (!in_array($mime, ['application/pdf', 'image/jpeg', 'image/png', 'image/webp', 'image/gif'], true)) {
            throw new \RuntimeException('Dateityp nicht erkannt – bitte PDF oder Foto hochladen.');
        }

        $zeile = [
            'type'       => 'object',
            'properties' => [
                'booked_on'   => ['type' => 'string', 'description' => 'Buchungsdatum YYYY-MM-DD'],
                'amount'      => ['type' => 'number', 'description' => 'Betrag; Eingang positiv, Ausgang negativ'],
                'currency'    => ['type' => 'string'],
                'counterpart' => ['type' => 'string', 'description' => 'Auftraggeber/Empfänger'],
                'iban'        => ['type' => 'string'],
                'reference'   => ['type' => 'string', 'description' => 'Verwendungszweck/Buchungstext'],
            ],
            'required'             => ['booked_on', 'amount', 'currency', 'counterpart', 'iban', 'reference'],
            'additionalProperties' => false,
        ];

        $schema = [
            'type'                 => 'object',
            'properties'           => ['rows' => ['type' => 'array', 'items' => $zeile]],
            'required'             => ['rows'],
            'additionalProperties' => false,
        ];

        $anweisung = 'Du liest Kontoauszüge, Umsatzlisten und Abrechnungen beliebiger Banken und '
            . 'Zahlungsdienste für die Buchhaltung eines österreichischen Sportvereins aus. '
            . 'Extrahiere JEDE einzelne Umsatzzeile. Keine Zeilen erfinden, keine zusammenfassen. '
            . 'Salden, Zwischensummen, Kontostand und Überschriften sind KEINE Umsätze und werden '
            . 'weggelassen. Datum als YYYY-MM-DD (TT.MM.JJJJ umrechnen; fehlt das Jahr, aus dem '
            . 'Kontext des Auszugs ergänzen). Betrag mit Dezimalpunkt; Abgänge/Lastschriften negativ, '
            . 'Eingänge positiv. currency als ISO-Code (fehlt sie, EUR). IBAN nur, wenn eine der '
            . 'Gegenpartei dasteht. Nicht sicher lesbare Werte leer lassen statt raten.';

        $roh = Ai::request(
            [
                Ai::fileBlock($mime, $pfad),
                ['type' => 'text', 'text' => 'Bitte alle Umsatzzeilen dieses Auszugs („' . $name . '“) extrahieren.'],
            ],
            $anweisung,
            $schema,
            16000
        );

        $rows = [];

        foreach ((array) ($roh['rows'] ?? []) as $r) {
            if (!is_array($r)) {
                continue;
            }

            $datum  = parse_date(trim((string) ($r['booked_on'] ?? '')));
            $betrag = (float) ($r['amount'] ?? 0);

            if ($datum === null || abs($betrag) < 0.005) {
                continue;
            }

            $rows[] = [
                'booked_on'   => $datum,
                'amount'      => round($betrag, 2),
                'currency'    => strtoupper(mb_substr(trim((string) ($r['currency'] ?? 'EUR')), 0, 3)) ?: 'EUR',
                'counterpart' => mb_substr(trim((string) ($r['counterpart'] ?? '')), 0, 150),
                'iban'        => strtoupper((string) preg_replace('/\s+/', '', (string) ($r['iban'] ?? ''))),
                'reference'   => mb_substr(trim((string) ($r['reference'] ?? '')), 0, 300),
            ];
        }

        if ($rows === []) {
            throw new \RuntimeException('Die KI hat keine Umsatzzeilen gefunden – ist das wirklich ein Kontoauszug?');
        }

        return ['rows' => $rows];
    }

    /**
     * KI-Zuordnung: Claude bekommt die unbestimmten Zahlungen und die
     * Mitgliederliste und schlaegt je Zahlung ein Mitglied oder eine
     * Kategorie vor. Nichts wird endgueltig uebernommen – die Vorschlaege
     * laufen wie die Auto-Zuordnung ueber den Status "vorgeschlagen".
     */
    public function aiMatch(): void
    {
        AuthController::requireRole('superuser', 'kassier');
        Csrf::verify();

        if (!Ai::configured()) {
            Flash::error('Für die KI-Zuordnung in den Einstellungen einen Anthropic API-Schlüssel hinterlegen.');
            $this->back();
        }

        $txs = Database::all(
            "SELECT id, booked_on, amount, counterpart, iban, reference
               FROM bank_transactions WHERE status = 'unbestimmt'
              ORDER BY booked_on DESC, id DESC LIMIT 150"
        );

        if ($txs === []) {
            Flash::info('Keine unbestimmten Zahlungen vorhanden.');
            $this->back();
        }

        $mitglieder = Database::all(
            "SELECT id, first_name, last_name, member_no FROM members
              WHERE deleted_at IS NULL ORDER BY last_name COLLATE NOCASE, first_name COLLATE NOCASE"
        );

        $txText = implode("\n", array_map(static fn (array $t): string => sprintf(
            '%d | %s | %.2f | %s | %s | %s',
            (int) $t['id'],
            (string) $t['booked_on'],
            (float) $t['amount'],
            (string) $t['counterpart'],
            (string) $t['iban'],
            (string) $t['reference']
        ), $txs));

        $mText = implode("\n", array_map(static fn (array $m): string => sprintf(
            '%d | %s %s | %s',
            (int) $m['id'],
            (string) $m['first_name'],
            (string) $m['last_name'],
            (string) $m['member_no']
        ), $mitglieder));

        $schema = [
            'type'       => 'object',
            'properties' => ['zuordnungen' => ['type' => 'array', 'items' => [
                'type'       => 'object',
                'properties' => [
                    'id'         => ['type' => 'integer', 'description' => 'Id der Zahlung'],
                    'member_id'  => ['type' => ['integer', 'null'], 'description' => 'Id aus der Mitgliederliste oder null'],
                    'category'   => ['type' => ['string', 'null'], 'enum' => array_merge(array_keys(self::CATEGORIES), [null])],
                    'confidence' => ['type' => 'string', 'enum' => ['hoch', 'mittel', 'niedrig']],
                ],
                'required'             => ['id', 'member_id', 'category', 'confidence'],
                'additionalProperties' => false,
            ]]],
            'required'             => ['zuordnungen'],
            'additionalProperties' => false,
        ];

        $anweisung = 'Du ordnest Bankzahlungen eines österreichischen Sportvereins zu. Je Zahlung: '
            . 'entweder das passende Mitglied (member_id aus der Liste) ODER eine Kategorie, wenn kein '
            . 'Mitglied passt. Mitglieder erkennst du an Namen in Gegenpartei oder Verwendungszweck – '
            . 'auch bei Tippfehlern, fehlenden Umlauten, vertauschter Reihenfolge oder wenn ein '
            . 'Elternteil für ein Kind zahlt (Kind steht im Zweck). Vorsicht bei mehreren Mitgliedern '
            . 'mit gleichem Nachnamen: ohne eindeutigen Vornamen member_id null und confidence niedrig. '
            . 'Kategorien nur bei klaren Fällen (z. B. Miete/Energie = betriebskosten, Förderung = '
            . 'foerderung, Spende = spende); sonst category null. Rate nicht: lieber null und '
            . 'confidence niedrig als eine falsche Zuordnung.';

        try {
            $roh = Ai::request(
                [[
                    'type' => 'text',
                    'text' => "Zahlungen (id | datum | betrag | gegenpartei | iban | zweck):\n" . $txText
                        . "\n\nMitglieder (id | name | mitgliedsnummer):\n" . $mText,
                ]],
                $anweisung,
                $schema,
                16000
            );
        } catch (\RuntimeException $e) {
            Flash::error('KI-Zuordnung fehlgeschlagen: ' . $e->getMessage());
            $this->back();
        }

        $txIds      = array_flip(array_map(static fn (array $t): int => (int) $t['id'], $txs));
        $memberIds  = array_flip(array_map(static fn (array $m): int => (int) $m['id'], $mitglieder));
        $betraege   = array_column($txs, 'amount', 'id');
        $vorschlag  = 0;

        foreach ((array) ($roh['zuordnungen'] ?? []) as $z) {
            if (!is_array($z) || !isset($txIds[(int) ($z['id'] ?? 0)])) {
                continue;
            }

            if (!in_array((string) ($z['confidence'] ?? ''), ['hoch', 'mittel'], true)) {
                continue;
            }

            $txId     = (int) $z['id'];
            $memberId = $z['member_id'] !== null && isset($memberIds[(int) $z['member_id']]) ? (int) $z['member_id'] : null;
            $category = isset(self::CATEGORIES[(string) ($z['category'] ?? '')]) ? (string) $z['category'] : '';

            if ($memberId !== null) {
                // Mitgliedsbeitrag nur bei Eingaengen vorbelegen.
                $category = (float) ($betraege[$txId] ?? 0) > 0 ? 'mitgliedsbeitrag' : $category;
            } elseif ($category === '') {
                continue;
            }

            Database::update('bank_transactions', $txId, [
                'member_id' => $memberId,
                'category'  => $category,
                'status'    => 'vorgeschlagen',
            ]);
            $vorschlag++;
        }

        Audit::log('bank_ai_match', 'bank_transaction', null, count($txs) . ' geprüft, ' . $vorschlag . ' Vorschläge');
        Flash::success($vorschlag > 0
            ? sprintf('KI-Zuordnung: %d von %d unbestimmten Zahlungen haben jetzt einen Vorschlag – bitte prüfen und übernehmen.', $vorschlag, count($txs))
            : 'KI-Zuordnung: Für die unbestimmten Zahlungen wurde kein sicherer Vorschlag gefunden.');
        $this->back();
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
            // Mit dieser Zahlung beglichene Beitragsperioden wieder oeffnen,
            // damit Kassabuch und Beitragsliste konsistent bleiben.
            $wiederOffen = 0;

            foreach (array_filter(array_map('intval', explode(',', (string) ($tx['settled_ids'] ?? '')))) as $entryId) {
                FeeRepo::markOpen($entryId);
                $wiederOffen++;
            }

            Database::update('bank_transactions', $id, [
                'status' => 'unbestimmt', 'member_id' => null, 'category' => '',
                'settled_info' => '', 'settled_ids' => '',
                'assigned_by' => null, 'assigned_at' => null,
            ]);
            Flash::success('Zuordnung aufgehoben.'
                . ($wiederOffen > 0 ? " $wiederOffen Beitragsperiode(n) wieder auf offen gesetzt." : ''));
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

        // Beitragskopplung: Eingang + Mitglied + Kategorie Mitgliedsbeitrag
        // gleicht offene Beitragsperioden aus (abschaltbar per Haken).
        $settledInfo = '';
        $settledIds  = [];

        if ($memberId !== null && $category === 'mitgliedsbeitrag'
            && (float) $tx['amount'] > 0 && post_bool('settle') === 1) {
            [$settledInfo, $settledIds] = $this->settleFees($memberId, (float) $tx['amount'], (string) $tx['booked_on'], $id);
        }

        Database::update('bank_transactions', $id, [
            'member_id'    => $memberId,
            'category'     => $category,
            'note'         => mb_substr(trim(post('note')), 0, 300),
            'settled_info' => $settledInfo,
            'settled_ids'  => implode(',', $settledIds),
            'status'       => 'uebernommen',
            'assigned_by'  => Auth::id(),
            'assigned_at'  => gmdate('Y-m-d H:i:s'),
        ]);

        Audit::log('bank_assign', 'bank_transaction', $id, sprintf(
            '%s %.2f: %s%s',
            (string) $tx['booked_on'],
            (float) $tx['amount'],
            $memberId !== null ? 'Mitglied #' . $memberId : self::CATEGORIES[$category],
            $settledInfo !== '' ? ' – ' . $settledInfo : ''
        ));

        Flash::success('Zahlung übernommen.' . ($settledInfo !== '' ? ' ' . $settledInfo : ''));
        $this->back();
    }

    /** Alle Auto-Vorschlaege uebernehmen; Beitragseingaenge gleichen dabei offene Perioden aus. */
    public function confirmSuggestions(): void
    {
        AuthController::requireRole('superuser', 'kassier');
        Csrf::verify();

        $anzahl    = 0;
        $beglichen = 0;

        foreach (Database::all("SELECT * FROM bank_transactions WHERE status = 'vorgeschlagen'") as $tx) {
            $settledInfo = '';
            $settledIds  = [];

            if ($tx['member_id'] !== null && (string) $tx['category'] === 'mitgliedsbeitrag'
                && (float) $tx['amount'] > 0) {
                [$settledInfo, $settledIds] = $this->settleFees(
                    (int) $tx['member_id'],
                    (float) $tx['amount'],
                    (string) $tx['booked_on'],
                    (int) $tx['id']
                );
            }

            Database::update('bank_transactions', (int) $tx['id'], [
                'status'       => 'uebernommen',
                'settled_info' => $settledInfo,
                'settled_ids'  => implode(',', $settledIds),
                'assigned_by'  => Auth::id(),
                'assigned_at'  => gmdate('Y-m-d H:i:s'),
            ]);

            $anzahl++;

            if ($settledInfo !== '') {
                $beglichen++;
            }
        }

        Audit::log('bank_confirm_all', 'bank_transaction', null, $anzahl . ' Vorschläge übernommen, ' . $beglichen . ' mit Beitragsausgleich');
        Flash::success($anzahl . ' vorgeschlagene Zuordnungen übernommen'
            . ($beglichen > 0 ? ", $beglichen davon mit ausgeglichenen Beiträgen" : '') . '.');
        $this->back();
    }

    /**
     * Offene Beitragsperioden eines Mitglieds mit einer Bankzahlung ausgleichen.
     *
     * Zuerst wird eine Periode mit EXAKT passendem Betrag gesucht; sonst
     * werden die aeltesten offenen Perioden beglichen, solange der Betrag
     * reicht. Ein Rest bleibt unverbucht und wird ausgewiesen. Jede
     * beglichene Periode laeuft ueber FeeRepo::markPaid (inkl. Kassabuch).
     *
     * @return array{0: string, 1: list<int>} [Anzeige-Text, beglichene fee_entries-Ids]
     */
    private function settleFees(int $memberId, float $betrag, string $bookedOn, int $txId): array
    {
        $offen = Database::all(
            'SELECT id, period, period_label, amount FROM fee_entries
              WHERE member_id = ? AND paid = 0
              ORDER BY due_date, period',
            [$memberId]
        );

        if ($offen === []) {
            return ['Keine offenen Beiträge – nichts ausgeglichen.', []];
        }

        $beglichen = [];
        $rest      = round($betrag, 2);

        // 1) Exakter Treffer auf genau eine Periode.
        foreach ($offen as $zeile) {
            if (abs((float) $zeile['amount'] - $rest) < 0.005) {
                $beglichen[] = $zeile;
                $rest        = 0.0;
                break;
            }
        }

        // 2) Sonst die aeltesten Perioden ausgleichen, solange der Betrag reicht.
        if ($beglichen === []) {
            foreach ($offen as $zeile) {
                if ((float) $zeile['amount'] <= $rest + 0.005) {
                    $beglichen[] = $zeile;
                    $rest        = round($rest - (float) $zeile['amount'], 2);
                }
            }
        }

        if ($beglichen === []) {
            return [sprintf('Betrag (%.2f €) deckt keine offene Periode – nichts ausgeglichen.', $betrag), []];
        }

        foreach ($beglichen as $zeile) {
            FeeRepo::markPaid(
                (int) $zeile['id'],
                null,
                $bookedOn,
                Auth::id(),
                'per Bankimport (Zahlung #' . $txId . ')',
                // Kassabuch: Bankimport = Zahlungsart Bank (sonst landet es als Barkassa).
                LedgerRepo::defaultMethodId('bank')
            );
        }

        $labels = implode(', ', array_map(
            static fn (array $z): string => (string) ($z['period_label'] ?: $z['period']),
            $beglichen
        ));

        return [
            'Beglichen: ' . $labels
                . ($rest > 0.005 ? sprintf(' – Rest %.2f € offen gelassen', $rest) : ''),
            array_map(static fn (array $z): int => (int) $z['id'], $beglichen),
        ];
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
            'Status', 'Mitglied', 'Mitgl.-Nr.', 'Kategorie', 'Beglichene Beiträge', 'Notiz', 'Belege',
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
                (string) ($r['settled_info'] ?? ''),
                (string) $r['note'],
                implode(' | ', array_map(
                    static fn (array $f): string => $f['filename'] . ': ' . $basis . url('/admin/bank/beleg/' . $f['id']),
                    $belege
                )),
            ];
        }

        $xlsx = new XlsxWriter();
        $xlsx->addSheet('Zahlungen', $daten, [11, 11, 8, 26, 24, 40, 13, 24, 10, 20, 28, 24, 50]);
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
