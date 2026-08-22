<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * Erfolge und Wettkaempfe: Kaempfe (Boxen, Muay Thai, Kickboxen),
 * Kraftdreikampf-Wettkaempfe und Auszeichnungen – inklusive Bilanzen und
 * Statistiken nach Alters- und Gewichtsklasse.
 */
final class SportRepo
{
    /** Kampfsportarten (fuer Kampf-Eintraege). */
    public const FIGHT_SPORTS = ['Boxen', 'Muay Thai', 'Kickboxen'];

    /** Stile, v. a. im Kickboxen. */
    public const STYLES = [
        'K-1', 'Low Kick', 'Full Contact', 'Light Contact', 'Kick Light',
        'Pointfighting', 'Olympisches Boxen', 'Muay Thai (Vollkontakt)',
    ];

    /** Uebliche Altersklassen (frei erweiterbar, reine Vorschlaege). */
    public const AGE_CLASSES = [
        'Schüler', 'Kadetten', 'Junioren', 'U21', 'Elite / Allgemeine Klasse',
        'Masters / Veteranen', 'Sub-Junior (KDK)', 'Junior (KDK)', 'Open (KDK)',
        'Master 1 (KDK)', 'Master 2 (KDK)',
    ];

    public const RESULTS = [
        'sieg'          => 'Sieg',
        'niederlage'    => 'Niederlage',
        'unentschieden' => 'Unentschieden',
        'kampflos'      => 'kampflos',
    ];

    public const METHODS = ['Punkte', 'KO', 'TKO', 'Aufgabe', 'Disqualifikation', 'Abbruch', 'Walkover'];

    // --------------------------------------------------------------- Kaempfe --

    /** @return list<array<string,mixed>> */
    public static function fightsForMember(int $memberId): array
    {
        return Database::all(
            'SELECT * FROM member_fights WHERE member_id = ? ORDER BY fight_date DESC, id DESC',
            [$memberId]
        );
    }

    /**
     * Bilanz eines Mitglieds je Sportart.
     *
     * @return list<array{sport:string,kaempfe:int,siege:int,niederlagen:int,unentschieden:int,ko:int}>
     */
    public static function recordForMember(int $memberId): array
    {
        return array_map(
            static fn (array $r): array => [
                'sport'         => (string) $r['sport'],
                'kaempfe'       => (int) $r['kaempfe'],
                'siege'         => (int) $r['siege'],
                'niederlagen'   => (int) $r['niederlagen'],
                'unentschieden' => (int) $r['unentschieden'],
                'ko'            => (int) $r['ko'],
            ],
            Database::all(
                "SELECT sport,
                        COUNT(*) AS kaempfe,
                        SUM(CASE WHEN result = 'sieg' THEN 1 ELSE 0 END) AS siege,
                        SUM(CASE WHEN result = 'niederlage' THEN 1 ELSE 0 END) AS niederlagen,
                        SUM(CASE WHEN result = 'unentschieden' THEN 1 ELSE 0 END) AS unentschieden,
                        SUM(CASE WHEN result = 'sieg' AND method IN ('KO', 'TKO') THEN 1 ELSE 0 END) AS ko
                   FROM member_fights
                  WHERE member_id = ?
                  GROUP BY sport
                  ORDER BY kaempfe DESC",
                [$memberId]
            )
        );
    }

    /** Kurzform "12-3-1" fuer Anzeigen. */
    public static function recordLabel(int $siege, int $niederlagen, int $unentschieden): string
    {
        return $siege . '-' . $niederlagen . '-' . $unentschieden;
    }

    // --------------------------------------------------------- Kraftdreikampf --

    /** @return list<array<string,mixed>> mit berechneten Bestwerten und Total. */
    public static function meetsForMember(int $memberId): array
    {
        $meets = Database::all(
            'SELECT * FROM member_meets WHERE member_id = ? ORDER BY meet_date DESC, id DESC',
            [$memberId]
        );

        return array_map([self::class, 'withTotals'], $meets);
    }

