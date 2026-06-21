<?php
declare(strict_types=1);

namespace App\Core;

use App\Models\User;
use App\Services\PaymentReminderService;

final class Auth
{
    public static function user(): ?array
    {
        $id = $_SESSION['user_id'] ?? null;
        if (!$id) {
            return null;
        }

        PaymentReminderService::enforceDeadline();

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

        $user = User::findById($userId);
        if ($user && PaymentReminderService::shouldShowModalForUser($user)) {
            $_SESSION['show_payment_modal'] = true;
        }
    }

    public static function logout(): void
    {
        unset($_SESSION['user_id'], $_SESSION['show_payment_modal']);
    }

    public static function shouldShowPaymentModal(): bool
    {
        if (empty($_SESSION['show_payment_modal'])) {
            return false;
        }

        $id = $_SESSION['user_id'] ?? null;
        if (!$id) {
            return false;
        }

        $user = User::findById((int)$id);
        if (!$user || !PaymentReminderService::shouldShowModalForUser($user)) {
            unset($_SESSION['show_payment_modal']);

            return false;
        }

        return true;
    }

    public static function dismissPaymentModal(): void
    {
        unset($_SESSION['show_payment_modal']);
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

    public static function canSeePropOuPoints(): bool
    {
        $id = self::id();
        if ($id === null) {
            return false;
        }

        return User::hasRole($id, 'admin') || User::hasRole($id, 'asesor');
    }
}

