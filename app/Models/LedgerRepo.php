<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * Buchhaltung (Kassabuch): Einnahmen und Ausgaben mit Kategorie.
 *
 * Beitragszahlungen werden von FeeRepo automatisch gebucht (fee_entry_id
 * gesetzt) und verschwinden wieder, wenn die Zahlung geoeffnet wird.
 */
final class LedgerRepo
{
    public const CAT_FEE        = 'Mitgliedsbeitrag';
    public const CAT_ENROLLMENT = 'Einschreibegebühr';

    /** @return list<string> Kategorien fuer Einnahmen bzw. Ausgaben. */
    public static function categories(string $type): array
    {
        $key = $type === 'ausgabe' ? 'ledger_categories_out' : 'ledger_categories_in';

        $default = $type === 'ausgabe'
            ? 'Miete/Betriebskosten;Internet/Telefon;Versicherung;Trainer/Prämie;Ausstattung/Geräte;Sonstige Ausgabe'
            : self::CAT_FEE . ';' . self::CAT_ENROLLMENT . ';Spende;Sonstige Einnahme';

        $values = array_values(array_filter(array_map(
            'trim',
            explode(';', Setting::get($key, $default))
        ), static fn (string $v): bool => $v !== ''));

        return $values === [] ? [$type === 'ausgabe' ? 'Sonstige Ausgabe' : 'Sonstige Einnahme'] : $values;
    }

    /**
     * Legt eine Buchung an.
     *
     * @param array<string,mixed> $data booked_on, type, category, text, amount,
     *                                  member_id?, fee_entry_id?, fixed_cost_id?,
     *                                  invoice_id?, payment_method_id?, created_by?
     */
    public static function add(array $data): int
    {
        return Database::insert('ledger_entries', [
            'booked_on'     => (string) ($data['booked_on'] ?: date('Y-m-d')),
            'type'          => $data['type'] === 'ausgabe' ? 'ausgabe' : 'einnahme',
            'category'      => (string) ($data['category'] ?? ''),
            'text'          => (string) ($data['text'] ?? ''),
            'amount'        => abs((float) ($data['amount'] ?? 0)),
            'member_id'     => $data['member_id'] ?? null,
            'fee_entry_id'  => $data['fee_entry_id'] ?? null,
            'fixed_cost_id' => $data['fixed_cost_id'] ?? null,
            'invoice_id'    => $data['invoice_id'] ?? null,
            'payment_method_id' => $data['payment_method_id'] ?? null,
            'created_by'    => $data['created_by'] ?? null,
        ]);
    }

    // ---------------------------------------------------------- Zahlungsarten --

    /** @return list<array<string,mixed>> */
    public static function paymentMethods(bool $onlyActive = false): array
    {
        $where = $onlyActive ? 'WHERE active = 1' : '';

        return Database::all(
            "SELECT * FROM payment_methods $where ORDER BY sort_order, name COLLATE NOCASE"
        );
    }

    /** Standard-Zahlungsart einer Gattung, z. B. 'bar' -> Barkassa. */
    public static function defaultMethodId(string $kind): ?int
    {
        $row = Database::one(
            'SELECT id FROM payment_methods WHERE kind = ? AND active = 1 ORDER BY protected DESC, sort_order LIMIT 1',
            [$kind]
        );

        return $row === null ? null : (int) $row['id'];
    }

    // -------------------------------------------------- Rechnungen (Mitglied) --

    /** Bucht eine bezahlte Mitglieder-Rechnung (idempotent). */
    public static function bookInvoice(int $invoiceId, ?int $userId): void
    {
        $invoice = Database::one(
            'SELECT i.*, m.first_name, m.last_name
               FROM member_invoices i
               JOIN members m ON m.id = i.member_id
              WHERE i.id = ? AND i.paid = 1',
            [$invoiceId]
        );

        if ($invoice === null) {
            return;
        }

        Database::run('DELETE FROM ledger_entries WHERE invoice_id = ?', [$invoiceId]);

        self::add([
            'booked_on'  => $invoice['paid_on'] ?: date('Y-m-d'),
            'type'       => 'einnahme',
            'category'   => (string) $invoice['category'],
            'text'       => sprintf(
                'Rechnung: %s – %s %s',
                (string) $invoice['text'],
                (string) $invoice['first_name'],
                (string) $invoice['last_name']
            ),
            'amount'     => (float) $invoice['amount'],
            'member_id'  => (int) $invoice['member_id'],
            'invoice_id' => $invoiceId,
            'payment_method_id' => $invoice['payment_method_id'] ?? null,
            'created_by' => $userId,
        ]);
    }

