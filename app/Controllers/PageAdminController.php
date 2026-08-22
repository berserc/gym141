<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\Flash;
use App\Core\Url;
use App\Core\View;
use App\Models\PageRepo;

final class PageAdminController
{
    public function index(): void
    {
        AuthController::requireRole('superuser');

        View::display('admin/pages/index', [
            'title' => 'Seiten',
            'pages' => PageRepo::all(),
        ], 'layouts/admin');
    }

    public function create(): void
    {
        AuthController::requireRole('superuser');

        View::display('admin/pages/form', [
            'title' => 'Neue Seite',
            'page'  => Flash::oldInput() + [
                'id' => 0, 'slug' => '', 'title' => '', 'body' => '',
                'in_footer' => 1, 'sort_order' => 0, 'published' => 1,
            ],
            'errors' => Flash::errors(),
            'isNew'  => true,
        ], 'layouts/admin');
    }

    /** @param array<string,string> $args */
    public function edit(array $args): void
    {
        AuthController::requireRole('superuser');

        $page = PageRepo::find((int) ($args['id'] ?? 0));

        if ($page === null) {
            Flash::error('Seite nicht gefunden.');
            Url::redirect('/admin/seiten');
        }

        View::display('admin/pages/form', [
            'title'  => (string) $page['title'],
            'page'   => Flash::oldInput() + $page,
            'errors' => Flash::errors(),
            'isNew'  => false,
        ], 'layouts/admin');
    }

    public function store(): void
    {
        AuthController::requireRole('superuser');
        Csrf::verify();

        [$data, $errors] = $this->validate(null);

        if ($errors !== []) {
            Flash::withInput($_POST, $errors);
            Url::redirect('/admin/seiten/neu');
        }

        $id = Database::insert('pages', $data);

        Audit::log('page_created', 'page', $id, (string) $data['title']);
        Flash::success('Seite angelegt.');
        Url::redirect('/admin/seiten/' . $id);
    }

    /** @param array<string,string> $args */
    public function update(array $args): void
    {
        AuthController::requireRole('superuser');
        Csrf::verify();

        $id   = (int) ($args['id'] ?? 0);
        $page = PageRepo::find($id);

        if ($page === null) {
            Flash::error('Seite nicht gefunden.');
            Url::redirect('/admin/seiten');
        }

        [$data, $errors] = $this->validate($id);

        if ($errors !== []) {
            Flash::withInput($_POST, $errors);
            Url::redirect('/admin/seiten/' . $id);
        }

        Database::update('pages', $id, $data);

        Audit::log('page_updated', 'page', $id, (string) $data['title']);
        Flash::success('Seite gespeichert.');
        Url::redirect('/admin/seiten/' . $id);
    }

    /** @param array<string,string> $args */
    public function destroy(array $args): void
    {
        AuthController::requireRole('superuser');
        Csrf::verify();

        $id   = (int) ($args['id'] ?? 0);
        $page = PageRepo::find($id);

        if ($page === null) {
            Url::redirect('/admin/seiten');
        }

        // Impressum und Datenschutz sind rechtlich verpflichtend.
        if (in_array((string) $page['slug'], ['impressum', 'datenschutz'], true)) {
            Flash::error('Impressum und Datenschutz können nicht gelöscht werden.');
            Url::redirect('/admin/seiten/' . $id);
        }

        Database::run('DELETE FROM pages WHERE id = ?', [$id]);

        Audit::log('page_deleted', 'page', $id, (string) $page['title']);
        Flash::success('Seite gelöscht.');
        Url::redirect('/admin/seiten');
    }

    /** @return array{0:array<string,mixed>,1:array<string,string>} */
    private function validate(?int $id): array
    {
        $errors = [];
        $title  = post('title');

        if ($title === '') {
            $errors['title'] = 'Titel ist erforderlich.';
        }

        $slug = slugify(post('slug') !== '' ? post('slug') : $title);

        if ($slug === '') {
            $errors['slug'] = 'Bitte ein URL-Kürzel angeben.';
        } else {
            $taken = Database::one(
                'SELECT id FROM pages WHERE slug = ? AND (? IS NULL OR id <> ?)',
                [$slug, $id, $id]
            );

            if ($taken !== null) {
                $errors['slug'] = 'Dieses URL-Kürzel ist bereits vergeben.';
            }
        }

        return [[
            'slug'       => $slug,
            'title'      => $title,
            'body'       => safe_html((string) ($_POST['body'] ?? '')),
            'in_footer'  => post_bool('in_footer'),
            'sort_order' => post_int('sort_order'),
            'published'  => post_bool('published'),
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ], $errors];
    }
}
