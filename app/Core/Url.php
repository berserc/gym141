<?php

declare(strict_types=1);

namespace App\Core;

final class Url
{
    /**
     * Baut eine absolute Pfad-URL inkl. base_path.
     *
     * @param array<string,scalar|null> $query
     */
    public static function to(string $path = '/', array $query = []): string
    {
        $base = rtrim((string) Config::get('base_path', ''), '/');
        $path = '/' . ltrim($path, '/');
        $url  = $base . ($path === '/' ? '/' : rtrim($path, '/'));

        $query = array_filter($query, static fn ($v): bool => $v !== null && $v !== '');

        if ($query !== []) {
            $url .= '?' . http_build_query($query);
        }

        return $url === '' ? '/' : $url;
    }

    /** URL einer hochgeladenen Datei (Pfad relativ zu public/uploads). */
    public static function upload(string $relativePath): string
    {
        if ($relativePath === '') {
            return '';
        }

        return self::to(rtrim((string) Config::get('upload_url', '/uploads'), '/') . '/' . ltrim($relativePath, '/'));
    }

    /**
     * URL einer statischen Datei aus public/assets.
     *
     * Haengt den Aenderungszeitpunkt als ?v= an. Der Webserver darf die Dateien
     * dadurch lange cachen (siehe public/.htaccess), ohne dass Besucher nach
     * einer Aenderung ein veraltetes Stylesheet behalten.
     */
    public static function asset(string $relativePath): string
    {
        $relativePath = ltrim($relativePath, '/');
        $url          = self::to('/assets/' . $relativePath);

        $file = dirname(__DIR__, 2) . '/public/assets/' . $relativePath;

        if (str_contains($relativePath, '..') || !is_file($file)) {
            return $url;
        }

        $stand = filemtime($file);

        return $stand === false ? $url : $url . '?v=' . $stand;
    }

    /** Aktuelle Anfrage-URL inkl. Querystring (fuer "zurueck zur Liste"). */
    public static function current(): string
    {
        return (string) ($_SERVER['REQUEST_URI'] ?? '/');
    }

    public static function redirect(string $path, array $query = []): never
    {
        header('Location: ' . self::to($path, $query));
        exit;
    }

    /** Leitet auf eine bereits fertige URL um (z. B. gemerkte Listenansicht). */
    public static function redirectRaw(string $url): never
    {
        // Nur eigene, absolute Pfade zulassen – keine externen Weiterleitungen.
        if ($url === '' || $url[0] !== '/' || str_starts_with($url, '//')) {
            $url = self::to('/admin');
        }

        header('Location: ' . $url);
        exit;
    }
}