    /** Entfernt die Buchung(en) einer Rechnung (wieder geoeffnet/geloescht). */
    public static function unbookInvoice(int $invoiceId): void
    {
        Database::run('DELETE FROM ledger_entries WHERE invoice_id = ?', [$invoiceId]);
    }

    /** @return list<array<string,mixed>> Rechnungen eines Mitglieds. */
    public static function invoicesForMember(int $memberId): array
    {
        return Database::all(
            'SELECT i.*, pm.name AS payment_method_name
               FROM member_invoices i
               LEFT JOIN payment_methods pm ON pm.id = i.payment_method_id
              WHERE i.member_id = ?
              ORDER BY i.invoice_date DESC, i.id DESC',
            [$memberId]
        );
    }

    /** Bucht eine bezahlte Beitragszeile (idempotent). */
    public static function bookFeeEntry(int $feeEntryId, ?int $userId, ?int $methodId = null): void
    {
        $entry = Database::one(
            'SELECT f.*, m.first_name, m.last_name, p.name AS plan_name
               FROM fee_entries f
               JOIN members m ON m.id = f.member_id
               LEFT JOIN fee_plans p ON p.id = f.plan_id
              WHERE f.id = ? AND f.paid = 1',
            [$feeEntryId]
        );

        if ($entry === null) {
            return;
        }

        // Doppelte Buchungen vermeiden (z. B. erneutes Abhaken).
        Database::run('DELETE FROM ledger_entries WHERE fee_entry_id = ?', [$feeEntryId]);

        self::add([
            'booked_on'    => $entry['paid_on'] ?: date('Y-m-d'),
            'type'         => 'einnahme',
            'category'     => self::CAT_FEE,
            'text'         => sprintf(
                'Beitrag %s – %s %s',
                (string) $entry['period_label'],
                (string) $entry['first_name'],
                (string) $entry['last_name']
            ),
            'amount'       => (float) ($entry['paid_amount'] ?? $entry['amount']),
            'member_id'    => (int) $entry['member_id'],
            'fee_entry_id' => $feeEntryId,
            'payment_method_id' => $methodId ?? self::defaultMethodId('bar'),
            'created_by'   => $userId,
        ]);
    }

    /** Entfernt die Buchung(en) einer wieder geoeffneten Beitragszeile. */
    public static function unbookFeeEntry(int $feeEntryId): void
    {
        Database::run('DELETE FROM ledger_entries WHERE fee_entry_id = ?', [$feeEntryId]);
    }

    // -------------------------------------------------------------- Fixkosten --

    /** @return list<array<string,mixed>> */
    public static function fixedCosts(bool $onlyActive = false): array
    {
        $where = $onlyActive ? 'WHERE active = 1' : '';

        return Database::all(
            "SELECT * FROM fixed_costs $where ORDER BY active DESC, name COLLATE NOCASE"
        );
    }

