<?php
declare(strict_types=1);

namespace App\Services;

use App\Models\User;

final class PaymentReminderService
{
    /** @var array<string, mixed>|null */
    private static ?array $paymentConfig = null;

    /** @return array<string, mixed> */
    private static function paymentConfig(): array
    {
        if (self::$paymentConfig === null) {
            $cfg = require dirname(__DIR__) . '/Config/app.php';
            self::$paymentConfig = is_array($cfg['payment_reminder'] ?? null)
                ? $cfg['payment_reminder']
                : [];
        }

        return self::$paymentConfig;
    }

    private static function timezone(): \DateTimeZone
    {
        $cfg = require dirname(__DIR__) . '/Config/app.php';

        return new \DateTimeZone($cfg['timezone'] ?? 'America/Bogota');
    }

    public static function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', self::timezone());
    }

    public static function deadline(): \DateTimeImmutable
    {
        $deadline = (string)(self::paymentConfig()['deadline'] ?? '2026-06-26 00:00:00');

        return new \DateTimeImmutable($deadline, self::timezone());
    }

    public static function deadlineFormatted(): string
    {
        $months = [
            1 => 'enero', 2 => 'febrero', 3 => 'marzo', 4 => 'abril',
            5 => 'mayo', 6 => 'junio', 7 => 'julio', 8 => 'agosto',
            9 => 'septiembre', 10 => 'octubre', 11 => 'noviembre', 12 => 'diciembre',
        ];
        $dt = self::deadline();
        $month = (int)$dt->format('n');

        return $dt->format('j') . ' de ' . ($months[$month] ?? $dt->format('F')) . ' de ' . $dt->format('Y');
    }

    public static function countdownStartsAt(): \DateTimeImmutable
    {
        return self::deadline()->modify('-24 hours');
    }

    public static function isInCountdownWindow(): bool
    {
        $now = self::now();

        return $now >= self::countdownStartsAt() && $now < self::deadline();
    }

    public static function shouldShowModalForUser(array $user): bool
    {
        $userId = (int)($user['id'] ?? 0);
        if ($userId <= 0) {
            return false;
        }
        if (($user['status'] ?? '') !== 'active') {
            return false;
        }
        if ((int)($user['has_paid'] ?? 0) !== 0) {
            return false;
        }
        if (!User::hasRole($userId, 'asesor')) {
            return false;
        }

        return self::now() < self::deadline();
    }

    public static function secondsRemaining(): int
    {
        $remaining = self::deadline()->getTimestamp() - self::now()->getTimestamp();

        return max(0, $remaining);
    }

    public static function amountCop(): int
    {
        return (int)(self::paymentConfig()['amount_cop'] ?? 50000);
    }

    public static function qrImagePath(): string
    {
        return (string)(self::paymentConfig()['qr_image'] ?? 'img/codigo-qr.jpg');
    }

    public static function enforceDeadline(): void
    {
        if (self::now() < self::deadline()) {
            return;
        }

        User::suspendUnpaidAsesorsPastDeadline();
    }
}
