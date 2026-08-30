<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Config;
use App\Core\Database;
use App\Models\FeeRepo;
use App\Models\Setting;

/**
 * JSON-API fuer die Verwaltungs-Apps (/api/app/verwaltung/*) – Admin-App und
 * Trainer-App melden sich mit einem Verwaltungs-Benutzer an (users-Tabelle).
 *
 * Sichtbereich wie in der Web-Verwaltung: Superuser und Kassier sehen alles,
 * Sektionsleitungen nur die Mitglieder ihrer Sektionen (user_sections).
 * Bearer-Token analog zur Mitglieder-API (user_api_tokens, nur SHA-256-Hash).
 */
final class StaffApiController
{
    // ------------------------------------------------------------- Anmeldung --

    public function login(): void
    {
        $ip = client_ip();

        if (Auth::isThrottled($ip)) {
            $this->json(['error' => 'Zu viele Fehlversuche – bitte in 15 Minuten erneut versuchen.'], 429);
        }

        $body     = $this->body();
        $username = trim((string) ($body['username'] ?? ''));
        $password = (string) ($body['password'] ?? '');
        $device   = mb_substr(trim((string) ($body['device'] ?? '')), 0, 120);

        $user = Database::one(
            'SELECT * FROM users WHERE username = ? COLLATE NOCASE AND active = 1',
            [$username]
        );

        if ($user === null || !password_verify($password, (string) $user['password_hash'])) {
            Auth::recordFailedAttempt($ip, 'app-verwaltung:' . $username);
            $this->json(['error' => 'Benutzername oder Passwort ist falsch.'], 401);
        }

        Auth::clearAttempts($ip);

        $token = bin2hex(random_bytes(32));

        Database::insert('user_api_tokens', [
            'user_id'     => (int) $user['id'],
            'token_hash'  => hash('sha256', $token),
            'device_name' => $device,
        ]);

        $this->json([
            'token' => $token,
            'club'  => $this->club(),
            'user'  => $this->staffUser($user),
        ]);
    }

    public function logout(): void
    {
        [$tokenId] = $this->requireToken();
        Database::run('DELETE FROM user_api_tokens WHERE id = ?', [$tokenId]);
        $this->json(['ok' => true]);
    }

    // ------------------------------------------------------------ Uebersicht --

    /** Kennzahlen fuer das Dashboard (auf den Sichtbereich eingeschraenkt). */
    public function overview(): void
    {
        [, $user] = $this->requireToken();

        $allowed = $this->allowedSectionIds($user);
        [$filter, $params] = $this->memberFilter($allowed);

        $aktiv = (int) Database::value(
            "SELECT COUNT(*) FROM members m
              WHERE m.status = 'aktiv' AND m.deleted_at IS NULL AND m.archived_at IS NULL $filter",
            $params
        );

        $inaktiv = (int) Database::value(
            "SELECT COUNT(*) FROM members m
              WHERE m.status = 'inaktiv' AND m.deleted_at IS NULL AND m.archived_at IS NULL $filter",
            $params
        );

        $trainer = (int) Database::value(
            "SELECT COUNT(*) FROM members m
              WHERE m.is_trainer = 1 AND m.deleted_at IS NULL AND m.archived_at IS NULL $filter",
            $params
        );

        $offen = FeeRepo::openStats($allowed);

        $this->json([
            'club' => $this->club(),
            'user' => $this->staffUser($user),
            'stats' => [
                'active_members'   => $aktiv,
                'inactive_members' => $inaktiv,
                'trainers'         => $trainer,
                'open_fee_count'   => $offen['count'],
                'open_fee_total'   => round((float) $offen['sum'], 2),
                'open_fee_members' => $offen['members'],
            ],
        ]);
    }

    // ------------------------------------------------------------ Mitglieder --

    /** Mitgliederliste mit Suche (?suche=), auf den Sichtbereich eingeschraenkt. */
    public function members(): void
    {
        [, $user] = $this->requireToken();

        $allowed = $this->allowedSectionIds($user);
        [$filter, $params] = $this->memberFilter($allowed);

        $suche = trim((string) ($_GET['suche'] ?? ''));

        if ($suche !== '') {
            $filter .= " AND (m.first_name LIKE ? OR m.last_name LIKE ? OR m.member_no LIKE ?
                          OR (m.first_name || ' ' || m.last_name) LIKE ?)";
            $like = '%' . $suche . '%';
            array_push($params, $like, $like, $like, $like);
        }

