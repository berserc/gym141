<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/**
 * Bildverarbeitung fuer die Bildbibliothek: Upload uebernehmen, nach EXIF drehen,
 * auf eine maximale Kantenlaenge verkleinern und eine Vorschau erzeugen.
 * Ergebnis sind immer JPEGs (PNG-Transparenz bleibt als PNG erhalten).
 */
final class ImageTool
{
    public const MAX_EDGE   = 1800;
    public const THUMB_EDGE = 480;
    public const MAX_BYTES  = 25 * 1024 * 1024;

    /**
     * Uebernimmt eine hochgeladene Datei in <upload_dir>/<subDir>/ (+ /t/ fuer die Vorschau).
     *
     * @param array<string,mixed> $file ein Eintrag aus $_FILES (name/tmp_name/error/size)
     * @return array{file:string,thumb:string,width:int,height:int} Pfade relativ zum Upload-Ordner
     */
    public static function ingest(array $file, string $subDir = 'bilder', string $prefix = 'b'): array
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new RuntimeException(self::errorMessage((int) ($file['error'] ?? 0)));
        }
        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_file($tmp)) {
            throw new RuntimeException('Datei fehlt.');
        }
        if ((int) ($file['size'] ?? 0) > self::MAX_BYTES) {
            throw new RuntimeException('Die Datei ist größer als ' . (self::MAX_BYTES / 1048576) . ' MB.');
        }
        if (!function_exists('imagecreatetruecolor')) {
            throw new RuntimeException('Die Bildbibliothek braucht die PHP-Erweiterung GD.');
        }

        $info = @getimagesize($tmp);
        if ($info === false) {
            throw new RuntimeException('Die Datei ist kein Bild.');
        }
        $type = (int) $info[2];
        $src  = match ($type) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($tmp),
            IMAGETYPE_PNG  => @imagecreatefrompng($tmp),
            IMAGETYPE_GIF  => @imagecreatefromgif($tmp),
            IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($tmp) : false,
            default        => false,
        };
        if ($src === false) {
            throw new RuntimeException('Nur JPG, PNG, GIF oder WebP – oder das Bild ist zu groß für den Speicher.');
        }

        // Handyfotos: Drehung aus den EXIF-Daten anwenden
        if ($type === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
            $exif = @exif_read_data($tmp);
            $src  = match ((int) ($exif['Orientation'] ?? 1)) {
                3 => imagerotate($src, 180, 0),
                6 => imagerotate($src, -90, 0),
                8 => imagerotate($src, 90, 0),
                default => $src,
            };
        }

        $keepPng = $type === IMAGETYPE_PNG;
        $ext     = $keepPng ? 'png' : 'jpg';
        $base    = rtrim((string) Config::get('upload_dir'), '/\\') . '/' . trim($subDir, '/');
        foreach ([$base, $base . '/t'] as $dir) {
            if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
                throw new RuntimeException('Der Upload-Ordner konnte nicht angelegt werden.');
            }
        }
        $name  = $prefix . date('ymd') . '-' . substr(bin2hex(random_bytes(4)), 0, 7);
        $w     = imagesx($src);
        $h     = imagesy($src);
        $big   = self::scale($src, $w, $h, self::MAX_EDGE, $keepPng);
        $thumb = self::scale($src, $w, $h, self::THUMB_EDGE, $keepPng);

        $okBig = $keepPng ? imagepng($big, "$base/$name.$ext", 6) : imagejpeg($big, "$base/$name.$ext", 84);
        $okTh  = $keepPng ? imagepng($thumb, "$base/t/$name.$ext", 6) : imagejpeg($thumb, "$base/t/$name.$ext", 80);
        if (!$okBig || !$okTh) {
            throw new RuntimeException('Das Bild konnte nicht gespeichert werden (Schreibrechte im Upload-Ordner?).');
        }
        @chmod("$base/$name.$ext", 0644);
        @chmod("$base/t/$name.$ext", 0644);

        $rel = trim($subDir, '/');

        return [
            'file'   => "$rel/$name.$ext",
            'thumb'  => "$rel/t/$name.$ext",
            'width'  => imagesx($big),
            'height' => imagesy($big),
        ];
    }

    /** Skaliert auf die maximale Kantenlaenge (nie vergroessern). */
    private static function scale(\GdImage $src, int $w, int $h, int $maxEdge, bool $alpha): \GdImage
    {
        $factor = min(1, $maxEdge / max(1, max($w, $h)));
        if ($factor >= 1) {
            return $src;
        }
        $nw  = max(1, (int) round($w * $factor));
        $nh  = max(1, (int) round($h * $factor));
        $dst = imagecreatetruecolor($nw, $nh);
        if ($alpha) {
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
        }
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);

        return $dst;
    }

    private static function errorMessage(int $code): string
    {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Die Datei ist zu groß (Server-Limit).',
            UPLOAD_ERR_PARTIAL                        => 'Der Upload wurde abgebrochen.',
            UPLOAD_ERR_NO_FILE                        => 'Keine Datei ausgewählt.',
            default                                   => 'Beim Upload ist ein Fehler aufgetreten.',
        };
    }
}