    /**
     * Alle Faelligkeiten einer Fixkostenzeile in einem Zeitfenster –
     * Periodenraster wie bei den Beitragsarten (monatlich, quartalsweise,
     * halbjaehrlich, jaehrlich).
     *
     * @param array<string,mixed> $cost
     * @return list<array{due:\DateTimeImmutable,period:\DateTimeImmutable}>
     */
    private static function occurrences(array $cost, \DateTimeImmutable $bis): array
    {
        $months = FeeRepo::INTERVALS[(string) ($cost['interval'] ?? 'monatlich')][0] ?? 1;

        try {
            $seit = new \DateTimeImmutable(((string) $cost['since']) ?: 'today');
        } catch (\Exception) {
            $seit = new \DateTimeImmutable('today');
        }

        // Sicherheitsgrenze: hoechstens 3 Jahre rueckwirkend, 3 Jahre voraus.
        $untergrenze = new \DateTimeImmutable('today -3 years');
        if ($seit < $untergrenze) {
            $seit = $untergrenze;
        }

        $obergrenze = new \DateTimeImmutable('today +3 years');
        if ($bis > $obergrenze) {
            $bis = $obergrenze;
        }

        $periode = FeeRepo::periodStart($seit, $months);
        $result  = [];

        while ($periode <= $bis) {
            $tag = min((int) $cost['due_day'], (int) $periode->format('t'));
            $due = $periode->setDate((int) $periode->format('Y'), (int) $periode->format('n'), $tag);

            // Die erste Periode zaehlt erst ab "since" (kein rueckwirkender Start).
            if ($due >= $seit->modify('first day of this month') && $due <= $bis) {
                $result[] = ['due' => $due, 'period' => $periode];
            }

            $periode = $periode->modify('+' . $months . ' months');
        }

        return $result;
    }

    /**
     * Bucht faellige Fixkosten: je aktiver Fixkostenzeile und Periode eine
     * Buchung, sobald der Buchungstag erreicht ist. Laeuft beim Aufruf
     * der Buchhaltung – idempotent, ein Cronjob ist nicht noetig.
     *
     * @return int Anzahl neuer Buchungen
     */
    public static function generateFixedCosts(): int
    {
        $today   = new \DateTimeImmutable('today');
        $created = 0;

        foreach (self::fixedCosts(true) as $cost) {
            $months = FeeRepo::INTERVALS[(string) ($cost['interval'] ?? 'monatlich')][0] ?? 1;

            foreach (self::occurrences($cost, $today) as $vorkommen) {
                $exists = (int) Database::value(
                    "SELECT COUNT(*) FROM ledger_entries
                      WHERE fixed_cost_id = ? AND strftime('%Y-%m', booked_on) = ?",
                    [(int) $cost['id'], $vorkommen['period']->format('Y-m')]
                );

                if ($exists > 0) {
                    continue;
                }

                $due = $vorkommen['due']->format('Y-m-d');

                self::add([
                    'booked_on'     => $due,
                    'type'          => (string) $cost['type'],
                    'category'      => (string) $cost['category'],
                    'text'          => $cost['name'] . ' ' . FeeRepo::periodLabel($vorkommen['period'], $months),
                    'amount'        => FeeRepo::amountAt('fixed_cost', (int) $cost['id'], $due)
                        ?? (float) $cost['amount'],
                    'fixed_cost_id' => (int) $cost['id'],
                    // Fixkosten laufen typischerweise ueber die Bank.
                    'payment_method_id' => $cost['payment_method_id'] ?? self::defaultMethodId('bank'),
                ]);
                $created++;
            }
        }

        return $created;
    }

    // -------------------------------------------------------------- Prognose --

