<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Config;
use App\Core\Url;
use App\Core\View;
use App\Models\PageRepo;
use App\Models\SectionRepo;
use App\Models\Setting;

final class PublicController
{
    public function home(): void
    {
        $sections = SectionRepo::allPublished();

        $angebote = implode(', ', array_slice(array_column($sections, 'name'), 0, 6));

        View::display('public/home', [
            'title'       => 'Training',
            'metaDesc'    => Setting::get('club_name', 'Unser Verein')
                             . ($angebote !== '' ? ' – ' . $angebote . '.' : '')
                             . ' Trainingsangebot, Wochenplan und Kontakt online.',
            'sections'    => $sections,
            'introTitle'  => Setting::get('home_title', 'Gym141'),
            'introText'   => Setting::get('home_text', ''),
            'pageBlocks'  => \App\Models\BlockRepo::forPage(null, publishedOnly: true),
            'blocksOnly'  => Setting::get('home_blocks_only', '0') === '1',
            'activePage'  => 'home',
        ]);
    }

    /** @param array<string,string> $args */
    public function section(array $args): void
    {
        $section = SectionRepo::findBySlug($args['slug'] ?? '');

        if ($section === null || (int) $section['published'] !== 1) {
            $this->notFound();

            return;
        }

        View::display('public/section', [
            'title'      => (string) $section['name'],
            'metaDesc'   => trim(($section['club_name'] ?: $section['name']) . ' – ' . $section['tagline']),
            'section'    => $section,
            'contacts'   => SectionRepo::contacts((int) $section['id']),
            'sections'   => SectionRepo::allPublished(),
            'activePage' => 'section',
        ]);
    }

    /**
     * Alte Drupal-URLs /sportart/{slug} dauerhaft auf /sektion/{slug} umleiten,
     * damit bestehende Links und Suchtreffer nicht ins Leere laufen.
     *
     * @param array<string,string> $args
     */
    public function legacySection(array $args): void
    {
        $slug = $args['slug'] ?? '';

        // Der alte Slug "hapkido" bleibt gueltig; sonst 1:1 uebernehmen.
        http_response_code(301);
        header('Location: ' . Url::to('/sektion/' . rawurlencode($slug)));
        exit;
    }

    /** @param array<string,string> $args */
    public function page(array $args): void
    {
        $page = PageRepo::findBySlug($args['slug'] ?? '');

        if ($page === null || (int) $page['published'] !== 1) {
            $this->notFound();

            return;
        }

        View::display('public/page', [
            'title'      => (string) $page['title'],
            'page'       => $page,
            'pageBlocks' => \App\Models\BlockRepo::forPage((int) $page['id'], publishedOnly: true),
            'activePage' => 'page',
        ]);
    }

    /**
     * robots.txt wird bewusst von PHP erzeugt: die Testumgebung muss anders
     * antworten als der Produktivbetrieb, obwohl beide dieselben Dateien nutzen können.
     */
    /**
     * Einbettbarer Wochenplan für bestehende Websites: läuft in einem iframe
     * (siehe public/embed.js) und darf deshalb – anders als der Rest der
     * Anwendung – von fremden Seiten eingebettet werden.
     */
    public function embedSchedule(): void
    {
        header_remove('X-Frame-Options');
        header('Content-Security-Policy: frame-ancestors *');

        // Kachel-Links nur, wenn die öffentliche Website überhaupt läuft.
        $planLinks = \App\Models\Setting::get('public_site', '1') !== '0';

        View::display('public/embed-schedule', [
            'week'      => \App\Models\Schedule::week(),
            'planLinks' => $planLinks,
        ], null);
    }

    public function robots(): void
    {
        header('Content-Type: text/plain; charset=UTF-8');

        $nurVerwaltung = \App\Models\Setting::get('public_site', '1') === '0';

        if ($nurVerwaltung || (bool) Config::get('noindex', false)) {
            echo "User-agent: *\nDisallow: /\n";

            return;
        }

        $host    = (string) Config::get('canonical_host', '') ?: (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
        $sitemap = 'https://' . $host . Url::to('/sitemap.xml');

        echo "User-agent: *\n"
            . "Disallow: /admin\n"
            . "Allow: /\n\n"
            . "Sitemap: $sitemap\n";
    }

    public function sitemap(): void
    {
        header('Content-Type: application/xml; charset=UTF-8');

        $host = (string) Config::get('canonical_host', '') ?: (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
        $base = 'https://' . $host;

        $urls = [['loc' => $base . Url::to('/'), 'prio' => '1.0']];

        foreach (SectionRepo::allPublished() as $section) {
            $urls[] = [
                'loc'  => $base . Url::to('/sektion/' . $section['slug']),
                'prio' => '0.8',
                'mod'  => substr((string) $section['updated_at'], 0, 10),
            ];
        }

        foreach (PageRepo::footerPages() as $page) {
            $urls[] = ['loc' => $base . Url::to('/seite/' . $page['slug']), 'prio' => '0.3'];
        }

        echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

        foreach ($urls as $url) {
            echo "  <url>\n";
            echo '    <loc>' . e($url['loc']) . "</loc>\n";

            if (($url['mod'] ?? '') !== '') {
                echo '    <lastmod>' . e($url['mod']) . "</lastmod>\n";
            }

            echo '    <priority>' . $url['prio'] . "</priority>\n";
            echo "  </url>\n";
        }

        echo '</urlset>' . "\n";
    }

    public function notFound(): void
    {
        http_response_code(404);

        View::display('errors/404', [
            'title'      => 'Seite nicht gefunden',
            'sections'   => SectionRepo::allPublished(),
            'activePage' => '',
        ]);
    }
}
