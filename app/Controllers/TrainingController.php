<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\Flash;
use App\Core\Url;
use App\Core\View;
use App\Models\MemberRepo;
use App\Models\SectionRepo;

/**
 * Entwicklung je Mitglied (Gewichtsverlauf, Trainingsanwesenheit mit
 * Leistungsbewertung 1-10) inklusive Diagrammen, sowie die
 * Anwesenheits-Schnellerfassung fuer Trainer.
 */
final class TrainingController
{
    // ------------------------------------------------------------ Entwicklung --

    /** Startseite: Mitglied auswaehlen + Leistungstests verwalten. */
    public function index(): void
    {
        AuthController::requireLogin();

        View::display('admin/training/index', [
            'title'      => 'Entwicklung',
            'mitglieder' => Database::all(
                "SELECT id, first_name, last_name, member_no, birthdate
                   FROM members
                  WHERE deleted_at IS NULL AND archived_at IS NULL
                  ORDER BY last_name COLLATE NOCASE, first_name COLLATE NOCASE"
            ),
            'zuletzt'    => Database::all(
                "SELECT m.id, m.first_name, m.last_name, MAX(a.attended_on) AS letztes_training
                   FROM member_attendance a
                   JOIN members m ON m.id = a.member_id
                  WHERE m.deleted_at IS NULL AND m.archived_at IS NULL
                  GROUP BY m.id
                  ORDER BY letztes_training DESC
                  LIMIT 10"
            ),
            'tests'      => Database::all(
                'SELECT t.*, (SELECT COUNT(*) FROM performance_results r WHERE r.test_id = t.id) AS result_count
                   FROM performance_tests t
                  ORDER BY t.active DESC, t.name COLLATE NOCASE'
            ),
            'canEdit'    => Auth::canWrite(),
        ], 'layouts/admin');
    }

    /** Auswahlformular: Mitglied aufloesen und zu seiner Entwicklungsseite. */
    public function openMember(): void
    {
        AuthController::requireLogin();
        Csrf::verify();

        $memberId = MemberRepo::resolveRef(post('member_ref'));

        if ($memberId === null) {
            Flash::error('Mitglied nicht gefunden (Mitgliedsnummer oder "Zuname Vorname").');
            Url::redirect('/admin/entwicklung');
        }

        Url::redirect('/admin/mitglieder/' . $memberId . '/entwicklung');
    }

    /** @param array<string,string> $args */
    public function memberPage(array $args): void
    {
        AuthController::requireLogin();

        $id     = (int) ($args['id'] ?? 0);
        $member = $this->findAccessible($id);

        View::display('admin/training/member', [
            'title'      => 'Entwicklung – ' . $member['first_name'] . ' ' . $member['last_name'],
            'member'     => $member,
            'weights'    => self::weights($id),
            'attendance' => self::attendance($id),
            'perMonth'   => self::attendancePerMonth($id),
            'tests'      => self::activeTests(),
            'results'    => self::resultsForMember($id),
            'canEdit'    => Auth::canWrite(),
        ], 'layouts/admin');
    }

    // --------------------------------------------------------- Leistungstests --

    /** @return list<array<string,mixed>> */
    public static function activeTests(): array
    {
        return Database::all(
            'SELECT * FROM performance_tests WHERE active = 1 ORDER BY name COLLATE NOCASE'
        );
    }

    /**
     * Ergebnisse eines Mitglieds, je Test chronologisch.
     *
     * @return array<int,list<array<string,mixed>>> test_id => Ergebnisse
     */
    public static function resultsForMember(int $memberId): array
    {
        $results = [];

        foreach (Database::all(
            'SELECT r.*, t.name AS test_name, t.unit, t.higher_is_better, t.description AS test_description
               FROM performance_results r
               JOIN performance_tests t ON t.id = r.test_id
              WHERE r.member_id = ?
              ORDER BY r.tested_on, r.id',
            [$memberId]
        ) as $row) {
            $results[(int) $row['test_id']][] = $row;
        }

        return $results;
    }

