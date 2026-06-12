<?php
declare(strict_types=1);

namespace App\Core;

use App\Models\User;

final class Auth
{
    public static function user(): ?array
    {
        $id = $_SESSION['user_id'] ?? null;
        if (!$id) {
            return null;
        }
        $user = User::findById((int)$id);
        if (!$user || ($user['status'] ?? '') !== 'active') {
            self::logout();
            return null;
        }
        return $user;
    }

    public static function check(): bool
    {
        return (bool)($_SESSION['user_id'] ?? null);
    }

    public static function isActive(): bool
    {
        $user = self::user();
        return $user !== null && ($user['status'] ?? '') === 'active';
    }

    public static function login(int $userId): void
    {
        $_SESSION['user_id'] = $userId;
    }

    public static function logout(): void
    {
        unset($_SESSION['user_id']);
    }

    public static function id(): ?int
    {
        $id = $_SESSION['user_id'] ?? null;
        return $id ? (int)$id : null;
    }

    public static function isAdmin(): bool
    {
        $id = self::id();
        return $id !== null && User::hasRole($id, 'admin');
    }
}

