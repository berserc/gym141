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
        'slideshow' => ['Slideshow', 'Bilderwechsler: Bilder hochladen oder vom Server auswählen; Zeit, Pfeile und Punkte einstellbar.'],
        'video'   => ['Video', 'Eigenes Video (MP4/WebM) oder YouTube – datenschutzfreundlich erst nach Klick geladen.'],
        'schedule' => ['Wochenplan', 'Der Wochenplan aus der Terminverwaltung – wie auf der Standard-Startseite.'],
        'sections' => ['Trainingsgruppen', 'Kachelübersicht aller veröffentlichten Trainingsgruppen.'],
        'cta'      => ['Aufruf (CTA)', 'Hervorgehobene Box mit Titel, Text und Button – z. B. für das Probetraining.'],
    ];

    /**
     * WHERE-Bedingung für einen Block-Kontext: Seite, Sektionsseite oder
     * (beides null) Startseite.
     *
     * @return array{0:string,1:array<string,int>}
     */
    private static function contextWhere(?int $pageId, ?int $sectionId): array
    {
        if ($pageId !== null) {
            return ['page_id = :page', ['page' => $pageId]];
        }
        if ($sectionId !== null) {
            return ['section_id = :section', ['section' => $sectionId]];
        }

        return ['page_id IS NULL AND section_id IS NULL', []];
    }

    /** @return list<array<string,mixed>> */
    public static function forContext(?int $pageId, ?int $sectionId, bool $publishedOnly = false): array
    {
        [$where, $params] = self::contextWhere($pageId, $sectionId);
        $where .= $publishedOnly ? ' AND published = 1' : '';

        try {
            $rows = Database::all(
                "SELECT * FROM page_blocks WHERE $where ORDER BY sort_order, id",
                $params
            );
        } catch (\PDOException) {
            // Tabelle/Spalte fehlt noch (Migration nach einem Update
            // ausstehend): die Website darf daran niemals scheitern.
            return [];
        }

        foreach ($rows as &$row) {
            $row['config'] = self::decode((string) $row['config']);
        }

        return $rows;
    }

    /** @return list<array<string,mixed>> */
    public static function forPage(?int $pageId, bool $publishedOnly = false): array
    {
        return self::forContext($pageId, null, $publishedOnly);
    }

    /** @return list<array<string,mixed>> */
    public static function forSection(int $sectionId, bool $publishedOnly = false): array
    {
        return self::forContext(null, $sectionId, $publishedOnly);
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

    public static function create(?int $pageId, ?int $sectionId, string $type): int
    {
        if (!isset(self::TYPES[$type])) {
            throw new \InvalidArgumentException("Unbekannter Blocktyp: $type");
        }

        [$where, $params] = self::contextWhere($pageId, $sectionId);

        $max = (int) Database::value(
            "SELECT COALESCE(MAX(sort_order), 0) FROM page_blocks WHERE $where",
            $params
        );

        Database::run(
            'INSERT INTO page_blocks (page_id, section_id, type, sort_order)
             VALUES (:page, :section, :type, :sort)',
            ['page' => $pageId, 'section' => $sectionId, 'type' => $type, 'sort' => $max + 10]
        );

        return (int) Database::pdo()->lastInsertId();
    }

    /**
     * Setzt die komplette Reihenfolge eines Kontexts (Drag-and-drop):
     * nur Blöcke, die wirklich zum Kontext gehören, werden berücksichtigt.
     *
     * @param list<int> $ids Block-Ids in gewünschter Reihenfolge
     */
    public static function reorder(?int $pageId, ?int $sectionId, array $ids): void
    {
        [$where, $params] = self::contextWhere($pageId, $sectionId);

        $position = 0;
        foreach ($ids as $id) {
            $position += 10;
            Database::run(
                "UPDATE page_blocks SET sort_order = :sort, updated_at = datetime('now')
                 WHERE id = :id AND $where",
                $params + ['sort' => $position, 'id' => (int) $id]
            );
        }
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
            'INSERT INTO page_blocks (page_id, section_id, type, config, sort_order, published)
             VALUES (:page, :section, :type, :config, :sort, :published)',
            [
                'page'      => $block['page_id'],
                'section'   => $block['section_id'] ?? null,
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

        [$pageWhere, $params] = self::contextWhere(
            $block['page_id'] !== null ? (int) $block['page_id'] : null,
            ($block['section_id'] ?? null) !== null ? (int) $block['section_id'] : null
        );

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
