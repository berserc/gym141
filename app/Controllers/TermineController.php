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
use App\Models\CalendarRepo;

/**
 * Verwaltung des Wettkampf- und Eventkalenders (Login-Bereich der Mitglieder).
 * Eintragen duerfen Superuser, Kassier und Sektionsleitungen.
 */
final class TermineController
{
    public function index(): void
    {
        AuthController::requireLogin();

        $events = CalendarRepo::allEvents(query('alle') !== '1');

        $antworten = [];

        foreach ($events as $event) {
            if ((int) $event['zusagen'] + (int) $event['absagen'] > 0) {
                $antworten[(int) $event['id']] = CalendarRepo::signups((int) $event['id']);
            }
        }

        // Orga-Zaehler (Aufgaben + To-dos) je Termin
        $orga = [];

        foreach (Database::all('SELECT event_id, COUNT(*) AS n FROM event_tasks GROUP BY event_id') as $r) {
            $orga[(int) $r['event_id']]['tasks'] = (int) $r['n'];
        }

        foreach (Database::all('SELECT event_id, COUNT(*) AS n, SUM(done) AS erledigt FROM event_todos GROUP BY event_id') as $r) {
            $orga[(int) $r['event_id']]['todos']    = (int) $r['n'];
            $orga[(int) $r['event_id']]['erledigt'] = (int) $r['erledigt'];
        }

        View::display('admin/termine/index', [
            'title'     => 'Termine',
            'events'    => $events,
            'antworten' => $antworten,
            'orga'      => $orga,
            'groups'    => CalendarRepo::groups(),
            'kinds'     => CalendarRepo::KINDS,
            'recurs'    => CalendarRepo::RECURS,
            'alle'      => query('alle') === '1',
            'errors'    => Flash::errors(),
        ], 'layouts/admin');
    }

    /** iCalendar-Download (gleicher Inhalt wie der API-Feed). */
    public function exportIcs(): void
    {
        AuthController::requireLogin();

        header('Content-Type: text/calendar; charset=UTF-8');
        header('Content-Disposition: attachment; filename="termine.ics"');
        echo CalendarRepo::ics(Database::all('SELECT * FROM calendar_events ORDER BY starts_on, id'));
        exit;
    }

    public function store(): void
    {
        $this->save(0);
    }

    /** @param array<string,string> $args */
    public function update(array $args): void
    {
        $this->save((int) ($args['id'] ?? 0));
    }

    private function save(int $id): void
    {
        AuthController::requireRole('superuser', 'kassier', 'sektionsleiter');
        Csrf::verify();

        $title  = post('title');
        $starts = parse_date(post('starts_on'));

        if ($title === '' || $starts === null) {
            Flash::error('Bitte Titel und ein gültiges Beginn-Datum angeben.');
            Url::redirect('/admin/termine');
        }

        $ends = post('ends_on') === '' ? null : parse_date(post('ends_on'));

        if ($ends !== null && $ends < $starts) {
            Flash::error('Das Ende liegt vor dem Beginn.');
            Url::redirect('/admin/termine');
        }

        $recur = array_key_exists(post('recur'), CalendarRepo::RECURS) ? post('recur') : 'keine';

        $data = [
            'kind'        => post('kind') === 'wettkampf' ? 'wettkampf' : 'event',
            'title'       => $title,
            'starts_on'   => $starts,
            'ends_on'     => $ends,
            'location'    => post('location'),
            'description' => post('description'),
            'rsvp'        => post_bool('rsvp'),
            'recur'       => $recur,
            'recur_until' => $recur === 'keine' || post('recur_until') === ''
                ? null
                : parse_date(post('recur_until')),
        ];

        if ($id > 0) {
            if (Database::one('SELECT id FROM calendar_events WHERE id = ?', [$id]) === null) {
                Flash::error('Termin nicht gefunden.');
                Url::redirect('/admin/termine');
            }

            Database::update('calendar_events', $id, $data);
            Flash::success('Termin gespeichert.');
        } else {
            $data['created_by'] = Auth::id();
            $id = Database::insert('calendar_events', $data);
            Flash::success('Termin „' . $title . '“ eingetragen.');
        }

        // Gruppen-Sichtbarkeit neu setzen (keine Auswahl = fuer alle sichtbar)
        Database::run('DELETE FROM calendar_event_groups WHERE event_id = ?', [$id]);

        foreach ((array) ($_POST['group_ids'] ?? []) as $groupId) {
            Database::run(
                'INSERT OR IGNORE INTO calendar_event_groups (event_id, group_id) VALUES (?, ?)',
                [$id, (int) $groupId]
            );
        }

        Audit::log('calendar_event_saved', 'calendar', $id, $title);
        Url::redirect('/admin/termine');
    }

