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
use App\Core\XlsxWriter;
use App\Models\FeeRepo;
use App\Models\LedgerRepo;
use App\Models\MemberRepo;
use App\Models\SectionRepo;
use App\Models\Setting;

final class MemberController
{
    /** Waehlbare Seitengroessen; 'alle' zeigt alles auf einer Seite. */
    public const PAGE_SIZES = ['50', '100', '200', 'alle'];

    private const PER_PAGE_DEFAULT = '200';

    // ------------------------------------------------------------------ Liste --

    public function index(): void
    {
        AuthController::requireLogin();

        $filters  = $this->filtersFromQuery();
        $sections = SectionRepo::forUser(Auth::allowedSectionIds());

        $perPageWahl = in_array($filters['per_page'], self::PAGE_SIZES, true)
            ? $filters['per_page']
            : self::PER_PAGE_DEFAULT;
        $perPage = $perPageWahl === 'alle' ? 100000 : (int) $perPageWahl;

        $result = MemberRepo::search(
            $filters,
            Auth::allowedSectionIds(),
            max(1, (int) query('page', '1')),
            $perPage,
            query('sort', 'name'),
            query('dir', 'asc')
        );

        View::display('admin/members/index', [
            'title'     => !empty($filters['trashed']) ? 'Papierkorb'
                : (!empty($filters['archived']) ? 'Ehemalige Mitglieder' : 'Mitglieder'),
            'filters'   => $filters,
            'perPage'   => $perPageWahl,
            'sections'  => $sections,
            'gemeinden' => MemberRepo::distinctGemeinden(),
            'result'    => $result,
            'sort'      => query('sort', 'name'),
            'dir'       => query('dir', 'asc'),
            'feePlans'  => FeeRepo::plans(),
            'feeTotals' => MemberRepo::activeFeeStats(Auth::allowedSectionIds()),
            'errors'    => Flash::errors(),
        ], 'layouts/admin');
    }

    /** @return array<string,mixed> */
    private function filtersFromQuery(): array
    {
        // Vorauswahl: beim ersten Aufruf nur aktive Mitglieder zeigen.
        // Sobald ein Filter gesetzt wurde (auch "alle"), gilt die Auswahl.
        $status = isset($_GET['status']) || isset($_GET['trashed']) || isset($_GET['delete_requested']) || isset($_GET['archived'])
            ? query('status')
            : 'aktiv';

        return [
            'q'                => query('q'),
            'section_id'       => query('section_id'),
            'status'           => $status,
            'per_page'         => query('per_page'),
            'gemeinde'         => query('gemeinde'),
            'gender'           => query('gender'),
            'delete_requested' => query('delete_requested'),
            'trashed'          => query('trashed'),
            'fee_overdue'      => query('fee_overdue'),
            'fee_plan_id'      => query('fee_plan_id'),
            'paused'           => query('paused'),
            'trainer'          => query('trainer'),
            'archived'         => query('archived'),
            'age_from'         => query('age_from'),
            'age_to'           => query('age_to'),
        ];
    }

    // --------------------------------------------------------------- Formulare --

    public function create(): void
    {
        AuthController::requireRole('superuser', 'sektionsleiter');

        $sections = SectionRepo::forUser(Auth::allowedSectionIds());

        if ($sections === []) {
            Flash::error('Ihnen ist keine Sektion zugeordnet. Bitte an den Superuser wenden.');
            Url::redirect('/admin/mitglieder');
        }

        View::display('admin/members/form', [
            'title'     => 'Neues Mitglied',
            'member'    => Flash::oldInput() + $this->emptyMember((int) $sections[0]['id']),
            'sections'  => $sections,
            'gemeinden' => $this->gemeindeOptions(),
            'feeOptions' => Setting::feeOptions(),
            'feePlans'  => FeeRepo::plans(),
            'errors'    => Flash::errors(),
            'fees'      => [],
            'isNew'     => true,
            'canEdit'   => true,
            'hasScanKey' => Setting::get('anthropic_api_key') !== '',
        ], 'layouts/admin');
    }

    /** @param array<string,string> $args */
    public function edit(array $args): void
    {
        AuthController::requireLogin();

        $member = $this->findAccessible((int) ($args['id'] ?? 0));

        $id = (int) $member['id'];

        View::display('admin/members/form', [
            'title'     => $member['first_name'] . ' ' . $member['last_name'],
            'member'    => Flash::oldInput() + $member,
            'sections'  => SectionRepo::forUser(Auth::allowedSectionIds()),
            'gemeinden' => $this->gemeindeOptions(),
            'feeOptions' => Setting::feeOptions(),
            'feePlans'  => FeeRepo::plans(),
            'errors'    => Flash::errors(),
            'fees'      => FeeRepo::entriesForMember($id),
            'guardians' => MemberRepo::guardians($id),
            'wardsOf'   => MemberRepo::wardsOf($id),
            'pauses'    => MemberRepo::pauses($id),
            'reminders' => Database::all(
                'SELECT * FROM member_reminders WHERE member_id = ? ORDER BY done, due_on',
                [$id]
            ),
            'amountHistory' => FeeRepo::amountHistory('member', $id),
            'invoices'  => LedgerRepo::invoicesForMember($id),
            'methods'   => LedgerRepo::paymentMethods(true),
            'invoiceCategories' => LedgerRepo::categories('einnahme'),
            'files'     => MemberRepo::files($id),
            'photo'     => MemberRepo::photo($id),
            'ledger'    => LedgerRepo::forMember($id),
            'hasEnrollmentFee' => LedgerRepo::hasEnrollmentFee($id),
            'enrollmentFeeDefault' => (float) str_replace(',', '.', Setting::get('enrollment_fee', '50')),
            'askEnrollment' => query('einschreiben') === '1' && Auth::canManageFees(),
            'linkedUser' => Database::one(
                'SELECT id, username, role, active FROM users WHERE member_id = ?',
                [$id]
            ),
            'isNew'     => false,
            // Kassier sieht alles, darf aber keine Stammdaten aendern.
            'canEdit'   => Auth::canWrite(),
        ], 'layouts/admin');
    }

    // ------------------------------------------------------------- Speichern --

    public function store(): void
    {
        AuthController::requireRole('superuser', 'sektionsleiter');
        Csrf::verify();

        [$data, $errors] = $this->validate(true);

        if ($errors !== []) {
            Flash::withInput($_POST, $errors);
            Flash::error('Bitte prüfen Sie die markierten Felder.');
            Url::redirect('/admin/mitglieder/neu');
        }

        // Gratis-Limit: neue AKTIVE Mitglieder zaehlen dagegen.
        if ((string) $data['status'] === 'aktiv'
            && ($limitFehler = \App\Core\License::memberLimitError()) !== null) {
            Flash::withInput($_POST);
            Flash::error($limitFehler);
            Url::redirect('/admin/mitglieder/neu');
        }

        $duplicate = MemberRepo::findDuplicate(
            (string) $data['first_name'],
            (string) $data['last_name'],
            $data['birthdate'] === null ? null : (string) $data['birthdate']
        );

        if ($duplicate !== null && post('confirm_duplicate') !== '1') {
            Flash::withInput($_POST, ['duplicate' => sprintf(
                'Es gibt bereits ein Mitglied "%s %s" (%s) in der Sektion %s. Zum Anlegen bitte bestätigen.',
                $duplicate['first_name'],
                $duplicate['last_name'],
                format_date($duplicate['birthdate'] === null ? null : (string) $duplicate['birthdate']) ?: 'ohne Geburtsdatum',
                $duplicate['section_name']
            )]);
            Flash::error('Mögliche Dublette gefunden.');
            Url::redirect('/admin/mitglieder/neu');
        }

        /** @var list<int> $sectionIds */
        $sectionIds = (array) ($data['section_ids'] ?? []);
        unset($data['section_ids']);

        $id = Database::insert('members', $data);

        // Mitgliedschaften in allen gewaehlten Sektionen anlegen.
        foreach ($sectionIds as $sectionId) {
            Database::run(
                'INSERT OR IGNORE INTO member_sections (member_id, section_id, fee_amount, fee_category, status, joined_on)
                 VALUES (?, ?, ?, ?, ?, ?)',
                [
                    $id,
                    (int) $sectionId,
                    (float) $data['fee_amount'],
                    (string) $data['fee_category'],
                    (string) $data['status'],
                    $data['joined_on'],
                ]
            );
        }

        Audit::log('member_created', 'member', $id, $data['last_name'] . ', ' . $data['first_name']);
        Flash::success('Mitglied angelegt.');

        // Direkt nach dem Anlegen die Einschreibegebuehr abfragen.
        Url::redirect('/admin/mitglieder/' . $id . '?einschreiben=1');
    }

    // ------------------------------------------------- KI-Formularerkennung --

    /**
     * Liest ein fotografiertes/gescanntes Mitgliedsformular per Claude API aus
     * und fuellt die Neuanlage vor. Es wird nichts gespeichert – die erkannten
     * Werte landen als Formularvorbelegung zur Kontrolle.
     */
    public function scanForm(): void
    {
        AuthController::requireRole('superuser', 'sektionsleiter');
        Csrf::verify();

        $apiKey = Setting::get('anthropic_api_key');

        if ($apiKey === '') {
            Flash::error('Kein Anthropic API-Schlüssel hinterlegt. Bitte zuerst in den Einstellungen eintragen.');
            Url::redirect('/admin/mitglieder/neu');
        }

        $file = $_FILES['scan_file'] ?? null;

        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            Flash::error('Bitte ein Foto oder PDF des ausgefüllten Formulars auswählen.');
            Url::redirect('/admin/mitglieder/neu');
        }

