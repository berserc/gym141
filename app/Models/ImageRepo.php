<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Core\Upload;

/**
 * Bildbibliothek: Bilder mit mehreren Schlagwoertern (Tags), Bildtext und Vorschau.
 * Tags werden kleingeschrieben und in der Spalte `tags` als ",tag1,tag2," abgelegt –
 * so reicht ein LIKE fuer die Suche, ohne Zwischentabelle.
 */
final class ImageRepo
{
    /** @return list<string> */
    public static function parseTags(string $input): array
    {
        $out = [];
        foreach (preg_split('/[,;\n]+/', $input) ?: [] as $tag) {
            $tag = mb_strtolower(trim((string) preg_replace('/\s+/', ' ', $tag)), 'UTF-8');
            if ($tag !== '' && !in_array($tag, $out, true)) {
                $out[] = $tag;
            }
        }

        return $out;
    }

    /** @param list<string> $tags */
    public static function packTags(array $tags): string
    {
        return $tags === [] ? '' : ',' . implode(',', $tags) . ',';
    }

    /** @return list<string> */
    public static function unpackTags(?string $packed): array
    {
        return array_values(array_filter(explode(',', (string) $packed), static fn (string $t): bool => $t !== ''));
    }

    /** @return array<string,mixed>|null */
    public static function find(int $id): ?array
    {
        $row = Database::one('SELECT * FROM library_images WHERE id = ?', [$id]);

        return $row === null ? null : self::hydrate($row);
    }

    /**
     * Suche: alle Woerter muessen in Tags, Bildtext oder Dateiname vorkommen; $tag filtert exakt.
     *
     * @return list<array<string,mixed>>
     */
    public static function search(string $q = '', string $tag = '', int $limit = 2000): array
    {
        $where  = [];
        $params = [];
        foreach (preg_split('/\s+/', mb_strtolower(trim($q), 'UTF-8')) ?: [] as $word) {
            if ($word === '') {
                continue;
            }
            $where[]  = "(tags LIKE ? OR lower(caption) LIKE ? OR lower(orig_name) LIKE ?)";
            $like     = '%' . str_replace(['%', '_'], ['\%', '\_'], $word) . '%';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }
        if ($tag !== '') {
            $where[]  = 'tags LIKE ?';
            $params[] = '%,' . str_replace(['%', '_'], ['\%', '\_'], mb_strtolower($tag, 'UTF-8')) . ',%';
        }
        $sql = 'SELECT * FROM library_images'
             . ($where !== [] ? ' WHERE ' . implode(' AND ', $where) : '')
             . ' ORDER BY id DESC LIMIT ' . $limit;

        return array_map([self::class, 'hydrate'], Database::all($sql, $params));
    }

    /** Alle Bilder, die ALLE genannten Tags tragen (fuer dynamische Galerien). @param list<string> $tags @return list<array<string,mixed>> */
    public static function byTags(array $tags, int $limit = 500): array
    {
        $tags = array_values(array_filter(array_map('trim', $tags)));
        if ($tags === []) {
            return [];
        }
        $where  = [];
        $params = [];
        foreach ($tags as $tag) {
            $where[]  = 'tags LIKE ?';
            $params[] = '%,' . str_replace(['%', '_'], ['\%', '\_'], mb_strtolower($tag, 'UTF-8')) . ',%';
        }

        return array_map([self::class, 'hydrate'], Database::all(
            'SELECT * FROM library_images WHERE ' . implode(' AND ', $where) . ' ORDER BY id DESC LIMIT ' . $limit,
            $params
        ));
    }

    /** Bilder in der Reihenfolge der uebergebenen IDs. @param list<int> $ids @return list<array<string,mixed>> */
    public static function byIds(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if ($ids === []) {
            return [];
        }
        $rows = Database::all(
            'SELECT * FROM library_images WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')',
            $ids
        );
        $byId = [];
        foreach ($rows as $row) {
            $byId[(int) $row['id']] = self::hydrate($row);
        }
        $out = [];
        foreach ($ids as $id) {
            if (isset($byId[$id])) {
                $out[] = $byId[$id];
            }
        }

        return $out;
    }

    /** Tag => Anzahl, haeufigste zuerst. @return array<string,int> */
    public static function tagCounts(): array
    {
        $counts = [];
        foreach (Database::all("SELECT tags FROM library_images WHERE tags <> ''") as $row) {
            foreach (self::unpackTags((string) $row['tags']) as $tag) {
                $counts[$tag] = ($counts[$tag] ?? 0) + 1;
            }
        }
        arsort($counts);

        return $counts;
    }

    public static function count(): int
    {
        return (int) Database::value('SELECT COUNT(*) FROM library_images');
    }

    /**
     * @param array{file:string,thumb:string,width:int,height:int} $img
     * @param list<string> $tags
     */
    public static function create(array $img, array $tags, string $caption, string $origName, ?int $userId): int
    {
        return Database::insert('library_images', [
            'file'        => $img['file'],
            'thumb'       => $img['thumb'],
            'width'       => $img['width'],
            'height'      => $img['height'],
            'caption'     => $caption,
            'tags'        => self::packTags($tags),
            'orig_name'   => mb_substr($origName, 0, 120),
            'uploaded_by' => $userId,
            'created_at'  => date('Y-m-d H:i:s'),
        ]);
    }

    /** @param list<string> $tags */
    public static function update(int $id, string $caption, array $tags): void
    {
        Database::update('library_images', $id, ['caption' => $caption, 'tags' => self::packTags($tags)]);
    }

    /** @param list<int> $ids @param list<string> $tags */
    public static function addTags(array $ids, array $tags): void
    {
        foreach (self::byIds($ids) as $img) {
            $merged = array_values(array_unique(array_merge($img['tags'], $tags)));
            Database::update('library_images', (int) $img['id'], ['tags' => self::packTags($merged)]);
        }
    }

    /** @param list<int> $ids @param list<string> $tags */
    public static function removeTags(array $ids, array $tags): void
    {
        foreach (self::byIds($ids) as $img) {
            $rest = array_values(array_diff($img['tags'], $tags));
            Database::update('library_images', (int) $img['id'], ['tags' => self::packTags($rest)]);
        }
    }

    /** Loescht Bild + Dateien; Verweise in Galerien fallen per ON DELETE CASCADE weg. */
    public static function delete(int $id): void
    {
        $img = self::find($id);
        if ($img === null) {
            return;
        }
        Database::run('DELETE FROM library_images WHERE id = ?', [$id]);
        Upload::delete((string) $img['file']);
        Upload::delete((string) $img['thumb']);
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private static function hydrate(array $row): array
    {
        $row['tags'] = self::unpackTags((string) ($row['tags'] ?? ''));

        return $row;
    }
}
