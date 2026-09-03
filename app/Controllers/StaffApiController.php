<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
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

    // -------------------------------------------------- Mitglieder-Stammdaten --

    /** Von der Admin-App aenderbare Stammdaten-Felder. */
    private const MEMBER_EDITABLE = [
        'first_name', 'last_name', 'street', 'zip', 'city', 'country', 'email', 'phone',
    ];

    /** Stammdaten eines Mitglieds (Trainer: nur lesen; Schreibrecht siehe can.members_write). */
    public function member_get(array $args): void
    {
        [, $user] = $this->requireToken();

        $member = $this->requireMember($user, (int) ($args['id'] ?? 0));

        $this->json(['member' => $this->memberJson($member, $user)]);
    }

    /** Stammdaten aendern – nur Admin/Verwaltung/Sektionsleitung (im Sichtbereich). */
    public function member_update(array $args): void
    {
        [, $user] = $this->requireToken();

        if (array_intersect($this->rolesOf($user), ['superuser', 'verwaltung', 'sektionsleiter']) === []) {
            $this->json(['error' => 'Stammdaten ändern dürfen Admin, Verwaltung und Sektionsleitung.'], 403);
        }

        $member = $this->requireMember($user, (int) ($args['id'] ?? 0));
        $body   = $this->body();

        $changes = [];

        foreach (self::MEMBER_EDITABLE as $field) {
            if (!array_key_exists($field, $body)) {
                continue;
            }

            $value = trim((string) $body[$field]);

            if ($field === 'email' && $value !== '' && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                $this->json(['error' => 'Ungültige E-Mail-Adresse.'], 422);
            }

            if (in_array($field, ['first_name', 'last_name'], true) && $value === '') {
                $this->json(['error' => 'Vor- und Zuname dürfen nicht leer sein.'], 422);
            }

            $value = $field === 'country' ? (strtoupper(mb_substr($value, 0, 2)) ?: 'AT') : mb_substr($value, 0, 200);

            if ($value !== (string) $member[$field]) {
                $changes[$field] = $value;
            }
        }

        if ($changes !== []) {
            $vorher = array_intersect_key($member, $changes);

            $changes['updated_at'] = gmdate('Y-m-d H:i:s');
            Database::update('members', (int) $member['id'], $changes);
            unset($changes['updated_at']);

            if (isset($changes['phone'])) {
                \App\Models\MemberRepo::syncPrimaryPhone((int) $member['id'], (string) $changes['phone']);
            }

            // Aenderung fuer Admin und Verwaltung nachvollziehbar machen.
            Audit::logAs(
                (int) $user['id'],
                (string) $user['username'] . ' (Admin-App)',
                'member_updated',
                'member',
                (int) $member['id'],
                Audit::diff($vorher, $changes)
            );

            $member = array_merge($member, $changes);
        }

        $this->json(['ok' => true, 'member' => $this->memberJson($member, $user)]);
    }

    /**
     * Stammdaten-Aenderungen der letzten 30 Tage (Admin/Verwaltung alle,
     * Sektionsleitung nur die eigenen Mitglieder) – aus dem Protokoll.
     */
    public function member_changes(): void
    {
        [, $user] = $this->requireToken();

        if (array_intersect($this->rolesOf($user), ['superuser', 'verwaltung', 'sektionsleiter']) === []) {
            $this->json(['error' => 'Kein Zugriff auf das Änderungsprotokoll.'], 403);
        }

        $allowed = $this->allowedSectionIds($user);
        $filter  = '';
        $params  = [];

        if ($allowed !== null) {
            if ($allowed === []) {
                $this->json(['changes' => []]);
            }

            $marks  = implode(',', array_fill(0, count($allowed), '?'));
            $filter = " AND a.entity_id IN (SELECT member_id FROM member_sections WHERE section_id IN ($marks))";
            $params = $allowed;
        }

        $rows = Database::all(
            "SELECT a.created_at AS at, a.username, a.action, a.entity_id, a.detail,
                    m.first_name, m.last_name, m.member_no
               FROM audit_log a
               JOIN members m ON m.id = a.entity_id
              WHERE a.entity = 'member'
                AND a.action IN ('member_updated', 'member_created')
                AND a.created_at > datetime('now', '-30 days')
                $filter
              ORDER BY a.created_at DESC
              LIMIT 100",
            $params
        );

        $this->json(['changes' => array_map(static fn (array $r): array => [
            'at'         => (string) $r['at'],
            'by'         => (string) $r['username'],
            'action'     => (string) $r['action'],
            'member_id'  => (int) $r['entity_id'],
            'member'     => (string) ($r['last_name'] . ' ' . $r['first_name']),
            'member_no'  => (string) $r['member_no'],
            'detail'     => (string) $r['detail'],
        ], $rows)]);
    }

    /**
     * App-Einladung fuer ein Mitglied erzeugen (Link + QR-Inhalt, 10 Minuten
     * gueltig, einmalig). Admin/Verwaltung/Sektionsleitung im Sichtbereich.
     */
    public function member_invite(array $args): void
    {
        [, $user] = $this->requireToken();

        if (array_intersect($this->rolesOf($user), ['superuser', 'verwaltung', 'sektionsleiter']) === []) {
            $this->json(['error' => 'App-Einladungen dürfen Admin, Verwaltung und Sektionsleitung erzeugen.'], 403);
        }

        $member = $this->requireMember($user, (int) ($args['id'] ?? 0));

        [$token, $expires] = \App\Models\InviteRepo::create((int) $member['id'], (int) $user['id']);

        Audit::logAs(
            (int) $user['id'],
            (string) $user['username'] . ' (Admin-App)',
            'member_invite',
            'member',
            (int) $member['id'],
            'App-Einladung erzeugt (gültig 10 Minuten)'
        );

        $this->json([
            'ok'          => true,
            'url'         => \App\Models\InviteRepo::urlFor($token),
            'uri'         => \App\Models\InviteRepo::uriFor($token),
            'invite'      => $token,
            'expires_at'  => $expires,
            'member_name' => trim($member['first_name'] . ' ' . $member['last_name']),
        ]);
    }

    /** Mitglied laden und Sichtbereich pruefen (404/403 sonst). @return array<string,mixed> */
    private function requireMember(array $user, int $id): array
    {
        $member = Database::one(
            'SELECT * FROM members WHERE id = ? AND deleted_at IS NULL',
            [$id]
        );

        if ($member === null) {
            $this->json(['error' => 'Mitglied nicht gefunden.'], 404);
        }

        $allowed = $this->allowedSectionIds($user);

        if ($allowed !== null && !$this->memberInSections($id, $allowed)) {
            $this->json(['error' => 'Kein Zugriff auf dieses Mitglied.'], 403);
        }

        return $member;
    }

    /** @param list<int> $sectionIds */
    private function memberInSections(int $memberId, array $sectionIds): bool
    {
        if ($sectionIds === []) {
            return false;
        }

        $marks = implode(',', array_fill(0, count($sectionIds), '?'));

        return Database::one(
            "SELECT 1 FROM member_sections WHERE member_id = ? AND section_id IN ($marks)
              UNION SELECT 1 FROM members WHERE id = ? AND section_id IN ($marks)",
            array_merge([$memberId], $sectionIds, [$memberId], $sectionIds)
        ) !== null;
    }

    /** @return array<string,mixed> */
    private function memberJson(array $member, array $user): array
    {
        return [
            'id'         => (int) $member['id'],
            'member_no'  => (string) $member['member_no'],
            'first_name' => (string) $member['first_name'],
            'last_name'  => (string) $member['last_name'],
            'birthdate'  => $member['birthdate'],
            'gender'     => (string) $member['gender'],
            'street'     => (string) $member['street'],
            'zip'        => (string) $member['zip'],
            'city'       => (string) $member['city'],
            'country'    => (string) $member['country'],
            'email'      => (string) $member['email'],
            'phone'      => (string) $member['phone'],
            'status'     => (string) $member['status'],
            'is_trainer' => (bool) $member['is_trainer'],
            'joined_on'  => $member['joined_on'],
            'updated_at' => (string) ($member['updated_at'] ?? ''),
            'editable'   => array_intersect($this->rolesOf($user), ['superuser', 'verwaltung', 'sektionsleiter']) !== []
                ? self::MEMBER_EDITABLE
                : [],
            // Alle Nummern (primaere zuerst); 'phone' oben bleibt die primaere.
            'phones'     => array_map(static fn (array $p): array => [
                'label'   => (string) $p['label'],
                'number'  => (string) $p['number'],
                'primary' => (bool) $p['is_primary'],
            ], \App\Models\MemberRepo::phones((int) $member['id'])),
            'sections'   => (string) (Database::value(
                "SELECT GROUP_CONCAT(s.name, ', ')
                   FROM member_sections ms JOIN sections s ON s.id = ms.section_id
                  WHERE ms.member_id = ? AND ms.status = 'aktiv'",
                [(int) $member['id']]
            ) ?? ''),
        ];
    }

    // -------------------------------------------------------------- Beitraege --

    /** Offene, faellige Beitraege (nur Superuser/Kassier). */
    public function fees_open(): void
    {
        [, $user] = $this->requireToken();
        $this->requireFeeRole($user);

        // Finanz-Sichtbereich (nicht der allgemeine): ein Sektionskassier
        // sieht nur die Beitraege der Mitglieder seiner Sektionen.
        $entries = FeeRepo::openEntries(['only_due' => 1], $this->feeSectionIds($user));

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
            'SELECT f.id, f.paid, f.member_id FROM fee_entries f WHERE f.id = ?',
            [$entryId]
        );

        if ($entry === null) {
            $this->json(['error' => 'Beitrag nicht gefunden.'], 404);
        }

        // Sektionskassier: nur Beitraege von Mitgliedern der eigenen Sektionen.
        $feeSections = $this->feeSectionIds($user);

        if ($feeSections !== null && !$this->memberInSections((int) $entry['member_id'], $feeSections)) {
            $this->json(['error' => 'Kein Zugriff auf Beiträge dieses Mitglieds.'], 403);
        }

        if ((int) $entry['paid'] === 1) {
            $this->json(['ok' => true, 'already' => true]);
        }

        FeeRepo::markPaid($entryId, null, null, (int) $user['id'], 'per Gym141-Admin-App');

        $this->json(['ok' => true]);
    }

    // --------------------------------------------------------------- Aufgaben --

    /**
     * Aufgabenliste inkl. Checklisten – vollstaendig, damit die Apps offline
     * arbeiten koennen. updated_at ist die Konfliktbasis fuer Schreibzugriffe.
     */
    public function tasks_get(): void
    {
        [, $user] = $this->requireToken();

        $tasks = Database::all(
            'SELECT t.*, u.name AS creator_name FROM club_tasks t
               LEFT JOIN users u ON u.id = t.created_by
              ORDER BY t.status, COALESCE(t.due_date, \'9999\'), t.id DESC'
        );

        $this->json(['tasks' => array_map(
            fn (array $t): array => $this->taskJson($t, $user),
            $tasks
        )]);
    }

    /** Neue Aufgabe: {title, description?, due_date?}. */
    public function tasks_create(): void
    {
        [, $user] = $this->requireToken();

        $body  = $this->body();
        $titel = trim((string) ($body['title'] ?? ''));

        if ($titel === '') {
            $this->json(['error' => 'Titel fehlt.'], 422);
        }

        $id = Database::insert('club_tasks', [
            'title'       => mb_substr($titel, 0, 200),
            'description' => mb_substr((string) ($body['description'] ?? ''), 0, 4000),
            'due_date'    => ($body['due_date'] ?? null) ?: null,
            'created_by'  => (int) $user['id'],
        ]);

        $this->json(['task' => $this->taskJson(
            Database::one('SELECT t.*, ? AS creator_name FROM club_tasks t WHERE t.id = ?', [(string) ($user['name'] ?: $user['username']), $id]),
            $user
        )], 201);
    }

    /**
     * Aufgabe aendern: {status|toggle_item|neues_item|delete_item|share|title|description|due_date}.
     *
     * Offline-Sync: Die App schickt base_updated_at (Stand, auf dem ihre
     * Aenderung beruht). Ist der Server neuer, kommt 409 samt aktuellem
     * Stand zurueck – die Entscheidung trifft der Admin oder wer die Aufgabe
     * angelegt hat (force=true), oder sie wird verschoben ("spaeter").
     */
    public function task_action(array $args): void
    {
        [, $user] = $this->requireToken();

        $id   = (int) ($args['id'] ?? 0);
        $task = Database::one('SELECT * FROM club_tasks WHERE id = ?', [$id]);

        if ($task === null) {
            $this->json(['error' => 'Aufgabe nicht gefunden.'], 404);
        }

        $body  = $this->body();
        $basis = trim((string) ($body['base_updated_at'] ?? ''));
        $force = !empty($body['force']);

        if ($force && !$this->mayDecide($user, $task)) {
            $this->json([
                'error'          => 'Konflikte entscheidet der Admin oder wer die Aufgabe angelegt hat.',
                'decision_locked' => true,
            ], 403);
        }

        if ($basis !== '' && !$force && $basis !== (string) $task['updated_at']) {
            // Stand am Server ist neuer als die Basis der App-Aenderung.
            $this->json([
                'conflict'   => true,
                'can_decide' => $this->mayDecide($user, $task),
                'task'       => $this->taskJson($task + ['creator_name' => $this->creatorName($task)], $user),
            ], 409);
        }

        $now = gmdate('Y-m-d H:i:s');

        if (isset($body['status'])) {
            Database::update('club_tasks', $id, [
                'status'     => $body['status'] === 'erledigt' ? 'erledigt' : 'offen',
                'updated_at' => $now,
            ]);
        }

        if (isset($body['title']) || isset($body['description']) || array_key_exists('due_date', $body)) {
            Database::update('club_tasks', $id, [
                'title'       => mb_substr(trim((string) ($body['title'] ?? $task['title'])) ?: (string) $task['title'], 0, 200),
                'description' => mb_substr((string) ($body['description'] ?? $task['description']), 0, 4000),
                'due_date'    => array_key_exists('due_date', $body) ? (($body['due_date'] ?? null) ?: null) : $task['due_date'],
                'updated_at'  => $now,
            ]);
        }

        if (!empty($body['toggle_item'])) {
            Database::run('UPDATE club_task_items SET done = 1 - done WHERE id = ? AND task_id = ?', [(int) $body['toggle_item'], $id]);
            Database::run('UPDATE club_tasks SET updated_at = ? WHERE id = ?', [$now, $id]);
        }

        if (trim((string) ($body['neues_item'] ?? '')) !== '') {
            Database::insert('club_task_items', [
                'task_id' => $id,
                'title'   => mb_substr(trim((string) $body['neues_item']), 0, 200),
                'sort'    => (int) Database::value('SELECT COALESCE(MAX(sort),0)+1 FROM club_task_items WHERE task_id = ?', [$id]),
            ]);
            Database::run('UPDATE club_tasks SET updated_at = ? WHERE id = ?', [$now, $id]);
        }

        if (!empty($body['delete_item'])) {
            Database::run('DELETE FROM club_task_items WHERE id = ? AND task_id = ?', [(int) $body['delete_item'], $id]);
            Database::run('UPDATE club_tasks SET updated_at = ? WHERE id = ?', [$now, $id]);
        }

        if (isset($body['share'])) {
            Database::update('club_tasks', $id, [
                'share_token' => !empty($body['share']) ? bin2hex(random_bytes(16)) : null,
            ]);
        }

        $task = Database::one('SELECT * FROM club_tasks WHERE id = ?', [$id]);

        $this->json(['task' => $this->taskJson($task + ['creator_name' => $this->creatorName($task)], $user)]);
    }

    /** Konflikt entscheiden darf der Admin (Superuser) oder wer die Aufgabe angelegt hat. */
    private function mayDecide(array $user, array $task): bool
    {
        return (string) $user['role'] === 'superuser'
            || ((int) ($task['created_by'] ?? 0) !== 0 && (int) $task['created_by'] === (int) $user['id']);
    }

    private function creatorName(array $task): string
    {
        return (string) (Database::value(
            'SELECT name FROM users WHERE id = ?',
            [(int) ($task['created_by'] ?? 0)]
        ) ?? '');
    }

    /** @return array<string,mixed> */
    private function taskJson(array $task, array $user): array
    {
        $id     = (int) $task['id'];
        $schema = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host   = (string) ($_SERVER['HTTP_HOST'] ?? '');

        return [
            'id'          => $id,
            'title'       => (string) $task['title'],
            'description' => (string) $task['description'],
            'status'      => (string) $task['status'],
            'due_date'    => $task['due_date'],
            'updated_at'  => (string) $task['updated_at'],
            'created_by'  => (int) ($task['created_by'] ?? 0),
            'creator'     => (string) ($task['creator_name'] ?? ''),
            'can_decide'  => $this->mayDecide($user, $task),
            'shared'      => $task['share_token'] !== null,
            'share_url'   => $task['share_token'] !== null && $host !== ''
                ? $schema . '://' . $host . url('/f/' . $task['share_token'])
                : null,
            'items'       => array_map(static fn (array $i): array => [
                'id'    => (int) $i['id'],
                'title' => (string) $i['title'],
                'done'  => (bool) $i['done'],
            ], Database::all('SELECT * FROM club_task_items WHERE task_id = ? ORDER BY sort, id', [$id])),
            'files'       => array_map(static fn (array $f): array => [
                'id'       => (int) $f['id'],
                'filename' => (string) $f['filename'],
                'size'     => (int) $f['size'],
            ], Database::all('SELECT * FROM club_task_files WHERE task_id = ? ORDER BY id', [$id])),
        ];
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

    /** Beitragsfunktionen: vereinsweite Kassiere plus sektionsbezogene Finanzrollen. */
    private function requireFeeRole(array $user): void
    {
        if (array_intersect($this->rolesOf($user), ['superuser', 'kassier', 'sektionskassier', 'sektionsleiter']) === []) {
            $this->json(['error' => 'Beiträge sind Kassieren und Sektionsleitungen vorbehalten.'], 403);
        }
    }

    /** @return list<string> alle Rollen des API-Benutzers (Mehrfach-Rollen). */
    private function rolesOf(array $user): array
    {
        return Auth::rolesOf((int) $user['id'], (string) $user['role']);
    }

    /**
     * Sichtbereich fuer FINANZEN: null = alle; Liste = nur diese Sektionen.
     *
     * @return list<int>|null
     */
    private function feeSectionIds(array $user): ?array
    {
        $roles = $this->rolesOf($user);

        if (array_intersect($roles, ['superuser', 'kassier']) !== []) {
            return null;
        }

        return array_map(
            static fn (array $r): int => (int) $r['section_id'],
            Database::all('SELECT section_id FROM user_sections WHERE user_id = ?', [(int) $user['id']])
        );
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

    /** Sichtbare Sektionen: null = alle (vereinsweite Rollen). @return list<int>|null */
    private function allowedSectionIds(array $user): ?array
    {
        if (array_intersect($this->rolesOf($user), ['superuser', 'verwaltung', 'kassier']) !== []) {
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

        $roles = $this->rolesOf($user);

        return [
            'id'         => (int) $user['id'],
            'username'   => (string) $user['username'],
            'name'       => (string) ($user['name'] ?: $user['username']),
            'role'       => (string) $user['role'],
            'role_label' => implode(', ', array_map(
                static fn (string $r): string => Auth::ROLES[$r] ?? $r,
                $roles
            )),
            'roles'      => $roles,
            'sections'   => $sections, // null = alle Sektionen
            // Was darf dieser Benutzer in der App? (Mehrfach-Rollen vereinigt)
            'can'        => [
                'members_read'   => true,
                'members_write'  => array_intersect($roles, ['superuser', 'verwaltung', 'sektionsleiter']) !== [],
                'fees'           => array_intersect($roles, ['superuser', 'kassier', 'sektionskassier', 'sektionsleiter']) !== [],
                'member_changes' => array_intersect($roles, ['superuser', 'verwaltung', 'sektionsleiter']) !== [],
                'attendance'     => true,
            ],
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
        $ct  = (string) ($_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '');

        // Content-Type ODER JSON-artiger Inhalt – je nach SAPI fehlt der Header.
        if ($raw !== '' && (str_contains($ct, 'json') || str_starts_with(ltrim($raw), '{'))) {
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
