<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Csrf;
use App\Core\Flash;
use App\Core\Url;
use App\Core\View;
use App\Models\Setting;

/**
 * Design-Baukasten fuer die oeffentliche Website: Farben (grafischer
 * Farbwaehler) und Schrift werden als Einstellungen gespeichert und im
 * Frontend als CSS-Variablen ueber site.css gelegt (siehe theme_css()
 * in helpers.php). Leer = Standard-Design (dunkel/gold).
 */
final class DesignController
{
    /** Einstellbare Farben: Setting-Key => [CSS-Variablen, Label, Beschreibung, Standard]. */
    public const COLORS = [
        'theme_accent'        => [['--gold', '--link'], 'Akzentfarbe', 'Buttons, Links, Überschriften-Akzente', '#d4a437'],
        'theme_accent_bright' => [['--gold-bright', '--link-hover'], 'Akzent (hell)', 'Hover-Zustände und Verläufe', '#f2cd6f'],
        'theme_bg'            => [['--bg', '--dark'], 'Hintergrund', 'Grundfläche der Website', '#101014'],
        'theme_bg_soft'       => [['--bg-soft'], 'Hintergrund (weich)', 'Abgesetzte Bereiche', '#17181d'],
        'theme_card'          => [['--card'], 'Kacheln/Karten', 'Trainingsgruppen-Kacheln, Info-Boxen', '#1b1c22'],
        'theme_line'          => [['--line'], 'Linien/Rahmen', 'Trennlinien und Ränder', '#2e2f38'],
        'theme_text'          => [['--ink'], 'Text', 'Haupttextfarbe', '#f1efe8'],
        'theme_text_soft'     => [['--ink-soft'], 'Text (gedämpft)', 'Nebentexte und Hinweise', '#a9a79d'],
    ];

    /** Waehlbare Schriften (websicher, ohne externe Webfonts – DSGVO-frei). */
    public const FONTS = [
        ''        => ['Standard (System)', "system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif"],
        'serif'   => ['Klassisch (Serif)', "Georgia, 'Times New Roman', serif"],
        'rounded' => ['Freundlich (Trebuchet)', "'Trebuchet MS', Verdana, sans-serif"],
        'narrow'  => ['Kompakt (Arial Narrow)', "'Arial Narrow', Arial, sans-serif"],
        'mono'    => ['Technisch (Monospace)', "'Cascadia Code', Consolas, 'Courier New', monospace"],
    ];

    public function index(): void
    {
        AuthController::requireRole('superuser');

        $werte = [];

        foreach (self::COLORS as $key => $def) {
            $werte[$key] = Setting::get($key) ?: $def[3];
        }

        View::display('admin/design', [
            'title'  => 'Design',
            'colors' => self::COLORS,
            'fonts'  => self::FONTS,
            'werte'  => $werte,
            'font'   => Setting::get('theme_font'),
            'custom' => $this->hasCustomTheme(),
        ], 'layouts/admin');
    }

    public function save(): void
    {
        AuthController::requireRole('superuser');
        Csrf::verify();

        // Zuruecksetzen: alle Design-Einstellungen leeren -> Standard.
        if (post('reset') === '1') {
            foreach (array_keys(self::COLORS) as $key) {
                Setting::set($key, '');
            }

            Setting::set('theme_font', '');
            Audit::log('design_reset', 'settings');
            Flash::success('Design auf den Standard zurückgesetzt.');
            Url::redirect('/admin/design');
        }

        foreach (self::COLORS as $key => $def) {
            $wert = strtolower(trim(post($key)));

            if (!preg_match('/^#[0-9a-f]{6}$/', $wert)) {
                Flash::error('Ungültige Farbe für "' . $def[1] . '" – bitte den Farbwähler verwenden.');
                Url::redirect('/admin/design');
            }

            // Standardwert speichern wir nicht eigens - leer heisst Standard.
            Setting::set($key, $wert === strtolower($def[3]) ? '' : $wert);
        }

        $font = post('theme_font');
        Setting::set('theme_font', array_key_exists($font, self::FONTS) ? $font : '');

        Audit::log('design_save', 'settings');
        Flash::success('Design gespeichert – die Website verwendet die neuen Farben sofort.');
        Url::redirect('/admin/design');
    }

    private function hasCustomTheme(): bool
    {
        foreach (array_keys(self::COLORS) as $key) {
            if (Setting::get($key) !== '') {
                return true;
            }
        }

        return Setting::get('theme_font') !== '';
    }
}
