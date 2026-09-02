<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use DateTimeImmutable;

/**
 * Beitragsverwaltung: Beitragsarten (fee_plans) und Beitragshistorie
 * (fee_entries, eine Zeile je Mitglied und Zahlungsperiode).
 */
final class FeeRepo
{
    /** Intervall => [Monate je Periode, Bezeichnung] */
    public const INTERVALS = [
        'monatlich' => [1, 'monatlich'],
        'quartal'   => [3, 'quartalsweise'],
        'halbjahr'  => [6, 'halbjährlich'],
        'jahr'      => [12, 'jährlich'],
    ];

    private const MONTH_NAMES = [
        1 => 'Jänner', 'Februar', 'März', 'April', 'Mai', 'Juni',
        'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember',
    ];

    // ---------------------------------------------------------- Beitragsarten --

    /** @return list<array<string,mixed>> */
    public static function plans(bool $onlyActive = false): array
    {
        $where = $onlyActive ? 'WHERE active = 1' : '';

        return Database::all(
            "SELECT p.*,
                    (SELECT COUNT(*) FROM members m
                      WHERE m.fee_plan_id = p.id AND m.deleted_at IS NULL) AS member_count
               FROM fee_plans p $where
              ORDER BY p.active DESC, p.name COLLATE NOCASE"
        );
    }

    /** @return array<string,mixed>|null */
    public static function plan(int $id): ?array
    {
        return Database::one('SELECT * FROM fee_plans WHERE id = ?', [$id]);
    }

    public static function intervalLabel(string $interval): string
    {
        return self::INTERVALS[$interval][1] ?? $interval;
    }

    // ------------------------------------------------------------- Perioden --

    /**
     * Beginn der Periode, in die das Datum faellt.
     * Quartale beginnen im Jaenner/April/Juli/Oktober, Halbjahre im
     * Jaenner/Juli, Jahre im Jaenner.
     */
    public static function periodStart(DateTimeImmutable $date, int $months): DateTimeImmutable
    {
        $month = (int) $date->format('n');
        $start = intdiv($month - 1, $months) * $months + 1;

        return $date->setDate((int) $date->format('Y'), $start, 1);
    }

    /** Lesbares Label einer Periode, z. B. "Juli 2026" oder "3. Quartal 2026". */
    public static function periodLabel(DateTimeImmutable $start, int $months): string
    {
        $year  = (int) $start->format('Y');
        $month = (int) $start->format('n');

        return match ($months) {
            1       => self::MONTH_NAMES[$month] . ' ' . $year,
            3       => (intdiv($month - 1, 3) + 1) . '. Quartal ' . $year,
            6       => (intdiv($month - 1, 6) + 1) . '. Halbjahr ' . $year,
            default => 'Jahr ' . $year,
        };
    }

    /** Faelligkeit: der eingestellte Tag im ersten Monat der Periode. */
    public static function dueDate(DateTimeImmutable $start, int $dueDay): string
    {
        $day = min(max(1, $dueDay), (int) $start->format('t'));

        return $start->setDate((int) $start->format('Y'), (int) $start->format('n'), $day)
            ->format('Y-m-d');
    }

    // ------------------------------------------- Betraege und Beitragspausen --

    /**
     * Zum Stichtag gueltiger Betrag laut Aenderungshistorie – null, wenn es
     * keinen Historieneintrag gibt (dann gilt der Basisbetrag der Entitaet).
     */
    public static function amountAt(string $entity, int $entityId, string $date): ?float
    {
        $row = Database::one(
            'SELECT amount FROM amount_history
              WHERE entity = ? AND entity_id = ? AND valid_from <= ?
              ORDER BY valid_from DESC, id DESC
              LIMIT 1',
            [$entity, $entityId, $date]
        );

        return $row === null ? null : (float) $row['amount'];
    }

