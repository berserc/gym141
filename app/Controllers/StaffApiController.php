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

        // Sichtbare Sektionen aufgeloest (fuer Auswahllisten, z. B. Anwesenheit).
        $sections = $allowed === null
            ? Database::all('SELECT id, name FROM sections ORDER BY name')
            : ($allowed === [] ? [] : Database::all(
                'SELECT id, name FROM sections WHERE id IN ('
                . implode(',', array_fill(0, count($allowed), '?')) . ') ORDER BY name',
                $allowed
            ));

        $this->json([
            'club' => $this->club(),
            'user' => $this->staffUser($user),
            'sections' => array_map(static fn (array $s): array => [
                'id' => (int) $s['id'], 'name' => (string) $s['name'],
            ], $sections),
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

    // ----------------------------------------------------------- Anwesenheit --

    /** Aufstellung einer Sektion fuer ein Datum (?datum=&sektion=), mit Anwesend-Status. */
    public function attendance_get(): void
    {
        [, $user] = $this->requireToken();

        $sectionId = (int) ($_GET['sektion'] ?? 0);
        $datum     = date_create((string) ($_GET['datum'] ?? '')) ?: date_create('today');

        $this->requireSectionAccess($user, $sectionId);

        $rows = Database::all(
            "SELECT m.id, m.first_name, m.last_name, m.member_no, m.is_trainer,
                    CASE WHEN a.id IS NULL THEN 0 ELSE 1 END AS present
               FROM members m
               JOIN member_sections ms ON ms.member_id = m.id AND ms.section_id = ? AND ms.status = 'aktiv'
               LEFT JOIN member_attendance a ON a.member_id = m.id AND a.attended_on = ?
              WHERE m.deleted_at IS NULL AND m.archived_at IS NULL AND m.status = 'aktiv'
              ORDER BY m.last_name, m.first_name",
            [$sectionId, $datum->format('Y-m-d')]
        );

        $this->json([
            'date'    => $datum->format('Y-m-d'),
            'section' => $sectionId,
            'roster'  => array_map(static fn (array $m): array => [
                'id'         => (int) $m['id'],
                'first_name' => (string) $m['first_name'],
                'last_name'  => (string) $m['last_name'],
                'member_no'  => (string) $m['member_no'],
                'is_trainer' => (bool) $m['is_trainer'],
                'present'    => (bool) $m['present'],
            ], $rows),
        ]);
    }

    /**
     * Anwesenheit speichern: {date, section_id, present_ids, absent_ids}.
     * Anwesenheit gilt pro Tag (wie in der Web-Verwaltung); nur Mitglieder
     * der angegebenen Sektion werden angefasst.
     */
    public function attendance_save(): void
    {
        [, $user] = $this->requireToken();

        $body      = $this->body();
        $sectionId = (int) ($body['section_id'] ?? 0);
        $datum     = date_create((string) ($body['date'] ?? ''));

        if ($datum === false) {
            $this->json(['error' => 'Ungültiges Datum.'], 422);
        }

        $this->requireSectionAccess($user, $sectionId);

        $present = array_map('intval', (array) ($body['present_ids'] ?? []));
        $absent  = array_map('intval', (array) ($body['absent_ids'] ?? []));
        $alle    = array_values(array_unique(array_merge($present, $absent)));

        if ($alle === [] || count($alle) > 1000) {
            $this->json(['ok' => true, 'saved' => 0, 'removed' => 0]);
        }

        // Nur Mitglieder, die wirklich in dieser Sektion aktiv sind.
        $marks   = implode(',', array_fill(0, count($alle), '?'));
        $gueltig = array_map(
            static fn (array $r): int => (int) $r['member_id'],
            Database::all(
                "SELECT member_id FROM member_sections
                  WHERE section_id = ? AND status = 'aktiv' AND member_id IN ($marks)",
                array_merge([$sectionId], $alle)
            )
        );

        $present = array_values(array_intersect($present, $gueltig));
        $absent  = array_values(array_intersect($absent, $gueltig));
        $tag     = $datum->format('Y-m-d');
        $userId  = (int) $user['id'];
        $saved   = 0;

        Database::transaction(static function () use ($present, $absent, $tag, $userId, &$saved): void {
            foreach ($present as $memberId) {
                $saved += Database::run(
                    'INSERT OR IGNORE INTO member_attendance (member_id, attended_on, created_by) VALUES (?, ?, ?)',
                    [$memberId, $tag, $userId]
                )->rowCount();
            }

            if ($absent !== []) {
                Database::run(
                    'DELETE FROM member_attendance WHERE attended_on = ? AND member_id IN ('
                    . implode(',', array_fill(0, count($absent), '?')) . ')',
                    array_merge([$tag], $absent)
                );
            }
        });

        $this->json(['ok' => true, 'saved' => $saved, 'present' => count($present)]);
    }

    // -------------------------------------------------------------- Beitraege --

    /** Offene, faellige Beitraege (nur Superuser/Kassier). */
    public function fees_open(): void
    {
        [, $user] = $this->requireToken();
        $this->requireFeeRole($user);

        $entries = FeeRepo::openEntries(['only_due' => 1], $this->allowedSectionIds($user));

        $this->json(['fees' => array_map(static fn (array $f): array => [
            'id'           => (int) $f['id'],
            'member_no'    => (string) $f['member_no'],
            'first_name'   => (string) $f['first_name'],
            'last_name'    => (string) $f['last_name'],
            'amount'       => round((float) $f['amount'], 2),
            'due_date'     => (string) $f['due_date'],
            'period_label' => (string) ($f['period_label'] ?: $f['period']),
            'plan_name'    => (string) ($f['plan_name'] ?? ''),
        ], array_slice($entries, 0, 500))]);
    }

    /** Einen Beitrag als bezahlt verbuchen (inkl. Kassabuch, wie im Web). */
    public function fees_mark_paid(): void
    {
        [, $user] = $this->requireToken();
        $this->requireFeeRole($user);

        $entryId = (int) ($this->body()['entry_id'] ?? 0);

        $entry = Database::one(
            'SELECT f.id, f.paid FROM fee_entries f WHERE f.id = ?',
            [$entryId]
        );

        if ($entry === null) {
            $this->json(['error' => 'Beitrag nicht gefunden.'], 404);
        }

        if ((int) $entry['paid'] === 1) {
            $this->json(['ok' => true, 'already' => true]);
        }

        FeeRepo::markPaid($entryId, null, null, (int) $user['id'], 'per Gym141-Admin-App');

        $this->json(['ok' => true]);
    }

    // --------------------------------------------------------------- Intern --

    /** Zugriff auf eine Sektion pruefen (404/403 sonst). */
    private function requireSectionAccess(array $user, int $sectionId): void
    {
        if ($sectionId <= 0 || Database::one('SELECT id FROM sections WHERE id = ?', [$sectionId]) === null) {
            $this->json(['error' => 'Sektion nicht gefunden.'], 404);
        }

        $allowed = $this->allowedSectionIds($user);

        if ($allowed !== null && !in_array($sectionId, $allowed, true)) {
            $this->json(['error' => 'Kein Zugriff auf diese Sektion.'], 403);
        }
    }

    /** Beitragsfunktionen nur fuer Superuser und Kassier. */
    private function requireFeeRole(array $user): void
    {
        if (!in_array((string) $user['role'], ['superuser', 'kassier'], true)) {
            $this->json(['error' => 'Beiträge sind Superuser und Kassier vorbehalten.'], 403);
        }
    }

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
