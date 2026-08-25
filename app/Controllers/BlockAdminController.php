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
 * Verwaltung der Inhaltsblöcke ("Paragraphs") je Seite.
 *
 * URL-Konvention: /admin/inhalt/{page} mit page = 0 für die Startseite,
 * sonst die Seiten-Id aus pages.
 */
final class BlockAdminController
{
    public function index(array $args): void
    {
        AuthController::requireRole('superuser');

        [$pageId, $page] = $this->resolvePage($args);

        View::display('admin/blocks/index', [
            'title'  => 'Inhalt: ' . ($page['title'] ?? 'Startseite'),
            'pageId' => $pageId,
            'page'   => $page,
            'blocks' => BlockRepo::forPage($pageId),
            'types'  => BlockRepo::TYPES,
        ], 'layouts/admin');
    }

    public function store(array $args): void
    {
        AuthController::requireRole('superuser');
        Csrf::verify();

        [$pageId] = $this->resolvePage($args);
        $type = post('type');

        if (!isset(BlockRepo::TYPES[$type])) {
            Flash::error('Unbekannter Blocktyp.');
        } else {
            $id = BlockRepo::create($pageId, $type);
            Flash::success(BlockRepo::TYPES[$type][0] . '-Block hinzugefügt – jetzt befüllen und speichern.');
        }

        $this->back($pageId, $id ?? null);
    }

    /** Startseiten-Option: Standardaufbau ausblenden, nur Blöcke zeigen. */
    public function options(array $args): void
    {
        AuthController::requireRole('superuser');
        Csrf::verify();

        [$pageId] = $this->resolvePage($args);

        if ($pageId === null) {
            \App\Models\Setting::set('home_blocks_only', post_bool('blocks_only') === 1 ? '1' : '0');
            Flash::success('Startseiten-Aufbau gespeichert.');
        }

        $this->back($pageId);
    }

    public function update(array $args): void
    {
        AuthController::requireRole('superuser');
        Csrf::verify();

        $block = $this->requireBlock($args);
        $id    = (int) $block['id'];
        $cfg   = $block['config'];

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
        $this->back($block['page_id'] === null ? null : (int) $block['page_id'], $id);
    }

    public function move(array $args): void
    {
        AuthController::requireRole('superuser');
        Csrf::verify();

        $block = $this->requireBlock($args);
        BlockRepo::move((int) $block['id'], post('richtung') === 'hoch' ? 'hoch' : 'runter');

        $this->back($block['page_id'] === null ? null : (int) $block['page_id'], (int) $block['id']);
    }

    public function duplicate(array $args): void
    {
        AuthController::requireRole('superuser');
        Csrf::verify();

        $block = $this->requireBlock($args);
        $neuId = BlockRepo::duplicate((int) $block['id']);
        Flash::success('Block dupliziert.');

        $this->back($block['page_id'] === null ? null : (int) $block['page_id'], $neuId);
    }

    public function toggle(array $args): void
    {
        AuthController::requireRole('superuser');
        Csrf::verify();

        $block = $this->requireBlock($args);
        BlockRepo::togglePublished((int) $block['id']);

        $this->back($block['page_id'] === null ? null : (int) $block['page_id'], (int) $block['id']);
    }

    public function destroy(array $args): void
    {
        AuthController::requireRole('superuser');
        Csrf::verify();

        $block = $this->requireBlock($args);
        BlockRepo::delete((int) $block['id']);
        Flash::success('Block gelöscht.');

        $this->back($block['page_id'] === null ? null : (int) $block['page_id']);
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

    /** @return array{0:?int,1:?array<string,mixed>} */
    private function resolvePage(array $args): array
    {
        $raw = (int) ($args['page'] ?? 0);

        if ($raw === 0) {
            return [null, null];
        }

        $page = PageRepo::find($raw);
        if ($page === null) {
            Flash::error('Seite nicht gefunden.');
            Url::redirect('/admin/seiten');
        }

        return [$raw, $page];
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

    private function back(?int $pageId, ?int $blockId = null): void
    {
        Url::redirect('/admin/inhalt/' . ($pageId ?? 0) . ($blockId !== null ? '#block-' . $blockId : ''));
    }
}
