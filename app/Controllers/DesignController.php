<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Audit;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\Flash;
use App\Core\Url;
use App\Core\View;
use App\Models\Setting;

/**
 * Design-Baukasten fuer die oeffentliche Website: Farben (grafischer
 * Farbwaehler) und Schrift werden als Einstellungen gespeichert und im
 * Frontend als CSS-Variablen ueber site.css gelegt (theme_css()).
 *
 * Mitgeliefert werden ~20 fertige Templates (Konstante TEMPLATES, nicht
 * loeschbar, wandern mit Updates mit); eigene Zusammenstellungen kann der
 * Verein unter frei gewaehltem Namen speichern (Tabelle design_templates).
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

    /**
     * Waehlbare Schriften: key => [Label, CSS-Stack, Webfont-Datei|null].
     * Die Webfonts (OFL-lizenziert) liegen unter assets/fonts/ und werden
     * lokal ausgeliefert - keine externen Anfragen, DSGVO-unkritisch.
     */
    public const FONTS = [
        ''           => ['Standard (System)', "system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif", null],
        'inter'      => ['Inter – modern & neutral', "'Inter', system-ui, sans-serif", 'inter'],
        'montserrat' => ['Montserrat – markant', "'Montserrat', 'Segoe UI', sans-serif", 'montserrat'],
        'nunito'     => ['Nunito – freundlich rund', "'Nunito', 'Segoe UI', sans-serif", 'nunito'],
        'oswald'     => ['Oswald – kraftvoll schmal', "'Oswald', 'Arial Narrow', sans-serif", 'oswald'],
        'playfair'   => ['Playfair Display – elegant', "'Playfair Display', Georgia, serif", 'playfair'],
        'bebas'      => ['Bebas Neue – plakativ', "'Bebas Neue', 'Arial Narrow', sans-serif", 'bebas'],
        'serif'      => ['Klassisch (Georgia)', "Georgia, 'Times New Roman', serif", null],
        'rounded'    => ['Trebuchet', "'Trebuchet MS', Verdana, sans-serif", null],
        'mono'       => ['Technisch (Monospace)', "'Cascadia Code', Consolas, 'Courier New', monospace", null],
    ];

    /**
     * Mitgelieferte Templates (nicht loeschbar). Reihenfolge der Farben:
     * accent, accent_bright, bg, bg_soft, card, line, text, text_soft.
     */
    public const TEMPLATES = [
        'gold-nacht'      => ['Gold & Nacht (Standard)', ['#d4a437', '#f2cd6f', '#101014', '#17181d', '#1b1c22', '#2e2f38', '#f1efe8', '#a9a79d'], ''],
        'gruen-weiss'     => ['Grün & Weiß', ['#0a7a52', '#0f9b68', '#ffffff', '#eef5f1', '#f7faf9', '#d7e3dc', '#1a2420', '#5c6d64'], ''],
        'tannengruen'     => ['Tannengrün', ['#2ea977', '#4cd398', '#0e1512', '#14201a', '#18261f', '#26382f', '#eef5f0', '#9db3a8'], ''],
        'koenigsblau'     => ['Königsblau', ['#4d8dff', '#7fb0ff', '#0c1220', '#121a2e', '#16203a', '#263352', '#ecf1fa', '#98a6c2'], ''],
        'blau-weiss'      => ['Blau & Weiß', ['#1d5fd0', '#3b7ce8', '#ffffff', '#eff4fc', '#f7faff', '#d9e2f2', '#1a2233', '#5b6778'], ''],
        'rot-schwarz'     => ['Rot & Schwarz', ['#e33b3b', '#ff6b5e', '#120d0e', '#1c1416', '#221a1c', '#3a2b2e', '#f5eeee', '#b09fa1'], 'oswald'],
        'rot-weiss'       => ['Rot & Weiß', ['#c22127', '#e0393f', '#ffffff', '#faf0f0', '#fdf7f7', '#ecd9da', '#241a1b', '#74595b'], ''],
        'violett'         => ['Violette Nacht', ['#9a6bff', '#b78dff', '#100d1a', '#171228', '#1d1733', '#322a4d', '#f0edf8', '#a79fc0'], 'inter'],
        'orange-anthrazit'=> ['Orange & Anthrazit', ['#f07a1d', '#ffa04d', '#131211', '#1c1a18', '#23201d', '#3a352f', '#f4efe9', '#b3a89c'], 'montserrat'],
        'tuerkis'         => ['Türkis', ['#22c3b7', '#5adfd5', '#0b1516', '#112022', '#15282a', '#254042', '#eaf6f5', '#93b3b1'], ''],
        'mint-weiss'      => ['Mint & Weiß', ['#12a184', '#23bd9e', '#ffffff', '#edf7f4', '#f6fbf9', '#d5e8e2', '#182722', '#587068'], 'nunito'],
        'bordeaux-creme'  => ['Bordeaux & Creme', ['#7d1f34', '#a03249', '#faf5ef', '#f2e9df', '#fefaf5', '#e2d5c6', '#2b1a1a', '#7a615d'], 'playfair'],
        'schwarz-gelb'    => ['Schwarz-Gelb', ['#ffd400', '#ffe45c', '#0f0f0d', '#181712', '#201e16', '#3a3722', '#f7f4e8', '#b5b096'], 'oswald'],
        'himmelblau'      => ['Himmelblau (hell)', ['#2596d1', '#47b3ea', '#f6fafd', '#e9f3fa', '#ffffff', '#d3e4ef', '#16242e', '#5b7181'], ''],
        'natur-sand'      => ['Natur & Sand', ['#6c8a3f', '#86a659', '#f7f4ec', '#efe9db', '#fdfbf5', '#ded5c0', '#29251a', '#6f6a58'], 'nunito'],
        'magenta-nacht'   => ['Magenta Nacht', ['#e0369a', '#ff63bb', '#140d13', '#1e1420', '#271a29', '#432b45', '#f7edf4', '#bb9db3'], ''],
        'beton'           => ['Beton (minimal hell)', ['#37474f', '#546e7a', '#f4f5f6', '#e9ecee', '#ffffff', '#d5dadd', '#1d2529', '#66727a'], 'inter'],
        'nachtblau-kupfer'=> ['Nachtblau & Kupfer', ['#d9863c', '#f2a763', '#0d1420', '#131c2c', '#182338', '#2a3853', '#f0ede6', '#a3a596'], 'playfair'],
        'wald-creme'      => ['Wald & Creme', ['#2f6b3f', '#468a58', '#f6f7f2', '#ebeee2', '#fdfef9', '#d8ddc9', '#1e2a20', '#5e6f60'], 'montserrat'],
        'neon-schwarz'    => ['Neon auf Schwarz', ['#4dff88', '#86ffb0', '#0a0c0a', '#101510', '#141c15', '#23392a', '#eafbee', '#8fb99c'], 'bebas'],
    ];

    public function index(): void
    {
        AuthController::requireRole('superuser');

        $werte = [];

        foreach (self::COLORS as $key => $def) {
            $werte[$key] = Setting::get($key) ?: $def[3];
        }

        View::display('admin/design', [
            'title'    => 'Design',
            'colors'   => self::COLORS,
            'fonts'    => self::FONTS,
            'builtins' => self::TEMPLATES,
            'eigene'   => Database::all('SELECT * FROM design_templates ORDER BY name COLLATE NOCASE'),
            'werte'    => $werte,
            'font'     => Setting::get('theme_font'),
            'custom'   => $this->hasCustomTheme(),
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

        [$farben, $font] = $this->werteAusFormular();

        foreach (self::COLORS as $key => $def) {
            // Standardwert speichern wir nicht eigens - leer heisst Standard.
            Setting::set($key, $farben[$key] === strtolower($def[3]) ? '' : $farben[$key]);
        }

        Setting::set('theme_font', $font);

        Audit::log('design_save', 'settings');
        Flash::success('Design gespeichert – die Website verwendet die neuen Farben sofort.');
        Url::redirect('/admin/design');
    }

    /** Aktuelle Formularwerte als eigenes Template unter frei gewaehltem Namen sichern. */
    public function saveTemplate(): void
    {
        AuthController::requireRole('superuser');
        Csrf::verify();

        $name = trim(post('template_name'));

        if ($name === '' || mb_strlen($name) > 40) {
            Flash::error('Bitte einen Template-Namen mit höchstens 40 Zeichen angeben.');
            Url::redirect('/admin/design');
        }

        foreach (self::TEMPLATES as $builtin) {
            if (strcasecmp($builtin[0], $name) === 0) {
                Flash::error('Dieser Name ist von einem mitgelieferten Template belegt – bitte einen eigenen wählen.');
                Url::redirect('/admin/design');
            }
        }

        [$farben, $font] = $this->werteAusFormular();

        $config = json_encode(['colors' => array_values($farben), 'font' => $font]);

        $vorhanden = Database::one('SELECT id FROM design_templates WHERE name = ? COLLATE NOCASE', [$name]);

        if ($vorhanden !== null) {
            Database::update('design_templates', (int) $vorhanden['id'], ['name' => $name, 'config' => $config]);
            Flash::success('Template "' . $name . '" aktualisiert.');
        } else {
            Database::insert('design_templates', ['name' => $name, 'config' => $config]);
            Flash::success('Template "' . $name . '" gespeichert.');
        }

        Audit::log('design_template_save', 'design_templates', null, $name);
        Url::redirect('/admin/design');
    }

    public function deleteTemplate(): void
    {
        AuthController::requireRole('superuser');
        Csrf::verify();

        $id  = post_int('id');
        $row = Database::one('SELECT name FROM design_templates WHERE id = ?', [$id]);

        if ($row !== null) {
            Database::run('DELETE FROM design_templates WHERE id = ?', [$id]);
            Audit::log('design_template_delete', 'design_templates', $id, (string) $row['name']);
            Flash::success('Template "' . $row['name'] . '" gelöscht.');
        }

        Url::redirect('/admin/design');
    }

    // ------------------------------------------------------------------ Intern --

    /**
     * Farben und Schrift aus dem Formular validieren.
     *
     * @return array{0: array<string,string>, 1: string}
     */
    private function werteAusFormular(): array
    {
        $farben = [];

        foreach (self::COLORS as $key => $def) {
            $wert = strtolower(trim(post($key)));

            if (!preg_match('/^#[0-9a-f]{6}$/', $wert)) {
                Flash::error('Ungültige Farbe für "' . $def[1] . '" – bitte den Farbwähler verwenden.');
                Url::redirect('/admin/design');
            }

            $farben[$key] = $wert;
        }

        $font = post('theme_font');

        return [$farben, array_key_exists($font, self::FONTS) ? $font : ''];
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
