<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Csrf;
use App\Core\Flash;
use App\Core\Upload;
use App\Core\Url;
use App\Core\View;
use App\Models\BlockRepo;
use App\Models\PageRepo;

/**
 * Verwaltung der Inhaltsblöcke ("Paragraphs").
 *
 * URL-Konvention /admin/inhalt/{page}:
 *   0        Startseite
 *   <id>     redaktionelle Seite (pages)
 *   s<id>    Sektionsseite (sections)
 */
final class BlockAdminController
{
    public function index(array $args): void
    {
        AuthController::requireRole('superuser');

        [$pageId, $sectionId, $page, $section] = $this->resolveContext($args);

        View::display('admin/blocks/index', [
            'title'     => 'Inhalt: ' . ($page['title'] ?? $section['name'] ?? 'Startseite'),
            'pageId'    => $pageId,
            'sectionId' => $sectionId,
            'page'      => $page,
            'section'   => $section,
            'blocks'    => BlockRepo::forContext($pageId, $sectionId),
            'types'     => BlockRepo::TYPES,
        ], 'layouts/admin');
    }

    public function store(array $args): void
    {
        AuthController::requireRole('superuser');
        Csrf::verify();

        [$pageId, $sectionId] = $this->resolveContext($args);
        $type = post('type');

        if (!isset(BlockRepo::TYPES[$type])) {
            Flash::error('Unbekannter Blocktyp.');
        } else {
            $id = BlockRepo::create($pageId, $sectionId, $type);
            Flash::success(BlockRepo::TYPES[$type][0] . '-Block hinzugefügt – jetzt befüllen und speichern.');
        }

        $this->backTo(self::contextKey($pageId, $sectionId), $id ?? null);
    }