    // -------------------------------------------------------- Organisation --

    /** Orga-Seite eines Termins: Aufgabenbereiche mit Personen + To-do-Liste. */
    public function orga(array $args): void
    {
        AuthController::requireLogin();

        $event = $this->findEvent((int) ($args['id'] ?? 0));
        $id    = (int) $event['id'];

        $tasks = Database::all(
            'SELECT * FROM event_tasks WHERE event_id = ? ORDER BY id',
            [$id]
        );

        $people = [];

        foreach (Database::all(
            'SELECT p.*, m.first_name, m.last_name
               FROM event_task_people p
               LEFT JOIN members m ON m.id = p.member_id
              WHERE p.task_id IN (SELECT id FROM event_tasks WHERE event_id = ?)
              ORDER BY p.id',
            [$id]
        ) as $person) {
            $people[(int) $person['task_id']][] = $person;
        }

        View::display('admin/termine/orga', [
            'title'      => 'Organisation – ' . $event['title'],
            'event'      => $event,
            'tasks'      => $tasks,
            'people'     => $people,
            'todos'      => Database::all(
                "SELECT t.*, u.username AS done_by_name
                   FROM event_todos t
                   LEFT JOIN users u ON u.id = t.done_by
                  WHERE t.event_id = ?
                  ORDER BY t.done, COALESCE(t.due_on, '9999'), t.id",
                [$id]
            ),
            'mitglieder' => Database::all(
                "SELECT id, first_name, last_name, member_no
                   FROM members
                  WHERE deleted_at IS NULL AND archived_at IS NULL
                  ORDER BY last_name COLLATE NOCASE, first_name COLLATE NOCASE"
            ),
        ], 'layouts/admin');
    }

    /** Aufgabenbereich anlegen bzw. bearbeiten. */
    public function saveTask(array $args): void
    {
        AuthController::requireRole('superuser', 'kassier', 'sektionsleiter');
        Csrf::verify();

        $event  = $this->findEvent((int) ($args['id'] ?? 0));
        $taskId = (int) ($args['tid'] ?? 0);
        $titel  = post('title');

        if ($titel === '') {
            Flash::error('Bitte einen Titel für die Aufgabe angeben.');
            Url::redirect('/admin/termine/' . $event['id'] . '/orga');
        }

        if ($taskId > 0) {
            $this->findTask($taskId, (int) $event['id']);
            Database::update('event_tasks', $taskId, ['title' => $titel, 'note' => post('note')]);
            Flash::success('Aufgabe gespeichert.');
        } else {
            Database::insert('event_tasks', [
                'event_id' => (int) $event['id'],
                'title'    => $titel,
                'note'     => post('note'),
            ]);
            Flash::success('Aufgabe „' . $titel . '“ angelegt.');
        }

        Url::redirect('/admin/termine/' . $event['id'] . '/orga');
    }

    public function deleteTask(array $args): void
    {
        AuthController::requireRole('superuser', 'kassier', 'sektionsleiter');
        Csrf::verify();

        $event = $this->findEvent((int) ($args['id'] ?? 0));
        $this->findTask(post_int('task_id'), (int) $event['id']);

        Database::run('DELETE FROM event_tasks WHERE id = ?', [post_int('task_id')]);

        Flash::success('Aufgabe gelöscht.');
        Url::redirect('/admin/termine/' . $event['id'] . '/orga');
    }

    /** Person (Mitglied oder extern) einer Aufgabe zuteilen. */
    public function addTaskPerson(array $args): void
    {
        AuthController::requireRole('superuser', 'kassier', 'sektionsleiter');
        Csrf::verify();

        $event  = $this->findEvent((int) ($args['id'] ?? 0));
        $taskId = (int) ($args['tid'] ?? 0);
        $this->findTask($taskId, (int) $event['id']);

        $memberRef = trim(post('member_ref'));
        $extern    = post('name');
        $memberId  = null;

        if ($memberRef !== '') {
            $memberId = \App\Models\MemberRepo::resolveRef($memberRef);

            if ($memberId === null) {
                Flash::error('Mitglied nicht gefunden (Mitgliedsnummer oder "Zuname Vorname").');
                Url::redirect('/admin/termine/' . $event['id'] . '/orga');
            }
        } elseif ($extern === '') {
            Flash::error('Bitte ein Mitglied wählen oder einen externen Namen eingeben.');
            Url::redirect('/admin/termine/' . $event['id'] . '/orga');
        }

        Database::insert('event_task_people', [
            'task_id'   => $taskId,
            'member_id' => $memberId,
            'name'      => $memberId === null ? $extern : '',
            'contact'   => post('contact'),
            'note'      => post('person_note'),
        ]);

        Flash::success('Person zugeteilt.');
        Url::redirect('/admin/termine/' . $event['id'] . '/orga');
    }

