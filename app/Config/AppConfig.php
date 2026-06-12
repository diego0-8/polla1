<?php
declare(strict_types=1);

namespace App\Config;

final class AppConfig
{
    public static function boot(): void
    {
        $cfg = require __DIR__ . '/app.php';
        date_default_timezone_set($cfg['timezone'] ?? 'America/Bogota');

        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
    }
}

