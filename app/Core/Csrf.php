<?php

declare(strict_types=1);

namespace App\Core;

final class Csrf
{
    public static function token(): string
    {
        if (empty($_SESSION['_csrf'])) {
            $_SESSION['_csrf'] = bin2hex(random_bytes(32));
        }

        return (string) $_SESSION['_csrf'];
    }

    public static function field(): string
    {
        return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(self::token(), ENT_QUOTES) . '">';
    }

    public static function isValid(?string $token): bool
    {
        $expected = $_SESSION['_csrf'] ?? '';

        return is_string($token) && $expected !== '' && hash_equals((string) $expected, $token);
    }

    /** Bricht die Anfrage ab, wenn das Token fehlt oder falsch ist. */
    public static function verify(): void
    {
        if (self::isValid($_POST['csrf_token'] ?? null)) {
            return;
        }

        http_response_code(419);
        Flash::error('Die Sitzung ist abgelaufen. Bitte erneut versuchen.');

        header('Location: ' . Url::to('/admin'));
        exit;
    }
}
