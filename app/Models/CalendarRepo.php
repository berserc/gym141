<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * Wettkampf- und Eventkalender im Login-Bereich: mehrtaegige Termine,
 * Sichtbarkeit ueber Gruppen, An-/Abmeldungen der Mitglieder.
 */
final class CalendarRepo
{
    public const KINDS = ['wettkampf' => 'Wettkampf', 'event' => 'Event'];

    public const RECURS = [
        'keine'        => 'einmalig',
        'woechentlich' => 'wöchentlich',
        '14taegig'     => 'alle 14 Tage',
        'monatlich'    => 'monatlich',
    ];

    // ---------------------------------------------------------------- Gruppen --

    /** @return list<array<string,mixed>> */
    public static function groups(): array
    {
        return Database::all(
            'SELECT g.*, (SELECT COUNT(*) FROM member_group_members m WHERE m.group_id = g.id) AS member_count
               FROM member_groups g
              ORDER BY g.name COLLATE NOCASE'
        );
    }

    /** @return list<array<string,mixed>> Mitglieder einer Gruppe. */
    public static function groupMembers(int $groupId): array
    {
        return Database::all(
            'SELECT m.id, m.first_name, m.last_name, m.member_no, m.status, m.can_login
               FROM member_group_members gm
               JOIN members m ON m.id = gm.member_id
              WHERE gm.group_id = ? AND m.deleted_at IS NULL
              ORDER BY m.last_name COLLATE NOCASE, m.first_name COLLATE NOCASE',
            [$groupId]
        );
    }

    /** @return list<int> Gruppen-IDs eines Mitglieds. */
    public static function groupIdsForMember(int $memberId): array
    {
        return array_map(
            static fn (array $r): int => (int) $r['group_id'],
            Database::all('SELECT group_id FROM member_group_members WHERE member_id = ?', [$memberId])
        );
    }

    // ---------------------------------------------------------------- Termine --

    /**
     * Termine fuer die Verwaltung (alle), inkl. Gruppen und Antwort-Zaehlern.
     *
     * @return list<array<string,mixed>>
     */
    public static function allEvents(bool $nurKommende = false): array
    {
        $where = $nurKommende ? "WHERE COALESCE(e.ends_on, e.starts_on) >= date('now')" : '';

        $events = Database::all(
            "SELECT e.*, u.username AS created_by_name,
                    (SELECT COUNT(*) FROM calendar_signups s WHERE s.event_id = e.id AND s.status = 'zusage') AS zusagen,
                    (SELECT COUNT(*) FROM calendar_signups s WHERE s.event_id = e.id AND s.status = 'absage') AS absagen
               FROM calendar_events e
               LEFT JOIN users u ON u.id = e.created_by
              $where
              ORDER BY e.starts_on, e.id"
        );

        $gruppen = [];

        foreach (Database::all(
            'SELECT eg.event_id, g.id, g.name
               FROM calendar_event_groups eg
               JOIN member_groups g ON g.id = eg.group_id
              ORDER BY g.name COLLATE NOCASE'
        ) as $row) {
            $gruppen[(int) $row['event_id']][] = ['id' => (int) $row['id'], 'name' => (string) $row['name']];
        }

        return array_map(static function (array $event) use ($gruppen): array {
            $event['groups'] = $gruppen[(int) $event['id']] ?? [];

            return $event;
        }, $events);
    }

    /**
     * Sichtbare Termine fuer ein Mitglied: ohne Gruppenzuordnung fuer alle,
     * sonst nur fuer Mitglieder der zugeordneten Gruppen. Inklusive eigener
     * Antwort (status) und Zusagen-Zaehler.
     *
     * @return list<array<string,mixed>>
     */
    public static function eventsForMember(int $memberId, bool $nurKommende = true): array
    {
        $zeit = $nurKommende ? "AND COALESCE(e.ends_on, e.starts_on) >= date('now')" : '';

        return Database::all(
            "SELECT e.*,
                    (SELECT s.status FROM calendar_signups s
                      WHERE s.event_id = e.id AND s.member_id = :m) AS my_status,
                    (SELECT COUNT(*) FROM calendar_signups s
                      WHERE s.event_id = e.id AND s.status = 'zusage') AS zusagen
               FROM calendar_events e
              WHERE (
                        NOT EXISTS (SELECT 1 FROM calendar_event_groups eg WHERE eg.event_id = e.id)
                     OR EXISTS (SELECT 1 FROM calendar_event_groups eg
                                  JOIN member_group_members gm ON gm.group_id = eg.group_id
                                 WHERE eg.event_id = e.id AND gm.member_id = :m)
                    ) $zeit
              ORDER BY e.starts_on, e.id",
            ['m' => $memberId]
        );
    }

