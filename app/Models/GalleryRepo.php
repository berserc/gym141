<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * Galerien: benannte Bildersammlungen aus der Bildbibliothek mit eigener
 * oeffentlicher Seite (/galerie/{slug}). Reihenfolge in gallery_images.sort_order.
 */
final class GalleryRepo
{
    /** @return list<array<string,mixed>> */
    public static function all(bool $publishedOnly = false): array
    {
        $rows = Database::all(
            'SELECT g.*, (SELECT COUNT(*) FROM gallery_images gi WHERE gi.gallery_id = g.id) AS image_count,'
            . ' (SELECT thumb FROM library_images li WHERE li.id = COALESCE(g.cover_image_id,'
            . ' (SELECT image_id FROM gallery_images gi2 WHERE gi2.gallery_id = g.id ORDER BY gi2.sort_order, gi2.image_id LIMIT 1))) AS cover_thumb'
            . ' FROM galleries g' . ($publishedOnly ? ' WHERE g.published = 1' : '')
            . ' ORDER BY g.sort_order, g.id DESC'
        );

        return $rows;
    }

    /** @return array<string,mixed>|null */
    public static function find(int $id): ?array
    {
        return Database::one('SELECT * FROM galleries WHERE id = ?', [$id]);
    }

    /** @return array<string,mixed>|null */
    public static function findBySlug(string $slug, bool $publishedOnly = true): ?array
    {
        return Database::one(
            'SELECT * FROM galleries WHERE slug = ?' . ($publishedOnly ? ' AND published = 1' : ''),
            [$slug]
        );
    }

    /** Bilder einer Galerie in Reihenfolge. @return list<array<string,mixed>> */
    public static function images(int $galleryId): array
    {
        $ids = array_map(
            static fn (array $r): int => (int) $r['image_id'],
            Database::all('SELECT image_id FROM gallery_images WHERE gallery_id = ? ORDER BY sort_order, image_id', [$galleryId])
        );

        return ImageRepo::byIds($ids);
    }

    /** @return list<int> */
    public static function imageIds(int $galleryId): array
    {
        return array_map(static fn (array $i): int => (int) $i['id'], self::images($galleryId));
    }

    /** @param array<string,mixed> $data */
    public static function create(array $data): int
    {
        $data['created_at'] = $data['updated_at'] = date('Y-m-d H:i:s');

        return Database::insert('galleries', $data);
    }

    /** @param array<string,mixed> $data */
    public static function update(int $id, array $data): void
    {
        $data['updated_at'] = date('Y-m-d H:i:s');
        Database::update('galleries', $id, $data);
    }

    /** @param list<int> $imageIds */
    public static function setImages(int $galleryId, array $imageIds): void
    {
        Database::transaction(static function () use ($galleryId, $imageIds): void {
            Database::run('DELETE FROM gallery_images WHERE gallery_id = ?', [$galleryId]);
            $pos = 0;
            foreach (array_values(array_unique(array_filter(array_map('intval', $imageIds)))) as $imageId) {
                Database::run(
                    'INSERT OR IGNORE INTO gallery_images (gallery_id, image_id, sort_order) VALUES (?, ?, ?)',
                    [$galleryId, $imageId, $pos++]
                );
            }
        });
    }

    public static function delete(int $id): void
    {
        Database::run('DELETE FROM galleries WHERE id = ?', [$id]);
    }

    public static function uniqueSlug(string $wunsch, ?int $ignoreId = null): string
    {
        $base = slugify($wunsch) ?: 'galerie';
        $slug = $base;
        $n    = 2;
        while (Database::value('SELECT id FROM galleries WHERE slug = ? AND id <> ?', [$slug, (int) $ignoreId]) !== null) {
            $slug = $base . '-' . $n++;
        }

        return $slug;
    }
}
