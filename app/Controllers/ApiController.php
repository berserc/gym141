<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Database;
use App\Models\CalendarRepo;

/**
 * REST-API fuer die Kalender-Synchronisation.
 *
 * Anmeldung per HTTP Basic Auth mit den Zugangsdaten der VERWALTUNGS-Benutzer
 * (Tabelle users). Alle Antworten sind JSON (UTF-8), ausser dem ICS-Feed.
 *
 *   GET    /api/termine            Termine inkl. aufgeloester Wiederholungen
 *                                  (?von=YYYY-MM-DD&bis=YYYY-MM-DD, Vorgabe: -30/+365 Tage)
 *   POST   /api/termine            Termin anlegen (JSON-Body)
 *   PUT    /api/termine/{id}       Termin aendern (nur uebergebene Felder)
 *   DELETE /api/termine/{id}       Termin loeschen
 *   GET    /api/termine.ics        iCalendar-Feed (fuer Kalender-Abos)
 */
final class ApiController
{
    /** @var array<string,mixed>|null */
    private ?array $user = null;

    // -------------------------------------------------------------- Anmeldung --

    /** @return array<string,mixed> */
    private function requireUser(): array
    {
        if ($this->user !== null) {
            return $this->user;
        }

        $ip = client_ip();

        if (Auth::isThrottled($ip)) {
            $this->json(['error' => 'Zu viele Fehlversuche – bitte später erneut versuchen.'], 429);
        }

        $username = (string) ($_SERVER['PHP_AUTH_USER'] ?? '');
        $password = (string) ($_SERVER['PHP_AUTH_PW'] ?? '');

        // FastCGI reicht PHP_AUTH_* oft nicht durch -> Authorization-Header selbst parsen.
        if ($username === '') {
            $header = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');

            if (preg_match('/^Basic\s+(.+)$/i', $header, $m)) {
                [$username, $password] = array_pad(explode(':', (string) base64_decode($m[1], true), 2), 2, '');
            }
        }

        $user = $username === '' ? null : Database::one(
            'SELECT * FROM users WHERE username = ? COLLATE NOCASE AND active = 1',
            [$username]
        );

        if ($user === null || !password_verify($password, (string) $user['password_hash'])) {
            Auth::recordFailedAttempt($ip, 'api:' . $username);
            header('WWW-Authenticate: Basic realm="Gym141 API", charset="UTF-8"');
            $this->json(['error' => 'Anmeldung erforderlich (Basic Auth mit Verwaltungs-Benutzer).'], 401);
        }

        Auth::clearAttempts($ip);

        return $this->user = $user;
    }

    /** @return never */
    private function json(mixed $data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        exit;
    }