    public function storeTest(): void
    {
        AuthController::requireRole('superuser', 'sektionsleiter');
        Csrf::verify();

        $name = post('name');

        if ($name === '') {
            Flash::error('Bitte einen Namen für den Test angeben.');
            Url::redirect('/admin/entwicklung');
        }

        Database::insert('performance_tests', [
            'name'             => $name,
            'unit'             => post('unit'),
            'higher_is_better' => post('better') === 'lower' ? 0 : 1,
            'description'      => post('description'),
        ]);

        Flash::success('Leistungstest „' . $name . '“ angelegt.');
        Url::redirect('/admin/entwicklung');
    }

    /** @param array<string,string> $args */
    public function updateTest(array $args): void
    {
        AuthController::requireRole('superuser', 'sektionsleiter');
        Csrf::verify();

        $id   = (int) ($args['id'] ?? 0);
        $test = Database::one('SELECT * FROM performance_tests WHERE id = ?', [$id]);

        if ($test === null) {
            Flash::error('Test nicht gefunden.');
            Url::redirect('/admin/entwicklung');
        }

        if (post('toggle') === '1') {
            Database::update('performance_tests', $id, ['active' => 1 - (int) $test['active']]);
            Flash::success('Test „' . $test['name'] . '“ ' . ((int) $test['active'] === 1 ? 'deaktiviert' : 'aktiviert') . '.');
            Url::redirect('/admin/entwicklung');
        }

        Database::update('performance_tests', $id, [
            'name'             => post('name') ?: (string) $test['name'],
            'unit'             => post('unit'),
            'higher_is_better' => post('better') === 'lower' ? 0 : 1,
            'description'      => post('description'),
        ]);

        Flash::success('Test gespeichert.');
        Url::redirect('/admin/entwicklung');
    }

    /** @param array<string,string> $args */
    public function deleteTest(array $args): void
    {
        AuthController::requireRole('superuser');
        Csrf::verify();

        $id   = (int) ($args['id'] ?? 0);
        $test = Database::one('SELECT * FROM performance_tests WHERE id = ?', [$id]);

        if ($test === null) {
            Url::redirect('/admin/entwicklung');
        }

        $anzahl = (int) Database::value('SELECT COUNT(*) FROM performance_results WHERE test_id = ?', [$id]);

        if ($anzahl > 0) {
            Flash::error('Für „' . $test['name'] . "“ sind $anzahl Ergebnisse erfasst – bitte stattdessen deaktivieren.");
            Url::redirect('/admin/entwicklung');
        }

        Database::run('DELETE FROM performance_tests WHERE id = ?', [$id]);
        Flash::success('Test gelöscht.');
        Url::redirect('/admin/entwicklung');
    }

    /** Testergebnis eines Mitglieds erfassen. */
    public function saveResult(array $args): void
    {
        AuthController::requireRole('superuser', 'sektionsleiter');
        Csrf::verify();

        $id = (int) ($args['id'] ?? 0);
        $this->findAccessible($id);

        $test = Database::one(
            'SELECT * FROM performance_tests WHERE id = ? AND active = 1',
            [post_int('test_id')]
        );

        if ($test === null) {
            Flash::error('Bitte einen Leistungstest wählen.');
            Url::redirect('/admin/mitglieder/' . $id . '/entwicklung');
        }

        $wert = post_float('value', -1);

        if ($wert < 0) {
            Flash::error('Bitte einen gültigen Wert eingeben.');
            Url::redirect('/admin/mitglieder/' . $id . '/entwicklung');
        }

        Database::insert('performance_results', [
            'test_id'    => (int) $test['id'],
            'member_id'  => $id,
            'tested_on'  => parse_date(post('tested_on')) ?? date('Y-m-d'),
            'value'      => $wert,
            'note'       => post('note'),
            'created_by' => Auth::id(),
        ]);

        Flash::success(sprintf(
            '%s: %s %s erfasst.',
            $test['name'],
            $wert == (int) $wert ? (string) (int) $wert : number_format($wert, 2, ',', '.'),
            $test['unit']
        ));
        Url::redirect('/admin/mitglieder/' . $id . '/entwicklung');
    }

