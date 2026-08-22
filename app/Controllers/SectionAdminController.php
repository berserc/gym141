<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\Flash;
use App\Core\Upload;
use App\Core\Url;
use App\Core\View;
use App\Models\SectionRepo;
use RuntimeException;

final class SectionAdminController
{
    /** Bildarten mit maximaler Breite in Pixeln. */
    private const IMAGE_FIELDS = [
        'logo_path' => 500,
        'tile_path' => 900,
        'hero_path' => 1600,
    ];

    public function index(): void
    {
        AuthController::requireLogin();

        View::display('admin/sections/index', [
            'title'    => 'Sektionen',
            'sections' => SectionRepo::forUser(Auth::allowedSectionIds()),
            'counts'   => SectionRepo::memberCounts(),
        ], 'layouts/admin');
    }

    public function create(): void
    {
        AuthController::requireRole('superuser');

        View::display('admin/sections/form', [
            'title'    => 'Neue Sektion',
            'section'  => Flash::oldInput() + $this->emptySection(),
            'contacts' => [],
            'errors'   => Flash::errors(),
            'isNew'    => true,
        ], 'layouts/admin');
    }

    /** @param array<string,string> $args */
    public function edit(array $args): void
    {
        AuthController::requireLogin();

        $section = $this->findAccessible((int) ($args['id'] ?? 0));

        View::display('admin/sections/form', [
            'title'    => (string) $section['name'],
            'section'  => Flash::oldInput() + $section,
            'contacts' => SectionRepo::contacts((int) $section['id']),
            'errors'   => Flash::errors(),
            'isNew'    => false,
        ], 'layouts/admin');
    }

    public function store(): void
    {
        AuthController::requireRole('superuser');
        Csrf::verify();

        [$data, $errors] = $this->validate();

        if ($errors !== []) {
            Flash::withInput($_POST, $errors);
            Flash::error('Bitte prüfen Sie die markierten Felder.');
            Url::redirect('/admin/sektionen/neu');
        }

        $data['slug']       = SectionRepo::uniqueSlug($data['slug'] !== '' ? $data['slug'] : slugify($data['name']));
        $data['sort_order'] = $data['sort_order'] > 0 ? $data['sort_order'] : SectionRepo::nextSortOrder();

        $id = Database::insert('sections', $data);

        try {
            $this->handleImages($id, $data['slug']);
        } catch (RuntimeException $e) {
            Flash::error('Sektion angelegt, aber ein Bild wurde nicht übernommen: ' . $e->getMessage());
        }

        Audit::log('section_created', 'section', $id, (string) $data['name']);
        Flash::success('Sektion angelegt.');
        Url::redirect('/admin/sektionen/' . $id);
    }

    /** @param array<string,string> $args */
    public function update(array $args): void
    {
        AuthController::requireLogin();
        Csrf::verify();

        $id      = (int) ($args['id'] ?? 0);
        $section = $this->findAccessible($id);

        if (!Auth::canWrite()) {
            AuthController::requireRole('superuser');
        }

        [$data, $errors] = $this->validate($id);

        // Nur der Superuser darf Slug, Sortierung und Sichtbarkeit ändern.
        if (!Auth::isSuperuser()) {
            unset($data['slug'], $data['sort_order'], $data['published']);
        } else {
            $data['slug'] = SectionRepo::uniqueSlug(
                $data['slug'] !== '' ? $data['slug'] : slugify((string) $data['name']),
                $id
            );
        }

        if ($errors !== []) {
            Flash::withInput($_POST, $errors);
            Flash::error('Bitte prüfen Sie die markierten Felder.');
            Url::redirect('/admin/sektionen/' . $id);
        }

        $data['updated_at'] = gmdate('Y-m-d H:i:s');

        Database::update('sections', $id, $data);

        try {
            $this->handleImages($id, (string) ($data['slug'] ?? $section['slug']));
        } catch (RuntimeException $e) {
            Flash::error('Ein Bild wurde nicht übernommen: ' . $e->getMessage());
        }

        Audit::log('section_updated', 'section', $id, Audit::diff($section, $data));
        Flash::success('Sektion gespeichert.');
        Url::redirect('/admin/sektionen/' . $id);
    }

