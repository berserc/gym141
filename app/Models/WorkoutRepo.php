<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * Trainingseinheiten, die die Mitglieder-App uebermittelt hat (member_workouts).
 * Kopfdaten in Spalten, die komplette Einheit (Herzfrequenz, GPS) als JSON in data.
 */
final class WorkoutRepo
{
    public const TYPES = [
        'laufen'    => 'Laufen',
        'radfahren' => 'Radfahren',
        'gehen'     => 'Gehen / Wandern',
        'training'  => 'Training',
        'sonstiges' => 'Sonstiges',
    ];

    /** Kopfdaten eines Mitglieds, neueste zuerst. @return list<array<string,mixed>> */
    public static function forMember(int $memberId, int $limit = 200): array
    {
        try {
            return Database::all(
                'SELECT id, uid, type, title, exercise, note, started_at, duration_s, distance_m, avg_hr, max_hr, device, created_at
                   FROM member_workouts WHERE member_id = ? ORDER BY started_at DESC LIMIT ' . $limit,
                [$memberId]
            );
        } catch (\PDOException) {
            return []; // Tabelle fehlt noch (Migration ausstehend)
        }
    }

    /** Eine Einheit inkl. Rohdaten (Schluessel `workout` = dekodiertes JSON). @return array<string,mixed>|null */
    public static function find(int $memberId, string $uid): ?array
    {
        $row = Database::one('SELECT * FROM member_workouts WHERE member_id = ? AND uid = ?', [$memberId, $uid]);
        if ($row === null) {
            return null;
        }
        $row['workout'] = json_decode((string) $row['data'], true) ?: [];

        return $row;
    }

    public static function typeLabel(string $type): string
    {
        return self::TYPES[$type] ?? 'Sonstiges';
    }

    /** 1:23:45 bzw. 23:45 */
    public static function duration(int $seconds): string
    {
        $h = intdiv($seconds, 3600);
        $m = intdiv($seconds % 3600, 60);
        $s = $seconds % 60;

        return $h > 0 ? sprintf('%d:%02d:%02d', $h, $m, $s) : sprintf('%d:%02d', $m, $s);
    }

    /** Tempo in min/km ('' ohne Strecke) */
    public static function pace(int $seconds, int $meters): string
    {
        if ($meters < 50 || $seconds <= 0) {
            return '';
        }
        $minPerKm = $seconds / 60 / ($meters / 1000);

        return sprintf('%d:%02d min/km', (int) $minPerKm, (int) round(fmod($minPerKm, 1) * 60));
    }
}
