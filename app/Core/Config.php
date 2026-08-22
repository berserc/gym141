<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

final class Config
{
    /** @var array<string,mixed> */
    private static array $values = [];

    private static bool $loaded = false;

    public static function load(string $file): void
    {
        if (!is_file($file)) {
            throw new RuntimeException(
                'Konfiguration fehlt: ' . $file . ' – bitte app/config.example.php nach app/config.php kopieren.'
            );
        }

        /** @var array<string,mixed> $values */
        $values = require $file;

        self::$values = $values;
        self::$loaded = true;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        if (!self::$loaded) {
            throw new RuntimeException('Config::load() wurde nicht aufgerufen.');
        }

        return self::$values[$key] ?? $default;
    }

    public static function isLoaded(): bool
    {
        return self::$loaded;
    }
}