    /** @return array<string,mixed> JSON-Body (oder Formulardaten als Rueckfall). */
    private function body(): array
    {
        $raw = (string) file_get_contents('php://input');

        if ($raw !== '') {
            $data = json_decode($raw, true);

            if (is_array($data)) {
                return $data;
            }

            if (str_contains((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'json')) {
                $this->json(['error' => 'Ungültiges JSON im Request-Body.'], 400);
            }

            parse_str($raw, $data);

            return is_array($data) ? $data : [];
        }

        return $_POST;
    }

    // ----------------------------------------------------------------- Termine --

    public function listEvents(): void
    {
        $this->requireUser();

        $von = parse_date((string) ($_GET['von'] ?? '')) ?? date('Y-m-d', strtotime('-30 days'));
        $bis = parse_date((string) ($_GET['bis'] ?? '')) ?? date('Y-m-d', strtotime('+365 days'));

        if ($von > $bis) {
            [$von, $bis] = [$bis, $von];
        }

        $events = [];

        foreach (Database::all('SELECT * FROM calendar_events ORDER BY starts_on, id') as $event) {
            $vorkommen = CalendarRepo::occurrences($event, $von, $bis);

            if ($vorkommen === []) {
                continue;
            }

            $events[] = [
                'id'          => (int) $event['id'],
                'kind'        => $event['kind'],
                'title'       => $event['title'],
                'starts_on'   => $event['starts_on'],
                'ends_on'     => $event['ends_on'],
                'location'    => $event['location'],
                'description' => $event['description'],
                'rsvp'        => (int) $event['rsvp'] === 1,
                'recur'       => $event['recur'] ?? 'keine',
                'recur_until' => $event['recur_until'] ?? null,
                'occurrences' => array_map(
                    static fn (array $o): array => ['on' => $o['on'], 'ends' => $o['ends']],
                    $vorkommen
                ),
            ];
        }

        $this->json(['von' => $von, 'bis' => $bis, 'termine' => $events]);
    }

    public function createEvent(): void
    {
        $user = $this->requireUser();
        $data = $this->validate($this->body(), null);

        $data['created_by'] = (int) $user['id'];
        $id = Database::insert('calendar_events', $data);

        Audit::log('api_event_created', 'calendar', $id, (string) $data['title'] . ' (API: ' . $user['username'] . ')');
        $this->json(['ok' => true, 'id' => $id], 201);
    }

    /** @param array<string,string> $args */
    public function updateEvent(array $args): void
    {
        $user  = $this->requireUser();
        $id    = (int) ($args['id'] ?? 0);
        $event = Database::one('SELECT * FROM calendar_events WHERE id = ?', [$id]);

        if ($event === null) {
            $this->json(['error' => 'Termin nicht gefunden.'], 404);
        }

        $data = $this->validate(array_merge($event, $this->body()), $event);
        Database::update('calendar_events', $id, $data);

        Audit::log('api_event_updated', 'calendar', $id, (string) $data['title'] . ' (API: ' . $user['username'] . ')');
        $this->json(['ok' => true, 'id' => $id]);
    }

    /** @param array<string,string> $args */
    public function deleteEvent(array $args): void
    {
        $user  = $this->requireUser();
        $id    = (int) ($args['id'] ?? 0);
        $event = Database::one('SELECT * FROM calendar_events WHERE id = ?', [$id]);

        if ($event === null) {
            $this->json(['error' => 'Termin nicht gefunden.'], 404);
        }

        Database::run('DELETE FROM calendar_events WHERE id = ?', [$id]);

        Audit::log('api_event_deleted', 'calendar', $id, (string) $event['title'] . ' (API: ' . $user['username'] . ')');
        $this->json(['ok' => true]);
    }

    public function icsFeed(): void
    {
        $this->requireUser();

        header('Content-Type: text/calendar; charset=UTF-8');
        header('Content-Disposition: inline; filename="termine.ics"');
        echo CalendarRepo::ics(Database::all('SELECT * FROM calendar_events ORDER BY starts_on, id'));
        exit;
    }

    // ------------------------------------------------------------------- Hilfen --

    /**
     * Eingaben pruefen und auf die Spalten der Tabelle abbilden.
     *
     * @param array<string,mixed>      $in
     * @param array<string,mixed>|null $existing
     * @return array<string,mixed>
     */
    private function validate(array $in, ?array $existing): array
    {
        $title  = trim((string) ($in['title'] ?? ''));
        $starts = parse_date((string) ($in['starts_on'] ?? ''));

        if ($title === '' || $starts === null) {
            $this->json(['error' => 'title und starts_on (YYYY-MM-DD) sind erforderlich.'], 422);
        }

        $ends = trim((string) ($in['ends_on'] ?? '')) === '' ? null : parse_date((string) $in['ends_on']);

        if ($ends !== null && $ends < $starts) {
            $this->json(['error' => 'ends_on liegt vor starts_on.'], 422);
        }

        $recur = (string) ($in['recur'] ?? 'keine');

        if (!isset(CalendarRepo::RECURS[$recur])) {
            $this->json(['error' => 'recur muss eines von: ' . implode(', ', array_keys(CalendarRepo::RECURS)) . ' sein.'], 422);
        }

        return [
            'kind'        => ($in['kind'] ?? '') === 'wettkampf' ? 'wettkampf' : 'event',
            'title'       => $title,
            'starts_on'   => $starts,
            'ends_on'     => $ends,
            'location'    => trim((string) ($in['location'] ?? '')),
            'description' => trim((string) ($in['description'] ?? '')),
            'rsvp'        => filter_var($in['rsvp'] ?? true, FILTER_VALIDATE_BOOL) ? 1 : 0,
            'recur'       => $recur,
            'recur_until' => $recur === 'keine' ? null
                : (trim((string) ($in['recur_until'] ?? '')) === '' ? null : parse_date((string) $in['recur_until'])),
        ];
    }
}
