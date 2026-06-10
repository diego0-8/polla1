<?php
declare(strict_types=1);

namespace App\Core;

final class View
{
    public static function render(string $view, array $params = []): void
    {
        $viewName = $view;
        $basePath = Url::basePath();
        $url = static fn (string $path = '/'): string => Url::to($path);
        extract($params, EXTR_SKIP);

        $viewFile = __DIR__ . '/../Views/' . $viewName . '.php';
        if (!is_file($viewFile)) {
            http_response_code(500);
            echo "Vista no encontrada: " . htmlspecialchars($view);
            return;
        }

        $layout = __DIR__ . '/../Views/layouts/main.php';
        if (is_file($layout)) {
            require $layout;
            return;
        }

        require $viewFile;
    }
}

