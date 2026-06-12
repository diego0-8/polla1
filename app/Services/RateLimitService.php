<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\DB;

final class RateLimitService
{
    private static function minuteKey(): string
    {
        $cfg = require dirname(__DIR__) . '/Config/app.php';
        $tz = new \DateTimeZone($cfg['timezone'] ?? 'America/Bogota');
        return (new \DateTimeImmutable('now', $tz))->format('Y-m-d H:i');
    }

    public static function canRequestPerMinute(int $softLimit): bool
    {
        self::ensureTable();
        $key = self::minuteKey();
        $st = DB::pdo()->prepare('SELECT requests_count FROM api_usage_minute WHERE minute_key = :key');
        $st->execute(['key' => $key]);
        $count = (int)($st->fetchColumn() ?: 0);
        return $count < $softLimit;
    }

    public static function recordRequestPerMinute(): void
    {
        self::ensureTable();
        $key = self::minuteKey();
        $st = DB::pdo()->prepare(
            'INSERT INTO api_usage_minute (minute_key, requests_count, last_request_at)
             VALUES (:key, 1, NOW())
             ON DUPLICATE KEY UPDATE requests_count = requests_count + 1, last_request_at = NOW()'
        );
        $st->execute(['key' => $key]);
    }

    public static function acquireOrWait(int $softLimit, int $maxWaitSeconds = 55): void
    {
        $deadline = time() + $maxWaitSeconds;
        while (!self::canRequestPerMinute($softLimit)) {
            if (time() >= $deadline) {
                throw new \RuntimeException('Límite por minuto de API alcanzado (espera agotada).');
            }
            sleep(1);
        }
    }

    /** @deprecated API-Football diario; usar acquireOrWait + recordRequestPerMinute */
    public static function canRequest(int $softLimit): bool
    {
        return self::canRequestPerMinute($softLimit);
    }

    /** @deprecated */
    public static function recordRequest(): void
    {
        self::recordRequestPerMinute();
    }

    private static function ensureTable(): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        DB::pdo()->exec(
            'CREATE TABLE IF NOT EXISTS api_usage_minute (
              minute_key CHAR(16) PRIMARY KEY,
              requests_count INT NOT NULL DEFAULT 0,
              last_request_at DATETIME NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
        $done = true;
    }
}