    public function removeTaskPerson(array $args): void
    {
        AuthController::requireRole('superuser', 'kassier', 'sektionsleiter');
        Csrf::verify();

        $event = $this->findEvent((int) ($args['id'] ?? 0));

        Database::run(
            'DELETE FROM event_task_people
              WHERE id = ? AND task_id IN (SELECT id FROM event_tasks WHERE event_id = ?)',
            [post_int('person_id'), (int) $event['id']]
        );

        Flash::success('Person entfernt.');
        Url::redirect('/admin/termine/' . $event['id'] . '/orga');
    }

    /** To-do anlegen. */
    public function addTodo(array $args): void
    {
        AuthController::requireRole('superuser', 'kassier', 'sektionsleiter');
        Csrf::verify();

        $event = $this->findEvent((int) ($args['id'] ?? 0));
        $titel = post('title');

        if ($titel === '') {
            Flash::error('Bitte einen Text für das To-do angeben.');
            Url::redirect('/admin/termine/' . $event['id'] . '/orga');
        }

        Database::insert('event_todos', [
            'event_id' => (int) $event['id'],
            'title'    => $titel,
            'due_on'   => post('due_on') === '' ? null : parse_date(post('due_on')),
        ]);

        Url::redirect('/admin/termine/' . $event['id'] . '/orga');
    }

    /** To-do abhaken bzw. wieder oeffnen. */
    public function toggleTodo(array $args): void
    {
        AuthController::requireRole('superuser', 'kassier', 'sektionsleiter');
        Csrf::verify();

        $event = $this->findEvent((int) ($args['id'] ?? 0));
        $todo  = Database::one(
            'SELECT * FROM event_todos WHERE id = ? AND event_id = ?',
            [post_int('todo_id'), (int) $event['id']]
        );

        if ($todo !== null) {
            $erledigt = (int) $todo['done'] === 1;

            Database::update('event_todos', (int) $todo['id'], [
                'done'    => $erledigt ? 0 : 1,
                'done_by' => $erledigt ? null : Auth::id(),
                'done_at' => $erledigt ? null : gmdate('Y-m-d H:i:s'),
            ]);
        }

        Url::redirect('/admin/termine/' . $event['id'] . '/orga');
    }

    public function deleteTodo(array $args): void
    {
        AuthController::requireRole('superuser', 'kassier', 'sektionsleiter');
        Csrf::verify();

        $event = $this->findEvent((int) ($args['id'] ?? 0));

        Database::run(
            'DELETE FROM event_todos WHERE id = ? AND event_id = ?',
            [post_int('todo_id'), (int) $event['id']]
        );

        Url::redirect('/admin/termine/' . $event['id'] . '/orga');
    }

    /** @return array<string,mixed> */
    private function findEvent(int $id): array
    {
        $event = Database::one('SELECT * FROM calendar_events WHERE id = ?', [$id]);

        if ($event === null) {
            Flash::error('Termin nicht gefunden.');
            Url::redirect('/admin/termine');
        }

        return $event;
    }

    private function findTask(int $taskId, int $eventId): void
    {
        if (Database::one(
            'SELECT id FROM event_tasks WHERE id = ? AND event_id = ?',
            [$taskId, $eventId]
        ) === null) {
            Flash::error('Aufgabe nicht gefunden.');
            Url::redirect('/admin/termine/' . $eventId . '/orga');
        }
    }

    /** @param array<string,string> $args */
    public function destroy(array $args): void
    {
        AuthController::requireRole('superuser', 'kassier', 'sektionsleiter');
        Csrf::verify();

        $id    = (int) ($args['id'] ?? 0);
        $event = Database::one('SELECT * FROM calendar_events WHERE id = ?', [$id]);

        if ($event === null) {
            Flash::error('Termin nicht gefunden.');
            Url::redirect('/admin/termine');
        }

        Database::run('DELETE FROM calendar_events WHERE id = ?', [$id]);

        Audit::log('calendar_event_deleted', 'calendar', $id, (string) $event['title']);
        Flash::success('Termin gelöscht.');
        Url::redirect('/admin/termine');
    }
}
