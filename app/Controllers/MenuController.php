<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\Flash;
use App\Core\Url;
use App\Core\View;

/**
 * Eigene Menuepunkte der oeffentlichen Website: Hauptmenue (Header) und/oder
 * Footer, mit freier URL - interner Pfad (/meineurl), Ankerpunkt auf der
 * Startseite (/#anker) oder externe Adresse (https://...).
 */
final class MenuController
{
    public const POSITIONS = [
        'haupt'  => 'Hauptmenü',
        'footer' => 'Footer',
        'beide'  => 'Hauptmenü + Footer',
    ];

    public function index(): void
    {
        AuthController::requireRole('superuser');

        View::display('admin/menu/index', [
            'title'     => 'Menü',
            'items'     => Database::all('SELECT * FROM menu_items ORDER BY sort_order, id'),
            'positions' => self::POSITIONS,
        ], 'layouts/admin');
    }

    public function store(): void
    {
        AuthController::requireRole('superuser');
        Csrf::verify();

        [$data, $fehler] = $this->validate();

        if ($fehler !== null) {
            Flash::error($fehler);
            Url::redirect('/admin/menue');
        }

        $id = Database::insert('menu_items', $data);
        Audit::log('menu_created', 'menu_item', $id, $data['label'] . ' → ' . $data['url']);
        Flash::success('Menüpunkt „' . $data['label'] . '“ angelegt.');
        Url::redirect('/admin/menue');
    }

    public function update(array $args): void
    {
        AuthController::requireRole('superuser');
        Csrf::verify();

        $item = $this->find((int) ($args['id'] ?? 0));

        if (post('aktion') === 'umschalten') {
            Database::update('menu_items', (int) $item['id'], ['published' => 1 - (int) $item['published']]);
            Url::redirect('/admin/menue');
        }

        [$data, $fehler] = $this->validate();

        if ($fehler !== null) {
            Flash::error($fehler);
            Url::redirect('/admin/menue');
        }

        Database::update('menu_items', (int) $item['id'], $data);
        Audit::log('menu_updated', 'menu_item', (int) $item['id'], $data['label'] . ' → ' . $data['url']);
        Flash::success('Menüpunkt gespeichert.');
        Url::redirect('/admin/menue');
    }

    public function destroy(array $args): void
    {
        AuthController::requireRole('superuser');
        Csrf::verify();

        $item = $this->find((int) ($args['id'] ?? 0));

        Database::run('DELETE FROM menu_items WHERE id = ?', [(int) $item['id']]);
        Audit::log('menu_deleted', 'menu_item', (int) $item['id'], (string) $item['label']);
        Flash::success('Menüpunkt gelöscht.');
        Url::redirect('/admin/menue');
    }

    /**
     * Sichtbare Menuepunkte fuer eine Position (Frontend-Helper).
     *
     * @return list<array<string,mixed>>
     */
    public static function itemsFor(string $position): array
    {
        try {
            return Database::all(
                "SELECT label, url FROM menu_items
                  WHERE published = 1 AND (position = ? OR position = 'beide')
                  ORDER BY sort_order, id",
                [$position]
            );
        } catch (\PDOException) {
            return []; // Migration noch nicht gelaufen
        }
    }

    // --------------------------------------------------------------- Intern --

    /** @return array{0: array<string,mixed>, 1: ?string} */
    private function validate(): array
    {
        $label    = trim(post('label'));
        $url      = trim(post('url'));
        $position = post('position');

        if ($label === '') {
            return [[], 'Bitte eine Beschriftung angeben.'];
        }

        // Erlaubt: interner Pfad (/...), Anker auf der Startseite (/#... oder
        // #...) oder externe Adresse (http/https).
        if (str_starts_with($url, '#')) {
            $url = '/' . $url;
        }

        $intern = str_starts_with($url, '/') && !str_starts_with($url, '//');
        $extern = preg_match('#^https?://[^\s]+$#i', $url) === 1;

        if ($url === '' || (!$intern && !$extern)) {
            return [[], 'Die URL muss mit / beginnen (z. B. /meineurl oder /#anker) oder eine externe https://-Adresse sein.'];
        }

        return [[
            'label'      => mb_substr($label, 0, 60),
            'url'        => mb_substr($url, 0, 300),
            'position'   => isset(self::POSITIONS[$position]) ? $position : 'haupt',
            'sort_order' => post_int('sort_order'),
            'published'  => post_bool('published'),
        ], null];
    }

    /** @return array<string,mixed> */
    private function find(int $id): array
    {
        $item = Database::one('SELECT * FROM menu_items WHERE id = ?', [$id]);

        if ($item === null) {
            Flash::error('Menüpunkt nicht gefunden.');
            Url::redirect('/admin/menue');
        }

        return $item;
    }
}