        if ((int) $file['size'] > 20 * 1024 * 1024) {
            Flash::error('Die Datei ist zu groß (maximal 20 MB).');
            Url::redirect('/admin/mitglieder/neu');
        }

        $mime = (string) (mime_content_type((string) $file['tmp_name']) ?: '');

        $erlaubt = [
            'application/pdf' => 'pdf',
            'image/jpeg'      => 'bild',
            'image/png'       => 'bild',
            'image/webp'      => 'bild',
            'image/gif'       => 'bild',
        ];

        if (!isset($erlaubt[$mime])) {
            Flash::error('Nur Bilder (JPG, PNG, WebP, GIF) oder PDF werden unterstützt.');
            Url::redirect('/admin/mitglieder/neu');
        }

        $daten = base64_encode((string) file_get_contents((string) $file['tmp_name']));

        $ergebnis = $this->extractFormFields($apiKey, $mime, $erlaubt[$mime], $daten);

        if (isset($ergebnis['fehler'])) {
            Flash::error('Formular konnte nicht ausgelesen werden: ' . $ergebnis['fehler']);
            Url::redirect('/admin/mitglieder/neu');
        }

        Flash::withInput($ergebnis['felder']);
        Flash::info('Formular ausgelesen – bitte alle Felder kontrollieren und dann speichern.');
        Url::redirect('/admin/mitglieder/neu');
    }

    /**
     * Ruft die Anthropic Messages API (Claude Sonnet) auf und liefert die
     * normalisierten Formularfelder.
     *
     * @return array{felder?:array<string,string>,fehler?:string}
     */
    private function extractFormFields(string $apiKey, string $mime, string $art, string $base64): array
    {
        $quelle = ['type' => 'base64', 'media_type' => $mime, 'data' => $base64];

        $block = $art === 'pdf'
            ? ['type' => 'document', 'source' => $quelle]
            : ['type' => 'image', 'source' => $quelle];

        // Nullable Strings als JSON-Schema-Typ.
        $s = ['type' => ['string', 'null']];

        $schema = [
            'type'       => 'object',
            'properties' => [
                'first_name' => $s,
                'last_name'  => $s,
                'birthdate'  => $s,
                'gender'     => ['type' => ['string', 'null'], 'enum' => ['m', 'w', 'd', null]],
                'street'     => $s,
                'zip'        => $s,
                'city'       => $s,
                'email'      => $s,
                'phone'      => $s,
                'joined_on'  => $s,
                'notes'      => $s,
            ],
            'required' => [
                'first_name', 'last_name', 'birthdate', 'gender', 'street',
                'zip', 'city', 'email', 'phone', 'joined_on', 'notes',
            ],
            'additionalProperties' => false,
        ];

        $anweisung = 'Du liest ein (meist handschriftlich) ausgefülltes Beitrittsformular '
            . 'eines österreichischen Sportvereins aus. Extrahiere die Mitgliedsdaten. '
            . 'Datumsangaben immer als YYYY-MM-DD (österreichisches Format TT.MM.JJJJ umrechnen). '
            . 'Telefonnummern unverändert übernehmen. gender: m=männlich, w=weiblich, d=divers, '
            . 'sonst null. joined_on ist das Beitritts-/Unterschriftsdatum, falls vorhanden. '
            . 'In notes nur eintragen, was sonst nirgends passt (z. B. Erziehungsberechtigte '
            . 'mit Name und Kontakt). Nicht lesbare oder fehlende Felder als null.';

        $body = json_encode([
            'model'         => 'claude-sonnet-5',
            'max_tokens'    => 1500,
            'system'        => $anweisung,
            'output_config' => ['format' => ['type' => 'json_schema', 'schema' => $schema]],
            'messages'      => [[
                'role'    => 'user',
                'content' => [
                    $block,
                    ['type' => 'text', 'text' => 'Bitte die Felder dieses Mitgliedsformulars extrahieren.'],
                ],
            ]],
        ], JSON_UNESCAPED_UNICODE);

        $headers = [
            'Content-Type: application/json',
            'x-api-key: ' . $apiKey,
            'anthropic-version: 2023-06-01',
        ];

        if (function_exists('curl_init')) {
            $ch = curl_init('https://api.anthropic.com/v1/messages');
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $body,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT        => 120,
                CURLOPT_HTTPHEADER     => $headers,
            ]);

            $antwort = curl_exec($ch);
            $status  = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $curlErr = curl_error($ch);

            if ($antwort === false) {
                return ['fehler' => 'Verbindung zur Claude API fehlgeschlagen (' . $curlErr . ').'];
            }
        } else {
            // Fallback ohne curl-Erweiterung.
            $kontext = stream_context_create(['http' => [
                'method'        => 'POST',
                'header'        => implode("\r\n", $headers),
                'content'       => $body,
                'timeout'       => 120,
                'ignore_errors' => true,
            ]]);

            $antwort = @file_get_contents('https://api.anthropic.com/v1/messages', false, $kontext);
            $status  = 0;

            foreach ($http_response_header ?? [] as $zeile) {
                if (preg_match('#^HTTP/\S+\s+(\d{3})#', $zeile, $m) === 1) {
                    $status = (int) $m[1];
                }
            }

            if ($antwort === false) {
                return ['fehler' => 'Verbindung zur Claude API fehlgeschlagen.'];
            }
        }

        /** @var array<string,mixed> $json */
        $json = json_decode((string) $antwort, true) ?? [];

        if ($status !== 200) {
            $meldung = (string) ($json['error']['message'] ?? ('HTTP ' . $status));

            if ($status === 401) {
                $meldung = 'API-Schlüssel ungültig – bitte in den Einstellungen prüfen.';
            }

            return ['fehler' => $meldung];
        }

        if (($json['stop_reason'] ?? '') === 'refusal') {
            return ['fehler' => 'Die KI hat die Auswertung abgelehnt.'];
        }

        $text = '';
        foreach ((array) ($json['content'] ?? []) as $teil) {
            if (($teil['type'] ?? '') === 'text') {
                $text = (string) $teil['text'];
                break;
            }
        }

        /** @var array<string,mixed>|null $roh */
        $roh = json_decode($text, true);

        if (!is_array($roh)) {
            return ['fehler' => 'Unerwartete Antwort der KI.'];
        }

        // Normalisieren: null -> '', Daten pruefen, nur bekannte Felder.
        $felder = [];
        foreach (['first_name', 'last_name', 'birthdate', 'gender', 'street', 'zip', 'city', 'email', 'phone', 'joined_on', 'notes'] as $feld) {
            $wert = trim((string) ($roh[$feld] ?? ''));

            if (in_array($feld, ['birthdate', 'joined_on'], true) && $wert !== '') {
                $wert = (string) (parse_date($wert) ?? '');
            }

            if ($feld === 'gender' && !in_array($wert, ['m', 'w', 'd'], true)) {
                $wert = 'unbekannt';
            }

            $felder[$feld] = $wert;
        }

        return ['felder' => $felder];
    }

    /** @param array<string,string> $args */
    public function update(array $args): void
    {
        AuthController::requireRole('superuser', 'sektionsleiter');
        Csrf::verify();

        $id     = (int) ($args['id'] ?? 0);
        $before = $this->findAccessible($id);

        [$data, $errors] = $this->validate();

        if ($errors !== []) {
            Flash::withInput($_POST, $errors);
            Flash::error('Bitte prüfen Sie die markierten Felder.');
            Url::redirect('/admin/mitglieder/' . $id);
        }

        // Sektion und Sektionsbeitrag gehoeren zur Mitgliedschaft, nicht zur
        // Person – beim Bearbeiten bleiben diese Altspalten unberuehrt.
        // Eintritt/Austritt werden dagegen an der Person gepflegt.
        unset($data['section_id'], $data['section_ids'], $data['fee_amount'], $data['fee_category']);

        $data['updated_at'] = gmdate('Y-m-d H:i:s');

        Database::update('members', $id, $data);

        Audit::log('member_updated', 'member', $id, Audit::diff($before, $data));
        Flash::success('Änderungen gespeichert.');

        // Wiedereintritt: war inaktiv/ausgetreten und ist jetzt wieder aktiv ->
        // Einschreibegebuehr erneut abfragen (sie faellt auch bei Ehemaligen an).
        $wiedereintritt = (string) $before['status'] === 'inaktiv'
            && (string) $data['status'] === 'aktiv';

        Url::redirect('/admin/mitglieder/' . $id . ($wiedereintritt ? '?einschreiben=1' : ''));
    }

    /**
     * Prueft und normalisiert die Formulareingaben.
     *
     * @return array{0:array<string,mixed>,1:array<string,string>}
     */
    private function validate(bool $isNew = false): array
    {
        $errors = [];

        // Sektionen werden nur beim Anlegen abgefragt (Mehrfachauswahl möglich);
        // danach laufen die Zuordnungen ueber die Mitgliedschaften im Formular.
        /** @var list<int> $sectionIds */
        $sectionIds = array_values(array_unique(array_filter(
            array_map('intval', (array) ($_POST['section_ids'] ?? []))
        )));

        if ($isNew) {
            if ($sectionIds === []) {
                $errors['section_ids'] = 'Bitte mindestens eine Sektion wählen.';
            }

            foreach ($sectionIds as $sectionId) {
                if (SectionRepo::find($sectionId) === null) {
                    $errors['section_ids'] = 'Bitte gültige Sektionen wählen.';
                } elseif (!Auth::canAccessSection($sectionId)) {
                    $errors['section_ids'] = 'Für mindestens eine gewählte Sektion fehlt Ihnen die Berechtigung.';
                }
            }
        }

        $firstName = post('first_name');
        $lastName  = post('last_name');

        if ($firstName === '') {
            $errors['first_name'] = 'Vorname ist erforderlich.';
        }

        if ($lastName === '') {
            $errors['last_name'] = 'Zuname ist erforderlich.';
        }

        $birthdate = post('birthdate') === '' ? null : parse_date(post('birthdate'));

        if (post('birthdate') !== '' && $birthdate === null) {
            $errors['birthdate'] = 'Geburtsdatum bitte als TT.MM.JJJJ angeben.';
        } elseif ($birthdate !== null && $birthdate > date('Y-m-d')) {
            $errors['birthdate'] = 'Das Geburtsdatum liegt in der Zukunft.';
        }

        $gender = post('gender', 'unbekannt');

        if (!in_array($gender, ['m', 'w', 'd', 'unbekannt'], true)) {
            $gender = 'unbekannt';
        }

        $status = post('status', 'aktiv');

        if (!in_array($status, ['aktiv', 'inaktiv'], true)) {
            $status = 'aktiv';
        }

        $email = post('email');

        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Bitte eine gültige E-Mail-Adresse eingeben.';
        }

        $fee = post_float('fee_amount');

        if ($fee < 0) {
            $errors['fee_amount'] = 'Der Beitrag darf nicht negativ sein.';
        }

        $joinedOn = post('joined_on') === '' ? null : parse_date(post('joined_on'));
        $leftOn   = post('left_on') === '' ? null : parse_date(post('left_on'));

        if ($joinedOn !== null && $leftOn !== null && $leftOn < $joinedOn) {
            $errors['left_on'] = 'Das Austrittsdatum liegt vor dem Eintritt.';
        }

        // Beitragsart (siehe Beitragsverwaltung) – optional
        $feePlanId = post_int('fee_plan_id');

        if ($feePlanId > 0 && FeeRepo::plan($feePlanId) === null) {
            $errors['fee_plan_id'] = 'Diese Beitragsart gibt es nicht.';
        }

        $feeSince = post('fee_since') === '' ? null : parse_date(post('fee_since'));

        if (post('fee_since') !== '' && $feeSince === null) {
            $errors['fee_since'] = 'Beitragspflichtig-ab bitte als TT.MM.JJJJ angeben.';
        }

        // Individuelle Abweichungen von der Beitragsart
        $feeAmountOverride = post('fee_amount_override') === '' ? null : post_float('fee_amount_override');

        if ($feeAmountOverride !== null && $feeAmountOverride < 0) {
            $errors['fee_amount_override'] = 'Der Betrag darf nicht negativ sein.';
        }

        $feeDueDayOverride = post('fee_due_day_override') === '' ? null : post_int('fee_due_day_override');

        if ($feeDueDayOverride !== null && ($feeDueDayOverride < 1 || $feeDueDayOverride > 28)) {
            $errors['fee_due_day_override'] = 'Der Fälligkeitstag muss zwischen 1 und 28 liegen.';
        }

        $data = [
            'section_id'   => $sectionIds[0] ?? 0,
            'section_ids'  => $sectionIds,
            'member_no'    => post('member_no'),
            'first_name'   => $firstName,
            'last_name'    => $lastName,
            'birthdate'    => $birthdate,
            'gender'       => $gender,
            'street'       => post('street'),
            'zip'          => post('zip'),
            'city'         => post('city'),
            'gemeinde'     => post('gemeinde'),
            'country'      => post('country', 'AT') ?: 'AT',
            'email'        => $email,
            'phone'        => post('phone'),
            'fee_amount'   => $fee,
            'fee_category' => post('fee_category'),
            'fee_plan_id'  => $feePlanId > 0 ? $feePlanId : null,
            'fee_since'    => $feeSince,
            'fee_amount_override'  => $feeAmountOverride,
            'fee_due_day_override' => $feeDueDayOverride,
            'status'       => $status,
            'is_trainer'   => post_bool('is_trainer'),
            'joined_on'    => $joinedOn,
            'left_on'      => $leftOn,
            'notes'        => post('notes'),
        ];

        return [$data, $errors];
    }

    // --------------------------------------------------------- Mitgliedschaften --

    /** Legt eine Sektionsmitgliedschaft an oder aendert Beitrag und Status. */
    public function saveMembership(array $args): void
    {
        AuthController::requireRole('superuser', 'sektionsleiter');
        Csrf::verify();

        $id = (int) ($args['id'] ?? 0);
        $this->findAccessible($id);

        $sectionId = post_int('section_id');

        if ($sectionId <= 0 || SectionRepo::find($sectionId) === null) {
            Flash::error('Bitte eine Sektion wählen.');
            Url::redirect('/admin/mitglieder/' . $id);
        }

        if (!Auth::canAccessSection($sectionId)) {
            Flash::error('Für diese Sektion fehlt Ihnen die Berechtigung.');
            Url::redirect('/admin/mitglieder/' . $id);
        }

        $status = in_array(post('status'), ['aktiv', 'inaktiv'], true) ? post('status') : 'aktiv';

        Database::run(
            'INSERT INTO member_sections (member_id, section_id, fee_amount, fee_category, status, joined_on, note)
             VALUES (:m, :s, :f, :k, :st, :j, :n)
             ON CONFLICT(member_id, section_id) DO UPDATE SET
                fee_amount = excluded.fee_amount, fee_category = excluded.fee_category,
                status = excluded.status, joined_on = excluded.joined_on,
                note = excluded.note, updated_at = datetime(\'now\')',
            [
                'm'  => $id,
                's'  => $sectionId,
                'f'  => post_float('fee_amount'),
                'k'  => post('fee_category'),
                'st' => $status,
                'j'  => post('joined_on') === '' ? null : parse_date(post('joined_on')),
                'n'  => post('note'),
            ]
        );

        Audit::log('membership_saved', 'member', $id, 'Sektion ' . $sectionId);
        Flash::success('Mitgliedschaft gespeichert.');
        Url::redirect('/admin/mitglieder/' . $id);
    }

    public function deleteMembership(array $args): void
    {
        AuthController::requireRole('superuser', 'sektionsleiter');
        Csrf::verify();

        $id        = (int) ($args['id'] ?? 0);
        $sectionId = post_int('section_id');

        $this->findAccessible($id);

        if (!Auth::canAccessSection($sectionId)) {
            Flash::error('Für diese Sektion fehlt Ihnen die Berechtigung.');
            Url::redirect('/admin/mitglieder/' . $id);
        }

        Database::run(
            'DELETE FROM member_sections WHERE member_id = ? AND section_id = ?',
            [$id, $sectionId]
        );

        Audit::log('membership_removed', 'member', $id, 'Sektion ' . $sectionId);
        Flash::success('Mitgliedschaft entfernt.');
        Url::redirect('/admin/mitglieder/' . $id);
    }

    // --------------------------------------------------------------- Loeschen --

    /** Sektionsleitung markiert zum Loeschen; Superuser loescht direkt. */
    public function requestDelete(array $args): void
    {
        AuthController::requireRole('superuser', 'sektionsleiter');
        Csrf::verify();

        $id     = (int) ($args['id'] ?? 0);
        $member = $this->findAccessible($id);

        Database::update('members', $id, [
            'delete_requested'    => 1,
            'delete_requested_by' => Auth::id(),
            'delete_requested_at' => gmdate('Y-m-d H:i:s'),
            'delete_reason'       => post('reason'),
            'updated_at'          => gmdate('Y-m-d H:i:s'),
        ]);

        Audit::log('member_delete_requested', 'member', $id, (string) post('reason'));
        Flash::success(sprintf(
            '%s %s wurde zum Löschen vorgemerkt. Ein Superuser entscheidet endgültig.',
            $member['first_name'],
            $member['last_name']
        ));

        Url::redirectRaw(post('return_to') ?: url('/admin/mitglieder'));
    }

    public function cancelDelete(array $args): void
    {
        AuthController::requireRole('superuser', 'sektionsleiter');
        Csrf::verify();

        $id = (int) ($args['id'] ?? 0);
        $this->findAccessible($id);

        Database::update('members', $id, [
            'delete_requested'    => 0,
            'delete_requested_by' => null,
            'delete_requested_at' => null,
            'delete_reason'       => '',
            'updated_at'          => gmdate('Y-m-d H:i:s'),
        ]);

        Audit::log('member_delete_cancelled', 'member', $id);
        Flash::success('Löschvormerkung aufgehoben.');

        Url::redirectRaw(post('return_to') ?: url('/admin/mitglieder'));
    }

    /**
     * Archivieren: das Mitglied wird zum "ehemaligen Mitglied". Die gesamte
     * Historie (Beitraege, Buchungen, Erfolge, Dateien ...) bleibt erhalten;
     * dargestellt wird es nur noch in der Ansicht "Ehemalige Mitglieder".
     */
    public function archive(array $args): void
    {
        AuthController::requireRole('superuser', 'sektionsleiter');
        Csrf::verify();

        $id     = (int) ($args['id'] ?? 0);
        $member = $this->findAccessible($id);

        Database::update('members', $id, [
            'archived_at' => gmdate('Y-m-d H:i:s'),
            'status'      => 'inaktiv',
            'can_login'   => 0,
            'left_on'     => $member['left_on'] ?: date('Y-m-d'),
            'updated_at'  => gmdate('Y-m-d H:i:s'),
        ]);

        Audit::log('member_archived', 'member', $id, $member['last_name'] . ', ' . $member['first_name']);
        Flash::success(sprintf(
            '%s %s ist jetzt als ehemaliges Mitglied archiviert – die gesamte Historie bleibt erhalten.',
            $member['first_name'],
            $member['last_name']
        ));

        Url::redirectRaw(post('return_to') ?: url('/admin/mitglieder'));
    }

    /** Archivierung aufheben (Wiedereintritt geht dann ueber Status "aktiv"). */
    public function unarchive(array $args): void
    {
        AuthController::requireRole('superuser', 'sektionsleiter');
        Csrf::verify();

        $id     = (int) ($args['id'] ?? 0);
        $member = $this->findAccessible($id);

        Database::update('members', $id, [
            'archived_at' => null,
            'updated_at'  => gmdate('Y-m-d H:i:s'),
        ]);

        Audit::log('member_unarchived', 'member', $id, $member['last_name'] . ', ' . $member['first_name']);
        Flash::success('Archivierung aufgehoben – das Mitglied ist wieder in der normalen Liste (Status: inaktiv).');
        Url::redirect('/admin/mitglieder/' . $id);
    }

    /** Superuser: in den Papierkorb verschieben (weiterhin wiederherstellbar). */
    public function trash(array $args): void
    {
        AuthController::requireRole('superuser');
        Csrf::verify();

        $id     = (int) ($args['id'] ?? 0);
        $member = $this->findAccessible($id);

        Database::update('members', $id, [
            'deleted_at' => gmdate('Y-m-d H:i:s'),
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ]);

        Audit::log('member_trashed', 'member', $id, $member['last_name'] . ', ' . $member['first_name']);
        Flash::success('Mitglied in den Papierkorb verschoben.');

        Url::redirectRaw(post('return_to') ?: url('/admin/mitglieder'));
    }

    public function restore(array $args): void
    {
        AuthController::requireRole('superuser');
        Csrf::verify();

        $id = (int) ($args['id'] ?? 0);

        Database::update('members', $id, [
            'deleted_at'          => null,
            'delete_requested'    => 0,
            'delete_requested_by' => null,
            'delete_requested_at' => null,
            'delete_reason'       => '',
            'updated_at'          => gmdate('Y-m-d H:i:s'),
        ]);

        Audit::log('member_restored', 'member', $id);
        Flash::success('Mitglied wiederhergestellt.');

        Url::redirectRaw(post('return_to') ?: url('/admin/mitglieder', ['trashed' => '1']));
    }

    /** Superuser: endgueltig loeschen. */
    public function destroy(array $args): void
    {
        AuthController::requireRole('superuser');
        Csrf::verify();

        $id     = (int) ($args['id'] ?? 0);
        $member = MemberRepo::find($id);

        if ($member === null) {
            Flash::error('Mitglied nicht gefunden.');
            Url::redirect('/admin/mitglieder');
        }

        Database::run('DELETE FROM members WHERE id = ?', [$id]);

        Audit::log(
            'member_deleted',
            'member',
            $id,
            sprintf('%s, %s (%s)', $member['last_name'], $member['first_name'], $member['section_name'])
        );
        Flash::success('Mitglied endgültig gelöscht.');

        Url::redirectRaw(post('return_to') ?: url('/admin/mitglieder', ['trashed' => '1']));
    }

    // ------------------------------------------------------------- Beitraege --

    /**
     * Legt eine manuelle Beitragszeile in der Historie an (zusätzlich zu den
     * automatisch erzeugten Perioden der Beitragsart) – etwa für Nachzahlungen
     * oder Sonderbeiträge.
     */
    public function saveFee(array $args): void
    {
        AuthController::requireLogin();

        if (!Auth::canManageFees()) {
            AuthController::requireRole('superuser');
        }

        Csrf::verify();

        $id = (int) ($args['id'] ?? 0);
        $this->findAccessible($id);

        $dueDate = parse_date(post('due_date'));

        if ($dueDate === null) {
            Flash::error('Bitte ein gültiges Fälligkeitsdatum angeben.');
            Url::redirect('/admin/mitglieder/' . $id);
        }

        $period = substr($dueDate, 0, 7);
        $label  = post('label') !== '' ? post('label') : FeeRepo::periodLabel(new \DateTimeImmutable($dueDate), 1);
        $paid   = post_bool('paid');
        $paidOn = $paid === 1 ? (parse_date(post('paid_on')) ?? date('Y-m-d')) : null;
        $amount = post_float('amount');

        $exists = Database::one(
            'SELECT id FROM fee_entries WHERE member_id = ? AND period = ?',
            [$id, $period]
        );

        if ($exists !== null) {
            Flash::error('Für den Monat ' . format_date($dueDate) . ' gibt es bereits eine Beitragszeile.');
            Url::redirect('/admin/mitglieder/' . $id);
        }

        $entryId = Database::insert('fee_entries', [
            'member_id'    => $id,
            'plan_id'      => null,
            'period'       => $period,
            'period_label' => $label,
            'due_date'     => $dueDate,
            'amount'       => $amount,
            'paid'         => $paid,
            'paid_on'      => $paidOn,
            'paid_amount'  => $paid === 1 ? $amount : null,
            'paid_by'      => $paid === 1 ? Auth::id() : null,
            'note'         => post('note'),
        ]);

        if ($paid === 1) {
            \App\Models\LedgerRepo::bookFeeEntry($entryId, Auth::id());
        }

        Audit::log('fee_saved', 'member', $id, $label . ($paid ? ' bezahlt' : ' offen'));
        Flash::success('Beitragszeile „' . $label . '“ gespeichert.');
        Url::redirect('/admin/mitglieder/' . $id);
    }

    public function deleteFee(array $args): void
    {
        AuthController::requireRole('superuser', 'kassier');
        Csrf::verify();

        $id = (int) ($args['id'] ?? 0);
        $this->findAccessible($id);

        $entry = Database::one(
            'SELECT * FROM fee_entries WHERE id = ? AND member_id = ?',
            [post_int('entry_id'), $id]
        );

        if ($entry === null) {
            Flash::error('Beitragszeile nicht gefunden.');
            Url::redirect('/admin/mitglieder/' . $id);
        }

        \App\Models\LedgerRepo::unbookFeeEntry((int) $entry['id']);
        Database::run('DELETE FROM fee_entries WHERE id = ?', [(int) $entry['id']]);

        Audit::log('fee_deleted', 'member', $id, (string) $entry['period_label']);
        Flash::success('Beitragszeile entfernt.');
        Url::redirect('/admin/mitglieder/' . $id);
    }

    // ------------------------------------------------- Erziehungsberechtigte --

    /**
     * Erziehungsberechtigten erfassen: entweder als Verweis auf ein anderes
     * Mitglied (guardian_ref) oder mit direkt eingegebenen Kontaktdaten.
     */
    public function saveGuardian(array $args): void
    {
        AuthController::requireRole('superuser', 'sektionsleiter');
        Csrf::verify();

        $id = (int) ($args['id'] ?? 0);
        $this->findAccessible($id);

        $guardianRef      = trim(post('guardian_ref'));
        $guardianMemberId = null;
        $name             = post('name');

        if ($guardianRef !== '') {
            $guardianMemberId = MemberRepo::resolveRef($guardianRef);

            if ($guardianMemberId === null) {
                Flash::error('Verlinktes Mitglied nicht gefunden. Mitgliedsnummer oder "Vorname Zuname" angeben – oder die Felder für externe Personen verwenden.');
                Url::redirect('/admin/mitglieder/' . $id);
            }

            if ($guardianMemberId === $id) {
                Flash::error('Ein Mitglied kann nicht sein eigener Erziehungsberechtigter sein.');
                Url::redirect('/admin/mitglieder/' . $id);
            }

            $name = ''; // Name kommt aus dem verlinkten Datensatz
        } elseif ($name === '') {
            Flash::error('Bitte entweder ein Mitglied verlinken oder einen Namen eingeben.');
            Url::redirect('/admin/mitglieder/' . $id);
        }

        Database::insert('member_guardians', [
            'member_id'          => $id,
            'guardian_member_id' => $guardianMemberId,
            'name'               => $name,
            'relation'           => post('relation'),
            'phone'              => post('phone'),
            'email'              => post('email'),
            'note'               => post('note'),
        ]);

        Audit::log('guardian_added', 'member', $id, $guardianMemberId !== null ? 'Mitglied #' . $guardianMemberId : $name);
        Flash::success('Erziehungsberechtigte(r) erfasst.');
        Url::redirect('/admin/mitglieder/' . $id);
    }

    public function deleteGuardian(array $args): void
    {
        AuthController::requireRole('superuser', 'sektionsleiter');
        Csrf::verify();

        $id = (int) ($args['id'] ?? 0);
        $this->findAccessible($id);

        Database::run(
            'DELETE FROM member_guardians WHERE id = ? AND member_id = ?',
            [post_int('guardian_id'), $id]
        );

        Audit::log('guardian_removed', 'member', $id);
        Flash::success('Erziehungsberechtigte(r) entfernt.');
        Url::redirect('/admin/mitglieder/' . $id);
    }

    // ------------------------------------------------------------- Aussetzen --

    /** Beitragspause erfassen (Mitglied ist im Zeitraum beitragsfrei). */
    public function savePause(array $args): void
    {
        AuthController::requireLogin();

        if (!Auth::canManageFees()) {
            AuthController::requireRole('superuser');
        }

        Csrf::verify();

        $id = (int) ($args['id'] ?? 0);
        $this->findAccessible($id);

        $von = parse_date(post('pause_from'));
        $bis = post('pause_to') === '' ? null : parse_date(post('pause_to'));

        if ($von === null) {
            Flash::error('Bitte ein gültiges Beginn-Datum angeben.');
            Url::redirect('/admin/mitglieder/' . $id);
        }

        if (post('pause_to') !== '' && $bis === null) {
            Flash::error('Das Ende-Datum ist ungültig.');
            Url::redirect('/admin/mitglieder/' . $id);
        }

        if ($bis !== null && $bis < $von) {
            Flash::error('Das Ende der Pause liegt vor dem Beginn.');
            Url::redirect('/admin/mitglieder/' . $id);
        }

        Database::insert('member_pauses', [
            'member_id'  => $id,
            'pause_from' => $von,
            'pause_to'   => $bis,
            'note'       => post('note'),
            'created_by' => Auth::id(),
        ]);

        Audit::log('member_paused', 'member', $id, $von . ' – ' . ($bis ?? 'offen'));
        Flash::success('Beitragspause erfasst – Fälligkeiten in diesem Zeitraum entfallen.');
        Url::redirect('/admin/mitglieder/' . $id);
    }

    public function deletePause(array $args): void
    {
        AuthController::requireLogin();

        if (!Auth::canManageFees()) {
            AuthController::requireRole('superuser');
        }

        Csrf::verify();

        $id = (int) ($args['id'] ?? 0);
        $this->findAccessible($id);

        Database::run(
            'DELETE FROM member_pauses WHERE id = ? AND member_id = ?',
            [post_int('pause_id'), $id]
        );

        Audit::log('member_pause_removed', 'member', $id);
        Flash::success('Beitragspause entfernt.');
        Url::redirect('/admin/mitglieder/' . $id);
    }

    // --------------------------------------------------------- Erinnerungen --

    /** Erinnerung anlegen (aerztliche Untersuchung, Kampfpassverlaengerung ...). */
    public function saveReminder(array $args): void
    {
        AuthController::requireRole('superuser', 'sektionsleiter');
        Csrf::verify();

        $id = (int) ($args['id'] ?? 0);
        $this->findAccessible($id);

        $titel = post('title');
        $datum = parse_date(post('due_on'));

        if ($titel === '' || $datum === null) {
            Flash::error('Bitte Titel und Fälligkeitsdatum angeben.');
            Url::redirect('/admin/mitglieder/' . $id);
        }

        Database::insert('member_reminders', [
            'member_id' => $id,
            'title'     => $titel,
            'due_on'    => $datum,
            'note'      => post('note'),
        ]);

        Flash::success('Erinnerung gespeichert: ' . $titel . ' (fällig ' . format_date($datum) . ').');
        Url::redirect('/admin/mitglieder/' . $id);
    }

    /** Erinnerung als erledigt markieren bzw. wieder oeffnen. */
    public function toggleReminder(array $args): void
    {
        AuthController::requireRole('superuser', 'sektionsleiter');
        Csrf::verify();

        $id = (int) ($args['id'] ?? 0);
        $this->findAccessible($id);

        Database::run(
            'UPDATE member_reminders SET done = 1 - done WHERE id = ? AND member_id = ?',
            [post_int('reminder_id'), $id]
        );

        Url::redirectRaw((string) (post('return_to') ?: url('/admin/mitglieder/' . $id)));
    }

    public function deleteReminder(array $args): void
    {
        AuthController::requireRole('superuser', 'sektionsleiter');
        Csrf::verify();

        $id = (int) ($args['id'] ?? 0);
        $this->findAccessible($id);

        Database::run(
            'DELETE FROM member_reminders WHERE id = ? AND member_id = ?',
            [post_int('reminder_id'), $id]
        );

        Flash::success('Erinnerung gelöscht.');
        Url::redirect('/admin/mitglieder/' . $id);
    }

    // ------------------------------------------------------ Beitragsaenderung --

    /** Individueller Beitrag ab Stichtag fuer dieses Mitglied. */
    public function saveAmountChange(array $args): void
    {
        AuthController::requireLogin();

        if (!Auth::canManageFees()) {
            AuthController::requireRole('superuser');
        }

        Csrf::verify();

        $id = (int) ($args['id'] ?? 0);
        $this->findAccessible($id);

        $errors = FeeRepo::addAmountChange('member', $id, post('valid_from'), post_float('amount'), post('note'), Auth::id());

        if ($errors !== []) {
            Flash::error(implode(' ', $errors));
            Url::redirect('/admin/mitglieder/' . $id);
        }

        // Rueckwirkend bzw. ab sofort: bereits erzeugte OFFENE Zeilen anpassen.
        $angepasst = FeeRepo::refreshOpenEntries($id, (string) parse_date(post('valid_from')));

        Audit::log('member_amount_change', 'member', $id, format_money(post_float('amount')) . ' ab ' . post('valid_from'));
        Flash::success(
            'Beitragsänderung gespeichert – gilt für alle Fälligkeiten ab ' . format_date(parse_date(post('valid_from'))) . '.'
            . ($angepasst > 0 ? " $angepasst bereits erzeugte offene Beitragszeile(n) wurden angepasst." : '')
        );
        Url::redirect('/admin/mitglieder/' . $id);
    }

    public function deleteAmountChange(array $args): void
    {
        AuthController::requireLogin();

        if (!Auth::canManageFees()) {
            AuthController::requireRole('superuser');
        }

        Csrf::verify();

        $id = (int) ($args['id'] ?? 0);
        $this->findAccessible($id);

        $zeile = Database::one(
            "SELECT valid_from FROM amount_history WHERE id = ? AND entity = 'member' AND entity_id = ?",
            [post_int('history_id'), $id]
        );

        Database::run(
            "DELETE FROM amount_history WHERE id = ? AND entity = 'member' AND entity_id = ?",
            [post_int('history_id'), $id]
        );

        // Offene Zeilen ab dem entfernten Stichtag wieder auf den nun gueltigen Betrag setzen.
        $angepasst = $zeile !== null ? FeeRepo::refreshOpenEntries($id, (string) $zeile['valid_from']) : 0;

        Audit::log('member_amount_change_removed', 'member', $id);
        Flash::success('Beitragsänderung entfernt.' . ($angepasst > 0 ? " $angepasst offene Beitragszeile(n) angepasst." : ''));
        Url::redirect('/admin/mitglieder/' . $id);
    }

    // ---------------------------------------------------------- Mitglieder-Login --

    /**
     * Login-Zugang eines Mitglieds verwalten: Haken setzen/entziehen und
     * Passwort erzeugen bzw. zuruecksetzen (nur Admins).
     */
    public function updateLogin(array $args): void
    {
        AuthController::requireRole('superuser');
        Csrf::verify();

        $id     = (int) ($args['id'] ?? 0);
        $member = $this->findAccessible($id);
        $aktion = post('aktion');

        if ($aktion === 'sperren') {
            Database::update('members', $id, ['can_login' => 0]);
            Audit::log('member_login_disabled', 'member', $id);
            Flash::success('Login-Zugang entzogen.');
            Url::redirect('/admin/mitglieder/' . $id);
        }

        // Freischalten bzw. Passwort neu erzeugen
        $email = trim((string) $member['email']);

        if ($email === '') {
            Flash::error('Für den Login braucht das Mitglied eine E-Mail-Adresse (Anmeldung erfolgt per E-Mail).');
            Url::redirect('/admin/mitglieder/' . $id);
        }

        $doppelt = Database::one(
            "SELECT id, first_name, last_name FROM members
              WHERE email = ? COLLATE NOCASE AND can_login = 1 AND id <> ? AND deleted_at IS NULL",
            [$email, $id]
        );

        if ($doppelt !== null) {
            Flash::error(sprintf(
                'Die E-Mail-Adresse wird bereits von %s %s für den Login verwendet – bitte eine eigene Adresse hinterlegen.',
                $doppelt['first_name'],
                $doppelt['last_name']
            ));
            Url::redirect('/admin/mitglieder/' . $id);
        }

        $passwort = \App\Models\UserRepo::generatePassword(12);

        Database::update('members', $id, [
            'can_login'           => 1,
            'login_password_hash' => password_hash($passwort, PASSWORD_DEFAULT),
        ]);

        Audit::log('member_login_enabled', 'member', $id);
        Flash::success(sprintf(
            'Login freigeschaltet. Zugangsdaten: %s / %s – bitte sicher weitergeben, das Passwort wird nur einmal angezeigt. Ändern kann es das Mitglied unter „Passwort ändern“.',
            $email,
            $passwort
        ));
        Url::redirect('/admin/mitglieder/' . $id);
    }

    // ---------------------------------------------------- Dateien / Profilbild --

    /** Erlaubte Dateitypen fuer Mitglieder-Dokumente. */
    private const FILE_EXTENSIONS = [
        'jpg', 'jpeg', 'png', 'webp', 'gif', 'heic',
        'pdf', 'doc', 'docx', 'xls', 'xlsx', 'odt', 'ods', 'txt', 'csv',
    ];

    private const FILE_MAX_BYTES = 15 * 1024 * 1024;

    /** Ablageort ausserhalb des Document-Roots. */
    private function fileDir(int $memberId): string
    {
        return BASE_ROOT . '/data/mitglieder/' . $memberId;
    }

    /** Datei oder Profilbild hochladen. */
    public function uploadFile(array $args): void
    {
        AuthController::requireRole('superuser', 'sektionsleiter');
        Csrf::verify();

        $id = (int) ($args['id'] ?? 0);
        $this->findAccessible($id);

        $file = $_FILES['file'] ?? null;

        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            Flash::error('Bitte eine Datei auswählen.');
            Url::redirect('/admin/mitglieder/' . $id);
        }

        if ((int) $file['error'] !== UPLOAD_ERR_OK) {
            Flash::error('Upload fehlgeschlagen (Fehler ' . (int) $file['error'] . ') – ist die Datei zu groß?');
            Url::redirect('/admin/mitglieder/' . $id);
        }

        if ((int) $file['size'] > self::FILE_MAX_BYTES) {
            Flash::error('Die Datei ist größer als 15 MB.');
            Url::redirect('/admin/mitglieder/' . $id);
        }

        $original  = (string) $file['name'];
        $extension = strtolower((string) pathinfo($original, PATHINFO_EXTENSION));

        if (!in_array($extension, self::FILE_EXTENSIONS, true)) {
            Flash::error('Dateityp .' . $extension . ' ist nicht erlaubt (Bilder, PDF und Office-Dokumente).');
            Url::redirect('/admin/mitglieder/' . $id);
        }

        $mime    = $this->detectMime((string) $file['tmp_name'], $extension);
        $isPhoto = post_bool('is_photo') === 1;
        $isImage = str_starts_with($mime, 'image/');

        if ($isPhoto && !$isImage) {
            Flash::error('Als Profilbild sind nur Bilddateien möglich.');
            Url::redirect('/admin/mitglieder/' . $id);
        }

        $dir = $this->fileDir($id);

        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            Flash::error('Ablageverzeichnis konnte nicht angelegt werden.');
            Url::redirect('/admin/mitglieder/' . $id);
        }

        $storedName = bin2hex(random_bytes(16)) . '.' . $extension;
        $target     = $dir . '/' . $storedName;

        if (!move_uploaded_file((string) $file['tmp_name'], $target)) {
            Flash::error('Die Datei konnte nicht gespeichert werden.');
            Url::redirect('/admin/mitglieder/' . $id);
        }

        // Profilbilder platzsparend verkleinern (sofern GD verfuegbar).
        if ($isPhoto && function_exists('imagecreatefromstring')) {
            $this->shrinkPhoto($target, 800);
            $mime = $this->detectMime($target, $extension);
        }

        if ($isPhoto) {
            // Nur ein Profilbild aktiv – bisherige bleiben als normale Dateien.
            Database::run('UPDATE member_files SET is_photo = 0 WHERE member_id = ?', [$id]);
        }

        Database::insert('member_files', [
            'member_id'   => $id,
            'filename'    => $original,
            'stored_name' => $storedName,
            'mime'        => $mime,
            'size'        => (int) filesize($target),
            'tag'         => $isPhoto ? 'Profilbild' : post('tag'),
            'description' => post('description'),
            'is_photo'    => $isPhoto ? 1 : 0,
            'uploaded_by' => Auth::id(),
        ]);

        // Optional gleich eine Erinnerung setzen (z. B. Ablauf der aerztlichen
        // Untersuchung) – sie erscheint wie alle Erinnerungen auf der Uebersicht.
        $erinnerungAm = post('reminder_on') === '' ? null : parse_date(post('reminder_on'));

        if ($erinnerungAm !== null && !$isPhoto) {
            $titel = post('reminder_title');

            if ($titel === '') {
                $basis = post('tag') !== '' ? post('tag') : (post('description') !== '' ? post('description') : $original);
                $titel = $basis . ' läuft ab';
            }

            Database::insert('member_reminders', [
                'member_id' => $id,
                'title'     => $titel,
                'due_on'    => $erinnerungAm,
                'note'      => 'Dokument: ' . $original,
            ]);
        }

        Audit::log('member_file_uploaded', 'member', $id, $original . ($isPhoto ? ' (Profilbild)' : ''));
        Flash::success(
            ($isPhoto ? 'Profilbild gespeichert.' : 'Datei „' . $original . '“ hochgeladen.')
            . ($erinnerungAm !== null && !$isPhoto ? ' Erinnerung am ' . format_date($erinnerungAm) . ' gesetzt.' : '')
        );
        Url::redirect('/admin/mitglieder/' . $id);
    }

    /** Tag/Beschreibung einer Datei aendern. */
    public function updateFile(array $args): void
    {
        AuthController::requireRole('superuser', 'sektionsleiter');
        Csrf::verify();

        $id   = (int) ($args['id'] ?? 0);
        $this->findAccessible($id);
        $file = $this->findFile($id, post_int('file_id'));

        Database::update('member_files', (int) $file['id'], [
            'tag'         => post('tag'),
            'description' => post('description'),
        ]);

        Flash::success('Datei-Infos gespeichert.');
        Url::redirect('/admin/mitglieder/' . $id);
    }

    public function deleteFile(array $args): void
    {
        AuthController::requireRole('superuser', 'sektionsleiter');
        Csrf::verify();

        $id   = (int) ($args['id'] ?? 0);
        $this->findAccessible($id);
        $file = $this->findFile($id, post_int('file_id'));

        $path = $this->fileDir($id) . '/' . $file['stored_name'];

        if (is_file($path)) {
            @unlink($path);
        }

        Database::run('DELETE FROM member_files WHERE id = ?', [(int) $file['id']]);

        Audit::log('member_file_deleted', 'member', $id, (string) $file['filename']);
        Flash::success('Datei gelöscht.');
        Url::redirect('/admin/mitglieder/' . $id);
    }

    /**
     * Liefert eine Mitgliederdatei aus – nur angemeldet und nur mit Zugriff
     * auf das Mitglied (Sektionsrechte). Die Dateien liegen ausserhalb des
     * Document-Roots und sind daher nie direkt erreichbar.
     */
    public function serveFile(array $args): void
    {
        AuthController::requireLogin();

        $id   = (int) ($args['id'] ?? 0);
        $this->findAccessible($id);
        $file = Database::one(
            'SELECT * FROM member_files WHERE id = ? AND member_id = ?',
            [(int) ($args['fid'] ?? 0), $id]
        );

        $path = $file === null ? '' : $this->fileDir($id) . '/' . $file['stored_name'];

        if ($file === null || !is_file($path)) {
            http_response_code(404);
            View::display('errors/404-admin', ['title' => 'Datei nicht gefunden'], 'layouts/admin');
            exit;
        }

        $mime   = (string) ($file['mime'] ?: 'application/octet-stream');
        $inline = str_starts_with($mime, 'image/') || $mime === 'application/pdf';

        header('Content-Type: ' . $mime);
        header('Content-Length: ' . (string) filesize($path));
        header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment')
            . '; filename="' . rawurlencode((string) $file['filename']) . '"');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, max-age=300');

        readfile($path);
        exit;
    }

    /** MIME-Typ ermitteln – mit Fallback ueber die Endung, falls fileinfo fehlt. */
    private function detectMime(string $path, string $extension): string
    {
        if (class_exists(\finfo::class)) {
            $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($path);

            if ($mime !== '') {
                return $mime;
            }
        }

        return match ($extension) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png'   => 'image/png',
            'webp'  => 'image/webp',
            'gif'   => 'image/gif',
            'heic'  => 'image/heic',
            'pdf'   => 'application/pdf',
            'doc'   => 'application/msword',
            'docx'  => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xls'   => 'application/vnd.ms-excel',
            'xlsx'  => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'odt'   => 'application/vnd.oasis.opendocument.text',
            'ods'   => 'application/vnd.oasis.opendocument.spreadsheet',
            'txt'   => 'text/plain',
            'csv'   => 'text/csv',
            default => 'application/octet-stream',
        };
    }

    /** Verkleinert ein Bild auf maxKante Pixel und speichert es als JPEG. */
    private function shrinkPhoto(string $path, int $maxEdge): void
    {
        $data = (string) file_get_contents($path);
        $img  = @imagecreatefromstring($data);

        if ($img === false) {
            return;
        }

        if (function_exists('exif_read_data')) {
            $exif = @exif_read_data($path);
            $o    = (int) ($exif['Orientation'] ?? 1);
            if ($o === 3) { $img = imagerotate($img, 180, 0); }
            if ($o === 6) { $img = imagerotate($img, -90, 0); }
            if ($o === 8) { $img = imagerotate($img, 90, 0); }
        }

        $w = imagesx($img);
        $h = imagesy($img);

        if (max($w, $h) > $maxEdge) {
            $scale = $maxEdge / max($w, $h);
            $out   = imagecreatetruecolor((int) round($w * $scale), (int) round($h * $scale));
            imagecopyresampled($out, $img, 0, 0, 0, 0, imagesx($out), imagesy($out), $w, $h);
            $img = $out;
        }

        imagejpeg($img, $path, 85);
    }

    /** @return array<string,mixed> */
    private function findFile(int $memberId, int $fileId): array
    {
        $file = Database::one(
            'SELECT * FROM member_files WHERE id = ? AND member_id = ?',
            [$fileId, $memberId]
        );

        if ($file === null) {
            Flash::error('Datei nicht gefunden.');
            Url::redirect('/admin/mitglieder/' . $memberId);
        }

        return $file;
    }

    // ------------------------------------------------------------- Rechnungen --

    /** Rechnung an ein Mitglied stellen (z. B. Boxhandschuhe). */
    public function saveInvoice(array $args): void
    {
        AuthController::requireLogin();

        if (!Auth::canManageFees()) {
            AuthController::requireRole('superuser');
        }

        Csrf::verify();

        $id = (int) ($args['id'] ?? 0);
        $this->findAccessible($id);

        $text   = post('text');
        $amount = post_float('amount');

        if ($text === '') {
            Flash::error('Bitte angeben, wofür die Rechnung ist.');
            Url::redirect('/admin/mitglieder/' . $id);
        }

        if ($amount <= 0) {
            Flash::error('Bitte einen Betrag größer 0 angeben.');
            Url::redirect('/admin/mitglieder/' . $id);
        }

        Database::insert('member_invoices', [
            'member_id'    => $id,
            'invoice_date' => parse_date(post('invoice_date')) ?? date('Y-m-d'),
            'text'         => $text,
            'category'     => post('category') ?: 'Verkauf',
            'amount'       => $amount,
            'note'         => post('note'),
            'created_by'   => Auth::id(),
        ]);

        Audit::log('invoice_created', 'member', $id, $text . ' ' . format_money($amount));
        Flash::success('Rechnung über ' . format_money($amount) . ' erfasst (offen).');
        Url::redirect('/admin/mitglieder/' . $id);
    }

    /** Rechnung als bezahlt markieren – bucht in die Buchhaltung. */
    public function invoicePaid(array $args): void
    {
        AuthController::requireLogin();

        if (!Auth::canManageFees()) {
            AuthController::requireRole('superuser');
        }

        Csrf::verify();

        $id      = (int) ($args['id'] ?? 0);
        $this->findAccessible($id);
        $invoice = $this->findInvoice($id, post_int('invoice_id'));

        $methodId = post_int('payment_method_id');

        Database::update('member_invoices', (int) $invoice['id'], [
            'paid'              => 1,
            'paid_on'           => parse_date(post('paid_on')) ?? date('Y-m-d'),
            'payment_method_id' => $methodId > 0 ? $methodId : LedgerRepo::defaultMethodId('bar'),
        ]);

        LedgerRepo::bookInvoice((int) $invoice['id'], Auth::id());

        Audit::log('invoice_paid', 'member', $id, (string) $invoice['text']);
        Flash::success('Rechnung „' . $invoice['text'] . '“ als bezahlt markiert und gebucht.');
        Url::redirect('/admin/mitglieder/' . $id);
    }

    /** Zahlung einer Rechnung wieder oeffnen – entfernt die Buchung. */
    public function invoiceOpen(array $args): void
    {
        AuthController::requireLogin();

        if (!Auth::canManageFees()) {
            AuthController::requireRole('superuser');
        }

        Csrf::verify();

        $id      = (int) ($args['id'] ?? 0);
        $this->findAccessible($id);
        $invoice = $this->findInvoice($id, post_int('invoice_id'));

        Database::update('member_invoices', (int) $invoice['id'], [
            'paid'              => 0,
            'paid_on'           => null,
            'payment_method_id' => null,
        ]);

        LedgerRepo::unbookInvoice((int) $invoice['id']);

        Audit::log('invoice_reopened', 'member', $id, (string) $invoice['text']);
        Flash::success('Rechnung wieder auf offen gesetzt – die Buchung wurde entfernt.');
        Url::redirect('/admin/mitglieder/' . $id);
    }

    public function deleteInvoice(array $args): void
    {
        AuthController::requireLogin();

        if (!Auth::canManageFees()) {
            AuthController::requireRole('superuser');
        }

        Csrf::verify();

        $id      = (int) ($args['id'] ?? 0);
        $this->findAccessible($id);
        $invoice = $this->findInvoice($id, post_int('invoice_id'));

        LedgerRepo::unbookInvoice((int) $invoice['id']);
        Database::run('DELETE FROM member_invoices WHERE id = ?', [(int) $invoice['id']]);

        Audit::log('invoice_deleted', 'member', $id, (string) $invoice['text']);
        Flash::success('Rechnung gelöscht' . ((int) $invoice['paid'] === 1 ? ' – die Buchung wurde entfernt.' : '.'));
        Url::redirect('/admin/mitglieder/' . $id);
    }

    /** @return array<string,mixed> */
    private function findInvoice(int $memberId, int $invoiceId): array
    {
        $invoice = Database::one(
            'SELECT * FROM member_invoices WHERE id = ? AND member_id = ?',
            [$invoiceId, $memberId]
        );

        if ($invoice === null) {
            Flash::error('Rechnung nicht gefunden.');
            Url::redirect('/admin/mitglieder/' . $memberId);
        }

        return $invoice;
    }

    // -------------------------------------------------------- Einschreibegebuehr --

    /** Bucht die Einschreibegebuehr in die Buchhaltung. */
    public function enrollmentFee(array $args): void
    {
        AuthController::requireLogin();

        if (!Auth::canManageFees()) {
            AuthController::requireRole('superuser');
        }

        Csrf::verify();

        $id     = (int) ($args['id'] ?? 0);
        $member = $this->findAccessible($id);

        $amount = post_float('amount');

        if ($amount <= 0) {
            Flash::error('Bitte einen Betrag größer 0 angeben.');
            Url::redirect('/admin/mitglieder/' . $id . '?einschreiben=1');
        }

        $methodId = post_int('payment_method_id');

        LedgerRepo::add([
            'booked_on'  => parse_date(post('booked_on')) ?? date('Y-m-d'),
            'type'       => 'einnahme',
            'category'   => LedgerRepo::CAT_ENROLLMENT,
            'text'       => 'Einschreibegebühr ' . $member['first_name'] . ' ' . $member['last_name']
                . (post('note') !== '' ? ' – ' . post('note') : ''),
            'amount'     => $amount,
            'member_id'  => $id,
            'payment_method_id' => $methodId > 0 ? $methodId : LedgerRepo::defaultMethodId('bar'),
            'created_by' => Auth::id(),
        ]);

        Audit::log('enrollment_fee', 'member', $id, format_money($amount));
        Flash::success('Einschreibegebühr über ' . format_money($amount) . ' gebucht.');
        Url::redirect('/admin/mitglieder/' . $id);
    }

    // ----------------------------------------------------------- Sammelaktion --

    public function bulk(): void
    {
        AuthController::requireLogin();
        Csrf::verify();

        /** @var list<int> $ids */
        $ids = array_values(array_filter(array_map(
            'intval',
            (array) ($_POST['ids'] ?? [])
        )));

        $action   = post('action');
        $returnTo = post('return_to') ?: url('/admin/mitglieder');

        if ($ids === []) {
            Flash::error('Es wurde kein Mitglied ausgewählt.');
            Url::redirectRaw($returnTo);
        }

        // Nur Datensaetze verarbeiten, auf die der Benutzer wirklich Zugriff hat.
        $allowed = [];
        foreach ($ids as $id) {
            $member = MemberRepo::find($id);

            if ($member !== null && Auth::canAccessMember($member)) {
                $allowed[] = $id;
            }
        }

        if ($allowed === []) {
            Flash::error('Für die Auswahl fehlt Ihnen die Berechtigung.');
            Url::redirectRaw($returnTo);
        }

        $in  = implode(',', array_fill(0, count($allowed), '?'));
        $now = gmdate('Y-m-d H:i:s');

        switch ($action) {
            case 'aktiv':
            case 'inaktiv':
                AuthController::requireRole('superuser', 'sektionsleiter');
                Database::run(
                    "UPDATE members SET status = ?, updated_at = ? WHERE id IN ($in)",
                    array_merge([$action, $now], $allowed)
                );
                Flash::success(count($allowed) . ' Mitglied(er) auf "' . $action . '" gesetzt.');
                break;

            case 'delete_request':
                AuthController::requireRole('superuser', 'sektionsleiter');
                Database::run(
                    "UPDATE members
                        SET delete_requested = 1, delete_requested_by = ?, delete_requested_at = ?,
                            delete_reason = ?, updated_at = ?
                      WHERE id IN ($in)",
                    array_merge([Auth::id(), $now, post('reason'), $now], $allowed)
                );
                Flash::success(count($allowed) . ' Mitglied(er) zum Löschen vorgemerkt.');
                break;

            case 'mark_paid':
                if (!Auth::canManageFees()) {
                    AuthController::requireRole('superuser');
                }

                // Alle bis heute faelligen, offenen Beitragszeilen der Auswahl abhaken.
                FeeRepo::generateDue();

                $updated = 0;

                Database::transaction(static function () use ($allowed, &$updated): void {
                    $userId = Auth::id();

                    foreach ($allowed as $id) {
                        $entries = Database::all(
                            "SELECT id FROM fee_entries
                              WHERE member_id = ? AND paid = 0 AND due_date <= date('now')",
                            [$id]
                        );

                        foreach ($entries as $entry) {
                            FeeRepo::markPaid((int) $entry['id'], null, null, $userId);
                            $updated++;
                        }
                    }
                });

                Flash::success($updated . ' fällige Beitragszeile(n) für ' . count($allowed) . ' Mitglied(er) als bezahlt erfasst.');
                break;

            case 'fee_change':
                if (!Auth::canManageFees()) {
                    AuthController::requireRole('superuser');
                }

                if (post('fee_amount') === '') {
                    Flash::error('Bitte den neuen Beitrag (€) für die Beitragsänderung angeben.');
                    Url::redirectRaw($returnTo);
                }

                $betrag   = post_float('fee_amount');
                $stichtag = parse_date(post('fee_valid_from')) ?? date('Y-m-d');

                if ($betrag < 0) {
                    Flash::error('Der Beitrag darf nicht negativ sein.');
                    Url::redirectRaw($returnTo);
                }

                $zeilen = 0;

                Database::transaction(static function () use ($allowed, $betrag, $stichtag, &$zeilen): void {
                    foreach ($allowed as $memberId) {
                        FeeRepo::addAmountChange('member', $memberId, $stichtag, $betrag, post('reason'), Auth::id());
                        $zeilen += FeeRepo::refreshOpenEntries($memberId, $stichtag);
                    }
                });

                Flash::success(sprintf(
                    'Beitragsänderung auf %s ab %s für %d Mitglied(er) erfasst.%s',
                    format_money($betrag),
                    format_date($stichtag),
                    count($allowed),
                    $zeilen > 0 ? " $zeilen bereits erzeugte offene Beitragszeile(n) wurden angepasst." : ''
                ));
                break;

            case 'archive':
                AuthController::requireRole('superuser', 'sektionsleiter');
                // Wie beim Einzel-Archivieren: Historie bleibt vollstaendig
                // erhalten, Austritt wird gesetzt, falls noch keiner erfasst ist.
                Database::run(
                    "UPDATE members
                        SET archived_at = ?, status = 'inaktiv', can_login = 0,
                            left_on = CASE WHEN left_on IS NULL OR left_on = '' THEN date('now') ELSE left_on END,
                            updated_at = ?
                      WHERE id IN ($in) AND archived_at IS NULL",
                    array_merge([$now, $now], $allowed)
                );
                Flash::success(count($allowed) . ' Mitglied(er) als ehemalige Mitglieder archiviert – die Historie bleibt erhalten.');
                break;

            case 'trash':
                AuthController::requireRole('superuser');
                Database::run(
                    "UPDATE members SET deleted_at = ?, updated_at = ? WHERE id IN ($in)",
                    array_merge([$now, $now], $allowed)
                );
                Flash::success(count($allowed) . ' Mitglied(er) in den Papierkorb verschoben.');
                break;

            default:
                Flash::error('Unbekannte Aktion.');
                Url::redirectRaw($returnTo);
        }

        Audit::log('member_bulk', 'member', null, $action . ': ' . implode(',', $allowed));
        Url::redirectRaw($returnTo);
    }

    // ------------------------------------------------------------- CSV-Export --

    public function export(): void
    {
        AuthController::requireLogin();

        $rows = MemberRepo::searchAll($this->filtersFromQuery(), Auth::allowedSectionIds());

        Audit::log('member_export', 'member', null, count($rows) . ' Datensätze');

        $filename = 'mitglieder-' . date('Y-m-d') . '.csv';

        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');

        $out = fopen('php://output', 'wb');

        if ($out === false) {
            return;
        }

        // BOM, damit Excel unter Windows UTF-8 korrekt erkennt.
        fwrite($out, "\xEF\xBB\xBF");

        $header = [
            'Mitgliedsnummer', 'Vorname', 'Zuname', 'Geburtsdatum', 'Geschlecht',
            'Strasse', 'PLZ', 'Ort', 'Gemeinde', 'Land', 'E-Mail', 'Telefon',
            'Sektion', 'Beitrag', 'Beitragskategorie', 'Status', 'Eintritt', 'Austritt',
            'Loeschvormerkung', 'Notizen',
        ];

        fputcsv($out, $header, ';');

        foreach ($rows as $row) {
            fputcsv($out, [
                $row['member_no'],
                $row['first_name'],
                $row['last_name'],
                format_date($row['birthdate'] === null ? null : (string) $row['birthdate']),
                $this->genderLabel((string) $row['gender']),
                $row['street'],
                $row['zip'],
                $row['city'],
                $row['gemeinde'],
                $row['country'],
                $row['email'],
                $row['phone'],
                $row['section_name'],
                number_format((float) $row['fee_amount'], 2, ',', ''),
                $row['fee_category'],
                $row['status'],
                format_date($row['joined_on'] === null ? null : (string) $row['joined_on']),
                format_date($row['left_on'] === null ? null : (string) $row['left_on']),
                (int) $row['delete_requested'] === 1 ? 'ja' : '',
                str_replace(["\r", "\n"], ' ', (string) $row['notes']),
            ], ';');
        }

        fclose($out);
        exit;
    }

    // ----------------------------------------------------------- Excel-Export --

    /**
     * Vollstaendige Mitgliederliste als Excel-Arbeitsmappe – ein Blatt je Sektion,
     * aufgebaut wie die bisherigen Sektionslisten.
     * Sektionsleitungen erhalten nur ihre eigenen Sektionen.
     */
    public function exportXlsx(): void
    {
        AuthController::requireLogin();

        $allowed  = Auth::allowedSectionIds();
        $sections = SectionRepo::forUser($allowed);
        $xlsx     = new XlsxWriter();

        $kopf = [
            'Nachname', 'Vorname(n)', 'Geburtsdatum', 'Alter', 'Geschlecht',
            'Strasse', 'PLZ', 'Ort', 'Wohnsitzgemeinde', 'Beitrag',
            'Status', 'Mitgliedsnummer', 'E-Mail', 'Telefon', 'Eintritt',
        ];

        $breiten = [18, 16, 13, 6, 10, 26, 7, 20, 26, 9, 9, 15, 28, 16, 12];

        $gesamt      = [];
        $summeAktiv  = 0;
        $summeBeitrag = 0.0;

        foreach ($sections as $section) {
            $rows = MemberRepo::searchAll(
                ['section_id' => (int) $section['id']],
                $allowed
            );

            $blatt = [$kopf];

            foreach ($rows as $m) {
                $zeile = $this->exportRow($m);
                $blatt[] = $zeile;

                // Fuer das Sammelblatt zusaetzlich die Sektion vermerken
                $gesamt[] = array_merge([$section['name']], $zeile);

                if ((string) $m['status'] === 'aktiv') {
                    $summeAktiv++;
                    $summeBeitrag += (float) $m['fee_amount'];
                }
            }

            $xlsx->addSheet((string) $section['name'], $blatt, $breiten);
        }

        // Sammelblatt nur, wenn mehrere Sektionen sichtbar sind
        if (count($sections) > 1) {
            usort($gesamt, static function (array $a, array $b): int {
                return [$a[0], $a[1], $a[2]] <=> [$b[0], $b[1], $b[2]];
            });

            $xlsx->addSheet(
                'Alle Sektionen',
                array_merge([array_merge(['Sektion'], $kopf)], $gesamt),
                array_merge([20], $breiten)
            );
        }

        Audit::log('member_export_xlsx', 'member', null, count($gesamt) . ' Zeilen, ' . count($sections) . ' Sektionen');

        unset($summeAktiv, $summeBeitrag);

        $xlsx->download('mitglieder-' . date('Y-m-d') . '.xlsx');
    }

    /**
     * Eine Mitgliedszeile im Format der Sektionslisten.
     *
     * @param array<string,mixed> $m
     * @return list<mixed>
     */
    private function exportRow(array $m): array
    {
        $geburt = null;

        if (($m['birthdate'] ?? null) !== null && (string) $m['birthdate'] !== '') {
            try {
                $geburt = new \DateTimeImmutable((string) $m['birthdate']);
            } catch (\Exception) {
                $geburt = null;
            }
        }

        $alter = age_from($m['birthdate'] === null ? null : (string) $m['birthdate']);

        return [
            (string) $m['last_name'],
            (string) $m['first_name'],
            $geburt,
            $alter ?? '',
            $this->genderLabel((string) $m['gender']),
            trim((string) $m['street']),
            (string) $m['zip'],
            (string) $m['city'],
            (string) $m['gemeinde'],
            (float) $m['fee_amount'],
            (string) $m['status'],
            (string) $m['member_no'],
            (string) $m['email'],
            (string) $m['phone'],
            ($m['joined_on'] ?? null) !== null && (string) $m['joined_on'] !== ''
                ? new \DateTimeImmutable((string) $m['joined_on'])
                : null,
        ];
    }

    // ------------------------------------------------------------------ Hilfen --

    /** @return array<string,mixed> Bricht mit 403/404 ab, wenn kein Zugriff besteht. */
    private function findAccessible(int $id): array
    {
        $member = MemberRepo::find($id);

        if ($member === null) {
            http_response_code(404);
            View::display('errors/404-admin', ['title' => 'Nicht gefunden'], 'layouts/admin');
            exit;
        }

        if (!Auth::canAccessMember($member)) {
            http_response_code(403);
            View::display('errors/403', ['title' => 'Kein Zugriff'], 'layouts/admin');
            exit;
        }

        return $member;
    }

    /** @return array<string,mixed> */
    private function emptyMember(int $sectionId): array
    {
        $section = SectionRepo::find($sectionId);

        return [
            'id'               => 0,
            'section_id'       => $sectionId,
            'member_no'        => '',
            'first_name'       => '',
            'last_name'        => '',
            'birthdate'        => '',
            'gender'           => 'unbekannt',
            'street'           => '',
            'zip'              => '',
            'city'             => '',
            'gemeinde'         => '',
            'country'          => 'AT',
            'email'            => '',
            'phone'            => '',
            'fee_amount'       => (float) ($section['default_fee'] ?? 0),
            'fee_category'     => '',
            'fee_plan_id'      => null,
            'fee_since'        => '',
            'fee_amount_override'  => null,
            'fee_due_day_override' => null,
            'is_trainer'       => 0,
            'archived_at'      => null,
            'can_login'        => 0,
            'login_last_at'    => null,
            'status'           => 'aktiv',
            'joined_on'        => date('Y-m-d'),
            'left_on'          => '',
            'notes'            => '',
            'delete_requested' => 0,
            'delete_reason'    => '',
            'deleted_at'       => null,
            'created_at'       => '',
            'updated_at'       => '',
        ];
    }

    /**
     * Vorschlagsliste für das Gemeindefeld: die freigeschalteten amtlichen
     * Gemeinden plus alle bereits erfassten Schreibweisen.
     *
     * @return list<string>
     */
    private function gemeindeOptions(): array
    {
        $rows = Database::all(
            'SELECT name FROM gemeinden WHERE active = 1 ORDER BY name COLLATE NOCASE'
        );

        $list = array_map(static fn (array $r): string => (string) $r['name'], $rows);

        return array_values(array_unique(array_merge($list, MemberRepo::distinctGemeinden())));
    }

    private function genderLabel(string $gender): string
    {
        return match ($gender) {
            'm' => 'männlich',
            'w' => 'weiblich',
            'd' => 'divers',
            default => '',
        };
    }
}
