<?php
declare(strict_types=1);

namespace App\Core;

final class Url
{
    public static function basePath(): string
    {
        static $base = null;
        if ($base === null) {
            $cfg = require __DIR__ . '/../Config/app.php';
            $base = rtrim((string)($cfg['base_path'] ?? ''), '/');
        }
        return $base;
    }

    public static function to(string $path = '/'): string
    {
        $base = self::basePath();
        if ($path === '' || $path === '/') {
            return $base . '/';
        }
        return $base . (str_starts_with($path, '/') ? $path : '/' . $path);
    }
}