    /** Darf das Mitglied diesen Termin sehen? */
    public static function memberCanSee(int $eventId, int $memberId): bool
    {
        return (int) Database::value(
            'SELECT COUNT(*) FROM calendar_events e
              WHERE e.id = :e
                AND (
                        NOT EXISTS (SELECT 1 FROM calendar_event_groups eg WHERE eg.event_id = e.id)
                     OR EXISTS (SELECT 1 FROM calendar_event_groups eg
                                  JOIN member_group_members gm ON gm.group_id = eg.group_id
                                 WHERE eg.event_id = e.id AND gm.member_id = :m)
                    )',
            ['e' => $eventId, 'm' => $memberId]
        ) > 0;
    }

    // --------------------------------------------------------- Wiederholungen --

    /**
     * Vorkommen eines Termins im Zeitraum (inklusive einmaliger Termine).
     * Wiederholungen: recur woechentlich/14taegig/monatlich bis recur_until
     * (leer = offen, wird vom Zeitraum begrenzt).
     *
     * @param array<string,mixed> $event
     * @return list<array{on:string, ends:?string}>
     */
    public static function occurrences(array $event, string $from, string $to): array
    {
        $start = (string) $event['starts_on'];
        $ende  = ($event['ends_on'] ?? null) !== null && $event['ends_on'] !== '' ? (string) $event['ends_on'] : null;
        $recur = (string) ($event['recur'] ?? 'keine');

        if ($recur === '' || $recur === 'keine' || !isset(self::RECURS[$recur])) {
            return ($ende ?? $start) >= $from && $start <= $to
                ? [['on' => $start, 'ends' => $ende]]
                : [];
        }

        $dauer = $ende !== null ? max(0, (int) ((strtotime($ende) - strtotime($start)) / 86400)) : 0;
        $until = (string) ($event['recur_until'] ?? '');

        if ($until === '' || $until > $to) {
            $until = $to;
        }

        $anker  = new \DateTimeImmutable($start);
        $result = [];

        for ($i = 0; $i < 320; $i++) {
            $occ = match ($recur) {
                'woechentlich' => $anker->modify('+' . (7 * $i) . ' days'),
                '14taegig'     => $anker->modify('+' . (14 * $i) . ' days'),
                'monatlich'    => $anker->modify('+' . $i . ' months'),
            };

            // Monatlich am 29./30./31.: Monate ohne diesen Tag ueberspringen.
            if ($recur === 'monatlich' && $occ->format('j') !== $anker->format('j')) {
                continue;
            }

            $on = $occ->format('Y-m-d');

            if ($on > $until) {
                break;
            }

            $occEnde = $dauer > 0 ? $occ->modify('+' . $dauer . ' days')->format('Y-m-d') : null;

            if (($occEnde ?? $on) >= $from) {
                $result[] = ['on' => $on, 'ends' => $occEnde];
            }
        }

        return $result;
    }

    /** Lesbare Wiederholungsangabe, z. B. "wöchentlich bis 30.06.2027". */
    public static function recurLabel(array $event): string
    {
        $recur = (string) ($event['recur'] ?? 'keine');

        if ($recur === 'keine' || $recur === '' || !isset(self::RECURS[$recur])) {
            return '';
        }

        $label = self::RECURS[$recur];
        $until = (string) ($event['recur_until'] ?? '');

        return $until !== '' ? $label . ' bis ' . format_date($until) : $label;
    }

    // ------------------------------------------------------------ Abstimmung --

    /**
     * Abstimmung speichern; erneutes Waehlen derselben Antwort zieht sie
     * zurueck (wie bei WhatsApp).
     */
    public static function respond(int $eventId, int $memberId, string $occursOn, string $status, string $note = ''): void
    {
        $status = $status === 'absage' ? 'absage' : 'zusage';

        $bisher = Database::one(
            'SELECT status FROM calendar_signups WHERE event_id = ? AND member_id = ? AND occurs_on = ?',
            [$eventId, $memberId, $occursOn]
        );

        if ($bisher !== null && (string) $bisher['status'] === $status) {
            Database::run(
                'DELETE FROM calendar_signups WHERE event_id = ? AND member_id = ? AND occurs_on = ?',
                [$eventId, $memberId, $occursOn]
            );

            return;
        }

        Database::run(
            "INSERT INTO calendar_signups (event_id, member_id, occurs_on, status, note, updated_at)
             VALUES (?, ?, ?, ?, ?, datetime('now'))
             ON CONFLICT(event_id, member_id, occurs_on) DO UPDATE SET
                status = excluded.status, note = excluded.note, updated_at = excluded.updated_at",
            [$eventId, $memberId, $occursOn, $status, $note]
        );
    }

    /** @return list<array<string,mixed>> Antworten zu einem Termin (fuer die Verwaltung). */
    public static function signups(int $eventId): array
    {
        return Database::all(
            'SELECT s.*, m.first_name, m.last_name
               FROM calendar_signups s
               JOIN members m ON m.id = s.member_id
              WHERE s.event_id = ?
              ORDER BY s.occurs_on, s.status, m.last_name COLLATE NOCASE, m.first_name COLLATE NOCASE',
            [$eventId]
        );
    }

