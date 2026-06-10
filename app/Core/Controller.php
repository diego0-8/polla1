<?php
declare(strict_types=1);

namespace App\Core;

abstract class Controller
{
    protected function redirect(string $path): void
    {
        header('Location: ' . $path, true, 302);
        exit;
    }

    protected function url(string $path = '/'): string
    {
        return Url::to($path);
    }
}
