<?php

declare(strict_types=1);

use App\Core\Config;
use App\Core\Csrf;
use App\Core\Url;

/** HTML-Escaping fuer die Ausgabe in Templates. */
function e(mixed $value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function url(string $path = '/', array $query = []): string
{
    return Url::to($path, $query);
}

function asset(string $path): string
{
    return Url::asset($path);
}

function upload_url(string $path): string
{
    return Url::upload($path);
}

function csrf_field(): string
{
    return Csrf::field();
}

/**
 * Wandelt eine Telefonnummer in ein tel:-taugliches Format.
 *
 * "03172 / 2197"      -> "+3431722197"
 * "0664 / 1603800"    -> "+436641603800"
 * "+43 664 8841 6636" -> "+4366488416636"
 */
function tel_href(string $number): string
{
    $number = trim($number);

    if ($number === '') {
        return '';
    }

    $plus   = str_starts_with($number, '+');
    $digits = preg_replace('/\D+/', '', $number) ?? '';

    if ($digits === '') {
        return '';
    }

    if ($plus) {
        return '+' . $digits;
    }

    if (str_starts_with($digits, '00')) {
        return '+' . substr($digits, 2);
    }

    if (str_starts_with($digits, '0')) {
        // Fuehrende Verkehrsausscheidungsziffer durch Laendervorwahl ersetzen.
        return '+' . (string) Config::get('country_code', '43') . substr($digits, 1);
    }

    return $digits;
}

/** Klickbarer Telefonlink; gibt bei leerer Nummer einen leeren String zurueck. */
function tel_link(string $number, string $class = 'contact-link'): string
{
    $href = tel_href($number);

    if ($href === '') {
        return '';
    }

    return sprintf(
        '<a class="%s" href="tel:%s">%s</a>',
        e($class),
        e($href),
        e(trim($number))
    );
}

function mail_link(string $address, string $class = 'contact-link'): string
{
    $address = trim($address);

    if ($address === '' || !filter_var($address, FILTER_VALIDATE_EMAIL)) {
        return e($address);
    }

    return sprintf('<a class="%s" href="mailto:%s">%s</a>', e($class), e($address), e($address));
}

function link_out(string $url, string $label = '', string $class = 'contact-link'): string
{
    $url = trim($url);

    if ($url === '') {
        return '';
    }

    if (!preg_match('#^https?://#i', $url)) {
        $url = 'https://' . $url;
    }

    $label = $label !== '' ? $label : preg_replace('#^https?://(www\.)?#i', '', $url) ?? $url;

    return sprintf(
        '<a class="%s" href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
        e($class),
        e($url),
        e(rtrim((string) $label, '/'))
    );
}

/** "2026-07-27" -> "27.07.2026" */
function format_date(?string $isoDate): string
{
    if ($isoDate === null || trim($isoDate) === '') {
        return '';
    }

    $ts = strtotime($isoDate);

    return $ts === false ? $isoDate : date('d.m.Y', $ts);
}

function format_datetime(?string $iso): string
{
    if ($iso === null || trim($iso) === '') {
        return '';
    }

    $ts = strtotime($iso . ' UTC');

    return $ts === false ? $iso : date('d.m.Y H:i', $ts);
}

function format_money(float|int|string|null $amount): string
{
    return number_format((float) $amount, 2, ',', '.') . ' €';
}

/** Alter in Jahren zum Stichtag heute. */
function age_from(?string $birthdate): ?int
{
    if ($birthdate === null || trim($birthdate) === '') {
        return null;
    }

    try {
        $birth = new DateTimeImmutable($birthdate);
    } catch (Exception) {
        return null;
    }

    return (int) $birth->diff(new DateTimeImmutable('today'))->y;
}

/** Erzeugt einen URL-tauglichen Slug aus einem deutschen Titel. */
function slugify(string $text): string
{
    $map  = ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'Ä' => 'ae', 'Ö' => 'oe', 'Ü' => 'ue', 'ß' => 'ss'];
    $text = strtr($text, $map);
    $text = mb_strtolower($text, 'UTF-8');
    $text = preg_replace('/[^a-z0-9]+/u', '-', $text) ?? '';

    return trim($text, '-');
}

/**
 * Laesst nur eine kleine Menge harmloser Tags durch.
 * Reicht fuer redaktionelle Texte, die ausschliesslich angemeldete Redakteure pflegen.
 */
