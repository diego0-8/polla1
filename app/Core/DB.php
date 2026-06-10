<?php
declare(strict_types=1);

namespace App\Core;

use PDO;

final class DB
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo) {
            return self::$pdo;
        }

        $cfg = require __DIR__ . '/../Config/database.php';
        self::$pdo = new PDO(
            $cfg['dsn'],
            $cfg['username'],
            $cfg['password'],
            $cfg['options']
        );
        self::$pdo->exec("SET time_zone = '-05:00'");

        return self::$pdo;
    }
}

