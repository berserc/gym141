<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\Flash;
use App\Core\Url;
use App\Core\View;
use App\Models\MemberRepo;

/**
 * Vorstand und Rechnungspruefer.
 *
 * Die Rechnungspruefer sind laut oesterreichischem Vereinsgesetz ein eigenes
 * Organ (nicht Teil des Vorstands) und muessen mindestens zu zweit sein.
 * Abgelaufene Funktionsperioden bleiben als Historie erhalten.
 */
final class BoardController
{
    /** In Oesterreich uebliche Vorstandsfunktionen (Vorschlagsliste). */
    public const FUNCTIONS = [
        'Obmann/Obfrau',
        'Obmann-Stellvertreter/in',
        'Kassier/in',
        'Kassier-Stellvertreter/in',
        'Schriftführer/in',
        'Schriftführer-Stellvertreter/in',
        'Sportlicher Leiter/in',
        'Jugendwart/in',
        'Zeugwart/in',
        'Pressewart/in (Öffentlichkeitsarbeit)',
        'Beirat/Beirätin',
    ];

    public const MIN_AUDITORS = 2;

    public function index(): void
    {
        AuthController::requireLogin();

        $rows = Database::all(
            'SELECT b.*, m.first_name, m.last_name, m.email AS m_email, m.phone AS m_phone
               FROM board_members b
               LEFT JOIN members m ON m.id = b.member_id
              ORDER BY b.sort_order, b.id'
        );

        $heute   = date('Y-m-d');
        $aktiv   = static fn (array $r): bool => ($r['term_to'] ?? null) === null
            || (string) $r['term_to'] === '' || (string) $r['term_to'] >= $heute;

        $vorstand = array_values(array_filter($rows, static fn (array $r): bool => $r['organ'] !== 'pruefer' && $aktiv($r)));
        $pruefer  = array_values(array_filter($rows, static fn (array $r): bool => $r['organ'] === 'pruefer' && $aktiv($r)));
        $historie = array_values(array_filter($rows, static fn (array $r): bool => !$aktiv($r)));

        usort($historie, static fn (array $a, array $b): int => strcmp((string) $b['term_to'], (string) $a['term_to']));

        // Mitgliederliste fuer die Auswahlbox (Autocomplete)
        $mitglieder = Database::all(
            "SELECT id, first_name, last_name, member_no, birthdate
               FROM members
              WHERE deleted_at IS NULL
              ORDER BY last_name COLLATE NOCASE, first_name COLLATE NOCASE"
        );

        View::display('admin/board/index', [
            'title'      => 'Vorstand',
            'vorstand'   => $vorstand,
            'pruefer'    => $pruefer,
            'historie'   => $historie,
            'functions'  => self::FUNCTIONS,
            'minPruefer' => self::MIN_AUDITORS,
            'mitglieder' => $mitglieder,
            'errors'     => Flash::errors(),
        ], 'layouts/admin');
    }

    public function store(): void
    {
        AuthController::requireRole('superuser');
        Csrf::verify();

        $organ    = post('organ') === 'pruefer' ? 'pruefer' : 'vorstand';
        $function = $organ === 'pruefer'
            ? (post('function') !== '' ? post('function') : 'Rechnungsprüfer/in')
            : post('function');

        if ($function === '') {
            Flash::error('Bitte eine Funktion angeben.');
            Url::redirect('/admin/vorstand');
        }

        [$memberId, $name, $fehler] = $this->resolvePerson();

        if ($fehler !== null) {
            Flash::error($fehler);
            Url::redirect('/admin/vorstand');
        }

        $sort = (int) Database::value('SELECT COALESCE(MAX(sort_order), 0) FROM board_members') + 10;

        $id = Database::insert('board_members', [
            'organ'      => $organ,
            'function'   => $function,
            'member_id'  => $memberId,
            'name'       => $name,
            'email'      => post('email'),
            'phone'      => post('phone'),
            'since'      => post('since') === '' ? null : parse_date(post('since')),
            'term_to'    => post('term_to') === '' ? null : parse_date(post('term_to')),
            'note'       => post('note'),
            'sort_order' => $sort,
        ]);

        Audit::log('board_member_added', 'board', $id, $organ . ': ' . $function);
        Flash::success(($organ === 'pruefer' ? 'Rechnungsprüfer/in' : 'Funktion „' . $function . '“') . ' erfasst.');
        Url::redirect('/admin/vorstand');
    }

