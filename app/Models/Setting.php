<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/** Key/Value-Einstellungen (Vereinsdaten, Startseitentexte, Beitragsjahr …). */
final class Setting
{
    /** @var array<string,string>|null */
    private static ?array $cache = null;

    /** @return array<string,string> */
    public static function all(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        $values = [];
        foreach (Database::all('SELECT key, value FROM settings') as $row) {
            $values[(string) $row['key']] = (string) $row['value'];
        }

        return self::$cache = $values;
    }

    public static function get(string $key, string $default = ''): string
    {
        return self::all()[$key] ?? $default;
    }

    public static function set(string $key, string $value): void
    {
        Database::run(
            'INSERT INTO settings (key, value) VALUES (?, ?)
             ON CONFLICT(key) DO UPDATE SET value = excluded.value',
            [$key, $value]
        );

        self::$cache = null;
    }

    /** @param array<string,string> $values */
    public static function setMany(array $values): void
    {
        foreach ($values as $key => $value) {
            self::set($key, $value);
        }
    }

    /** Aktuelles Beitragsjahr; standardmaessig das laufende Kalenderjahr. */
    public static function feeYear(): int
    {
        $year = (int) self::get('fee_year', '0');

        return $year > 0 ? $year : (int) date('Y');
    }

    /**
     * Waehlbare Mitgliedsbeitraege in Euro.
     * Die Vorgabe stammt aus den Mitgliederlisten 2026 der Sektionen.
     *
     * @return list<float>
     */
    public static function feeOptions(): array
    {
        $raw = self::get('fee_options', '0;8;10;13;20');

        $values = array_map(
            static fn (string $v): float => (float) str_replace(',', '.', trim($v)),
            preg_split('/[;,\s]+/', $raw) ?: []
        );

        $values = array_values(array_unique(array_filter(
            $values,
            static fn (float $v): bool => $v >= 0
        )));

        sort($values);

        return $values === [] ? [0.0] : $values;
    }
}