    /**
     * Beitrag eines Mitglieds zum Stichtag. Reihenfolge:
     * Mitglieds-Betragsaenderung > individuelle Abweichung > Betragsaenderung
     * der Beitragsart > Basisbetrag der Beitragsart.
     *
     * @param array<string,mixed> $m id, fee_amount_override, fee_plan_id, plan_amount
     */
    public static function memberAmountAt(array $m, string $date): float
    {
        $vomMitglied = self::amountAt('member', (int) $m['id'], $date);

        if ($vomMitglied !== null) {
            return $vomMitglied;
        }

        if ($m['fee_amount_override'] !== null && $m['fee_amount_override'] !== '') {
            return (float) $m['fee_amount_override'];
        }

        $vomPlan = self::amountAt('fee_plan', (int) $m['fee_plan_id'], $date);

        return $vomPlan ?? (float) $m['plan_amount'];
    }

    /**
     * Aenderungshistorie einer Entitaet (neueste zuerst).
     *
     * @return list<array<string,mixed>>
     */
    public static function amountHistory(string $entity, int $entityId): array
    {
        return Database::all(
            'SELECT ah.*, u.username AS created_by_name
               FROM amount_history ah
               LEFT JOIN users u ON u.id = ah.created_by
              WHERE ah.entity = ? AND ah.entity_id = ?
              ORDER BY ah.valid_from DESC, ah.id DESC',
            [$entity, $entityId]
        );
    }

    /**
     * Legt eine Betragsaenderung ab Stichtag an.
     *
     * @return array<string,string> Fehler (leer bei Erfolg)
     */
    public static function addAmountChange(string $entity, int $entityId, string $validFromRaw, float $amount, string $note, ?int $userId): array
    {
        $validFrom = parse_date($validFromRaw);

        if ($validFrom === null) {
            return ['valid_from' => 'Bitte ein gültiges Datum angeben.'];
        }

        if ($amount < 0) {
            return ['amount' => 'Der Betrag darf nicht negativ sein.'];
        }

        Database::insert('amount_history', [
            'entity'     => $entity,
            'entity_id'  => $entityId,
            'amount'     => $amount,
            'valid_from' => $validFrom,
            'note'       => $note,
            'created_by' => $userId,
        ]);

        return [];
    }

    /**
     * Rechnet UNBEZAHLTE Beitragszeilen eines Mitglieds ab einem Stichtag neu –
     * noetig, damit rueckwirkende (oder ab sofort geltende) Betragsaenderungen
     * auch bereits erzeugte offene Faelligkeiten erfassen. Bezahlte Zeilen
     * bleiben unveraendert.
     *
     * @return int Anzahl der angepassten Zeilen
     */
    public static function refreshOpenEntries(int $memberId, string $fromDate): int
    {
        $m = Database::one(
            'SELECT m.id, m.fee_amount_override, m.fee_plan_id, p.amount AS plan_amount
               FROM members m
               JOIN fee_plans p ON p.id = m.fee_plan_id
              WHERE m.id = ?',
            [$memberId]
        );

        if ($m === null) {
            return 0; // kein laufender Beitrag zugeordnet
        }

        $angepasst = 0;

        foreach (Database::all(
            'SELECT id, period, amount FROM fee_entries
              WHERE member_id = ? AND paid = 0 AND period >= ?',
            [$memberId, substr($fromDate, 0, 7)]
        ) as $entry) {
            $soll = self::memberAmountAt($m, $entry['period'] . '-01');

            if (abs($soll - (float) $entry['amount']) > 0.004) {
                Database::update('fee_entries', (int) $entry['id'], ['amount' => $soll]);
                $angepasst++;
            }
        }

        return $angepasst;
    }

    /**
     * Beitragspausen aller Mitglieder, einmal geladen.
     *
     * @return array<int,list<array{0:string,1:string}>> member_id => [von, bis]
     */
    public static function allPauses(): array
    {
        $map = [];

        foreach (Database::all(
            "SELECT member_id, pause_from, COALESCE(NULLIF(pause_to, ''), '9999-12-31') AS pause_to
               FROM member_pauses"
        ) as $row) {
            $map[(int) $row['member_id']][] = [(string) $row['pause_from'], (string) $row['pause_to']];
        }

        return $map;
    }

    /** @param array<int,list<array{0:string,1:string}>> $pauses */
    public static function isPausedOn(array $pauses, int $memberId, string $date): bool
    {
        foreach ($pauses[$memberId] ?? [] as [$von, $bis]) {
            if ($date >= $von && $date <= $bis) {
                return true;
            }
        }

        return false;
    }