    /** @param array<string,string> $args */
    public function deleteResult(array $args): void
    {
        AuthController::requireRole('superuser', 'sektionsleiter');
        Csrf::verify();

        $id = (int) ($args['id'] ?? 0);
        $this->findAccessible($id);

        Database::run(
            'DELETE FROM performance_results WHERE id = ? AND member_id = ?',
            [post_int('result_id'), $id]
        );

        Flash::success('Testergebnis gelöscht.');
        Url::redirect('/admin/mitglieder/' . $id . '/entwicklung');
    }

    /** @return list<array<string,mixed>> chronologisch. */
    public static function weights(int $memberId): array
    {
        return Database::all(
            'SELECT * FROM member_weights WHERE member_id = ? ORDER BY measured_on, measured_time, id',
            [$memberId]
        );
    }

    /** @return list<array<string,mixed>> chronologisch. */
    public static function attendance(int $memberId): array
    {
        return Database::all(
            'SELECT a.*, u.username AS created_by_name
               FROM member_attendance a
               LEFT JOIN users u ON u.id = a.created_by
              WHERE a.member_id = ?
              ORDER BY a.attended_on, a.id',
            [$memberId]
        );
    }

    /**
     * Trainings je Monat der letzten 12 Monate (auch leere Monate).
     *
     * @return list<array{label:string,value:float}>
     */
    public static function attendancePerMonth(int $memberId): array
    {
        $roh = [];

        foreach (Database::all(
            "SELECT strftime('%Y-%m', attended_on) AS monat, COUNT(*) AS n
               FROM member_attendance
              WHERE member_id = ? AND attended_on >= date('now', '-12 months', 'start of month')
              GROUP BY monat",
            [$memberId]
        ) as $row) {
            $roh[(string) $row['monat']] = (int) $row['n'];
        }

        $result = [];

        for ($i = 11; $i >= 0; $i--) {
            $monat    = date('Y-m', (int) strtotime("first day of -$i months"));
            $result[] = [
                'label' => date('m/y', (int) strtotime($monat . '-01')),
                'value' => (float) ($roh[$monat] ?? 0),
            ];
        }

        return $result;
    }

    // ---------------------------------------------------------------- Gewicht --

    /** @param array<string,string> $args */
    public function saveWeight(array $args): void
    {
        AuthController::requireRole('superuser', 'sektionsleiter');
        Csrf::verify();

        $id = (int) ($args['id'] ?? 0);
        $this->findAccessible($id);

        $gewicht = (float) str_replace(',', '.', post('weight'));
        $datum   = parse_date(post('measured_on')) ?? date('Y-m-d');

        if ($gewicht <= 20 || $gewicht > 400) {
            Flash::error('Bitte ein plausibles Gewicht in kg angeben.');
            Url::redirect('/admin/mitglieder/' . $id . '/entwicklung');
        }

        $zeit = trim(post('measured_time'));

        Database::insert('member_weights', [
            'member_id'     => $id,
            'measured_on'   => $datum,
            // Mehrere Messungen pro Tag - die Uhrzeit unterscheidet sie.
            'measured_time' => preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $zeit) === 1 ? $zeit : '',
            'weight'        => $gewicht,
            'note'          => post('note'),
        ]);

