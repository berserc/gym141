<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\Flash;
use App\Core\Url;
use App\Core\View;
use App\Models\CalendarRepo;
use App\Models\MemberRepo;

/** Frei definierbare Mitglieder-Gruppen (u. a. fuer die Termin-Sichtbarkeit). */
final class GroupController
{
    public function index(): void
    {
        AuthController::requireLogin();

        $groups = CalendarRepo::groups();

        $mitglieder = [];

        foreach ($groups as $group) {
            $mitglieder[(int) $group['id']] = CalendarRepo::groupMembers((int) $group['id']);
        }

        View::display('admin/gruppen/index', [
            'title'         => 'Gruppen',
            'groups'        => $groups,
            'groupMembers'  => $mitglieder,
            'alleMitglieder' => Database::all(
                "SELECT id, first_name, last_name, member_no FROM members
                  WHERE deleted_at IS NULL
                  ORDER BY last_name COLLATE NOCASE, first_name COLLATE NOCASE"
            ),
            'errors'        => Flash::errors(),
        ], 'layouts/admin');
    }

    public function store(): void
    {
        AuthController::requireRole('superuser', 'sektionsleiter');
        Csrf::verify();

        $name = post('name');

        if ($name === '') {
            Flash::error('Bitte einen Gruppennamen angeben.');
            Url::redirect('/admin/gruppen');
        }

        if (Database::one('SELECT id FROM member_groups WHERE name = ? COLLATE NOCASE', [$name]) !== null) {
            Flash::error('Eine Gruppe mit diesem Namen gibt es bereits.');
            Url::redirect('/admin/gruppen');
        }

        $id = Database::insert('member_groups', ['name' => $name, 'note' => post('note')]);

        Audit::log('group_created', 'group', $id, $name);
        Flash::success('Gruppe „' . $name . '“ angelegt.');
        Url::redirect('/admin/gruppen');
    }

    /** @param array<string,string> $args */
    public function destroy(array $args): void
    {
        AuthController::requireRole('superuser');
        Csrf::verify();

        $id    = (int) ($args['id'] ?? 0);
        $group = Database::one('SELECT * FROM member_groups WHERE id = ?', [$id]);

        if ($group === null) {
            Flash::error('Gruppe nicht gefunden.');
            Url::redirect('/admin/gruppen');
        }

        Database::run('DELETE FROM member_groups WHERE id = ?', [$id]);

        Audit::log('group_deleted', 'group', $id, (string) $group['name']);
        Flash::success('Gruppe gelöscht. Termine, die nur dieser Gruppe zugeordnet waren, sind jetzt für alle sichtbar.');
        Url::redirect('/admin/gruppen');
    }

    /** @param array<string,string> $args */
    public function addMember(array $args): void
    {
        AuthController::requireRole('superuser', 'sektionsleiter');
        Csrf::verify();

        $id  = (int) ($args['id'] ?? 0);
        $ref = trim(post('member_ref'));

        // Auswahlbox liefert "Nachname Vorname" (ggf. mit Zusatz)
        $ref      = preg_replace('/\s*\(Nr\.\s*[^)]*\)\s*$/u', '', $ref) ?? $ref;
        $memberId = MemberRepo::resolveRef($ref);

        if ($memberId === null) {
            Flash::error('Mitglied nicht gefunden – bitte einen Eintrag aus der Auswahlliste übernehmen.');
            Url::redirect('/admin/gruppen');
        }

        Database::run(
            'INSERT OR IGNORE INTO member_group_members (group_id, member_id) VALUES (?, ?)',
            [$id, $memberId]
        );

        Flash::success('Mitglied zur Gruppe hinzugefügt.');
        Url::redirect('/admin/gruppen');
    }

    /** @param array<string,string> $args */
    public function removeMember(array $args): void
    {
        AuthController::requireRole('superuser', 'sektionsleiter');
        Csrf::verify();

        Database::run(
            'DELETE FROM member_group_members WHERE group_id = ? AND member_id = ?',
            [(int) ($args['id'] ?? 0), post_int('member_id')]
        );

        Flash::success('Mitglied aus der Gruppe entfernt.');
        Url::redirect('/admin/gruppen');
    }
}