    /**
     * Ergaenzt beste gueltige Versuche und Total (ungueltige Versuche sind
     * negativ eingetragen).
     *
     * @param array<string,mixed> $meet
     * @return array<string,mixed>
     */
    public static function withTotals(array $meet): array
    {
        $best = static function (array $versuche): ?float {
            $gueltig = array_filter(
                array_map(static fn ($v): ?float => $v === null || $v === '' ? null : (float) $v, $versuche),
                static fn (?float $v): bool => $v !== null && $v > 0
            );

            return $gueltig === [] ? null : max($gueltig);
        };

        $meet['best_squat'] = $best([$meet['squat_1'], $meet['squat_2'], $meet['squat_3']]);
        $meet['best_bench'] = $best([$meet['bench_1'], $meet['bench_2'], $meet['bench_3']]);
        $meet['best_dead']  = $best([$meet['dead_1'], $meet['dead_2'], $meet['dead_3']]);

        $meet['total'] = ($meet['best_squat'] !== null && $meet['best_bench'] !== null && $meet['best_dead'] !== null)
            ? $meet['best_squat'] + $meet['best_bench'] + $meet['best_dead']
            : null;

        return $meet;
    }

    // ---------------------------------------------------------- Auszeichnungen --

    /** @return list<array<string,mixed>> */
    public static function awardsForMember(int $memberId): array
    {
        return Database::all(
            'SELECT * FROM member_awards WHERE member_id = ? ORDER BY award_date DESC, id DESC',
            [$memberId]
        );
    }

    // ------------------------------------------------------------- Statistiken --

    /**
     * Kampfbilanz gruppiert (z. B. nach sport, age_class oder weight_class).
     *
     * @param array<string,mixed> $filters sport, from, to
     * @return list<array<string,mixed>>
     */
    public static function fightStats(string $groupBy, array $filters = []): array
    {
        $allowed = ['sport' => 'sport', 'age_class' => 'age_class', 'weight_class' => 'weight_class'];
        $column  = $allowed[$groupBy] ?? 'sport';

        [$where, $params] = self::fightWhere($filters);

        return Database::all(
            "SELECT CASE WHEN $column = '' THEN '(ohne Angabe)' ELSE $column END AS gruppe,
                    COUNT(*) AS kaempfe,
                    COUNT(DISTINCT member_id) AS sportler,
                    SUM(CASE WHEN result = 'sieg' THEN 1 ELSE 0 END) AS siege,
                    SUM(CASE WHEN result = 'niederlage' THEN 1 ELSE 0 END) AS niederlagen,
                    SUM(CASE WHEN result = 'unentschieden' THEN 1 ELSE 0 END) AS unentschieden,
                    SUM(CASE WHEN result = 'sieg' AND method IN ('KO', 'TKO') THEN 1 ELSE 0 END) AS ko
               FROM member_fights
              WHERE $where
              GROUP BY gruppe
              ORDER BY kaempfe DESC, gruppe COLLATE NOCASE",
            $params
        );
    }

    /**
     * Kaempferliste mit Bilanz (fuer die Uebersicht).
     *
     * @param array<string,mixed> $filters
     * @return list<array<string,mixed>>
     */
    public static function fighterList(array $filters = []): array
    {
        [$where, $params] = self::fightWhere($filters);

        return Database::all(
            "SELECT f.member_id, m.first_name, m.last_name,
                    COUNT(*) AS kaempfe,
                    SUM(CASE WHEN f.result = 'sieg' THEN 1 ELSE 0 END) AS siege,
                    SUM(CASE WHEN f.result = 'niederlage' THEN 1 ELSE 0 END) AS niederlagen,
                    SUM(CASE WHEN f.result = 'unentschieden' THEN 1 ELSE 0 END) AS unentschieden,
                    SUM(CASE WHEN f.result = 'sieg' AND f.method IN ('KO', 'TKO') THEN 1 ELSE 0 END) AS ko,
                    MAX(f.fight_date) AS letzter_kampf
               FROM member_fights f
               JOIN members m ON m.id = f.member_id
              WHERE $where
              GROUP BY f.member_id
              ORDER BY kaempfe DESC, siege DESC",
            $params
        );
    }