function safe_html(string $html): string
{
    // strip_tags() entfernt nur die Tags, nicht deren Inhalt: aus
    // "<script>alert(1)</script>" bliebe sonst der sichtbare Text "alert(1)".
    // Diese Elemente werden daher samt Inhalt entfernt.
    $html = preg_replace(
        '#<(script|style|iframe|object|embed|noscript|template|svg)\b[^>]*>.*?</\1\s*>#is',
        '',
        $html
    ) ?? $html;

    // Nicht geschlossene Varianten und Kommentare ebenfalls verwerfen.
    $html = preg_replace('#<(script|style|iframe|object|embed)\b[^>]*>.*#is', '', $html) ?? $html;
    $html = preg_replace('#<!--.*?-->#s', '', $html) ?? $html;

    $allowed = '<p><br><strong><b><em><i><u><ul><ol><li><h2><h3><h4><a><blockquote><hr><table><thead><tbody><tr><th><td><small>';
    $clean   = strip_tags($html, $allowed);

    // Event-Handler und javascript:-URLs entfernen.
    $clean = preg_replace('/\son[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $clean) ?? $clean;
    $clean = preg_replace('/(href|src)\s*=\s*("|\')\s*javascript:[^"\']*(\2)/i', '$1="#"', $clean) ?? $clean;

    return $clean;
}

/** Wandelt Zeilenumbrueche eines Freitextfeldes in Absaetze. */
function nl2p(string $text): string
{
    $parts = preg_split('/\R{2,}/', trim($text)) ?: [];
    $out   = '';

    foreach ($parts as $part) {
        if (trim($part) === '') {
            continue;
        }

        $out .= '<p>' . nl2br(e($part)) . '</p>';
    }

    return $out;
}

/** Liest einen GET-Parameter als String. */
function query(string $key, string $default = ''): string
{
    $value = $_GET[$key] ?? $default;

    return is_string($value) ? trim($value) : $default;
}

/** Liest einen POST-Parameter als getrimmten String. */
function post(string $key, string $default = ''): string
{
    $value = $_POST[$key] ?? $default;

    return is_string($value) ? trim($value) : $default;
}

function post_int(string $key, int $default = 0): int
{
    $value = $_POST[$key] ?? null;

    return is_numeric($value) ? (int) $value : $default;
}

function post_float(string $key, float $default = 0.0): float
{
    $value = $_POST[$key] ?? null;

    if (!is_string($value) && !is_numeric($value)) {
        return $default;
    }

    // Deutsche Eingabe "12,50" ebenfalls akzeptieren.
    $normalized = str_replace([' ', "\u{00a0}"], '', (string) $value);
    $normalized = str_replace(',', '.', $normalized);

    return is_numeric($normalized) ? (float) $normalized : $default;
}

function post_bool(string $key): int
{
    return isset($_POST[$key]) && $_POST[$key] !== '' && $_POST[$key] !== '0' ? 1 : 0;
}

/**
 * Normalisiert eine Datumseingabe auf YYYY-MM-DD.
 * Akzeptiert "1998-04-23", "23.04.1998" und "23.4.1998".
 */
function parse_date(string $input): ?string
{
    $input = trim($input);

    if ($input === '') {
        return null;
    }

    foreach (['Y-m-d', 'd.m.Y', 'j.n.Y', 'd.m.y', 'd/m/Y'] as $format) {
        $date = DateTimeImmutable::createFromFormat('!' . $format, $input);

        if ($date !== false && $date->format($format) === $input) {
            return $date->format('Y-m-d');
        }
    }

    $ts = strtotime($input);

    return $ts === false ? null : date('Y-m-d', $ts);
}

/** @return array{0:int,1:int,2:int} [seite, offset, seitenAnzahl] */
function paginate(int $total, int $perPage, int $page): array
{
    $pages = max(1, (int) ceil($total / max(1, $perPage)));
    $page  = max(1, min($page, $pages));

    return [$page, ($page - 1) * $perPage, $pages];
}

function client_ip(): string
{
    return (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
}

/**
 * Einfaches SVG-Liniendiagramm (ohne externe Bibliotheken).
 *
 * @param list<array{label:string,value:float}> $points chronologisch sortiert
 * @param array{min?:float,max?:float,unit?:string,color?:string} $options
 */
function svg_line_chart(array $points, array $options = []): string
{
    if ($points === []) {
        return '<p class="muted">Noch keine Daten.</p>';
    }

    $w = 720;
    $h = 220;
    $padL = 44;
    $padR = 12;
    $padT = 12;
    $padB = 28;

    $values = array_map(static fn (array $p): float => $p['value'], $points);
    $min    = $options['min'] ?? min($values);
    $max    = $options['max'] ?? max($values);

    if ($max - $min < 0.001) {
        $min -= 1;
        $max += 1;
    }

    // etwas Luft nach oben/unten – aber nur bei automatisch ermittelten Grenzen
    $spanne = $max - $min;
    if (!isset($options['min'])) {
        $min -= $spanne * 0.08;
    }
    if (!isset($options['max'])) {
        $max += $spanne * 0.08;
    }

    $n  = count($points);
    $x  = static fn (int $i): float => $n === 1
        ? ($padL + ($w - $padL - $padR) / 2)
        : $padL + ($w - $padL - $padR) * $i / ($n - 1);
    $y  = static fn (float $v): float => $padT + ($h - $padT - $padB) * (1 - ($v - $min) / ($max - $min));

    $unit  = $options['unit'] ?? '';
    $farbe = $options['color'] ?? 'var(--gold)';

    $svg = '<svg viewBox="0 0 ' . $w . ' ' . $h . '" class="chart" role="img" preserveAspectRatio="xMidYMid meet">';

    // Y-Achse: 4 Hilfslinien mit Beschriftung
    for ($g = 0; $g <= 3; $g++) {
        $wert = $min + ($max - $min) * $g / 3;
        $yy   = $y($wert);
        $svg .= '<line x1="' . $padL . '" y1="' . $yy . '" x2="' . ($w - $padR) . '" y2="' . $yy . '" class="chart__grid"/>';
        $svg .= '<text x="' . ($padL - 6) . '" y="' . ($yy + 4) . '" text-anchor="end" class="chart__tick">'
            . e(number_format($wert, $max - $min > 20 ? 0 : 1, ',', '')) . '</text>';
    }

    // Linie
    $pfad = '';
    foreach ($points as $i => $p) {
        $pfad .= ($i === 0 ? 'M' : 'L') . round($x($i), 1) . ' ' . round($y($p['value']), 1) . ' ';
    }
    $svg .= '<path d="' . e(trim($pfad)) . '" fill="none" stroke="' . e($farbe) . '" stroke-width="2.5" stroke-linejoin="round" stroke-linecap="round"/>';

    // Punkte mit Tooltip
    foreach ($points as $i => $p) {
        $svg .= '<circle cx="' . round($x($i), 1) . '" cy="' . round($y($p['value']), 1) . '" r="4" fill="' . e($farbe) . '">'
            . '<title>' . e($p['label'] . ': ' . number_format($p['value'], 1, ',', '.') . ($unit !== '' ? ' ' . $unit : '')) . '</title></circle>';
    }

    // X-Beschriftung: erste, mittlere, letzte
    foreach (array_unique([0, intdiv($n - 1, 2), $n - 1]) as $i) {
        $svg .= '<text x="' . round($x($i), 1) . '" y="' . ($h - 8) . '" text-anchor="middle" class="chart__tick">'
            . e($points[$i]['label']) . '</text>';
    }

    return $svg . '</svg>';
}

/**
 * Einfaches SVG-Balkendiagramm (z. B. Trainings je Monat).
 *
 * @param list<array{label:string,value:float}> $bars
 */
function svg_bar_chart(array $bars, array $options = []): string
{
    if ($bars === []) {
        return '<p class="muted">Noch keine Daten.</p>';
    }

    $w = 720;
    $h = 220;
    $padL = 34;
    $padR = 12;
    $padT = 12;
    $padB = 28;

    $max = max(1.0, max(array_map(static fn (array $b): float => $b['value'], $bars)));
    $n   = count($bars);
    $bw  = ($w - $padL - $padR) / $n * 0.66;
    $farbe = $options['color'] ?? 'var(--gold)';

    $svg = '<svg viewBox="0 0 ' . $w . ' ' . $h . '" class="chart" role="img" preserveAspectRatio="xMidYMid meet">';

    for ($g = 0; $g <= 3; $g++) {
        $wert = $max * $g / 3;
        $yy   = $padT + ($h - $padT - $padB) * (1 - $g / 3);
        $svg .= '<line x1="' . $padL . '" y1="' . $yy . '" x2="' . ($w - $padR) . '" y2="' . $yy . '" class="chart__grid"/>';
        $svg .= '<text x="' . ($padL - 6) . '" y="' . ($yy + 4) . '" text-anchor="end" class="chart__tick">' . e((string) round($wert)) . '</text>';
    }

    foreach ($bars as $i => $b) {
        $cx = $padL + ($w - $padL - $padR) * ($i + 0.5) / $n;
        $bh = ($h - $padT - $padB) * $b['value'] / $max;
        $svg .= '<rect x="' . round($cx - $bw / 2, 1) . '" y="' . round($h - $padB - $bh, 1) . '" width="' . round($bw, 1) . '" height="' . round($bh, 1) . '" rx="3" fill="' . e($farbe) . '" fill-opacity="0.85">'
            . '<title>' . e($b['label'] . ': ' . (string) round($b['value'])) . '</title></rect>';
        $svg .= '<text x="' . round($cx, 1) . '" y="' . ($h - 8) . '" text-anchor="middle" class="chart__tick">' . e($b['label']) . '</text>';
    }

    return $svg . '</svg>';
}

/**
 * Tab-Leiste fuer zusammengehoerige Verwaltungsseiten.
 *
 * @param list<array{0:string,1:string}> $tabs [Label, Pfad] – aktiv ist der aktuelle Pfad
 */
function admin_tabs(array $tabs): string
{
    $current = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
    $html    = '<nav class="tabs" aria-label="Unterseiten">';

    foreach ($tabs as [$label, $path]) {
        $href   = url($path);
        $active = $current === $href;
        $html  .= '<a href="' . e($href) . '" class="tabs__tab' . ($active ? ' is-active' : '') . '"'
            . ($active ? ' aria-current="page"' : '') . '>' . e($label) . '</a>';
    }

    return $html . '</nav>';
}

/**
 * WhatsApp-Chat-Link (wa.me) mit optional vorbefuelltem Text.
 * Nummer aus Setting whatsapp_number, sonst Vereinstelefon.
 */
function whatsapp_link(string $text = ''): string
{
    // Ohne hinterlegte WhatsApp-Nummer gibt es keinen Button ('' = ausblenden).
    $nummer = \App\Models\Setting::get('whatsapp_number');

    if ($nummer === '') {
        return '';
    }

    // wa.me erwartet nur Ziffern inkl. Laendervorwahl (+ und 00 entfernen).
    $ziffern = preg_replace('/\D+/', '', $nummer) ?? '';
    $ziffern = preg_replace('/^00/', '', $ziffern) ?? '';

    if ($ziffern === '') {
        return '';
    }

    return 'https://wa.me/' . $ziffern . ($text !== '' ? '?text=' . rawurlencode($text) : '');
}

/**
 * URL des Website-Logos, falls eines unter assets/img/logo.* liegt
 * ('' = kein Logo, nur Textmarke anzeigen).
 */
function site_logo(): string
{
    static $logo = null;

    if ($logo !== null) {
        return $logo;
    }

    foreach (['svg', 'png', 'jpg', 'jpeg', 'webp'] as $ext) {
        if (is_file(dirname(__DIR__) . '/public/assets/img/logo.' . $ext)) {
            return $logo = asset('img/logo.' . $ext);
        }
    }

    return $logo = '';
}

/**
 * Vom Design-Baukasten (Verwaltung -> Design) gesetzte Farben und Schrift
 * als Inline-Style fuer die oeffentliche Website. Leerer String, wenn das
 * Standard-Design aktiv ist. Wird in den Public-/Mitglieder-Layouts nach
 * site.css eingebunden und ueberschreibt dessen CSS-Variablen.
 */
function theme_css(): string
{
    static $css = null;

    if ($css !== null) {
        return $css;
    }

    $variablen = [];

    foreach (\App\Controllers\DesignController::COLORS as $key => $def) {
        $wert = \App\Models\Setting::get($key);

        if ($wert !== '' && preg_match('/^#[0-9a-f]{6}$/i', $wert)) {
            foreach ($def[0] as $cssVar) {
                $variablen[] = $cssVar . ':' . $wert;
            }
        }
    }

    $regeln = $variablen === [] ? '' : ':root{' . implode(';', $variablen) . '}';

    $font = \App\Models\Setting::get('theme_font');

    if ($font !== '' && isset(\App\Controllers\DesignController::FONTS[$font])) {
        $regeln .= 'body{font-family:' . \App\Controllers\DesignController::FONTS[$font][1] . '}';
    }

    return $css = $regeln === '' ? '' : '<style id="theme">' . $regeln . '</style>';
}
