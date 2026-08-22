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

final class AuthController
{
    public function showLogin(): void
    {
        if (Auth::check()) {
            Url::redirect('/admin');
        }

        // $flash kommt bereits aus View::share() im Front-Controller.
        // Ein zweites Flash::take() hier würde die Meldungen verschlucken.
        View::display('admin/login', [
            'title'  => 'Anmeldung',
            'errors' => Flash::errors(),
            'old'    => Flash::oldInput(),
        ], 'layouts/blank');
    }

    public function login(): void
    {
        Csrf::verify();

        $ip       = client_ip();
        $username = post('username');
        $password = (string) ($_POST['password'] ?? '');

        if (Auth::isThrottled($ip)) {
            Flash::error('Zu viele Fehlversuche. Bitte in 15 Minuten erneut versuchen.');
            Url::redirect('/admin/login');
        }

        if ($username === '' || $password === '') {
            Flash::error('Bitte Benutzername und Passwort eingeben.');
            Flash::withInput(['username' => $username]);
            Url::redirect('/admin/login');
        }

        if (!Auth::attempt($username, $password)) {
            Auth::recordFailedAttempt($ip, $username);
            Flash::error('Benutzername oder Passwort ist falsch.');
            Flash::withInput(['username' => $username]);
            Url::redirect('/admin/login');
        }

        Auth::clearAttempts($ip);
        Audit::log('login', 'user', Auth::id());

        $user = Auth::user();

        if ($user !== null && (int) $user['must_change_password'] === 1) {
            Flash::info('Bitte vergeben Sie zuerst ein eigenes Passwort.');
            Url::redirect('/admin/profil');
        }

        Url::redirect('/admin');
    }

    public function logout(): void
    {
        Csrf::verify();
        Audit::log('logout', 'user', Auth::id());
        Auth::logout();

        Url::redirect('/admin/login');
    }

    /** Verwaltungsmodus wechseln (Admin/Kassier/Trainer/Mitglied). */
    public function switchMode(): void
    {
        self::requireLogin();
        Csrf::verify();

        $mode = post('mode');

        if (!in_array($mode, Auth::allowedModes(), true)) {
            Flash::error('Dieser Modus ist für Ihr Konto nicht freigeschaltet.');
            Url::redirect('/admin');
        }

        if ($mode === 'mitglied') {
            $user   = Auth::user();
            $member = Database::one(
                'SELECT id FROM members WHERE id = ? AND deleted_at IS NULL AND archived_at IS NULL',
                [(int) ($user['member_id'] ?? 0)]
            );

            if ($member === null) {
                Flash::error('Es ist kein (aktives) Mitglied mit Ihrem Konto verknüpft.');
                Url::redirect('/admin');
            }

            // Bruecke in den Mitgliederbereich – ohne eigenen Mitglieds-Login.
            $_SESSION['member_login_id']    = (int) $member['id'];
            $_SESSION['member_login_admin'] = true;

            Url::redirect('/mitglied');
        }

        $_SESSION['admin_mode'] = $mode;
        Url::redirect('/admin');
    }

    public function profile(): void
    {
        $user = Auth::user();

        View::display('admin/profile', [
            'title'  => 'Mein Konto',
            'user'   => $user,
            'errors' => Flash::errors(),
        ], 'layouts/admin');
    }

    public function updatePassword(): void
    {
        Csrf::verify();

        $user = Auth::user();

        if ($user === null) {
            Url::redirect('/admin/login');
        }

        $current = (string) ($_POST['current_password'] ?? '');
        $new     = (string) ($_POST['new_password'] ?? '');
        $confirm = (string) ($_POST['new_password_confirm'] ?? '');

        $errors = [];

        if (!password_verify($current, (string) $user['password_hash'])) {
            $errors['current_password'] = 'Das aktuelle Passwort stimmt nicht.';
        }

        if (mb_strlen($new) < Auth::MIN_PASSWORD_LENGTH) {
            $errors['new_password'] = 'Das neue Passwort muss mindestens '
                . Auth::MIN_PASSWORD_LENGTH . ' Zeichen haben.';
        }

        if ($new !== $confirm) {
            $errors['new_password_confirm'] = 'Die Wiederholung stimmt nicht überein.';
        }

        if ($errors !== []) {
            Flash::withInput([], $errors);
            Flash::error('Das Passwort wurde nicht geändert.');
            Url::redirect('/admin/profil');
        }

        Database::update('users', (int) $user['id'], [
            'password_hash'        => password_hash($new, PASSWORD_DEFAULT),
            'must_change_password' => 0,
            'updated_at'           => gmdate('Y-m-d H:i:s'),
        ]);

        Audit::log('password_changed', 'user', (int) $user['id']);
        Flash::success('Passwort geändert.');
        Url::redirect('/admin/profil');
    }

    /** Schuetzt alle /admin-Routen. */
    public static function requireLogin(): void
    {
        if (Auth::check()) {
            return;
        }

        Flash::error('Bitte melden Sie sich an.');
        Url::redirect('/admin/login');
    }

    public static function requireRole(string ...$roles): void
    {
        self::requireLogin();

        if (Auth::is(...$roles)) {
            return;
        }

        http_response_code(403);

        View::display('errors/403', ['title' => 'Kein Zugriff'], 'layouts/admin');
        exit;
    }
}
