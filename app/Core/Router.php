<?php
declare(strict_types=1);

namespace App\Core;

final class Router
{
    /** @var array<string, array<string, array{handler: callable|array, middlewares: list<class-string>}>> */
    private array $routes = [
        'GET' => [],
        'POST' => [],
    ];

    /** @param callable|array{class-string, string} $handler */
    public function get(string $path, callable|array $handler, array $middlewares = []): void
    {
        $this->routes['GET'][$path] = ['handler' => $handler, 'middlewares' => $middlewares];
    }

    /** @param callable|array{class-string, string} $handler */
    public function post(string $path, callable|array $handler, array $middlewares = []): void
    {
        $this->routes['POST'][$path] = ['handler' => $handler, 'middlewares' => $middlewares];
    }

    public function dispatch(string $method, string $uri): void
    {
        $method = strtoupper($method);
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';

        // Si la app está en una subcarpeta (ej. /Polla), recorta el base_path.
        $cfg = require __DIR__ . '/../Config/app.php';
        $basePath = rtrim((string)($cfg['base_path'] ?? ''), '/');
        if ($basePath !== '') {
            $pathLower = strtolower($path);
            $baseLower = strtolower($basePath);
            if ($pathLower === $baseLower) {
                $path = '/';
            } elseif (str_starts_with($pathLower, $baseLower . '/')) {
                $path = substr($path, strlen($basePath));
                if ($path === '') $path = '/';
            }
        }

        if ($path !== '/' && str_ends_with($path, '/')) {
            $path = rtrim($path, '/');
        }

        $route = $this->routes[$method][$path] ?? null;
        if (!$route) {
            http_response_code(404);
            echo '404';
            return;
        }

        foreach ($route['middlewares'] as $mw) {
            $mwInstance = new $mw();
            $mwInstance();
        }

        $handler = $route['handler'];
        if (is_array($handler)) {
            [$class, $action] = $handler;
            $controller = new $class();
            $controller->$action();
            return;
        }

        $handler();
    }
}