    /**
     * Geplante (noch nicht gebuchte) Betraege je Monat fuer einen Zeitraum:
     *
     *   - offene Beitragsforderungen (fee_entries, unbezahlt)
     *   - kuenftige Beitragsperioden aktiver Mitglieder (noch nicht erzeugt)
     *   - kuenftige Fixkosten-Faelligkeiten (Einnahmen wie Ausgaben)
     *
     * @return array<string,array{in:float,out:float}> Schluessel JJJJ-MM
     */
    public static function plannedByMonth(string $from, string $to): array
    {
        $plan  = [];
        $add   = static function (string $monat, string $typ, float $betrag) use (&$plan): void {
            $plan[$monat] ??= ['in' => 0.0, 'out' => 0.0];
            $plan[$monat][$typ === 'ausgabe' ? 'out' : 'in'] += $betrag;
        };
        $today = date('Y-m-d');

        // Offene Beitragsforderungen im Zeitraum
        foreach (Database::all(
            "SELECT strftime('%Y-%m', f.due_date) AS monat, SUM(f.amount) AS summe
               FROM fee_entries f
               JOIN members m ON m.id = f.member_id
              WHERE f.paid = 0 AND m.deleted_at IS NULL
                AND f.due_date >= ? AND f.due_date <= ?
              GROUP BY monat",
            [$from, $to]
        ) as $row) {
            $add((string) $row['monat'], 'einnahme', (float) $row['summe']);
        }

        // Kuenftige, noch nicht erzeugte Beitragsperioden
        foreach (FeeRepo::projectedEntries($to) as $projektion) {
            if ($projektion['due_date'] >= $from && $projektion['due_date'] <= $to) {
                $add(substr($projektion['due_date'], 0, 7), 'einnahme', $projektion['amount']);
            }
        }

        // Kuenftige Fixkosten (noch nicht gebucht)
        try {
            $bis = new \DateTimeImmutable($to);
        } catch (\Exception) {
            $bis = new \DateTimeImmutable('today');
        }

        foreach (self::fixedCosts(true) as $cost) {
            foreach (self::occurrences($cost, $bis) as $vorkommen) {
                $due = $vorkommen['due']->format('Y-m-d');

                if ($due <= $today || $due < $from || $due > $to) {
                    continue; // Vergangenes ist bereits gebucht
                }

                $betrag = FeeRepo::amountAt('fixed_cost', (int) $cost['id'], $due) ?? (float) $cost['amount'];

                $add($vorkommen['period']->format('Y-m'), (string) $cost['type'], $betrag);
            }
        }

        ksort($plan);

        return $plan;
    }

    // ------------------------------------------------------------ Auswertung --

    /**
     * Monatsuebersicht: je Monat Einnahmen, Ausgaben, Saldo.
     *
     * @return list<array{monat:string,ein:float,aus:float,saldo:float}>
     */
    public static function monthlySums(string $from, string $to): array
    {
        $rows = Database::all(
            "SELECT strftime('%Y-%m', booked_on) AS monat,
                    COALESCE(SUM(CASE WHEN type = 'einnahme' THEN amount ELSE 0 END), 0) AS ein,
                    COALESCE(SUM(CASE WHEN type = 'ausgabe'  THEN amount ELSE 0 END), 0) AS aus
               FROM ledger_entries
              WHERE booked_on >= ? AND booked_on <= ?
              GROUP BY monat
              ORDER BY monat",
            [$from, $to]
        );

        return array_map(static fn (array $r): array => [
            'monat' => (string) $r['monat'],
            'ein'   => (float) $r['ein'],
            'aus'   => (float) $r['aus'],
            'saldo' => (float) $r['ein'] - (float) $r['aus'],
        ], $rows);
    }

    /**
     * Einnahmen-/Ausgaben-Rechnung: Summen je Kategorie im Zeitraum.
     *
     * @return array{in:list<array{category:string,summe:float}>,out:list<array{category:string,summe:float}>}
     */
    public static function categorySums(string $from, string $to): array
    {
        $rows = Database::all(
            "SELECT type,
                    CASE WHEN category = '' THEN '(ohne Kategorie)' ELSE category END AS category,
                    SUM(amount) AS summe
               FROM ledger_entries
              WHERE booked_on >= ? AND booked_on <= ?
              GROUP BY type, category
              ORDER BY summe DESC",
            [$from, $to]
        );

        $result = ['in' => [], 'out' => []];

        foreach ($rows as $row) {
            $result[$row['type'] === 'ausgabe' ? 'out' : 'in'][] = [
                'category' => (string) $row['category'],
                'summe'    => (float) $row['summe'],
            ];
        }

        return $result;
    }

    /**
     * Buchungen suchen.
     *
     * @param array<string,mixed> $filters type, category, from, to, q, member_id
     * @return list<array<string,mixed>>
     */
    public static function search(array $filters = [], int $limit = 500): array
    {
        [$where, $params] = self::buildWhere($filters);

        return Database::all(
            "SELECT l.*, m.first_name, m.last_name, u.username AS created_by_name,
                    pm.name AS payment_method_name
               FROM ledger_entries l
               LEFT JOIN members m ON m.id = l.member_id
               LEFT JOIN users u ON u.id = l.created_by
               LEFT JOIN payment_methods pm ON pm.id = l.payment_method_id
              WHERE $where
              ORDER BY l.booked_on DESC, l.id DESC
              LIMIT $limit",
            $params
        );
    }

