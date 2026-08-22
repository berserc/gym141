<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

final class PageRepo
{
    /** @return array<string,mixed>|null */
    public static function findBySlug(string $slug): ?array
    {
        return Database::one('SELECT * FROM pages WHERE slug = ?', [$slug]);
    }

    /** @return array<string,mixed>|null */
    public static function find(int $id): ?array
    {
        return Database::one('SELECT * FROM pages WHERE id = ?', [$id]);
    }

    /** @return list<array<string,mixed>> */
    public static function all(): array
    {
        return Database::all('SELECT * FROM pages ORDER BY sort_order, title COLLATE NOCASE');
    }

    /** @return list<array<string,mixed>> */
    public static function footerPages(): array
    {
        return Database::all(
            'SELECT slug, title FROM pages
              WHERE published = 1 AND in_footer = 1
              ORDER BY sort_order, title COLLATE NOCASE'
        );
    }
}
