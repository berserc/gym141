<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

final class MemberRepo
{
    /** Spalten, die das Mitgliederformular schreibt. */
    public const EDITABLE = [
        'section_id', 'member_no', 'first_name', 'last_name', 'birthdate', 'gender',
        'street', 'zip', 'city', 'gemeinde', 'country', 'email', 'phone',
        'fee_amount', 'fee_category', 'fee_plan_id', 'fee_since',
        'status', 'joined_on', 'left_on', 'notes',
    ];

    /** Erlaubte Sortierspalten – schuetzt vor SQL-Injection ueber ?sort=. */
    private const SORTABLE = [
        'name'      => 'm.last_name COLLATE NOCASE %s, m.first_name COLLATE NOCASE %s',
        'vorname'   => 'm.first_name COLLATE NOCASE %s, m.last_name COLLATE NOCASE %s',
        'sektion'   => '(SELECT MIN(s.name) FROM member_sections ms JOIN sections s ON s.id = ms.section_id
                          WHERE ms.member_id = m.id) COLLATE NOCASE %s, m.last_name COLLATE NOCASE %s',
        'gemeinde'  => 'm.gemeinde COLLATE NOCASE %s, m.last_name COLLATE NOCASE %s',
        'geburtstag' => 'm.birthdate %s',
        'beitrag'   => '(SELECT p.name FROM fee_plans p WHERE p.id = m.fee_plan_id) COLLATE NOCASE %s,
                        m.last_name COLLATE NOCASE %s',
        'status'    => 'm.status %s, m.last_name COLLATE NOCASE %s',
        'geaendert' => 'm.updated_at %s',
    ];

    /**
     * Sucht Mitglieder mit Filtern und Paginierung.
     *
     * @param array<string,mixed> $filters q, section_id, status, gemeinde, gender,
     *                                     delete_requested, trashed, fee_overdue, fee_plan_id, age_from, age_to
     * @param list<int>|null      $allowedSectionIds null = alle Sektionen
     * @return array{rows:list<array<string,mixed>>, total:int, page:int, pages:int}
     */
    public static function search(
        array $filters,
        ?array $allowedSectionIds,
        int $page = 1,
        int $perPage = 50,
        string $sort = 'name',
        string $direction = 'asc'
    ): array {
        [$where, $params] = self::buildWhere($filters, $allowedSectionIds);

        if ($where === null) {
            return ['rows' => [], 'total' => 0, 'page' => 1, 'pages' => 1];
        }

        $total = (int) Database::value(
            "SELECT COUNT(*) FROM members m WHERE $where",
            $params
        );

        [$page, $offset, $pages] = paginate($total, $perPage, $page);

        $dir      = strtolower($direction) === 'desc' ? 'DESC' : 'ASC';
        $template = self::SORTABLE[$sort] ?? self::SORTABLE['name'];
        $orderBy  = vsprintf($template, array_fill(0, substr_count($template, '%s'), $dir));

        $rows = Database::all(
            "SELECT m.*,
                    (SELECT GROUP_CONCAT(s.name, ', ')
                       FROM member_sections ms JOIN sections s ON s.id = ms.section_id
                      WHERE ms.member_id = m.id
                      ORDER BY s.sort_order)                       AS section_name,
                    (SELECT COALESCE(SUM(ms.fee_amount), 0)
                       FROM member_sections ms WHERE ms.member_id = m.id) AS fee_amount,
                    (SELECT COUNT(*) FROM member_sections ms WHERE ms.member_id = m.id) AS section_count,
                    (SELECT p.name FROM fee_plans p WHERE p.id = m.fee_plan_id) AS fee_plan_name,
                    (SELECT p.interval FROM fee_plans p WHERE p.id = m.fee_plan_id) AS fee_plan_interval,
                    COALESCE(
                        (SELECT ah.amount FROM amount_history ah
                          WHERE ah.entity = 'member' AND ah.entity_id = m.id AND ah.valid_from <= date('now')
                          ORDER BY ah.valid_from DESC, ah.id DESC LIMIT 1),
                        m.fee_amount_override,
                        (SELECT ah.amount FROM amount_history ah
                          WHERE ah.entity = 'fee_plan' AND ah.entity_id = m.fee_plan_id AND ah.valid_from <= date('now')
                          ORDER BY ah.valid_from DESC, ah.id DESC LIMIT 1),
                        (SELECT p.amount FROM fee_plans p WHERE p.id = m.fee_plan_id)) AS fee_effective,
                    EXISTS (SELECT 1 FROM member_pauses mp
                             WHERE mp.member_id = m.id
                               AND mp.pause_from <= date('now')
                               AND (mp.pause_to IS NULL OR mp.pause_to = '' OR mp.pause_to >= date('now'))) AS is_paused,
                    (SELECT COUNT(*) FROM fee_entries f
                      WHERE f.member_id = m.id AND f.paid = 0
                        AND f.due_date <= date('now'))             AS fees_open
               FROM members m
              WHERE $where
              ORDER BY $orderBy
              LIMIT $perPage OFFSET $offset",
            $params
        );

        return ['rows' => $rows, 'total' => $total, 'page' => $page, 'pages' => $pages];
    }

    /**
     * Alle Treffer ohne Limit – fuer den CSV-Export.
     *
     * @param array<string,mixed> $filters
     * @param list<int>|null      $allowedSectionIds
     * @return list<array<string,mixed>>
     */
    public static function searchAll(array $filters, ?array $allowedSectionIds): array
    {
        [$where, $params] = self::buildWhere($filters, $allowedSectionIds);

        if ($where === null) {
            return [];
        }

        return Database::all(
            "SELECT m.*,
                    (SELECT GROUP_CONCAT(s.name, ', ')
                       FROM member_sections ms JOIN sections s ON s.id = ms.section_id
                      WHERE ms.member_id = m.id)                       AS section_name,
                    (SELECT COALESCE(SUM(ms.fee_amount), 0)
                       FROM member_sections ms WHERE ms.member_id = m.id) AS fee_amount
               FROM members m
              WHERE $where
              ORDER BY m.last_name COLLATE NOCASE, m.first_name COLLATE NOCASE",
            $params
        );
    }

    /**
     * Baut die WHERE-Klausel. Rueckgabe [null, []] bedeutet: der Benutzer
     * darf keine einzige Sektion sehen, die Abfrage entfaellt.
     *
     * @param array<string,mixed> $filters
     * @param list<int>|null      $allowedSectionIds
     * @return array{0:string|null,1:list<mixed>}
     */
    private static function buildWhere(array $filters, ?array $allowedSectionIds): array
    {
        $conditions = [];
        $params     = [];

        // Sichtbarkeit nach Rolle: sichtbar ist, wer in mindestens einer
        // freigegebenen Sektion Mitglied ist.
        if ($allowedSectionIds !== null) {
            if ($allowedSectionIds === []) {
                return [null, []];
            }

            $in           = implode(',', array_fill(0, count($allowedSectionIds), '?'));
            $conditions[] = "EXISTS (SELECT 1 FROM member_sections ms
                                      WHERE ms.member_id = m.id AND ms.section_id IN ($in))";
            $params       = array_merge($params, array_values($allowedSectionIds));
        }

        // Papierkorb
        $conditions[] = !empty($filters['trashed']) ? 'm.deleted_at IS NOT NULL' : 'm.deleted_at IS NULL';

        // Archiv: ehemalige Mitglieder erscheinen nur in der eigenen Ansicht.
        if (empty($filters['trashed'])) {
            $conditions[] = !empty($filters['archived']) ? 'm.archived_at IS NOT NULL' : 'm.archived_at IS NULL';
        }

        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            $like         = '%' . $q . '%';
            $conditions[] = '(m.last_name LIKE ? OR m.first_name LIKE ? OR m.email LIKE ?'
                          . ' OR m.member_no LIKE ? OR m.city LIKE ? OR m.gemeinde LIKE ?'
                          . " OR (m.first_name || ' ' || m.last_name) LIKE ?"
                          . " OR (m.last_name || ' ' || m.first_name) LIKE ?)";
            array_push($params, $like, $like, $like, $like, $like, $like, $like, $like);
        }

        if (!empty($filters['section_id'])) {
            $conditions[] = 'EXISTS (SELECT 1 FROM member_sections ms
                                      WHERE ms.member_id = m.id AND ms.section_id = ?)';
            $params[]     = (int) $filters['section_id'];
        }

        if (!empty($filters['status'])) {
            $conditions[] = 'm.status = ?';
            $params[]     = (string) $filters['status'];
        }

        if (!empty($filters['gemeinde'])) {
            $conditions[] = 'm.gemeinde = ?';
            $params[]     = (string) $filters['gemeinde'];
        }

        if (!empty($filters['gender'])) {
            $conditions[] = 'm.gender = ?';
            $params[]     = (string) $filters['gender'];
        }

        if (!empty($filters['delete_requested'])) {
            $conditions[] = 'm.delete_requested = 1';
        }

        // Altersfilter ueber das Geburtsdatum
        if (($filters['age_from'] ?? '') !== '') {
            $conditions[] = "m.birthdate IS NOT NULL AND m.birthdate <= date('now', ?)";
            $params[]     = '-' . (int) $filters['age_from'] . ' years';
        }

        if (($filters['age_to'] ?? '') !== '') {
            $conditions[] = "m.birthdate IS NOT NULL AND m.birthdate > date('now', ?)";
            $params[]     = '-' . ((int) $filters['age_to'] + 1) . ' years';
        }

        // Mitglieder mit faelligen offenen Beitraegen (siehe Beitragsverwaltung)
        if (!empty($filters['fee_overdue'])) {
            $conditions[] = "EXISTS (SELECT 1 FROM fee_entries f
                                      WHERE f.member_id = m.id AND f.paid = 0
                                        AND f.due_date <= date('now'))";
        }

        if (!empty($filters['fee_plan_id'])) {
            $conditions[] = 'm.fee_plan_id = ?';
            $params[]     = (int) $filters['fee_plan_id'];
        }

        // Nur Trainer
        if (!empty($filters['trainer'])) {
            $conditions[] = 'm.is_trainer = 1';
        }

        // Derzeit ausgesetzte Mitglieder (laufende Beitragspause)
        if (!empty($filters['paused'])) {
            $conditions[] = "EXISTS (SELECT 1 FROM member_pauses mp
                                      WHERE mp.member_id = m.id
                                        AND mp.pause_from <= date('now')
                                        AND (mp.pause_to IS NULL OR mp.pause_to = '' OR mp.pause_to >= date('now')))";
        }

        return [implode(' AND ', $conditions), $params];
    }

    /** @return array<string,mixed>|null */
    public static function find(int $id): ?array
    {
        $member = Database::one('SELECT * FROM members WHERE id = ?', [$id]);

        if ($member === null) {
            return null;
        }

        $member['memberships']  = self::memberships($id);
        $member['section_ids']  = array_map(static fn (array $m): int => (int) $m['section_id'], $member['memberships']);
        $member['section_name'] = implode(', ', array_column($member['memberships'], 'section_name'));
        $member['fee_amount']   = array_sum(array_map(
            static fn (array $m): float => (float) $m['fee_amount'],
            $member['memberships']
        ));

        return $member;
    }

    /**
     * Alle Sektionsmitgliedschaften einer Person.
     *
     * @return list<array<string,mixed>>
     */
    public static function memberships(int $memberId): array
    {
        return Database::all(
            'SELECT ms.*, s.name AS section_name, s.slug AS section_slug, s.fee_free
               FROM member_sections ms
               JOIN sections s ON s.id = ms.section_id
              WHERE ms.member_id = ?
              ORDER BY s.sort_order, s.name COLLATE NOCASE',
            [$memberId]
        );
    }


    /**
     * Erziehungsberechtigte eines Mitglieds – verlinkte Mitglieder werden mit
     * deren aktuellen Kontaktdaten aufgeloest.
     *
     * @return list<array<string,mixed>>
     */
    public static function guardians(int $memberId): array
    {
        return Database::all(
            'SELECT g.*,
                    gm.first_name AS gm_first_name, gm.last_name AS gm_last_name,
                    gm.phone AS gm_phone, gm.email AS gm_email, gm.member_no AS gm_member_no
               FROM member_guardians g
               LEFT JOIN members gm ON gm.id = g.guardian_member_id
              WHERE g.member_id = ?
              ORDER BY g.id',
            [$memberId]
        );
    }

    /**
     * Umgekehrte Sicht: fuer welche Mitglieder ist diese Person
     * erziehungsberechtigt?
     *
     * @return list<array<string,mixed>>
     */
    public static function wardsOf(int $memberId): array
    {
        return Database::all(
            'SELECT g.relation, m.id, m.first_name, m.last_name, m.birthdate
               FROM member_guardians g
               JOIN members m ON m.id = g.member_id
              WHERE g.guardian_member_id = ? AND m.deleted_at IS NULL
              ORDER BY m.last_name COLLATE NOCASE, m.first_name COLLATE NOCASE',
            [$memberId]
        );
    }

    /** Loest "M00012", eine ID oder "Vorname Zuname" zu einer Mitglieds-ID auf. */
    public static function resolveRef(string $ref): ?int
    {
        $ref = trim($ref);

        if ($ref === '') {
            return null;
        }

        $row = Database::one(
            "SELECT id FROM members WHERE member_no = ? COLLATE NOCASE AND member_no <> '' AND deleted_at IS NULL",
            [$ref]
        );

        if ($row !== null) {
            return (int) $row['id'];
        }

        if (ctype_digit($ref)) {
            $row = Database::one('SELECT id FROM members WHERE id = ? AND deleted_at IS NULL', [(int) $ref]);

            if ($row !== null) {
                return (int) $row['id'];
            }
        }

        $row = Database::one(
            "SELECT id FROM members
              WHERE deleted_at IS NULL
                AND (first_name || ' ' || last_name = ? COLLATE NOCASE
                     OR last_name || ' ' || first_name = ? COLLATE NOCASE)",
            [$ref, $ref]
        );

        return $row === null ? null : (int) $row['id'];
    }

    /**
     * Dateien eines Mitglieds (Profilbild zuerst, dann neueste zuerst).
     *
     * @return list<array<string,mixed>>
     */
    public static function files(int $memberId): array
    {
        return Database::all(
            'SELECT f.*, u.username AS uploaded_by_name
               FROM member_files f
               LEFT JOIN users u ON u.id = f.uploaded_by
              WHERE f.member_id = ?
              ORDER BY f.is_photo DESC, f.id DESC',
            [$memberId]
        );
    }

    /** @return array<string,mixed>|null Aktuelles Profilbild des Mitglieds. */
    public static function photo(int $memberId): ?array
    {
        return Database::one(
            'SELECT * FROM member_files WHERE member_id = ? AND is_photo = 1 ORDER BY id DESC LIMIT 1',
            [$memberId]
        );
    }

    /**
     * Beitragspausen eines Mitglieds (neueste zuerst).
     *
     * @return list<array<string,mixed>>
     */
    public static function pauses(int $memberId): array
    {
        return Database::all(
            'SELECT p.*, u.username AS created_by_name
               FROM member_pauses p
               LEFT JOIN users u ON u.id = p.created_by
              WHERE p.member_id = ?
              ORDER BY p.pause_from DESC, p.id DESC',
            [$memberId]
        );
    }

    /**
     * Gesamtstand fuer die Kopfzeile der Mitgliederliste: Anzahl aktiver
     * Mitglieder und Summe ihrer laufenden Monatsbeitraege (Beitragsart
     * "monatlich", individuelle Abweichungen beruecksichtigt).
     *
     * @param list<int>|null $allowedSectionIds null = alle
     * @return array{active:int,monthly_members:int,monthly_sum:float}
     */
    public static function activeFeeStats(?array $allowedSectionIds): array
    {
        $scope  = '1 = 1';
        $params = [];

        if ($allowedSectionIds !== null) {
            if ($allowedSectionIds === []) {
                return ['active' => 0, 'monthly_members' => 0, 'monthly_sum' => 0.0, 'paused' => 0, 'trainer' => 0];
            }

            $in     = implode(',', array_fill(0, count($allowedSectionIds), '?'));
            $scope  = "EXISTS (SELECT 1 FROM member_sections ms
                                WHERE ms.member_id = m.id AND ms.section_id IN ($in))";
            $params = array_values($allowedSectionIds);
        }

        // Zaehlt nur, wer wirklich beitragspflichtig ist: nicht ausgetreten und
        // nicht gerade in einer Beitragspause. Der Betrag ist der heute
        // gueltige (Betragsaenderungen und Abweichungen beruecksichtigt).
        // Quartals-, Halbjahres- und Jahreszahler zaehlen mit dem auf den
        // Monat umgerechneten Betrag (Periodenbetrag / Monate der Periode).
        $zahlend = "p.id IS NOT NULL AND p.active = 1
                    AND (m.left_on IS NULL OR m.left_on = '' OR m.left_on >= date('now'))
                    AND NOT EXISTS (SELECT 1 FROM member_pauses mp
                                     WHERE mp.member_id = m.id
                                       AND mp.pause_from <= date('now')
                                       AND (mp.pause_to IS NULL OR mp.pause_to = '' OR mp.pause_to >= date('now')))";

        $monate = "CASE p.interval
                       WHEN 'quartal'  THEN 3.0
                       WHEN 'halbjahr' THEN 6.0
                       WHEN 'jahr'     THEN 12.0
                       ELSE 1.0
                   END";

        $betrag = "COALESCE(
                       (SELECT ah.amount FROM amount_history ah
                         WHERE ah.entity = 'member' AND ah.entity_id = m.id AND ah.valid_from <= date('now')
                         ORDER BY ah.valid_from DESC, ah.id DESC LIMIT 1),
                       m.fee_amount_override,
                       (SELECT ah.amount FROM amount_history ah
                         WHERE ah.entity = 'fee_plan' AND ah.entity_id = p.id AND ah.valid_from <= date('now')
                         ORDER BY ah.valid_from DESC, ah.id DESC LIMIT 1),
                       p.amount)";

        $row = Database::one(
            "SELECT COUNT(*) AS aktiv,
                    SUM(CASE WHEN $zahlend THEN 1 ELSE 0 END) AS monats_mitglieder,
                    COALESCE(SUM(CASE WHEN $zahlend THEN ($betrag) / $monate ELSE 0 END), 0) AS monats_summe,
                    SUM(m.is_trainer) AS trainer,
                    SUM(CASE WHEN EXISTS (SELECT 1 FROM member_pauses mp
                                           WHERE mp.member_id = m.id
                                             AND mp.pause_from <= date('now')
                                             AND (mp.pause_to IS NULL OR mp.pause_to = '' OR mp.pause_to >= date('now')))
                             THEN 1 ELSE 0 END) AS ausgesetzt
               FROM members m
               LEFT JOIN fee_plans p ON p.id = m.fee_plan_id
              WHERE m.deleted_at IS NULL AND m.archived_at IS NULL AND m.status = 'aktiv' AND $scope",
            $params
        );

        return [
            'active'          => (int) ($row['aktiv'] ?? 0),
            'monthly_members' => (int) ($row['monats_mitglieder'] ?? 0),
            'monthly_sum'     => (float) ($row['monats_summe'] ?? 0),
            'paused'          => (int) ($row['ausgesetzt'] ?? 0),
            'trainer'         => (int) ($row['trainer'] ?? 0),
        ];
    }

    /** Anzahl offener Loeschvormerkungen (fuer den Superuser-Hinweis). */
    public static function pendingDeletions(): int
    {
        return (int) Database::value(
            'SELECT COUNT(*) FROM members WHERE delete_requested = 1 AND deleted_at IS NULL'
        );
    }

    /** @return list<string> */
    public static function distinctGemeinden(): array
    {
        $rows = Database::all(
            "SELECT DISTINCT gemeinde FROM members
              WHERE gemeinde <> '' AND deleted_at IS NULL
              ORDER BY gemeinde COLLATE NOCASE"
        );

        return array_map(static fn (array $r): string => (string) $r['gemeinde'], $rows);
    }

    /** Prueft, ob es einen sehr aehnlichen Datensatz bereits gibt (Dublettenwarnung). */
    public static function findDuplicate(string $firstName, string $lastName, ?string $birthdate, ?int $ignoreId = null): ?array
    {
        return Database::one(
            'SELECT m.*, s.name AS section_name
               FROM members m JOIN sections s ON s.id = m.section_id
              WHERE m.deleted_at IS NULL
                AND m.first_name = ? COLLATE NOCASE
                AND m.last_name  = ? COLLATE NOCASE
                AND ((? IS NULL AND m.birthdate IS NULL) OR m.birthdate = ?)
                AND (? IS NULL OR m.id <> ?)
              LIMIT 1',
            [$firstName, $lastName, $birthdate, $birthdate, $ignoreId, $ignoreId]
        );
    }
}
