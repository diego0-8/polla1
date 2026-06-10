<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\DB;

final class PropPrediction
{
    public const MARKETS = ['btts', 'goals_ou', 'corners_ou', 'cards_ou'];

    /** @return array<string, array<string, mixed>|null> */
    public static function forUserMatch(int $userId, int $matchId): array
    {
        $st = DB::pdo()->prepare(
            'SELECT * FROM prop_predictions
             WHERE user_id = :user_id AND match_id = :match_id'
        );
        $st->execute(['user_id' => $userId, 'match_id' => $matchId]);
        $rows = $st->fetchAll() ?: [];

        $byMarket = array_fill_keys(self::MARKETS, null);
        foreach ($rows as $row) {
            $byMarket[(string)$row['market']] = $row;
        }

        return $byMarket;
    }

    /** @return array<string, array<string, mixed>|null> */
    public static function myForMatch(int $matchId): array
    {
        $userId = (int)($_SESSION['user_id'] ?? 0);
        if ($userId <= 0) {
            return array_fill_keys(self::MARKETS, null);
        }

        return self::forUserMatch($userId, $matchId);
    }

    public static function forUserMatchMarket(int $userId, int $matchId, string $market): ?array
    {
        $st = DB::pdo()->prepare(
            'SELECT * FROM prop_predictions
             WHERE user_id = :user_id AND match_id = :match_id AND market = :market
             LIMIT 1'
        );
        $st->execute([
            'user_id' => $userId,
            'match_id' => $matchId,
            'market' => $market,
        ]);
        $row = $st->fetch();
        return $row ?: null;
    }

    public static function create(
        int $userId,
        int $matchId,
        string $market,
        ?float $line,
        string $pick,
        string $lockedAt,
    ): void {
        if (self::forUserMatchMarket($userId, $matchId, $market) !== null) {
            throw new \RuntimeException('Ya registraste este pronóstico especial. No se puede modificar.');
        }

        $st = DB::pdo()->prepare(
            'INSERT INTO prop_predictions (user_id, match_id, market, line, pick, locked_at, created_at, updated_at)
             VALUES (:user_id, :match_id, :market, :line, :pick, :locked_at, NOW(), NOW())'
        );
        $st->execute([
            'user_id' => $userId,
            'match_id' => $matchId,
            'market' => $market,
            'line' => $line,
            'pick' => $pick,
            'locked_at' => $lockedAt,
        ]);
    }

    public static function label(array $prediction): string
    {
        $market = (string)($prediction['market'] ?? '');
        $pick = (string)($prediction['pick'] ?? '');
        $line = $prediction['line'] ?? null;

        return match ($market) {
            'btts' => $pick === 'yes' ? 'Ambos marcan: Sí' : 'Ambos marcan: No',
            'goals_ou' => self::ouLabel('Goles', $pick, $line),
            'corners_ou' => self::ouLabel('Córners', $pick, $line),
            'cards_ou' => self::ouLabel('Tarjetas', $pick, $line),
            default => $market,
        };
    }

    private static function ouLabel(string $name, string $pick, mixed $line): string
    {
        $side = $pick === 'over' ? 'Más' : 'Menos';
        $lineStr = $line !== null ? (string)$line : '?';
        return "$name: $side de $lineStr";
    }

    public static function pointsForMarket(string $market, ?float $line = null): int
    {
        $cfg = require __DIR__ . '/../Config/app.php';
        $propPoints = $cfg['prop_points'] ?? [];

        if ($market === 'btts') {
            return (int)($propPoints['btts'] ?? 2);
        }

        if ($market === 'goals_ou') {
            return (int)($propPoints['goals_ou'] ?? 2);
        }

        if ($market === 'corners_ou') {
            return (int)($propPoints['corners_ou'] ?? 2);
        }

        if ($market === 'cards_ou') {
            return (int)($propPoints['cards_ou'] ?? 3);
        }

        return 0;
    }

    /** @return list<float> */
    public static function allowedLines(string $market): array
    {
        $cfg = require __DIR__ . '/../Config/app.php';
        $lines = $cfg['prop_lines'][$market] ?? [];
        return array_map('floatval', $lines);
    }
}
