<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;
use Throwable;

/**
 * Schlichtes PHP-Templating: Views sind PHP-Dateien unter app/Views.
 * $__layout bestimmt, welches Layout den gerenderten Inhalt umschliesst.
 */
final class View
{
    /** @var array<string,mixed> Werte, die in jedem Template verfuegbar sind. */
    private static array $shared = [];

    public static function share(string $key, mixed $value): void
    {
        self::$shared[$key] = $value;
    }

    /** @param array<string,mixed> $data */
    public static function render(string $template, array $data = [], ?string $layout = 'layouts/public'): string
    {
        $content = self::capture($template, $data);

        if ($layout === null) {
            return $content;
        }

        return self::capture($layout, $data + ['content' => $content]);
    }

    /** @param array<string,mixed> $data */
    public static function display(string $template, array $data = [], ?string $layout = 'layouts/public'): void
    {
        echo self::render($template, $data, $layout);
    }

    /** @param array<string,mixed> $data */
    private static function capture(string $template, array $data): string
    {
        $file = dirname(__DIR__) . '/Views/' . $template . '.php';

        if (!is_file($file)) {
            throw new RuntimeException('Template nicht gefunden: ' . $template);
        }

        extract(self::$shared, EXTR_SKIP);
        extract($data, EXTR_OVERWRITE);

        ob_start();

        try {
            require $file;
        } catch (Throwable $e) {
            ob_end_clean();

            throw $e;
        }

        return (string) ob_get_clean();
    }
}
