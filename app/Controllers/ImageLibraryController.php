<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Flash;
use App\Core\ImageTool;
use App\Core\Url;
use App\Core\View;
use App\Models\ImageRepo;
use RuntimeException;

/**
 * Bildbibliothek (/admin/bilder): Mehrfach-Upload mit Verkleinerung, Tags, Bildtext,
 * Suche und Sammelaktionen. Die Bilder werden von Galerien, dem Galerie-Block
 * und (spaeter) weiteren Stellen nur referenziert – einmal hochladen, ueberall waehlen.
 */
final class ImageLibraryController
{
    private const ROLES = ['superuser', 'verwaltung'];

    public function index(): void
    {
        AuthController::requireRole(...self::ROLES);

        $q   = trim(query('q'));
        $tag = trim(query('tag'));

        View::display('admin/bilder/index', [
            'title'     => 'Bilder',
            'images'    => ImageRepo::search($q, $tag),
            'total'     => ImageRepo::count(),
            'tagCounts' => ImageRepo::tagCounts(),
            'q'         => $q,
            'tag'       => $tag,
        ], 'layouts/admin');
    }

    public function upload(): void
    {
        AuthController::requireRole(...self::ROLES);
        Csrf::verify();

        $tags    = ImageRepo::parseTags(post('tags'));
        $caption = trim(post('caption'));
        $files   = $_FILES['files'] ?? null;
        $ok      = 0;
        $errors  = [];

        if (is_array($files) && is_array($files['name'] ?? null)) {
            foreach ($files['name'] as $i => $name) {
                $einzel = [
                    'name'     => (string) $name,
                    'tmp_name' => (string) ($files['tmp_name'][$i] ?? ''),
                    'error'    => (int) ($files['error'][$i] ?? UPLOAD_ERR_NO_FILE),
                    'size'     => (int) ($files['size'][$i] ?? 0),
                ];
                if ($einzel['error'] === UPLOAD_ERR_NO_FILE) {
                    continue;
                }
                try {
                    $img = ImageTool::ingest($einzel, 'bilder');
                    ImageRepo::create($img, $tags, $caption, (string) $name, Auth::id());
                    $ok++;
                } catch (RuntimeException $e) {
                    $errors[] = $name . ': ' . $e->getMessage();
                }
            }
        }

        if ($ok > 0) {
            Audit::log('images_uploaded', 'image', null, $ok . ' Bild(er), Tags: ' . implode(', ', $tags));
            Flash::success($ok . ' Bild(er) übernommen.');
        }
        if ($errors !== []) {
            Flash::error(implode(' · ', array_slice($errors, 0, 5)) . (count($errors) > 5 ? ' …' : ''));
        }
        if ($ok === 0 && $errors === []) {
            Flash::error('Keine Datei ausgewählt.');
        }

        Url::redirect('/admin/bilder', ['tag' => $tags[0] ?? '']);
    }

    /** @param array<string,string> $args */
    public function update(array $args): void
    {
        AuthController::requireRole(...self::ROLES);
        Csrf::verify();

        $id = (int) ($args['id'] ?? 0);
        if (ImageRepo::find($id) === null) {
            Flash::error('Bild nicht gefunden.');
            Url::redirect('/admin/bilder');
        }
        ImageRepo::update($id, trim(post('caption')), ImageRepo::parseTags(post('tags')));
        Flash::success('Bild gespeichert.');
        $this->back();
    }

    /** Sammelaktion fuer die angehakten Bilder: Tags ergaenzen/entfernen oder loeschen. */
    public function bulk(): void
    {
        AuthController::requireRole(...self::ROLES);
        Csrf::verify();

        $ids    = array_values(array_filter(array_map('intval', (array) ($_POST['ids'] ?? []))));
        $action = post('action');
        $tags   = ImageRepo::parseTags(post('bulk_tags'));

        if ($ids === []) {
            Flash::error('Keine Bilder ausgewählt.');
            $this->back();
        }

        switch ($action) {
            case 'addtags':
                if ($tags === []) {
                    Flash::error('Bitte Tags eingeben.');
                    $this->back();
                }
                ImageRepo::addTags($ids, $tags);
                Flash::success('Tags bei ' . count($ids) . ' Bild(ern) ergänzt.');
                break;
            case 'deltags':
                if ($tags === []) {
                    Flash::error('Bitte Tags eingeben.');
                    $this->back();
                }
                ImageRepo::removeTags($ids, $tags);
                Flash::success('Tags bei ' . count($ids) . ' Bild(ern) entfernt.');
                break;
            case 'delete':
                foreach ($ids as $id) {
                    ImageRepo::delete($id);
                }
                Audit::log('images_deleted', 'image', null, count($ids) . ' Bild(er)');
                Flash::success(count($ids) . ' Bild(er) gelöscht.');
                break;
            default:
                Flash::error('Unbekannte Aktion.');
        }

        $this->back();
    }

    private function back(): never
    {
        Url::redirect('/admin/bilder', ['q' => post('q'), 'tag' => post('tag')]);
    }
}
