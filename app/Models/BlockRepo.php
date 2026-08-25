<?php

declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

/**
 * Inhaltsblöcke für Seiten und Startseite – das "Paragraphs"-System.
 *
 * Jeder Block hat einen Typ aus TYPES und eine JSON-Konfiguration, deren
 * Felder der Typ selbst definiert (Formular in admin/blocks/index.php,
 * Ausgabe in public/blocks/<typ>.php). Neue Typen brauchen genau drei Dinge:
 * einen TYPES-Eintrag, Formularfelder und ein Ausgabe-Template.
 */
final class BlockRepo
{
    /**
     * Verfügbare Blocktypen: type => [Label, Beschreibung].
     *
     * @var array<string,array{0:string,1:string}>
     */
    public const TYPES = [
        'text'    => ['Text', 'Fließtext mit Formatierung (Überschriften, Listen, Links).'],
        'hero'    => ['Hero', 'Großer Aufmacher mit Bild, Titel, Untertitel und Button.'],
        'image'   => ['Bild', 'Einzelnes Bild mit Bildunterschrift und Breitenwahl.'],
        'gallery' => ['Galerie', 'Bildergalerie mit Mehrfach-Upload und Großansicht (Lightbox).'],
        'video'   => ['Video', 'Eigenes Video (MP4/WebM) oder YouTube – datenschutzfreundlich erst nach Klick geladen.'],
        'schedule' => ['Wochenplan', 'Der Wochenplan aus der Terminverwaltung – wie auf der Standard-Startseite.'],
        'sections' => ['Trainingsgruppen', 'Kachelübersicht aller veröffentlichten Trainingsgruppen.'],
        'cta'      => ['Aufruf (CTA)', 'Hervorgehobene Box mit Titel, Text und Button – z. B. für das Probetraining.'],
    ];

    /** @return list<array<string,mixed>> */
    public static function forPage(?int $pageId, bool $publishedOnly = false): array
    {
        $where = $pageId === null ? 'page_id IS NULL' : 'page_id = :page';
        $where .= $publishedOnly ? ' AND published = 1' : '';

        $rows = Database::all(
            "SELECT * FROM page_blocks WHERE $where ORDER BY sort_order, id",
            $pageId === null ? [] : ['page' => $pageId]
        );

        foreach ($rows as &$row) {
            $row['config'] = self::decode((string) $row['config']);
        }

        return $rows;
    }

    /** @return array<string,mixed>|null */
    public static function find(int $id): ?array
    {
        $row = Database::one('SELECT * FROM page_blocks WHERE id = :id', ['id' => $id]);

        if ($row !== null) {
            $row['config'] = self::decode((string) $row['config']);
        }

        return $row;
    }

    public static function create(?int $pageId, string $type): int
    {
        if (!isset(self::TYPES[$type])) {
            throw new \InvalidArgumentException("Unbekannter Blocktyp: $type");
        }

        $max = (int) Database::value(
            'SELECT COALESCE(MAX(sort_order), 0) FROM page_blocks WHERE '
            . ($pageId === null ? 'page_id IS NULL' : 'page_id = :page'),
            $pageId === null ? [] : ['page' => $pageId]
        );

        Database::run(
            'INSERT INTO page_blocks (page_id, type, sort_order) VALUES (:page, :type, :sort)',
            ['page' => $pageId, 'type' => $type, 'sort' => $max + 10]
        );

        return (int) Database::pdo()->lastInsertId();
    }

    /** @param array<string,mixed> $config */
    public static function saveConfig(int $id, array $config): void
    {
        Database::run(
            "UPDATE page_blocks SET config = :config, updated_at = datetime('now') WHERE id = :id",
            ['config' => json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'id' => $id]
        );
    }

