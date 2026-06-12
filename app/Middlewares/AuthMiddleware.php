<?php
declare(strict_types=1);

namespace App\Middlewares;

use App\Core\Auth;
use App\Core\Url;

final class AuthMiddleware
{
    public function __invoke(): void
    {
        if (!Auth::check() || !Auth::isActive()) {
            Auth::logout();
            header('Location: ' . Url::to('/login'), true, 302);
            exit;
        }
    }
}

