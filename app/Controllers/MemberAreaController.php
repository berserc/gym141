<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\Flash;
use App\Core\MemberAuth;
use App\Core\Url;
use App\Core\View;
use App\Models\CalendarRepo;
use App\Models\FeeRepo;
use App\Models\SportRepo;

/**
 * Login-Bereich fuer Mitglieder (/mitglied): eigene Daten, Beitragsstatus,
 * Wettkampf- und Eventkalender mit An-/Abmeldung.
 */
final class MemberAreaController
{
    // -------------------------------------------------------------- Anmeldung --

    public function showLogin(): void
    {
        if (MemberAuth::check()) {
            Url::redirect('/mitglied');
        }

        View::display('member/login', [
            'title'  => 'Mitglieder-Login',
            'errors' => Flash::errors(),
        ], 'layouts/member-blank');
    }

    public function login(): void
    {
        Csrf::verify();

        $ip = client_ip();

        if (Auth::isThrottled($ip)) {
            Flash::error('Zu viele Fehlversuche. Bitte in 15 Minuten erneut versuchen.');
            Url::redirect('/mitglied/login');
        }

        if (!MemberAuth::attempt(post('email'), (string) ($_POST['password'] ?? ''))) {
            Auth::recordFailedAttempt($ip, 'mitglied:' . post('email'));
            Flash::error('E-Mail oder Passwort ist falsch – oder der Zugang ist nicht freigeschaltet.');
            Url::redirect('/mitglied/login');
        }

        Auth::clearAttempts($ip);
        Url::redirect('/mitglied');
    }

    public function logout(): void
    {
        Csrf::verify();
        MemberAuth::logout();
        Flash::success('Abgemeldet.');
        Url::redirect('/mitglied/login');
    }

    // -------------------------------------------------------------- Uebersicht --

    public function home(): void
    {
        $member = MemberAuth::require();
        $id     = (int) $member['id'];

        $offene = array_values(array_filter(
            FeeRepo::entriesForMember($id),
            static fn (array $f): bool => (int) $f['paid'] !== 1 && (string) $f['due_date'] <= date('Y-m-d')
        ));

        // Naechste Termine: Wiederholungen in einzelne Vorkommen aufloesen.
        $kommende = [];

        foreach (CalendarRepo::eventsForMember($id, false) as $event) {
            foreach (CalendarRepo::occurrences($event, date('Y-m-d'), date('Y-m-d', strtotime('+120 days'))) as $occ) {
                $eintrag              = $event;
                $eintrag['starts_on'] = $occ['on'];
                $eintrag['ends_on']   = $occ['ends'];
                $kommende[]           = $eintrag;
            }
        }

        usort($kommende, static fn (array $a, array $b): int => strcmp((string) $a['starts_on'], (string) $b['starts_on']));

        View::display('member/home', [
            'title'    => 'Mein Bereich',
            'member'   => $member,
            'record'   => SportRepo::recordForMember($id),
            'openFees' => $offene,
            'events'   => array_slice($kommende, 0, 5),
        ], 'layouts/member');
    }

    // ---------------------------------------------------------------- Termine --

    public function events(): void
    {
        $member = MemberAuth::require();
        $id     = (int) $member['id'];

        $nurKommende = query('alle') !== '1';
        $von         = $nurKommende ? date('Y-m-d') : date('Y-m-d', strtotime('-60 days'));
        $bis         = date('Y-m-d', strtotime('+180 days'));

        // Sichtbare Termine in einzelne Vorkommen aufloesen (Wiederholungen!).
        $events   = CalendarRepo::eventsForMember($id, false);
        $eintraege = [];

        foreach ($events as $event) {
            foreach (CalendarRepo::occurrences($event, $von, $bis) as $occ) {
                $eintraege[] = [
                    'event'     => $event,
                    'on'        => $occ['on'],
                    'ends'      => $occ['ends'],
                    // Abstimmungs-Schluessel: '' bei einmaligen Terminen.
                    'occurs_on' => ((string) ($event['recur'] ?? 'keine')) === 'keine' ? '' : $occ['on'],
                ];
            }
        }

        usort($eintraege, static fn (array $a, array $b): int => [$a['on'], $a['event']['id']] <=> [$b['on'], $b['event']['id']]);

        $poll = CalendarRepo::pollData(
            array_values(array_unique(array_map(static fn (array $e): int => (int) $e['event']['id'], $eintraege))),
            $id
        );

        View::display('member/events', [
            'title'       => 'Termine',
            'member'      => $member,
            'eintraege'   => $eintraege,
            'votes'       => $poll['votes'],
            'my'          => $poll['my'],
            'nurKommende' => $nurKommende,
            'kinds'       => CalendarRepo::KINDS,
        ], 'layouts/member');
    }