        $rows = Database::all(
            "SELECT m.id, m.member_no, m.first_name, m.last_name, m.status, m.is_trainer,
                    m.email, m.phone, m.birthdate,
                    (SELECT GROUP_CONCAT(s.name, ', ')
                       FROM member_sections ms JOIN sections s ON s.id = ms.section_id
                      WHERE ms.member_id = m.id AND ms.status = 'aktiv') AS sektionen
               FROM members m
              WHERE m.deleted_at IS NULL AND m.archived_at IS NULL $filter
              ORDER BY m.last_name, m.first_name
              LIMIT 300",
            $params
        );

        $this->json(['members' => array_map(static fn (array $m): array => [
            'id'         => (int) $m['id'],
            'member_no'  => (string) $m['member_no'],
            'first_name' => (string) $m['first_name'],
            'last_name'  => (string) $m['last_name'],
            'status'     => (string) $m['status'],
            'is_trainer' => (bool) $m['is_trainer'],
            'email'      => (string) $m['email'],
            'phone'      => (string) $m['phone'],
            'birthdate'  => $m['birthdate'],
            'sections'   => (string) ($m['sektionen'] ?? ''),
        ], $rows)]);
    }

    // --------------------------------------------------------------- Intern --

    /** @return array{0:int, 1:array<string,mixed>} */
    private function requireToken(): array
    {
        $header = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? '');

        if (!str_starts_with($header, 'Bearer ') || strlen($header) < 20) {
            $this->json(['error' => 'Anmeldung erforderlich.'], 401);
        }

        $row = Database::one(
            'SELECT t.id AS token_id, u.*
               FROM user_api_tokens t
               JOIN users u ON u.id = t.user_id
              WHERE t.token_hash = ? AND u.active = 1',
            [hash('sha256', substr($header, 7))]
        );

        if ($row === null) {
            $this->json(['error' => 'Sitzung abgelaufen – bitte neu anmelden.'], 401);
        }

        $tokenId = (int) $row['token_id'];
        unset($row['token_id']);

        Database::run('UPDATE user_api_tokens SET last_used_at = ? WHERE id = ?', [gmdate('Y-m-d H:i:s'), $tokenId]);

        return [$tokenId, $row];
    }

    /** Sichtbare Sektionen: null = alle (Superuser/Kassier). @return list<int>|null */
    private function allowedSectionIds(array $user): ?array
    {
        if (in_array((string) $user['role'], ['superuser', 'kassier'], true)) {
            return null;
        }

        return array_map(
            static fn (array $r): int => (int) $r['section_id'],
            Database::all('SELECT section_id FROM user_sections WHERE user_id = ?', [(int) $user['id']])
        );
    }

    /**
     * WHERE-Zusatz fuer die Sichtbereichs-Einschraenkung.
     *
     * @return array{0:string, 1:list<mixed>}
     */
    private function memberFilter(?array $allowed): array
    {
        if ($allowed === null) {
            return ['', []];
        }

        if ($allowed === []) {
            return [' AND 1 = 0', []];
        }

        $marks = implode(',', array_fill(0, count($allowed), '?'));

        return [
            " AND EXISTS (SELECT 1 FROM member_sections ms
                           WHERE ms.member_id = m.id AND ms.section_id IN ($marks))",
            $allowed,
        ];
    }

    /** @return array<string,mixed> */
    private function staffUser(array $user): array
    {
        $allowed = $this->allowedSectionIds($user);

        $sections = $allowed === null
            ? null
            : ($allowed === [] ? [] : Database::all(
                'SELECT id, name FROM sections WHERE id IN ('
                . implode(',', array_fill(0, count($allowed), '?')) . ') ORDER BY name',
                $allowed
            ));

        return [
            'id'         => (int) $user['id'],
            'username'   => (string) $user['username'],
            'name'       => (string) ($user['name'] ?: $user['username']),
            'role'       => (string) $user['role'],
            'role_label' => Auth::ROLES[(string) $user['role']] ?? (string) $user['role'],
            'sections'   => $sections, // null = alle Sektionen
        ];
    }

    /** @return array<string,string> */
    private function club(): array
    {
        return [
            'name'     => Setting::get('club_name') ?: (string) Config::get('app_name', 'Gym141'),
            'logo_url' => MemberApiController::logoUrl(),
        ];
    }

    /** @return array<string,mixed> */
    private function body(): array
    {
        $raw = (string) file_get_contents('php://input');

        if ($raw !== '' && str_contains((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'json')) {
            $data = json_decode($raw, true);

            if (is_array($data)) {
                return $data;
            }
        }

        return $_POST;
    }

    private function json(mixed $data, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=UTF-8');
        header('Cache-Control: no-store');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}