    /** Bestehenden Eintrag bearbeiten (Funktion, Person, Periode, Notiz). */
    public function update(array $args): void
    {
        AuthController::requireRole('superuser');
        Csrf::verify();

        $id  = (int) ($args['id'] ?? 0);
        $row = $this->find($id);

        [$memberId, $name, $fehler] = $this->resolvePerson();

        if ($fehler !== null) {
            Flash::error($fehler);
            Url::redirect('/admin/vorstand');
        }

        Database::update('board_members', $id, [
            'function'  => post('function') !== '' ? post('function') : (string) $row['function'],
            'member_id' => $memberId,
            'name'      => $name,
            'email'     => post('email'),
            'phone'     => post('phone'),
            'since'     => post('since') === '' ? null : parse_date(post('since')),
            'term_to'   => post('term_to') === '' ? null : parse_date(post('term_to')),
            'note'      => post('note'),
        ]);

        Audit::log('board_member_updated', 'board', $id, (string) $row['function']);
        Flash::success('Eintrag gespeichert.');
        Url::redirect('/admin/vorstand');
    }

    /** Funktionsperiode beenden – der Eintrag wandert in die Historie. */
    public function endTerm(array $args): void
    {
        AuthController::requireRole('superuser');
        Csrf::verify();

        $id  = (int) ($args['id'] ?? 0);
        $row = $this->find($id);

        $ende = parse_date(post('term_to')) ?? date('Y-m-d');

        Database::update('board_members', $id, ['term_to' => $ende]);

        Audit::log('board_term_ended', 'board', $id, $row['function'] . ' bis ' . $ende);
        Flash::success('Funktionsperiode beendet – der Eintrag bleibt in der Historie.');
        Url::redirect('/admin/vorstand');
    }

    /** @param array<string,string> $args */
    public function destroy(array $args): void
    {
        AuthController::requireRole('superuser');
        Csrf::verify();

        $id  = (int) ($args['id'] ?? 0);
        $row = $this->find($id);

        Database::run('DELETE FROM board_members WHERE id = ?', [$id]);

        Audit::log('board_member_removed', 'board', $id, (string) $row['function']);
        Flash::success('Eintrag endgültig gelöscht.');
        Url::redirect('/admin/vorstand');
    }

    // ------------------------------------------------------------------ Hilfen --

    /**
     * Person aus dem Formular: verlinktes Mitglied (Auswahlbox) oder externer Name.
     *
     * @return array{0:?int,1:string,2:?string} [member_id, name, fehler]
     */
    private function resolvePerson(): array
    {
        $memberRef = trim(post('member_ref'));
        $name      = post('name');

        if ($memberRef !== '') {
            // Auswahlbox liefert "Nachname Vorname" oder "Nachname Vorname (Nr. X)"
            $ref = preg_replace('/\s*\(Nr\.\s*[^)]*\)\s*$/u', '', $memberRef) ?? $memberRef;

            $memberId = MemberRepo::resolveRef($ref);

            if ($memberId === null) {
                return [null, '', 'Mitglied "' . $memberRef . '" nicht gefunden – bitte einen Eintrag aus der Auswahlliste übernehmen oder das Namensfeld für externe Personen verwenden.'];
            }

            return [$memberId, '', null];
        }

        if ($name === '') {
            return [null, '', 'Bitte ein Mitglied auswählen oder einen Namen eingeben.'];
        }

        return [null, $name, null];
    }

    /** @return array<string,mixed> */
    private function find(int $id): array
    {
        $row = Database::one('SELECT * FROM board_members WHERE id = ?', [$id]);

        if ($row === null) {
            Flash::error('Eintrag nicht gefunden.');
            Url::redirect('/admin/vorstand');
        }

        return $row;
    }
}
