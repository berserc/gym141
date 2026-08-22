<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Minimaler Router.
 *
 * Muster verwenden {name} als Platzhalter fuer ein Pfadsegment.
 * Beispiel: '/admin/mitglieder/{id}/bearbeiten'
 */
final class Router
{
    /** @var list<array{method:string,regex:string,params:list<string>,handler:callable}> */
    private array $routes = [];

    /** @var callable|null */
    private $notFound = null;

    public function get(string $pattern, callable $handler): void
    {
        $this->add('GET', $pattern, $handler);
    }

    public function post(string $pattern, callable $handler): void
    {
        $this->add('POST', $pattern, $handler);
    }

    public function put(string $pattern, callable $handler): void
    {
        $this->add('PUT', $pattern, $handler);
    }

    public function delete(string $pattern, callable $handler): void
    {
        $this->add('DELETE', $pattern, $handler);
    }

    /** Registriert das Muster fuer GET und POST. */
    public function any(string $pattern, callable $handler): void
    {
        $this->add('GET', $pattern, $handler);
        $this->add('POST', $pattern, $handler);
    }

    public function notFound(callable $handler): void
    {
        $this->notFound = $handler;
    }

    private function add(string $method, string $pattern, callable $handler): void
    {
        $params = [];

        // Literale Teile maskieren, damit Punkte in Pfaden (z. B. export.csv)
        // nicht als Regex-Platzhalter wirken.
        $regex = preg_replace_callback(
            '/\{([a-zA-Z_][a-zA-Z0-9_]*)\}|([^{]+)/',
            static function (array $m) use (&$params): string {
                if (($m[1] ?? '') !== '') {
                    $params[] = $m[1];

                    return '([^/]+)';
                }

                return preg_quote($m[2] ?? '', '#');
            },
            $pattern
        ) ?? preg_quote($pattern, '#');

        $this->routes[] = [
            'method'  => $method,
            'regex'   => '#^' . $regex . '$#',
            'params'  => $params,
            'handler' => $handler,
        ];
    }

    public function dispatch(string $method, string $path): void
    {
        // Trailing Slash normalisieren (ausser bei der Wurzel).
        if ($path !== '/' && str_ends_with($path, '/')) {
            $path = rtrim($path, '/');
        }

        if ($path === '') {
            $path = '/';
        }

        $pathMatchedButWrongMethod = false;

        foreach ($this->routes as $route) {
            if (!preg_match($route['regex'], $path, $matches)) {
                continue;
            }

            if ($route['method'] !== $method) {
                $pathMatchedButWrongMethod = true;
                continue;
            }

            array_shift($matches);

            $args = [];
            foreach ($route['params'] as $i => $name) {
                $args[$name] = rawurldecode($matches[$i] ?? '');
            }

            ($route['handler'])($args);

            return;
        }

        if ($pathMatchedButWrongMethod) {
            http_response_code(405);
            header('Allow: GET, POST');
            echo 'Methode nicht erlaubt.';

            return;
        }

        if ($this->notFound !== null) {
            ($this->notFound)([]);

            return;
        }

        http_response_code(404);
        echo 'Seite nicht gefunden.';
    }
}