    /**
     * Abstimmungsdaten fuer eine Terminliste (WhatsApp-artige Anzeige):
     * [event_id][occurs_on][status] = Liste der Namen; my[event_id][occurs_on] = eigener Status.
     *
     * @param list<int> $eventIds
     * @return array{votes: array<int,array<string,array<string,list<string>>>>, my: array<int,array<string,string>>}
     */
    public static function pollData(array $eventIds, int $memberId): array
    {
        if ($eventIds === []) {
            return ['votes' => [], 'my' => []];
        }

        $in    = implode(',', array_fill(0, count($eventIds), '?'));
        $votes = [];
        $my    = [];

        foreach (Database::all(
            "SELECT s.event_id, s.occurs_on, s.status, s.member_id, m.first_name, m.last_name
               FROM calendar_signups s
               JOIN members m ON m.id = s.member_id
              WHERE s.event_id IN ($in)
              ORDER BY m.first_name COLLATE NOCASE, m.last_name COLLATE NOCASE",
            array_values($eventIds)
        ) as $row) {
            $eid = (int) $row['event_id'];
            $occ = (string) $row['occurs_on'];

            $votes[$eid][$occ][(string) $row['status']][] = $row['first_name'] . ' ' . mb_substr((string) $row['last_name'], 0, 1) . '.';

            if ((int) $row['member_id'] === $memberId) {
                $my[$eid][$occ] = (string) $row['status'];
            }
        }

        return ['votes' => $votes, 'my' => $my];
    }

    // ------------------------------------------------------------------- ICS --

    /**
     * iCalendar-Export (ganztaegige Termine, Wiederholungen als RRULE) –
     * fuer Download und Kalender-Abo.
     *
     * @param list<array<string,mixed>> $events
     */
    public static function ics(array $events): string
    {
        $esc = static fn (string $s): string => str_replace(
            ['\\', ';', ',', "\r\n", "\n"],
            ['\\\\', '\;', '\,', '\n', '\n'],
            $s
        );

        $zeilen = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Gym141//Termine//DE',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'X-WR-CALNAME:Gym141 Termine',
            'X-WR-TIMEZONE:Europe/Vienna',
        ];

        $stamp = gmdate('Ymd\THis\Z');

        foreach ($events as $event) {
            $start = (string) $event['starts_on'];
            $ende  = ($event['ends_on'] ?? null) !== null && $event['ends_on'] !== '' ? (string) $event['ends_on'] : $start;

            $zeilen[] = 'BEGIN:VEVENT';
            $zeilen[] = 'UID:gym141-termin-' . (int) $event['id'] . '@example.org';
            $zeilen[] = 'DTSTAMP:' . $stamp;
            $zeilen[] = 'DTSTART;VALUE=DATE:' . str_replace('-', '', $start);
            // DTEND ist bei Ganztagesterminen EXKLUSIV -> Folgetag.
            $zeilen[] = 'DTEND;VALUE=DATE:' . date('Ymd', (int) strtotime($ende . ' +1 day'));
            $zeilen[] = 'SUMMARY:' . $esc((self::KINDS[$event['kind']] ?? '') !== '' ? '[' . self::KINDS[$event['kind']] . '] ' . $event['title'] : (string) $event['title']);

            if ((string) ($event['location'] ?? '') !== '') {
                $zeilen[] = 'LOCATION:' . $esc((string) $event['location']);
            }

            if ((string) ($event['description'] ?? '') !== '') {
                $zeilen[] = 'DESCRIPTION:' . $esc((string) $event['description']);
            }

            $rrule = match ((string) ($event['recur'] ?? 'keine')) {
                'woechentlich' => 'FREQ=WEEKLY',
                '14taegig'     => 'FREQ=WEEKLY;INTERVAL=2',
                'monatlich'    => 'FREQ=MONTHLY',
                default        => '',
            };

            if ($rrule !== '') {
                if ((string) ($event['recur_until'] ?? '') !== '') {
                    $rrule .= ';UNTIL=' . str_replace('-', '', (string) $event['recur_until']);
                }

                $zeilen[] = 'RRULE:' . $rrule;
            }

            $zeilen[] = 'END:VEVENT';
        }

        $zeilen[] = 'END:VCALENDAR';

        return implode("\r\n", $zeilen) . "\r\n";
    }

    /** Zeitraum lesbar: "10.05.2026" oder "10.05. – 12.05.2026". */
    public static function rangeLabel(string $start, ?string $ende): string
    {
        if ($ende === null || $ende === '' || $ende === $start) {
            return format_date($start);
        }

        return date('d.m.', (int) strtotime($start)) . ' – ' . format_date($ende);
    }
}