    /** Drag-and-drop: komplette Reihenfolge eines Kontexts speichern. */
    public function reorder(array $args): void
    {
        AuthController::requireRole('superuser');
        Csrf::verify();

        [$pageId, $sectionId] = $this->resolveContext($args);

        $ids = array_map('intval', (array) ($_POST['ids'] ?? []));
        BlockRepo::reorder($pageId, $sectionId, $ids);

        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['ok' => true]);
    }

    /** Startseiten-Option: Standardaufbau ausblenden, nur Blöcke zeigen. */
    public function options(array $args): void
    {
        AuthController::requireRole('superuser');
        Csrf::verify();

        [$pageId, $sectionId] = $this->resolveContext($args);

        if ($pageId === null && $sectionId === null) {
            \App\Models\Setting::set('home_blocks_only', post_bool('blocks_only') === 1 ? '1' : '0');
            Flash::success('Startseiten-Aufbau gespeichert.');
        }

        $this->backTo(self::contextKey($pageId, $sectionId));
    }

    public function update(array $args): void
    {
        AuthController::requireRole('superuser');
        Csrf::verify();

        $block = $this->requireBlock($args);
        $id    = (int) $block['id'];
        $cfg   = $block['config'];

        // Ankerpunkt (alle Blocktypen): macht den Block ueber einen
        // Menuepunkt /#anker anspringbar (nur Buchstaben/Ziffern/-).
        if (array_key_exists('anchor', $_POST)) {
            $cfg['anchor'] = (string) preg_replace('/[^a-z0-9\-]/', '', mb_strtolower(trim(post('anchor'))));
        }

        switch ((string) $block['type']) {
            case 'text':
                $cfg['html'] = safe_html((string) ($_POST['html'] ?? ''));
                break;

            case 'hero':
                $cfg['title']        = trim(post('title'));
                $cfg['text']         = trim(post('text'));
                $cfg['button_label'] = trim(post('button_label'));
                $cfg['button_url']   = trim(post('button_url'));
                $cfg['size']         = post('size') === 'gross' ? 'gross' : 'normal';
                $cfg['image']        = $this->bild($id, 'image', $cfg['image'] ?? '', 2000);

                if (post_bool('video_clear') === 1) {
                    Upload::delete((string) ($cfg['video'] ?? ''));
                    $cfg['video'] = '';
                } else {
                    $neuesVideo = Upload::video($_FILES['video'] ?? null, 'bloecke', 'block-' . $id . '-hero');
                    if ($neuesVideo !== null && ($cfg['video'] ?? '') !== '') {
                        Upload::delete((string) $cfg['video']);
                    }
                    $cfg['video'] = $neuesVideo ?? (string) ($cfg['video'] ?? '');
                }
                break;

            case 'image':
                $cfg['caption'] = trim(post('caption'));
                $breite         = post('width');
                $cfg['width']   = in_array($breite, ['normal', 'schmal', 'voll'], true) ? $breite : 'normal';
                $cfg['image']   = $this->bild($id, 'image', $cfg['image'] ?? '', 1800);
                break;

            case 'gallery':
                $spalten        = (int) post('columns', '3');
                $cfg['columns'] = in_array($spalten, [2, 3, 4], true) ? $spalten : 3;
                $cfg['images']  = $this->galerieBilder($id, is_array($cfg['images'] ?? null) ? $cfg['images'] : []);
                break;

            case 'slideshow':
                $cfg['images'] = $this->galerieBilder($id, is_array($cfg['images'] ?? null) ? $cfg['images'] : []);

                // Zusaetzlich: bereits am Server liegende Bilder uebernehmen
                // (Mehrfachauswahl aus der Galerie-Ansicht des Formulars).
                foreach ((array) ($_POST['server_images'] ?? []) as $pfad) {
                    $pfad = self::sichererUploadPfad((string) $pfad);

                    if ($pfad !== null && !in_array($pfad, array_column($cfg['images'], 'file'), true)) {
                        $cfg['images'][] = ['file' => $pfad, 'caption' => ''];
                    }
                }

                $sekunden        = (float) str_replace(',', '.', post('interval', '5'));
                $cfg['interval'] = $sekunden <= 0 ? 0 : (int) round(max(2, min(60, $sekunden)) * 1000);
                $cfg['arrows']   = post_bool('arrows');
                $cfg['bullets']  = post_bool('bullets');
                $cfg['width']    = post('width') === 'voll' ? 'voll' : 'normal';
                $cfg['effect']   = post('effect') === 'slide' ? 'slide' : 'fade';
                break;

            case 'video':
                $cfg['caption'] = trim(post('caption'));
                $cfg['youtube'] = $this->youtubeId(trim(post('youtube')));
                $cfg['poster']  = $this->bild($id, 'poster', $cfg['poster'] ?? '', 1600);

                if (post_bool('file_clear') === 1) {
                    Upload::delete((string) ($cfg['file'] ?? ''));
                    $cfg['file'] = '';
                } else {
                    $neu = Upload::video($_FILES['file'] ?? null, 'bloecke', 'block-' . $id . '-video');
                    if ($neu !== null && ($cfg['file'] ?? '') !== '') {
                        Upload::delete((string) $cfg['file']);
                    }
                    $cfg['file'] = $neu ?? (string) ($cfg['file'] ?? '');
                }
                break;

            case 'schedule':
                $cfg['title'] = trim(post('title'));
                break;

            case 'sections':
                $cfg['title'] = trim(post('title'));
                break;

            case 'cta':
                $cfg['title']        = trim(post('title'));
                $cfg['text']         = trim(post('text'));
                $cfg['button_label'] = trim(post('button_label'));
                $cfg['button_url']   = trim(post('button_url'));
                $cfg['whatsapp']     = post_bool('whatsapp');
                break;
        }

        BlockRepo::saveConfig($id, $cfg);
        Flash::success('Block gespeichert.');
        $this->backTo(self::blockContextKey($block), $id);
    }

    public function move(array $args): void
    {
        AuthController::requireRole('superuser');
        Csrf::verify();

        $block = $this->requireBlock($args);
        BlockRepo::move((int) $block['id'], post('richtung') === 'hoch' ? 'hoch' : 'runter');

        $this->backTo(self::blockContextKey($block), (int) $block['id']);
    }

    public function duplicate(array $args): void
    {
        AuthController::requireRole('superuser');
        Csrf::verify();

        $block = $this->requireBlock($args);
        $neuId = BlockRepo::duplicate((int) $block['id']);
        Flash::success('Block dupliziert.');

        $this->backTo(self::blockContextKey($block), $neuId);
    }

    public function toggle(array $args): void
    {
        AuthController::requireRole('superuser');
        Csrf::verify();

        $block = $this->requireBlock($args);
        BlockRepo::togglePublished((int) $block['id']);

        $this->backTo(self::blockContextKey($block), (int) $block['id']);
    }

    public function destroy(array $args): void
    {
        AuthController::requireRole('superuser');
        Csrf::verify();

        $block = $this->requireBlock($args);
        BlockRepo::delete((int) $block['id']);
        Flash::success('Block gelöscht.');

        $this->backTo(self::blockContextKey($block));
    }

    // ---------------------------------------------------------------- intern --

    /**
     * Bild-Upload eines Blocks: neue Datei ersetzt die alte; ohne Upload
     * bleibt der bisherige Pfad. Checkbox <feld>_clear entfernt das Bild.
     *
     * @return string Pfad relativ zum Upload-Verzeichnis ('' = keines)
     */
    private function bild(int $blockId, string $feld, string $bisher, int $maxBreite): string
    {
        if (post_bool($feld . '_clear') === 1) {
            return '';
        }

        $neu = Upload::image($_FILES[$feld] ?? null, 'bloecke', 'block-' . $blockId . '-' . $feld, $maxBreite);

        return $neu ?? $bisher;
    }

    /**
     * Galerie: bestehende Bilder aktualisieren (Bildunterschrift, Entfernen-
     * Haken) und neue aus dem Mehrfach-Upload anhängen.
     *
     * @param list<array<string,mixed>> $bisher
     * @return list<array{file:string,caption:string}>
     */
    private function galerieBilder(int $blockId, array $bisher): array
    {
        $captions  = (array) ($_POST['captions'] ?? []);
        $entfernen = (array) ($_POST['remove'] ?? []);
        $ergebnis  = [];

        foreach ($bisher as $i => $bild) {
            $datei = (string) ($bild['file'] ?? '');

            if (isset($entfernen[$i])) {
                Upload::delete($datei);
                continue;
            }

            $ergebnis[] = [
                'file'    => $datei,
                'caption' => trim((string) ($captions[$i] ?? ($bild['caption'] ?? ''))),
            ];
        }

        // Mehrfach-Upload: $_FILES['images'] kommt als name[]/tmp_name[]-Struktur.
        $files = $_FILES['images'] ?? null;

        if (is_array($files) && is_array($files['error'] ?? null)) {
            foreach ($files['error'] as $i => $error) {
                $einzel = [
                    'name'     => $files['name'][$i] ?? '',
                    'type'     => $files['type'][$i] ?? '',
                    'tmp_name' => $files['tmp_name'][$i] ?? '',
                    'error'    => $error,
                    'size'     => $files['size'][$i] ?? 0,
                ];

                $pfad = Upload::image($einzel, 'bloecke', 'block-' . $blockId . '-g' . substr(uniqid(), -6), 1800);

                if ($pfad !== null) {
                    $ergebnis[] = ['file' => $pfad, 'caption' => ''];
                }
            }
        }

        return $ergebnis;
    }

    /**
     * Pfad-Angabe aus der Server-Bildauswahl absichern: muss relativ sein,
     * innerhalb des Upload-Verzeichnisses liegen und ein Bild sein.
     */
    private static function sichererUploadPfad(string $pfad): ?string
    {
        $pfad = trim(str_replace('\\', '/', $pfad), '/');

        if ($pfad === '' || str_contains($pfad, '..')
            || preg_match('/\.(jpe?g|png|gif|webp)$/i', $pfad) !== 1) {
            return null;
        }

        $basis = rtrim((string) \App\Core\Config::get('upload_dir'), '/\\');

        return is_file($basis . '/' . $pfad) ? $pfad : null;
    }

    /**
     * Alle Bilder im Upload-Verzeichnis (neueste zuerst) fuer die
     * Server-Bildauswahl der Slideshow.
     *
     * @return list<string> Pfade relativ zum Upload-Verzeichnis
     */
    public static function serverBilder(int $limit = 240): array
    {
        $basis = rtrim((string) \App\Core\Config::get('upload_dir'), '/\\');

        if (!is_dir($basis)) {
            return [];
        }

        $bilder = [];
        $it     = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($basis, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($it as $datei) {
            /** @var \SplFileInfo $datei */
            if ($datei->isFile() && preg_match('/\.(jpe?g|png|gif|webp)$/i', $datei->getFilename()) === 1) {
                $rel = str_replace('\\', '/', substr($datei->getPathname(), strlen($basis) + 1));
                $bilder[$rel] = $datei->getMTime();
            }
        }

        arsort($bilder);

        return array_slice(array_keys($bilder), 0, $limit);
    }

    /** YouTube-Adresse oder -Id auf die reine Video-Id reduzieren ('' = keine). */
    private function youtubeId(string $eingabe): string
    {
        if ($eingabe === '') {
            return '';
        }

        if (preg_match('~(?:youtube(?:-nocookie)?\.com/(?:watch\?v=|embed/|shorts/)|youtu\.be/)([A-Za-z0-9_-]{6,20})~', $eingabe, $m)
            || preg_match('~^([A-Za-z0-9_-]{6,20})$~', $eingabe, $m)) {
            return $m[1];
        }

        return '';
    }

    /**
     * Löst den {page}-Parameter auf: '0' = Startseite, '<id>' = Seite,
     * 's<id>' = Sektionsseite.
     *
     * @return array{0:?int,1:?int,2:?array<string,mixed>,3:?array<string,mixed>}
     */
    private function resolveContext(array $args): array
    {
        $raw = (string) ($args['page'] ?? '0');

        if (str_starts_with($raw, 's')) {
            $sectionId = (int) substr($raw, 1);
            $section   = \App\Models\SectionRepo::find($sectionId);

            if ($section === null) {
                Flash::error('Sektion nicht gefunden.');
                Url::redirect('/admin/sektionen');
            }

            return [null, $sectionId, null, $section];
        }

        $pageId = (int) $raw;

        if ($pageId === 0) {
            return [null, null, null, null];
        }

        $page = PageRepo::find($pageId);
        if ($page === null) {
            Flash::error('Seite nicht gefunden.');
            Url::redirect('/admin/seiten');
        }

        return [$pageId, null, $page, null];
    }

    /** Kontext-Schlüssel für URLs: '0', '<pageId>' oder 's<sectionId>'. */
    private static function contextKey(?int $pageId, ?int $sectionId): string
    {
        if ($pageId !== null) {
            return (string) $pageId;
        }
        if ($sectionId !== null) {
            return 's' . $sectionId;
        }

        return '0';
    }

    /** @param array<string,mixed> $block */
    private static function blockContextKey(array $block): string
    {
        return self::contextKey(
            $block['page_id'] !== null ? (int) $block['page_id'] : null,
            ($block['section_id'] ?? null) !== null ? (int) $block['section_id'] : null
        );
    }

    /** @return array<string,mixed> */
    private function requireBlock(array $args): array
    {
        $block = BlockRepo::find((int) ($args['id'] ?? 0));

        if ($block === null) {
            Flash::error('Block nicht gefunden.');
            Url::redirect('/admin/seiten');
        }

        return $block;
    }

    private function backTo(string $kontext, ?int $blockId = null): void
    {
        Url::redirect('/admin/inhalt/' . $kontext . ($blockId !== null ? '#block-' . $blockId : ''));
    }
}
