<?php
declare(strict_types=1);

namespace App\Middlewares;

use App\Core\Auth;
use App\Core\Url;
use App\Models\User;

final class AdminMiddleware
{
    public function __invoke(): void
    {
        if (!Auth::check() || !Auth::isActive()) {
            Auth::logout();
            header('Location: ' . Url::to('/login'), true, 302);
            exit;
        }

        $userId = (int)($_SESSION['user_id'] ?? 0);
        if (!User::hasRole($userId, 'admin')) {
            header('Location: ' . Url::to('/'), true, 302);
            exit;
        }
    }
}