    /**
     * Summen (Einnahmen, Ausgaben, Saldo) fuer die gefilterte Ansicht.
     *
     * @param array<string,mixed> $filters
     * @return array{in:float,out:float,saldo:float,count:int}
     */
    public static function sums(array $filters = []): array
    {
        [$where, $params] = self::buildWhere($filters);

        $row = Database::one(
            "SELECT COUNT(*) AS n,
                    COALESCE(SUM(CASE WHEN type = 'einnahme' THEN amount ELSE 0 END), 0) AS ein,
                    COALESCE(SUM(CASE WHEN type = 'ausgabe'  THEN amount ELSE 0 END), 0) AS aus
               FROM ledger_entries l
              WHERE $where",
            $params
        );

        $in  = (float) ($row['ein'] ?? 0);
        $out = (float) ($row['aus'] ?? 0);

        return ['in' => $in, 'out' => $out, 'saldo' => $in - $out, 'count' => (int) ($row['n'] ?? 0)];
    }

    /** Gesamtsaldo (Kassastand) ueber alle Buchungen. */
    public static function balance(): float
    {
        return (float) Database::value(
            "SELECT COALESCE(SUM(CASE WHEN type = 'einnahme' THEN amount ELSE -amount END), 0)
               FROM ledger_entries"
        );
    }

    /** @return list<array<string,mixed>> Buchungen eines Mitglieds. */
    public static function forMember(int $memberId, int $limit = 100): array
    {
        return Database::all(
            "SELECT l.*, u.username AS created_by_name, pm.name AS payment_method_name
               FROM ledger_entries l
               LEFT JOIN users u ON u.id = l.created_by
               LEFT JOIN payment_methods pm ON pm.id = l.payment_method_id
              WHERE l.member_id = ?
              ORDER BY l.booked_on DESC, l.id DESC
              LIMIT $limit",
            [$memberId]
        );
    }

    /** Hat das Mitglied bereits eine Einschreibegebuehr bezahlt? */
    public static function hasEnrollmentFee(int $memberId): bool
    {
        return (int) Database::value(
            'SELECT COUNT(*) FROM ledger_entries WHERE member_id = ? AND category = ?',
            [$memberId, self::CAT_ENROLLMENT]
        ) > 0;
    }

    /**
     * @param array<string,mixed> $filters
     * @return array{0:string,1:list<mixed>}
     */
    private static function buildWhere(array $filters): array
    {
        $conditions = ['1 = 1'];
        $params     = [];

        if (in_array($filters['type'] ?? '', ['einnahme', 'ausgabe'], true)) {
            $conditions[] = 'l.type = ?';
            $params[]     = $filters['type'];
        }

        if (($filters['category'] ?? '') !== '') {
            $conditions[] = 'l.category = ?';
            $params[]     = (string) $filters['category'];
        }

        if (($filters['from'] ?? '') !== '') {
            $conditions[] = 'l.booked_on >= ?';
            $params[]     = (string) $filters['from'];
        }

        if (($filters['to'] ?? '') !== '') {
            $conditions[] = 'l.booked_on <= ?';
            $params[]     = (string) $filters['to'];
        }

        if (!empty($filters['member_id'])) {
            $conditions[] = 'l.member_id = ?';
            $params[]     = (int) $filters['member_id'];
        }

        if (!empty($filters['payment_method_id'])) {
            $conditions[] = 'l.payment_method_id = ?';
            $params[]     = (int) $filters['payment_method_id'];
        }

        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            $like         = '%' . $q . '%';
            $conditions[] = "(l.text LIKE ? OR l.category LIKE ?
                              OR EXISTS (SELECT 1 FROM members mm WHERE mm.id = l.member_id
                                          AND (mm.last_name LIKE ? OR mm.first_name LIKE ?)))";
            array_push($params, $like, $like, $like, $like);
        }

        return [implode(' AND ', $conditions), $params];
    }
}