    /** @param array<string,string> $args */
    public function destroy(array $args): void
    {
        AuthController::requireRole('superuser');
        Csrf::verify();

        $id      = (int) ($args['id'] ?? 0);
        $section = SectionRepo::find($id);

        if ($section === null) {
            Flash::error('Sektion nicht gefunden.');
            Url::redirect('/admin/sektionen');
        }

        $members = (int) Database::value('SELECT COUNT(*) FROM members WHERE section_id = ?', [$id]);

        if ($members > 0) {
            Flash::error(sprintf(
                'Die Sektion "%s" hat noch %d Mitglied(er). Bitte zuerst umbuchen oder löschen.',
                $section['name'],
                $members
            ));
            Url::redirect('/admin/sektionen/' . $id);
        }

        foreach (array_keys(self::IMAGE_FIELDS) as $field) {
            Upload::delete((string) $section[$field]);
        }

        Database::run('DELETE FROM sections WHERE id = ?', [$id]);

        Audit::log('section_deleted', 'section', $id, (string) $section['name']);
        Flash::success('Sektion gelöscht.');
        Url::redirect('/admin/sektionen');
    }

    /** Entfernt ein einzelnes Bild, ohne den Rest zu ändern. */
    public function removeImage(array $args): void
    {
        AuthController::requireLogin();
        Csrf::verify();

        $id      = (int) ($args['id'] ?? 0);
        $section = $this->findAccessible($id);
        $field   = post('field');

        if (!array_key_exists($field, self::IMAGE_FIELDS)) {
            Flash::error('Unbekanntes Bildfeld.');
            Url::redirect('/admin/sektionen/' . $id);
        }

        Upload::delete((string) $section[$field]);
        Database::update('sections', $id, [$field => '', 'updated_at' => gmdate('Y-m-d H:i:s')]);

        Audit::log('section_image_removed', 'section', $id, $field);
        Flash::success('Bild entfernt.');
        Url::redirect('/admin/sektionen/' . $id);
    }

    // ------------------------------------------------------------ Kontakte --

    /** @param array<string,string> $args */
    public function saveContact(array $args): void
    {
        AuthController::requireLogin();
        Csrf::verify();

        $sectionId = (int) ($args['id'] ?? 0);
        $this->findAccessible($sectionId);

        $contactId = post_int('contact_id');

        $data = [
            'section_id' => $sectionId,
            'role_label' => post('role_label', 'Sektionsleitung') ?: 'Sektionsleitung',
            'name'       => post('name'),
            'phone'      => post('phone'),
            'mobile'     => post('mobile'),
            'fax'        => post('fax'),
            'email'      => post('email'),
            'note'       => post('note'),
            'sort_order' => post_int('sort_order'),
        ];

        if ($data['name'] === '' && $data['email'] === '' && $data['phone'] === '' && $data['mobile'] === '') {
            Flash::error('Bitte zumindest Name oder eine Kontaktmöglichkeit angeben.');
            Url::redirect('/admin/sektionen/' . $sectionId);
        }

        if ($data['email'] !== '' && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            Flash::error('Die E-Mail-Adresse des Kontakts ist ungültig.');
            Url::redirect('/admin/sektionen/' . $sectionId);
        }

        if ($contactId > 0) {
            $existing = Database::one(
                'SELECT * FROM section_contacts WHERE id = ? AND section_id = ?',
                [$contactId, $sectionId]
            );

            if ($existing === null) {
                Flash::error('Kontakt nicht gefunden.');
                Url::redirect('/admin/sektionen/' . $sectionId);
            }

            unset($data['section_id']);
            Database::update('section_contacts', $contactId, $data);
            Audit::log('contact_updated', 'section', $sectionId, (string) $data['name']);
        } else {
            $contactId = Database::insert('section_contacts', $data);
            Audit::log('contact_created', 'section', $sectionId, (string) $data['name']);
        }

        Flash::success('Kontakt gespeichert.');
        Url::redirect('/admin/sektionen/' . $sectionId);
    }