    /**
     * @param array<string,mixed> $filters
     * @return array{0:string,1:list<mixed>}
     */
    private static function fightWhere(array $filters): array
    {
        $conditions = ['1 = 1'];
        $params     = [];

        if (($filters['sport'] ?? '') !== '') {
            $conditions[] = 'sport = ?';
            $params[]     = (string) $filters['sport'];
        }

        if (($filters['from'] ?? '') !== '') {
            $conditions[] = "fight_date >= ?";
            $params[]     = (string) $filters['from'];
        }

        if (($filters['to'] ?? '') !== '') {
            $conditions[] = "fight_date <= ?";
            $params[]     = (string) $filters['to'];
        }

        return [implode(' AND ', $conditions), $params];
    }

    /**
     * Kraftdreikampf-Uebersicht: Bestleistungen je Gruppe (Alters- oder
     * Gewichtsklasse) – Total wird in PHP aus den Versuchen gerechnet.
     *
     * @return list<array<string,mixed>>
     */
    public static function meetStats(string $groupBy): array
    {
        $column = $groupBy === 'weight_class' ? 'weight_class' : 'age_class';

        $meets = Database::all(
            'SELECT mm.*, m.first_name, m.last_name
               FROM member_meets mm
               JOIN members m ON m.id = mm.member_id'
        );

        $gruppen = [];

        foreach ($meets as $meet) {
            $meet   = self::withTotals($meet);
            $key    = (string) ($meet[$column] ?: '(ohne Angabe)');
            $gruppe = $gruppen[$key] ?? [
                'gruppe' => $key, 'starts' => 0, 'sportler' => [],
                'best_total' => null, 'best_total_name' => '',
                'best_points' => null, 'best_points_name' => '',
            ];

            $gruppe['starts']++;
            $gruppe['sportler'][(int) $meet['member_id']] = true;

            $name = $meet['first_name'] . ' ' . $meet['last_name'];

            if ($meet['total'] !== null && ($gruppe['best_total'] === null || $meet['total'] > $gruppe['best_total'])) {
                $gruppe['best_total']      = $meet['total'];
                $gruppe['best_total_name'] = $name;
            }

            if ($meet['points'] !== null && ($gruppe['best_points'] === null || (float) $meet['points'] > $gruppe['best_points'])) {
                $gruppe['best_points']      = (float) $meet['points'];
                $gruppe['best_points_name'] = $name;
            }

            $gruppen[$key] = $gruppe;
        }

        uasort($gruppen, static fn (array $a, array $b): int => $b['starts'] <=> $a['starts']);

        return array_values(array_map(static function (array $g): array {
            $g['sportler'] = count($g['sportler']);

            return $g;
        }, $gruppen));
    }

    /** @return list<array<string,mixed>> Beste Totals je Sportler (Bestenliste). */
    public static function meetBestList(): array
    {
        $meets = Database::all(
            'SELECT mm.*, m.first_name, m.last_name
               FROM member_meets mm
               JOIN members m ON m.id = mm.member_id'
        );

        $beste = [];

        foreach ($meets as $meet) {
            $meet = self::withTotals($meet);

            if ($meet['total'] === null) {
                continue;
            }

            $id = (int) $meet['member_id'];

            if (!isset($beste[$id]) || $meet['total'] > $beste[$id]['total']) {
                $beste[$id] = $meet;
            }
        }

        usort($beste, static fn (array $a, array $b): int => $b['total'] <=> $a['total']);

        return array_values($beste);
    }

    /** @return list<array<string,mixed>> Neueste Auszeichnungen (alle Mitglieder). */
    public static function latestAwards(int $limit = 50): array
    {
        return Database::all(
            "SELECT a.*, m.first_name, m.last_name
               FROM member_awards a
               JOIN members m ON m.id = a.member_id
              ORDER BY a.award_date DESC, a.id DESC
              LIMIT $limit"
        );
    }
}