    /**
     * Dupliziert einen Block samt Konfiguration direkt hinter das Original.
     * Hochgeladene Dateien werden mitkopiert, damit Original und Kopie
     * unabhängig voneinander bearbeitet und gelöscht werden können.
     */
    public static function duplicate(int $id): ?int
    {
        $block = self::find($id);
        if ($block === null) {
            return null;
        }

        $config = self::copyConfigFiles((array) $block['config']);

        Database::run(
            'INSERT INTO page_blocks (page_id, type, config, sort_order, published)
             VALUES (:page, :type, :config, :sort, :published)',
            [
                'page'      => $block['page_id'],
                'type'      => (string) $block['type'],
                'config'    => json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'sort'      => (int) $block['sort_order'] + 5,
                'published' => (int) $block['published'],
            ]
        );

        return (int) Database::pdo()->lastInsertId();
    }

    /**
     * Kopiert alle in einer Block-Konfiguration referenzierten Upload-Dateien
     * und ersetzt die Pfade durch die Kopien.
     *
     * @param array<string,mixed> $config
     * @return array<string,mixed>
     */
    private static function copyConfigFiles(array $config): array
    {
        foreach (['image', 'video', 'poster', 'file'] as $feld) {
            if (is_string($config[$feld] ?? null) && $config[$feld] !== '') {
                $config[$feld] = self::copyUpload((string) $config[$feld]);
            }
        }

        if (is_array($config['images'] ?? null)) {
            foreach ($config['images'] as &$bild) {
                if (is_string($bild['file'] ?? null) && $bild['file'] !== '') {
                    $bild['file'] = self::copyUpload((string) $bild['file']);
                }
            }
        }

        return $config;
    }

    /** @return string Pfad der Kopie (bzw. der alte Pfad, wenn Kopieren scheitert) */
    private static function copyUpload(string $relativ): string
    {
        $basis = rtrim((string) \App\Core\Config::get('upload_dir'), '/\\');
        $quelle = $basis . '/' . ltrim($relativ, '/');

        if (str_contains($relativ, '..') || !is_file($quelle)) {
            return $relativ;
        }

        $info = pathinfo($relativ);
        $neu  = ($info['dirname'] !== '.' ? $info['dirname'] . '/' : '')
              . $info['filename'] . '-kopie-' . substr(uniqid(), -6)
              . (isset($info['extension']) ? '.' . $info['extension'] : '');

        return @copy($quelle, $basis . '/' . $neu) ? $neu : $relativ;
    }

    public static function togglePublished(int $id): void
    {
        Database::run(
            "UPDATE page_blocks SET published = 1 - published, updated_at = datetime('now') WHERE id = :id",
            ['id' => $id]
        );
    }

    /** Tauscht die Position mit dem Nachbarn in der angegebenen Richtung. */
    public static function move(int $id, string $richtung): void
    {
        $block = self::find($id);
        if ($block === null) {
            return;
        }

        $pageWhere = $block['page_id'] === null ? 'page_id IS NULL' : 'page_id = :page';
        $params    = $block['page_id'] === null ? [] : ['page' => (int) $block['page_id']];

        $vergleich = $richtung === 'hoch' ? '<' : '>';
        $sortier   = $richtung === 'hoch' ? 'DESC' : 'ASC';

        $nachbar = Database::one(
            "SELECT id, sort_order FROM page_blocks
             WHERE $pageWhere AND (sort_order $vergleich :sort OR (sort_order = :sort AND id $vergleich :id))
             ORDER BY sort_order $sortier, id $sortier LIMIT 1",
            $params + ['sort' => (int) $block['sort_order'], 'id' => $id]
        );

        if ($nachbar === null) {
            return;
        }

        Database::run('UPDATE page_blocks SET sort_order = :s WHERE id = :id',
            ['s' => (int) $nachbar['sort_order'], 'id' => $id]);
        Database::run('UPDATE page_blocks SET sort_order = :s WHERE id = :id',
            ['s' => (int) $block['sort_order'], 'id' => (int) $nachbar['id']]);
    }

    public static function delete(int $id): void
    {
        Database::run('DELETE FROM page_blocks WHERE id = :id', ['id' => $id]);
    }

    /** @return array<string,mixed> */
    private static function decode(string $json): array
    {
        $data = json_decode($json, true);

        return is_array($data) ? $data : [];
    }
}
