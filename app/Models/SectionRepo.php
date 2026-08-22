<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

final class SectionRepo
{
    /** Spalten, die ueber das Sektionsformular gepflegt werden. */
    public const EDITABLE = [
        'slug', 'name', 'club_name', 'tagline', 'description', 'training_info',
        'website', 'facebook', 'instagram', 'default_fee', 'sort_order', 'published',
    ];

    /** @return list<array<string,mixed>> */
    public static function allPublished(): array
    {
        return Database::all(
            'SELECT * FROM sections WHERE published = 1 ORDER BY sort_order, name COLLATE NOCASE'
        );
    }

    /** @return list<array<string,mixed>> */
    public static function all(): array
    {
        return Database::all('SELECT * FROM sections ORDER BY sort_order, name COLLATE NOCASE');
    }

    /**
     * Sektionen, die der angemeldete Benutzer sehen darf.
     *
     * @param list<int>|null $allowedIds null = alle
     * @return list<array<string,mixed>>
     */
    public static function forUser(?array $allowedIds): array
    {
        if ($allowedIds === null) {
            return self::all();
        }

        if ($allowedIds === []) {
            return [];
        }

        $in = implode(',', array_fill(0, count($allowedIds), '?'));

        return Database::all(
            "SELECT * FROM sections WHERE id IN ($in) ORDER BY sort_order, name COLLATE NOCASE",
            array_values($allowedIds)
        );
    }

    /** @return array<string,mixed>|null */
    public static function find(int $id): ?array
    {
        return Database::one('SELECT * FROM sections WHERE id = ?', [$id]);
    }

    /** @return array<string,mixed>|null */
    public static function findBySlug(string $slug): ?array
    {
        return Database::one('SELECT * FROM sections WHERE slug = ?', [$slug]);
    }

    /** @return list<array<string,mixed>> */
    public static function contacts(int $sectionId): array
    {
        return Database::all(
            'SELECT * FROM section_contacts WHERE section_id = ? ORDER BY sort_order, id',
            [$sectionId]
        );
    }

    /** Mitgliederzahlen je Sektion (nur aktive, nicht geloeschte). @return array<int,int> */
    public static function memberCounts(): array
    {
        $rows = Database::all(
            "SELECT section_id, COUNT(*) AS n
               FROM members
              WHERE deleted_at IS NULL AND status = 'aktiv'
              GROUP BY section_id"
        );

        $counts = [];
        foreach ($rows as $row) {
            $counts[(int) $row['section_id']] = (int) $row['n'];
        }

        return $counts;
    }

    /** Sorgt fuer einen eindeutigen Slug. */
    public static function uniqueSlug(string $slug, ?int $ignoreId = null): string
    {
        $slug = $slug !== '' ? $slug : 'sektion';
        $base = $slug;
        $i    = 2;

        while (true) {
            $existing = Database::one(
                'SELECT id FROM sections WHERE slug = ? AND (? IS NULL OR id <> ?)',
                [$slug, $ignoreId, $ignoreId]
            );

            if ($existing === null) {
                return $slug;
            }

            $slug = $base . '-' . $i++;
        }
    }

    public static function nextSortOrder(): int
    {
        return ((int) Database::value('SELECT COALESCE(MAX(sort_order), 0) FROM sections')) + 10;
    }
}