        Flash::success('Gewicht erfasst: ' . number_format($gewicht, 1, ',', '.') . ' kg.');
        Url::redirect('/admin/mitglieder/' . $id . '/entwicklung');
    }

    /** @param array<string,string> $args */
    public function deleteWeight(array $args): void
    {
        AuthController::requireRole('superuser', 'sektionsleiter');
        Csrf::verify();

        $id = (int) ($args['id'] ?? 0);
        $this->findAccessible($id);

        Database::run(
            'DELETE FROM member_weights WHERE id = ? AND member_id = ?',
            [post_int('weight_id'), $id]
        );

        Flash::success('Gewichtseintrag gelöscht.');
        Url::redirect('/admin/mitglieder/' . $id . '/entwicklung');
    }

    // ------------------------------------------------------------ Anwesenheit --

    /** Einzelnen Trainingsbesuch erfassen bzw. Bewertung aktualisieren. */
    public function saveAttendance(array $args): void
    {
        AuthController::requireRole('superuser', 'sektionsleiter');
        Csrf::verify();

        $id = (int) ($args['id'] ?? 0);
        $this->findAccessible($id);

        $datum  = parse_date(post('attended_on')) ?? date('Y-m-d');
        $rating = post('rating') === '' ? null : max(1, min(10, post_int('rating')));

        Database::run(
            "INSERT INTO member_attendance (member_id, attended_on, rating, note, created_by)
             VALUES (?, ?, ?, ?, ?)
             ON CONFLICT(member_id, attended_on) DO UPDATE SET
                rating = excluded.rating, note = excluded.note",
            [$id, $datum, $rating, post('note'), Auth::id()]
        );

        Flash::success('Training am ' . format_date($datum) . ' erfasst' . ($rating !== null ? ' (Bewertung ' . $rating . '/10)' : '') . '.');
        Url::redirect('/admin/mitglieder/' . $id . '/entwicklung');
    }

    /** @param array<string,string> $args */
    public function deleteAttendance(array $args): void
    {
        AuthController::requireRole('superuser', 'sektionsleiter');
        Csrf::verify();

        $id = (int) ($args['id'] ?? 0);
        $this->findAccessible($id);

        Database::run(
            'DELETE FROM member_attendance WHERE id = ? AND member_id = ?',
            [post_int('attendance_id'), $id]
        );

        Flash::success('Trainingseintrag gelöscht.');
        Url::redirect('/admin/mitglieder/' . $id . '/entwicklung');
    }

    // --------------------------------------------------- Schnellerfassung --

    /** Anwesenheit fuer viele Mitglieder auf einmal (Trainingstag). */
    public function quickAttendance(): void
    {
        AuthController::requireRole('superuser', 'sektionsleiter', 'kassier');

        $datum     = parse_date(query('datum')) ?? date('Y-m-d');
        $sectionId = (int) query('section_id');
        $allowed   = Auth::allowedSectionIds();

        $filters = ['status' => 'aktiv'];

        if ($sectionId > 0) {
            $filters['section_id'] = $sectionId;
        }

        $mitglieder = MemberRepo::searchAll($filters, $allowed);

        // Wer war an dem Tag schon erfasst?
        $anwesend = [];

        foreach (Database::all(
            'SELECT member_id FROM member_attendance WHERE attended_on = ?',
            [$datum]
        ) as $row) {
            $anwesend[(int) $row['member_id']] = true;
        }

        View::display('admin/training/quick', [
            'title'      => 'Anwesenheit erfassen',
            'datum'      => $datum,
            'sectionId'  => $sectionId,
            'sections'   => SectionRepo::forUser($allowed),
            'mitglieder' => $mitglieder,
            'anwesend'   => $anwesend,
        ], 'layouts/admin');
    }

    public function storeQuickAttendance(): void
    {
        AuthController::requireRole('superuser', 'sektionsleiter');
        Csrf::verify();

        $datum = parse_date(post('datum'));

        if ($datum === null) {
            Flash::error('Bitte ein gültiges Datum angeben.');
            Url::redirect('/admin/anwesenheit');
        }

        /** @var list<int> $ids */
        $ids = array_values(array_filter(array_map('intval', (array) ($_POST['ids'] ?? []))));

        $neu = 0;

        Database::transaction(static function () use ($ids, $datum, &$neu): void {
            foreach ($ids as $memberId) {
                $member = MemberRepo::find($memberId);

                if ($member === null || !Auth::canAccessMember($member)) {
                    continue;
                }

                $neu += Database::run(
                    'INSERT OR IGNORE INTO member_attendance (member_id, attended_on, created_by)
                     VALUES (?, ?, ?)',
                    [$memberId, $datum, Auth::id()]
                )->rowCount();
            }
        });

        Flash::success($neu . ' Trainingsbesuch(e) am ' . format_date($datum) . ' erfasst.');
        Url::redirect('/admin/anwesenheit?datum=' . $datum . '&section_id=' . post_int('section_id'));
    }

    // ------------------------------------------------------------------ Hilfen --

    /** @return array<string,mixed> */
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
}
