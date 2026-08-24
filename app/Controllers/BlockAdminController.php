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
                $cfg['image']        = $this->bild($id, 'image', $cfg['image'] ?? '', 2000);
                break;

            case 'image':
                $cfg['caption'] = trim(post('caption'));
                $breite         = post('width');
                $cfg['width']   = in_array($breite, ['normal', 'schmal', 'voll'], true) ? $breite : 'normal';
                $cfg['image']   = $this->bild($id, 'image', $cfg['image'] ?? '', 1800);
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
