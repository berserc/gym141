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
use App\Models\MemberRepo;
use App\Models\SectionRepo;
use App\Models\UserRepo;

final class UserController
{
    /**
     * Mitgliederliste fuer die Auswahlbox (Autocomplete) im Formular.
     *
     * @return list<array<string,mixed>>
     */
    private function memberOptions(): array
    {
        return Database::all(
            'SELECT id, first_name, last_name, member_no, birthdate
               FROM members
              WHERE deleted_at IS NULL
              ORDER BY last_name COLLATE NOCASE, first_name COLLATE NOCASE'
        );
    }

    public function index(): void
    {
        AuthController::requireRole('superuser');

        View::display('admin/users/index', [
            'title' => 'Benutzer',
            'users' => UserRepo::all(),
        ], 'layouts/admin');
    }

    public function create(): void
    {
        AuthController::requireRole('superuser');

        View::display('admin/users/form', [
            'title'    => 'Neuer Benutzer',
            'user'     => Flash::oldInput() + [
                'id' => 0, 'username' => '', 'name' => '', 'email' => '',
                'role' => 'sektionsleiter', 'roles' => [], 'active' => 1, 'section_ids' => [],
                'member_id' => null, 'member_first_name' => null, 'member_last_name' => null,
            ],
            'sections'   => SectionRepo::all(),
            'mitglieder' => $this->memberOptions(),
            'errors'     => Flash::errors(),
            'isNew'      => true,
        ], 'layouts/admin');
    }

    /** @param array<string,string> $args */
    public function edit(array $args): void
    {
        AuthController::requireRole('superuser');

        $user = UserRepo::find((int) ($args['id'] ?? 0));

        if ($user === null) {
            Flash::error('Benutzer nicht gefunden.');
            Url::redirect('/admin/benutzer');
        }

        View::display('admin/users/form', [
            'title'    => (string) $user['username'],
            'user'       => Flash::oldInput() + $user,
            'sections'   => SectionRepo::all(),
            'mitglieder' => $this->memberOptions(),
            'errors'     => Flash::errors(),
            'isNew'      => false,
        ], 'layouts/admin');
    }

    public function store(): void
    {
        AuthController::requireRole('superuser');
        Csrf::verify();

        [$data, $sectionIds, $password, $errors] = $this->validate(null);

        if ($errors !== []) {
            Flash::withInput($_POST, $errors);
            Url::redirect('/admin/benutzer/neu');
        }

        $generated = $password === '' ? UserRepo::generatePassword() : $password;

        $roles = (array) $data['roles'];
        unset($data['roles']);

        $data['password_hash']        = password_hash($generated, PASSWORD_DEFAULT);
        $data['must_change_password'] = 1;

        $id = Database::insert('users', $data);
        UserRepo::setSections($id, $sectionIds);
        UserRepo::setRoles($id, $roles);

        Audit::log('user_created', 'user', $id, (string) $data['username'] . ' (' . implode(', ', $roles) . ')');
        Flash::success(sprintf(
            'Benutzer "%s" angelegt. Startpasswort: %s – bitte sicher weitergeben, es wird nicht erneut angezeigt.',
            $data['username'],
            $generated
        ));
        Url::redirect('/admin/benutzer');
    }

    /** @param array<string,string> $args */
    public function update(array $args): void
    {
        AuthController::requireRole('superuser');
        Csrf::verify();

        $id       = (int) ($args['id'] ?? 0);
        $existing = UserRepo::find($id);

        if ($existing === null) {
            Flash::error('Benutzer nicht gefunden.');
            Url::redirect('/admin/benutzer');
        }

        [$data, $sectionIds, $password, $errors] = $this->validate($id);

        $roles = (array) $data['roles'];
        unset($data['roles']);

        // Den letzten aktiven Superuser nicht aussperren.
        $losesSuperuser = in_array('superuser', (array) $existing['roles'], true)
            && (!in_array('superuser', $roles, true) || (int) $data['active'] !== 1);

        if ($losesSuperuser && UserRepo::activeSuperuserCount($id) === 0) {
            $errors['roles'] = 'Das ist der letzte aktive Superuser – Rolle und Status können nicht geändert werden.';
        }

        if ($errors !== []) {
            Flash::withInput($_POST, $errors);
            Url::redirect('/admin/benutzer/' . $id);
        }

        if ($password !== '') {
            $data['password_hash']        = password_hash($password, PASSWORD_DEFAULT);
            $data['must_change_password'] = 1;
        }

        $data['updated_at'] = gmdate('Y-m-d H:i:s');

        Database::update('users', $id, $data);
        UserRepo::setSections($id, $sectionIds);
        UserRepo::setRoles($id, $roles);

        Audit::log('user_updated', 'user', $id, Audit::diff($existing, $data));
        Flash::success(
            'Benutzer gespeichert.' . ($password !== '' ? ' Neues Passwort: ' . $password : '')
        );
        Url::redirect('/admin/benutzer/' . $id);
    }

