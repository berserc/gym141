<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/** Bild-Uploads fuer Sektionslogos, Kacheln und Titelbilder. */
final class Upload
{
    private const MAX_BYTES = 8 * 1024 * 1024; // 8 MB

    private const ALLOWED = [
        IMAGETYPE_JPEG => 'jpg',
        IMAGETYPE_PNG  => 'png',
        IMAGETYPE_GIF  => 'gif',
        IMAGETYPE_WEBP => 'webp',
    ];

    /**
     * Nimmt eine hochgeladene Datei entgegen und liefert den Pfad relativ zu public/uploads.
     *
     * @param array<string,mixed>|null $file Eintrag aus $_FILES
     * @throws RuntimeException bei ungueltigen Dateien
     */
    public static function image(?array $file, string $subDir, string $basename, int $maxWidth = 1600): ?string
    {
        if ($file === null || !isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
            return null;
        }

        if ($file['error'] !== UPLOAD_ERR_OK) {
            throw new RuntimeException(self::errorMessage((int) $file['error']));
        }

        $tmp = (string) ($file['tmp_name'] ?? '');

        if ($tmp === '' || !is_uploaded_file($tmp)) {
            throw new RuntimeException('Die Datei konnte nicht gelesen werden.');
        }

        if ((int) ($file['size'] ?? 0) > self::MAX_BYTES) {
            throw new RuntimeException('Die Datei ist größer als 8 MB.');
        }

        $info = @getimagesize($tmp);

        if ($info === false || !isset(self::ALLOWED[$info[2]])) {
            throw new RuntimeException('Nur JPG-, PNG-, GIF- oder WEBP-Bilder sind erlaubt.');
        }

        $extension = self::ALLOWED[$info[2]];
        $dir       = rtrim((string) Config::get('upload_dir'), '/\\') . '/' . trim($subDir, '/');

        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('Das Upload-Verzeichnis konnte nicht angelegt werden.');
        }

        // Zeitstempel im Dateinamen umgeht den Browser-Cache nach einem Bildwechsel.
        $filename = $basename . '-' . time() . '.' . $extension;
        $target   = $dir . '/' . $filename;

        $resized = self::resize($tmp, $target, $info, $maxWidth);

        if (!$resized && !move_uploaded_file($tmp, $target)) {
            throw new RuntimeException('Die Datei konnte nicht gespeichert werden.');
        }

        @chmod($target, 0644);

        return trim($subDir, '/') . '/' . $filename;
    }

    /** Loescht eine zuvor hochgeladene Datei (Pfad relativ zu public/uploads). */
    public static function delete(string $relativePath): void
    {
        $relativePath = trim($relativePath, '/');

        if ($relativePath === '' || str_contains($relativePath, '..')) {
            return;
        }

        $file = rtrim((string) Config::get('upload_dir'), '/\\') . '/' . $relativePath;

        if (is_file($file)) {
            @unlink($file);
        }
    }

    /**
     * Verkleinert das Bild, falls GD verfuegbar ist und die Breite ueberschritten wird.
     *
     * @param array<int|string,mixed> $info Ergebnis von getimagesize()
     */
    private static function resize(string $source, string $target, array $info, int $maxWidth): bool
    {
        if (!function_exists('imagecreatetruecolor')) {
            return false;
        }

        $width  = (int) $info[0];
        $height = (int) $info[1];
        $type   = (int) $info[2];

        if ($width <= $maxWidth) {
            return false;
        }

        $src = match ($type) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($source),
            IMAGETYPE_PNG  => @imagecreatefrompng($source),
            IMAGETYPE_GIF  => @imagecreatefromgif($source),
            IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($source) : false,
            default        => false,
        };

        if ($src === false) {
            return false;
        }

        $newHeight = max(1, (int) round($height * ($maxWidth / $width)));
        $dst       = imagecreatetruecolor($maxWidth, $newHeight);

        if ($type === IMAGETYPE_PNG || $type === IMAGETYPE_GIF || $type === IMAGETYPE_WEBP) {
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
        }

        imagecopyresampled($dst, $src, 0, 0, 0, 0, $maxWidth, $newHeight, $width, $height);

        $ok = match ($type) {
            IMAGETYPE_JPEG => imagejpeg($dst, $target, 84),
            IMAGETYPE_PNG  => imagepng($dst, $target, 6),
            IMAGETYPE_GIF  => imagegif($dst, $target),
            IMAGETYPE_WEBP => function_exists('imagewebp') && imagewebp($dst, $target, 84),
            default        => false,
        };

        imagedestroy($src);
        imagedestroy($dst);

        return $ok;
    }

    private static function errorMessage(int $code): string
    {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Die Datei ist zu groß.',
            UPLOAD_ERR_PARTIAL                        => 'Der Upload wurde abgebrochen.',
            UPLOAD_ERR_NO_TMP_DIR                     => 'Auf dem Server fehlt ein temporäres Verzeichnis.',
            UPLOAD_ERR_CANT_WRITE                     => 'Die Datei konnte nicht geschrieben werden.',
            default                                   => 'Beim Upload ist ein Fehler aufgetreten.',
        };
    }
}