    /**
     * Legt fuer alle aktiven Mitglieder mit Beitragsart die faelligen
     * Beitragszeilen an (fehlende Perioden bis heute). Laeuft bei jedem
     * Aufruf der Beitragsseite – ohne Cronjob noetig zu sein.
     *
     * @return int Anzahl neu angelegter Zeilen
     */
    public static function generateDue(): int
    {
        $members = Database::all(
            "SELECT m.id, m.fee_plan_id, m.fee_since, m.joined_on, m.left_on,
                    m.fee_amount_override,
                    p.amount AS plan_amount,
                    COALESCE(m.fee_due_day_override, p.due_day) AS due_day,
                    p.interval
               FROM members m
               JOIN fee_plans p ON p.id = m.fee_plan_id
              WHERE m.deleted_at IS NULL AND m.archived_at IS NULL
                AND m.status = 'aktiv'
                AND p.active = 1
                AND (m.left_on IS NULL OR m.left_on = '' OR m.left_on >= date('now'))"
        );

        if ($members === []) {
            return 0;
        }

        $today   = new DateTimeImmutable('today');
        $pauses  = self::allPauses();
        $created = 0;

        // Vereinsweites Erfassungs-Startdatum: Perioden davor entstehen nicht
        // (Einstellungen -> "Beitraege erfassen ab"; leer = keine Grenze).
        $erfassenAb = null;

        try {
            $abRaw = Setting::get('fee_capture_from');

            if ($abRaw !== '') {
                $erfassenAb = new DateTimeImmutable($abRaw);
            }
        } catch (\Exception) {
            $erfassenAb = null;
        }

        Database::transaction(static function () use ($members, $today, $pauses, $erfassenAb, &$created): void {
            foreach ($members as $m) {
                $months = self::INTERVALS[(string) $m['interval']][0] ?? 1;

                $sinceRaw = (string) ($m['fee_since'] ?: $m['joined_on'] ?: $today->format('Y-m-d'));

                try {
                    $since = new DateTimeImmutable($sinceRaw);
                } catch (\Exception) {
                    $since = $today;
                }

                // Beitragspflicht beginnt mit der Periode, in die fee_since faellt.
                $period = self::periodStart($since, $months);

                // Sicherheitsgrenze: nicht weiter als 10 Jahre zurueck.
                $limit = $today->modify('-10 years');
                if ($period < $limit) {
                    $period = self::periodStart($limit, $months);
                }

                // Erfassungs-Startdatum: fruehestens die Periode, in die es faellt.
                if ($erfassenAb !== null) {
                    $abPeriode = self::periodStart($erfassenAb, $months);

                    if ($period < $abPeriode) {
                        $period = $abPeriode;
                    }
                }

                // Austritt: nach dem Austrittsdatum keine neuen Perioden mehr.
                $ende = $today;
                if (($m['left_on'] ?? null) !== null && (string) $m['left_on'] !== '') {
                    try {
                        $austritt = new DateTimeImmutable((string) $m['left_on']);
                        if ($austritt < $ende) {
                            $ende = $austritt;
                        }
                    } catch (\Exception) {
                    }
                }

                while ($period <= $ende) {
                    $due = self::dueDate($period, (int) $m['due_day']);

                    // Beitragspause: Faelligkeiten im Pausenzeitraum entfallen.
                    if (self::isPausedOn($pauses, (int) $m['id'], $due) || $due > $ende->format('Y-m-d')) {
                        $period = $period->modify('+' . $months . ' months');
                        continue;
                    }

                    $inserted = Database::run(
                        'INSERT OR IGNORE INTO fee_entries
                            (member_id, plan_id, period, period_label, due_date, amount)
                         VALUES (?, ?, ?, ?, ?, ?)',
                        [
                            (int) $m['id'],
                            (int) $m['fee_plan_id'],
                            $period->format('Y-m'),
                            self::periodLabel($period, $months),
                            $due,
                            self::memberAmountAt($m, $due),
                        ]
                    )->rowCount();

                    $created += $inserted;
                    $period   = $period->modify('+' . $months . ' months');
                }
            }
        });

        return $created;
    }