    /** Abstimmung zu einem Termin(-Vorkommen): komme / komme nicht. */
    public function respond(array $args): void
    {
        $member = MemberAuth::require();
        Csrf::verify();

        $eventId = (int) ($args['id'] ?? 0);
        $event   = Database::one('SELECT * FROM calendar_events WHERE id = ?', [$eventId]);

        if ($event === null || !CalendarRepo::memberCanSee($eventId, (int) $member['id'])) {
            Flash::error('Termin nicht gefunden.');
            Url::redirect('/mitglied/termine');
        }

        if ((int) $event['rsvp'] !== 1) {
            Flash::error('Für diesen Termin ist keine Abstimmung vorgesehen.');
            Url::redirect('/mitglied/termine');
        }

        // Bei Wiederholungsterminen muss das Vorkommen ein gueltiges Datum sein.
        $occursOn = '';

        if ((string) ($event['recur'] ?? 'keine') !== 'keine') {
            $occursOn = parse_date(post('occurs_on')) ?? '';
            $gueltig  = array_column(
                CalendarRepo::occurrences($event, date('Y-m-d', strtotime('-60 days')), date('Y-m-d', strtotime('+400 days'))),
                'on'
            );

            if (!in_array($occursOn, $gueltig, true)) {
                Flash::error('Ungültiger Wiederholungstermin.');
                Url::redirect('/mitglied/termine');
            }
        }

        CalendarRepo::respond($eventId, (int) $member['id'], $occursOn, post('status'), post('note'));
        Url::redirect('/mitglied/termine');
    }

    // ------------------------------------------------------------ Entwicklung --

    /** Eigene Entwicklung: Gewichtskurve, Trainingsbesuche, Bewertung. */
    public function development(): void
    {
        $member = MemberAuth::require();
        $id     = (int) $member['id'];

        View::display('member/development', [
            'title'      => 'Meine Entwicklung',
            'member'     => $member,
            'weights'    => \App\Controllers\TrainingController::weights($id),
            'attendance' => \App\Controllers\TrainingController::attendance($id),
            'perMonth'   => \App\Controllers\TrainingController::attendancePerMonth($id),
            'results'    => \App\Controllers\TrainingController::resultsForMember($id),
        ], 'layouts/member');
    }

    /** Oeffentliche App-Einladungsseite (Anleitung + Code; loest NICHT ein). */
    public function appInvite(array $args): void
    {
        $token   = (string) ($args['token'] ?? '');
        $gueltig = preg_match('/^[a-f0-9]{40}$/', $token) === 1
            && Database::one(
                "SELECT id FROM member_invites
                  WHERE token_hash = ? AND used_at IS NULL AND expires_at > datetime('now')",
                [hash('sha256', $token)]
            ) !== null;

        View::display('public/app-invite', [
            'title'   => 'Einladung zur Gym141-App',
            'noindex' => true,
            'gueltig' => $gueltig,
            'token'   => $token,
            'appUri'  => $gueltig ? \App\Models\InviteRepo::uriFor($token) : '',
        ], 'layouts/public');
    }

    /** Mitglied traegt sein eigenes Gewicht ein. */
    public function saveWeight(): void
    {
        $member = MemberAuth::require();
        Csrf::verify();

        $gewicht = (float) str_replace(',', '.', post('weight'));
        $datum   = parse_date(post('measured_on')) ?? date('Y-m-d');

        if ($gewicht <= 20 || $gewicht > 400) {
            Flash::error('Bitte ein plausibles Gewicht in kg angeben.');
            Url::redirect('/mitglied/entwicklung');
        }

        $zeit = trim(post('measured_time'));

        Database::insert('member_weights', [
            'member_id'     => (int) $member['id'],
            'measured_on'   => $datum,
            // Mehrere Messungen pro Tag - die Uhrzeit unterscheidet sie.
            'measured_time' => preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $zeit) === 1 ? $zeit : '',
            'weight'        => $gewicht,
            'note'          => post('note'),
        ]);

        Flash::success('Gewicht gespeichert: ' . number_format($gewicht, 1, ',', '.') . ' kg.');
        Url::redirect('/mitglied/entwicklung');
    }

    // --------------------------------------------------------------- Passwort --

    public function showPassword(): void
    {
        MemberAuth::require();

        View::display('member/password', [
            'title' => 'Passwort ändern',
        ], 'layouts/member');
    }

    public function changePassword(): void
    {
        $member = MemberAuth::require();
        Csrf::verify();

        $aktuell = (string) ($_POST['current'] ?? '');
        $neu     = (string) ($_POST['password'] ?? '');
        $neu2    = (string) ($_POST['password2'] ?? '');

        if (!password_verify($aktuell, (string) $member['login_password_hash'])) {
            Flash::error('Das aktuelle Passwort stimmt nicht.');
            Url::redirect('/mitglied/passwort');
        }

        if (mb_strlen($neu) < 8) {
            Flash::error('Das neue Passwort braucht mindestens 8 Zeichen.');
            Url::redirect('/mitglied/passwort');
        }

        if ($neu !== $neu2) {
            Flash::error('Die Wiederholung stimmt nicht überein.');
            Url::redirect('/mitglied/passwort');
        }

        Database::update('members', (int) $member['id'], [
            'login_password_hash' => password_hash($neu, PASSWORD_DEFAULT),
        ]);

        Flash::success('Passwort geändert.');
        Url::redirect('/mitglied');
    }
}