    /** @param array<string,string> $args */
    public function resetPassword(array $args): void
    {
        AuthController::requireRole('superuser');
        Csrf::verify();

        $id   = (int) ($args['id'] ?? 0);
        $user = UserRepo::find($id);

        if ($user === null) {
            Flash::error('Benutzer nicht gefunden.');
            Url::redirect('/admin/benutzer');
        }

        $password = UserRepo::generatePassword();

        Database::update('users', $id, [
            'password_hash'        => password_hash($password, PASSWORD_DEFAULT),
            'must_change_password' => 1,
            'updated_at'           => gmdate('Y-m-d H:i:s'),
        ]);

        Audit::log('user_password_reset', 'user', $id, (string) $user['username']);
        Flash::success(sprintf('Neues Passwort für "%s": %s', $user['username'], $password));
        Url::redirect('/admin/benutzer/' . $id);
    }

    /** @param array<string,string> $args */
    public function destroy(array $args): void
    {
        AuthController::requireRole('superuser');
        Csrf::verify();

        $id   = (int) ($args['id'] ?? 0);
        $user = UserRepo::find($id);

        if ($user === null) {
            Flash::error('Benutzer nicht gefunden.');
            Url::redirect('/admin/benutzer');
        }

        if ($id === Auth::id()) {
            Flash::error('Das eigene Konto kann nicht gelöscht werden.');
            Url::redirect('/admin/benutzer/' . $id);
        }

        if (in_array('superuser', (array) ($user['roles'] ?? []), true) && UserRepo::activeSuperuserCount($id) === 0) {
            Flash::error('Der letzte aktive Superuser kann nicht gelöscht werden.');
            Url::redirect('/admin/benutzer/' . $id);
        }

        Database::run('DELETE FROM users WHERE id = ?', [$id]);

        Audit::log('user_deleted', 'user', $id, (string) $user['username']);
        Flash::success('Benutzer gelöscht.');
        Url::redirect('/admin/benutzer');
    }

    /**
     * @return array{0:array<string,mixed>,1:list<int>,2:string,3:array<string,string>}
     */
    private function validate(?int $id): array
    {
        $errors   = [];
        $username = post('username');

        if ($username === '') {
            $errors['username'] = 'Benutzername ist erforderlich.';
        } elseif (!preg_match('/^[a-zA-Z0-9._@+-]{3,80}$/', $username)) {
            // E-Mail-Adressen sind als Benutzername ausdrücklich erlaubt.
            $errors['username'] = '3–80 Zeichen. Erlaubt sind Buchstaben, Ziffern und . _ - + @';
        } elseif (UserRepo::usernameTaken($username, $id)) {
            $errors['username'] = 'Dieser Benutzername ist bereits vergeben.';
        }

        // Mehrfach-Rollen (Checkboxen). Ein Benutzer kann z. B. Trainer UND
        // Admin sein, oder Sektionskassier mehrerer Sektionen.
        /** @var list<string> $roles */
        $roles = array_values(array_intersect(
            array_map('strval', (array) ($_POST['roles'] ?? [])),
            array_keys(Auth::ROLES)
        ));

        if ($roles === []) {
            $errors['roles'] = 'Bitte mindestens eine Rolle auswählen.';
            $roles = ['sektionsleiter'];
        }

        // users.role bleibt als Hauptrolle (Altbestand/Anzeige): die
        // ranghoechste legacy-kompatible Rolle.
        $role = in_array('superuser', $roles, true) ? 'superuser'
            : (in_array('kassier', $roles, true) ? 'kassier' : 'sektionsleiter');

        $email = post('email');

        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Bitte eine gültige E-Mail-Adresse eingeben.';
        }

        $password = (string) ($_POST['password'] ?? '');

        if ($password !== '' && mb_strlen($password) < Auth::MIN_PASSWORD_LENGTH) {
            $errors['password'] = 'Das Passwort muss mindestens ' . Auth::MIN_PASSWORD_LENGTH . ' Zeichen haben.';
        }

        /** @var list<int> $sectionIds */
        $sectionIds = array_values(array_filter(array_map('intval', (array) ($_POST['section_ids'] ?? []))));

        $sektionsbezogen = array_intersect($roles, Auth::SECTION_SCOPED_ROLES) !== [];

        if ($sektionsbezogen && $sectionIds === []) {
            $errors['section_ids'] = 'Sektionsbezogene Rollen (Sektionsleitung, Trainer, Sektionskassier) brauchen mindestens eine Sektion.';
        }

        // Ohne sektionsbezogene Rolle entfallen die Zuordnungen.
        if (!$sektionsbezogen) {
            $sectionIds = [];
        }

        // Verknuepfung mit einem Mitglied (Benutzer kann auch Mitglied sein).
        $memberId  = null;
        $memberRef = trim(post('member_ref'));

        if ($memberRef !== '') {
            $memberId = MemberRepo::resolveRef($memberRef);

            if ($memberId === null) {
                $errors['member_ref'] = 'Mitglied nicht gefunden (Mitgliedsnummer oder "Zuname Vorname").';
            } elseif (Database::one(
                'SELECT id FROM users WHERE member_id = ? AND (? IS NULL OR id <> ?)',
                [$memberId, $id, $id]
            ) !== null) {
                $errors['member_ref'] = 'Dieses Mitglied ist bereits mit einem anderen Benutzer verknüpft.';
            }
        }

        $data = [
            'username'  => $username,
            'name'      => post('name'),
            'email'     => $email,
            'role'      => $role,
            'active'    => post_bool('active'),
            'member_id' => $memberId,
            'roles'     => $roles, // wird vor dem DB-Schreiben wieder entnommen
        ];

        return [$data, $sectionIds, $password, $errors];
    }
}