    /**
     * Prognose: kuenftige Beitragsperioden aktiver Mitglieder, die noch
     * NICHT als Beitragszeile existieren (Faelligkeit nach heute, bis $until).
     * Es wird nichts gespeichert – reine Vorschau fuer die Auswertung.
     *
     * @return list<array{member_id:int,period:string,due_date:string,amount:float}>
     */
    public static function projectedEntries(string $until): array
    {
        $today = new DateTimeImmutable('today');

        try {
            $bis = new DateTimeImmutable($until);
        } catch (\Exception) {
            return [];
        }

        // Sicherheitsgrenze: hoechstens 3 Jahre voraus.
        $limit = $today->modify('+3 years');
        if ($bis > $limit) {
            $bis = $limit;
        }

        if ($bis <= $today) {
            return [];
        }

        $members = Database::all(
            "SELECT m.id, m.fee_plan_id, m.fee_since, m.joined_on, m.left_on,
                    m.fee_amount_override,
                    p.amount AS plan_amount,
                    COALESCE(m.fee_due_day_override, p.due_day) AS due_day,
                    p.interval
               FROM members m
               JOIN fee_plans p ON p.id = m.fee_plan_id
              WHERE m.deleted_at IS NULL AND m.archived_at IS NULL
                AND m.status = 'aktiv'
                AND p.active = 1"
        );

        $pauses = self::allPauses();
        $result = [];

        foreach ($members as $m) {
            $months   = self::INTERVALS[(string) $m['interval']][0] ?? 1;
            $sinceRaw = (string) ($m['fee_since'] ?: $m['joined_on'] ?: $today->format('Y-m-d'));

            try {
                $since = new DateTimeImmutable($sinceRaw);
            } catch (\Exception) {
                $since = $today;
            }

            // Austritt begrenzt die Vorschau
            $ende = $bis;
            if (($m['left_on'] ?? null) !== null && (string) $m['left_on'] !== '') {
                try {
                    $austritt = new DateTimeImmutable((string) $m['left_on']);
                    if ($austritt < $ende) {
                        $ende = $austritt;
                    }
                } catch (\Exception) {
                }
            }

            $start   = self::periodStart(max($since, $today), $months);
            $periode = $start;

            while ($periode <= $ende) {
                $due = self::dueDate($periode, (int) $m['due_day']);

                if ($due > $today->format('Y-m-d') && $due <= $ende->format('Y-m-d')
                    && !self::isPausedOn($pauses, (int) $m['id'], $due)
                ) {
                    $existiert = Database::one(
                        'SELECT id FROM fee_entries WHERE member_id = ? AND period = ?',
                        [(int) $m['id'], $periode->format('Y-m')]
                    );

                    if ($existiert === null) {
                        $result[] = [
                            'member_id' => (int) $m['id'],
                            'period'    => $periode->format('Y-m'),
                            'due_date'  => $due,
                            'amount'    => self::memberAmountAt($m, $due),
                        ];
                    }
                }

                $periode = $periode->modify('+' . $months . ' months');
            }
        }

        return $result;
    }

    // ---------------------------------------------------------- Offene Liste --

    /**
     * Offene (unbezahlte) Beitragszeilen, aelteste zuerst.
     *
     * @param array<string,mixed> $filters q, plan_id, section_id, only_due
     * @param list<int>|null      $allowedSectionIds null = alle
     * @return list<array<string,mixed>>
     */
    public static function openEntries(array $filters = [], ?array $allowedSectionIds = null): array
    {
        [$where, $params] = self::openWhere($filters, $allowedSectionIds);

        if ($where === null) {
            return [];
        }

        return Database::all(
            "SELECT f.*, m.first_name, m.last_name, m.member_no,
                    p.name AS plan_name, p.interval AS plan_interval
               FROM fee_entries f
               JOIN members m ON m.id = f.member_id
               LEFT JOIN fee_plans p ON p.id = f.plan_id
              WHERE $where
              ORDER BY f.due_date, m.last_name COLLATE NOCASE, m.first_name COLLATE NOCASE",
            $params
        );
    }