    /** @param array<string,string> $args */
    public function deleteContact(array $args): void
    {
        AuthController::requireLogin();
        Csrf::verify();

        $sectionId = (int) ($args['id'] ?? 0);
        $this->findAccessible($sectionId);

        Database::run(
            'DELETE FROM section_contacts WHERE id = ? AND section_id = ?',
            [post_int('contact_id'), $sectionId]
        );

        Audit::log('contact_deleted', 'section', $sectionId);
        Flash::success('Kontakt entfernt.');
        Url::redirect('/admin/sektionen/' . $sectionId);
    }

    // ------------------------------------------------------------- Hilfen --

    /** @return array{0:array<string,mixed>,1:array<string,string>} */
    private function validate(?int $id = null): array
    {
        $errors = [];
        $name   = post('name');

        if ($name === '') {
            $errors['name'] = 'Der Name der Sportart ist erforderlich.';
        }

        $slug = slugify(post('slug') !== '' ? post('slug') : $name);

        if ($slug === '' && $name !== '') {
            $errors['slug'] = 'Aus dem Namen ließ sich keine URL bilden. Bitte URL-Kürzel angeben.';
        }

        $website = post('website');

        if ($website !== '' && !preg_match('#^(https?://)?[\w.-]+\.[a-z]{2,}(/.*)?$#i', $website)) {
            $errors['website'] = 'Bitte eine gültige Webadresse angeben.';
        }

        unset($id);

        return [[
            'slug'          => $slug,
            'name'          => $name,
            'club_name'     => post('club_name'),
            'tagline'       => post('tagline'),
            'description'   => safe_html((string) ($_POST['description'] ?? '')),
            'training_info' => safe_html((string) ($_POST['training_info'] ?? '')),
            'website'       => $website,
            'facebook'      => post('facebook'),
            'instagram'     => post('instagram'),
            'default_fee'   => post_float('default_fee'),
            'fee_free'      => post_bool('fee_free'),
            'sort_order'    => post_int('sort_order'),
            'published'     => post_bool('published'),
        ], $errors];
    }

    /** Verarbeitet Logo, Kachel und Titelbild eines Formulars. */
    private function handleImages(int $sectionId, string $slug): void
    {
        $section = SectionRepo::find($sectionId);

        if ($section === null) {
            return;
        }

        $updates = [];

        foreach (self::IMAGE_FIELDS as $field => $maxWidth) {
            $key = str_replace('_path', '', $field); // logo, tile, hero

            /** @var array<string,mixed>|null $file */
            $file = $_FILES[$key] ?? null;

            $stored = Upload::image($file, 'sektionen', $slug . '-' . $key, $maxWidth);

            if ($stored === null) {
                continue;
            }

            // Altes Bild erst nach erfolgreichem Upload entfernen.
            if ((string) $section[$field] !== '') {
                Upload::delete((string) $section[$field]);
            }

            $updates[$field] = $stored;
        }

        if ($updates !== []) {
            $updates['updated_at'] = gmdate('Y-m-d H:i:s');
            Database::update('sections', $sectionId, $updates);
        }
    }

    /** @return array<string,mixed> */
    private function findAccessible(int $id): array
    {
        $section = SectionRepo::find($id);

        if ($section === null) {
            http_response_code(404);
            View::display('errors/404-admin', ['title' => 'Nicht gefunden'], 'layouts/admin');
            exit;
        }

        if (!Auth::canAccessSection($id)) {
            http_response_code(403);
            View::display('errors/403', ['title' => 'Kein Zugriff'], 'layouts/admin');
            exit;
        }

        return $section;
    }

    /** @return array<string,mixed> */
    private function emptySection(): array
    {
        return [
            'id'            => 0,
            'slug'          => '',
            'name'          => '',
            'club_name'     => '',
            'tagline'       => '',
            'description'   => '',
            'training_info' => '',
            'website'       => '',
            'facebook'      => '',
            'instagram'     => '',
            'logo_path'     => '',
            'tile_path'     => '',
            'hero_path'     => '',
            'default_fee'   => 0,
            'fee_free'      => 0,
            'base_funding'  => 0,
            'sort_order'    => 0,
            'published'     => 1,
        ];
    }
}
