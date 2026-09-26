<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Csrf;
use App\Core\Flash;
use App\Core\Url;
use App\Core\View;
use App\Models\GalleryRepo;
use App\Models\ImageRepo;

/**
 * Galerien (/admin/galerien): benannte Bildersammlungen aus der Bildbibliothek
 * mit eigener oeffentlicher Seite /galerie/{slug}.
 */
final class GalleryAdminController
{
    private const ROLES = ['superuser', 'verwaltung'];

    public function index(): void
    {
        AuthController::requireRole(...self::ROLES);

        View::display('admin/galerien/index', [
            'title'     => 'Galerien',
            'galleries' => GalleryRepo::all(),
            'imageCount' => ImageRepo::count(),
        ], 'layouts/admin');
    }

    public function create(): void
    {
        AuthController::requireRole(...self::ROLES);
        $this->form(null);
    }

    /** @param array<string,string> $args */
    public function edit(array $args): void
    {
        AuthController::requireRole(...self::ROLES);
        $gallery = GalleryRepo::find((int) ($args['id'] ?? 0));
        if ($gallery === null) {
            Flash::error('Galerie nicht gefunden.');
            Url::redirect('/admin/galerien');
        }
        $this->form($gallery);
    }

    /** @param array<string,mixed>|null $gallery */
    private function form(?array $gallery): void
    {
        $old = Flash::oldInput();
        $data = $gallery ?? ['id' => 0, 'title' => '', 'slug' => '', 'text' => '', 'published' => 1, 'sort_order' => 0, 'cover_image_id' => null];
        foreach (['title', 'slug', 'text'] as $k) {
            if (isset($old[$k])) {
                $data[$k] = (string) $old[$k];
            }
        }

        View::display('admin/galerien/form', [
            'title'    => $gallery === null ? 'Neue Galerie' : $gallery['title'],
            'gallery'  => $data,
            'isNew'    => $gallery === null,
            'errors'   => Flash::errors(),
            'selected' => $gallery === null ? [] : GalleryRepo::imageIds((int) $gallery['id']),
            'images'   => ImageRepo::search(),
        ], 'layouts/admin');
    }

    public function store(): void
    {
        AuthController::requireRole(...self::ROLES);
        Csrf::verify();

        [$data, $errors] = $this->validate(null);
        if ($errors !== []) {
            Flash::withInput($_POST, $errors);
            Url::redirect('/admin/galerien/neu');
        }
        $id = GalleryRepo::create($data);
        GalleryRepo::setImages($id, self::pickedIds('image_ids'));
        Audit::log('gallery_created', 'gallery', $id, (string) $data['title']);
        Flash::success('Galerie angelegt.');
        Url::redirect('/admin/galerien/' . $id);
    }

    /** @param array<string,string> $args */
    public function update(array $args): void
    {
        AuthController::requireRole(...self::ROLES);
        Csrf::verify();

        $id      = (int) ($args['id'] ?? 0);
        $gallery = GalleryRepo::find($id);
        if ($gallery === null) {
            Flash::error('Galerie nicht gefunden.');
            Url::redirect('/admin/galerien');
        }
        [$data, $errors] = $this->validate($gallery);
        if ($errors !== []) {
            Flash::withInput($_POST, $errors);
            Url::redirect('/admin/galerien/' . $id);
        }
        GalleryRepo::update($id, $data);
        GalleryRepo::setImages($id, self::pickedIds('image_ids'));
        Audit::log('gallery_updated', 'gallery', $id, (string) $data['title']);
        Flash::success('Galerie gespeichert.');
        Url::redirect('/admin/galerien/' . $id);
    }

    /** @param array<string,string> $args */
    public function toggle(array $args): void
    {
        AuthController::requireRole(...self::ROLES);
        Csrf::verify();
        $id      = (int) ($args['id'] ?? 0);
        $gallery = GalleryRepo::find($id);
        if ($gallery !== null) {
            GalleryRepo::update($id, ['published' => (int) $gallery['published'] === 1 ? 0 : 1]);
        }
        Url::redirect('/admin/galerien');
    }

    /** @param array<string,string> $args */
    public function destroy(array $args): void
    {
        AuthController::requireRole(...self::ROLES);
        Csrf::verify();
        $id      = (int) ($args['id'] ?? 0);
        $gallery = GalleryRepo::find($id);
        if ($gallery !== null) {
            GalleryRepo::delete($id);
            Audit::log('gallery_deleted', 'gallery', $id, (string) $gallery['title']);
            Flash::success('Galerie gelöscht – die Bilder bleiben in der Bildbibliothek.');
        }
        Url::redirect('/admin/galerien');
    }

    /**
     * Ausgewaehlte Bild-IDs aus dem Picker: Reihenfolge aus <feld>_order, Checkboxen als Quelle.
     *
     * @return list<int>
     */
    public static function pickedIds(string $field): array
    {
        $checked = array_values(array_filter(array_map('intval', (array) ($_POST[$field] ?? []))));
        $order   = array_values(array_filter(array_map('intval', explode(',', (string) ($_POST[$field . '_order'] ?? '')))));
        $out     = array_values(array_filter($order, static fn (int $id): bool => in_array($id, $checked, true)));
        foreach ($checked as $id) {
            if (!in_array($id, $out, true)) {
                $out[] = $id;
            }
        }

        return $out;
    }

    /**
     * @param array<string,mixed>|null $gallery
     * @return array{0: array<string,mixed>, 1: array<string,string>}
     */
    private function validate(?array $gallery): array
    {
        $errors = [];
        $title  = trim(post('title'));
        if ($title === '') {
            $errors['title'] = 'Bitte einen Titel eingeben.';
        }
        $slugWunsch = trim(post('slug')) !== '' ? post('slug') : $title;
        $cover      = post_int('cover_image_id');

        return [[
            'title'          => $title,
            'slug'           => GalleryRepo::uniqueSlug($slugWunsch, $gallery !== null ? (int) $gallery['id'] : null),
            'text'           => trim(post('text')),
            'published'      => post_bool('published') ? 1 : 0,
            'sort_order'     => post_int('sort_order'),
            'cover_image_id' => $cover > 0 ? $cover : null,
        ], $errors];
    }
}