    /**
     * @param array<string,mixed> $filters
     * @param list<int>|null      $allowedSectionIds
     * @return array{0:string|null,1:list<mixed>}
     */
    private static function openWhere(array $filters, ?array $allowedSectionIds): array
    {
        $conditions = ['f.paid = 0', 'm.deleted_at IS NULL', 'm.archived_at IS NULL'];
        $params     = [];

        if ($allowedSectionIds !== null) {
            if ($allowedSectionIds === []) {
                return [null, []];
            }

            $in           = implode(',', array_fill(0, count($allowedSectionIds), '?'));
            $conditions[] = "EXISTS (SELECT 1 FROM member_sections ms
                                      WHERE ms.member_id = m.id AND ms.section_id IN ($in))";
            $params       = array_merge($params, array_values($allowedSectionIds));
        }

        if (!empty($filters['only_due'])) {
            $conditions[] = "f.due_date <= date('now')";
        }

        if (!empty($filters['plan_id'])) {
            $conditions[] = 'f.plan_id = ?';
            $params[]     = (int) $filters['plan_id'];
        }

        if (!empty($filters['section_id'])) {
            $conditions[] = 'EXISTS (SELECT 1 FROM member_sections ms
                                      WHERE ms.member_id = m.id AND ms.section_id = ?)';
            $params[]     = (int) $filters['section_id'];
        }

        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            $like         = '%' . $q . '%';
            $conditions[] = '(m.last_name LIKE ? OR m.first_name LIKE ? OR m.member_no LIKE ?'
                          . " OR (m.first_name || ' ' || m.last_name) LIKE ?)";
            array_push($params, $like, $like, $like, $like);
        }

        return [implode(' AND ', $conditions), $params];
    }

    /** Kennzahlen fuer Uebersicht und Erinnerung. */
    public static function openStats(?array $allowedSectionIds = null): array
    {
        [$where, $params] = self::openWhere(['only_due' => 1], $allowedSectionIds);

        if ($where === null) {
            return ['count' => 0, 'sum' => 0.0, 'members' => 0];
        }

        $row = Database::one(
            "SELECT COUNT(*) AS n, COALESCE(SUM(f.amount), 0) AS summe,
                    COUNT(DISTINCT f.member_id) AS mitglieder
               FROM fee_entries f
               JOIN members m ON m.id = f.member_id
              WHERE $where",
            $params
        );

        return [
            'count'   => (int) ($row['n'] ?? 0),
            'sum'     => (float) ($row['summe'] ?? 0),
            'members' => (int) ($row['mitglieder'] ?? 0),
        ];
    }

    // -------------------------------------------------------------- Historie --

    /** @return list<array<string,mixed>> Beitragshistorie eines Mitglieds. */
    public static function entriesForMember(int $memberId): array
    {
        return Database::all(
            'SELECT f.*, p.name AS plan_name, u.username AS paid_by_name
               FROM fee_entries f
               LEFT JOIN fee_plans p ON p.id = f.plan_id
               LEFT JOIN users u ON u.id = f.paid_by
              WHERE f.member_id = ?
              ORDER BY f.period DESC',
            [$memberId]
        );
    }

    /** Markiert eine Beitragszeile als bezahlt und bucht sie ins Kassabuch. */
    public static function markPaid(int $entryId, ?float $paidAmount, ?string $paidOn, ?int $userId, string $note = '', ?int $methodId = null): void
    {
        Database::run(
            "UPDATE fee_entries
                SET paid = 1,
                    paid_on = ?,
                    paid_amount = COALESCE(?, amount),
                    paid_by = ?,
                    note = CASE WHEN ? <> '' THEN ? ELSE note END
              WHERE id = ?",
            [
                $paidOn ?: date('Y-m-d'),
                $paidAmount,
                $userId,
                $note,
                $note,
                $entryId,
            ]
        );

        LedgerRepo::bookFeeEntry($entryId, $userId, $methodId);
    }

    /** Setzt eine Zeile wieder auf offen und entfernt die Buchung. */
    public static function markOpen(int $entryId): void
    {
        Database::run(
            'UPDATE fee_entries
                SET paid = 0, paid_on = NULL, paid_amount = NULL, paid_by = NULL
              WHERE id = ?',
            [$entryId]
        );

        LedgerRepo::unbookFeeEntry($entryId);
    }
}
