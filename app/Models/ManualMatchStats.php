<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\DB;

final class ManualMatchStats
{
    private const STAT_FIELDS = [
        'home_corners',
        'away_corners',
        'total_corners',
        'total_yellow_cards',
        'total_red_cards',
        'total_cards',
        'total_goals',
        'btts',
    ];

    public static function forMatch(int $matchId): ?array
    {
        self::ensureTables();
        $st = DB::pdo()->prepare('SELECT * FROM manual_match_stats WHERE match_id = :match_id');
        $st->execute(['match_id' => $matchId]);
        $row = $st->fetch();
        return $row ?: null;
    }

    public static function upsert(
        int $matchId,
        ?int $homeCorners,
        ?int $awayCorners,
        ?int $totalYellowCards,
        ?int $totalRedCards,
        ?int $totalGoals,
        ?bool $btts,
        ?string $note,
        int $userId,
    ): void {
        self::ensureTables();

        foreach ([$homeCorners, $awayCorners, $totalYellowCards, $totalRedCards, $totalGoals] as $value) {
            if ($value !== null && $value < 0) {
                throw new \RuntimeException('Los valores de stats no pueden ser negativos.');
            }
        }

        $totalCorners = null;
        if ($homeCorners !== null && $awayCorners !== null) {
            $totalCorners = $homeCorners + $awayCorners;
        }

        $totalCards = null;
        if ($totalYellowCards !== null || $totalRedCards !== null) {
            $totalCards = (int)($totalYellowCards ?? 0) + (int)($totalRedCards ?? 0);
        }

        $existing = self::forMatch($matchId);
        if ($existing !== null) {
            $homeCorners = $homeCorners ?? (isset($existing['home_corners']) ? (int)$existing['home_corners'] : null);
            $awayCorners = $awayCorners ?? (isset($existing['away_corners']) ? (int)$existing['away_corners'] : null);
            $totalYellowCards = $totalYellowCards ?? (isset($existing['total_yellow_cards']) ? (int)$existing['total_yellow_cards'] : null);
            $totalRedCards = $totalRedCards ?? (isset($existing['total_red_cards']) ? (int)$existing['total_red_cards'] : null);
            $totalGoals = $totalGoals ?? (isset($existing['total_goals']) ? (int)$existing['total_goals'] : null);
            if ($btts === null && array_key_exists('btts', $existing) && $existing['btts'] !== null) {
                $btts = (int)$existing['btts'] === 1;
            }

            if ($homeCorners !== null && $awayCorners !== null) {
                $totalCorners = $homeCorners + $awayCorners;
            }
            if ($totalYellowCards !== null || $totalRedCards !== null) {
                $totalCards = (int)($totalYellowCards ?? 0) + (int)($totalRedCards ?? 0);
            }
        }

        if ($totalCorners === null && $totalCards === null && $totalGoals === null && $btts === null) {
            throw new \RuntimeException('Ingresa al menos córners, tarjetas, goles o BTTS.');
        }

        $st = DB::pdo()->prepare(
            'INSERT INTO manual_match_stats (
                match_id, home_corners, away_corners, total_corners,
                total_yellow_cards, total_red_cards, total_cards,
                total_goals, btts, note, updated_by, updated_at
             ) VALUES (
                :match_id, :home_corners, :away_corners, :total_corners,
                :total_yellow_cards, :total_red_cards, :total_cards,
                :total_goals, :btts, :note, :updated_by, NOW()
             )
             ON DUPLICATE KEY UPDATE
                home_corners = VALUES(home_corners),
                away_corners = VALUES(away_corners),
                total_corners = VALUES(total_corners),
                total_yellow_cards = VALUES(total_yellow_cards),
                total_red_cards = VALUES(total_red_cards),
                total_cards = VALUES(total_cards),
                total_goals = VALUES(total_goals),
                btts = VALUES(btts),
                note = VALUES(note),
                updated_by = VALUES(updated_by),
                updated_at = NOW()'
        );
        $st->execute([
            'match_id' => $matchId,
            'home_corners' => $homeCorners,
            'away_corners' => $awayCorners,
            'total_corners' => $totalCorners,
            'total_yellow_cards' => $totalYellowCards,
            'total_red_cards' => $totalRedCards,
            'total_cards' => $totalCards,
            'total_goals' => $totalGoals,
            'btts' => $btts === null ? null : ($btts ? 1 : 0),
            'note' => $note !== null && trim($note) !== '' ? trim($note) : null,
            'updated_by' => $userId,
        ]);
    }

    /** @param array<string, mixed>|null $apiStats */
    public static function mergeWithApi(int $matchId, ?array $apiStats): ?array
    {
        $manual = self::forMatch($matchId);
        if ($apiStats === null && $manual === null) {
            return null;
        }

        $merged = $apiStats ?? ['match_id' => $matchId];
        $usedManual = false;

        if ($manual !== null) {
            foreach (self::STAT_FIELDS as $field) {
                if (!self::apiFieldIsSet($apiStats, $field) && array_key_exists($field, $manual) && $manual[$field] !== null) {
                    $merged[$field] = $manual[$field];
                    $usedManual = true;
                }
            }

            if ($usedManual) {
                $merged['manual_stats_note'] = $manual['note'] ?? null;
                $merged['manual_stats_updated_at'] = $manual['updated_at'] ?? null;
            }
        }

        $merged['stats_source'] = self::resolveSource($apiStats, $manual, $usedManual);

        return $merged;
    }

    /** @param array<string, mixed>|null $stats */
    public static function hasStatsForMarket(?array $stats, string $market): bool
    {
        if ($stats === null) {
            return false;
        }

        return match ($market) {
            'btts' => array_key_exists('btts', $stats) && $stats['btts'] !== null,
            'goals_ou' => isset($stats['total_goals']) && $stats['total_goals'] !== null,
            'corners_ou' => isset($stats['total_corners']) && $stats['total_corners'] !== null,
            'cards_ou' => isset($stats['total_cards']) && $stats['total_cards'] !== null,
            default => false,
        };
    }

    /** @param array<string, mixed>|null $apiStats */
    private static function apiFieldIsSet(?array $apiStats, string $field): bool
    {
        if ($apiStats === null || !array_key_exists($field, $apiStats)) {
            return false;
        }

        return $apiStats[$field] !== null;
    }

    /** @param array<string, mixed>|null $apiStats @param array<string, mixed>|null $manual */
    private static function resolveSource(?array $apiStats, ?array $manual, bool $usedManual): string
    {
        if ($manual === null || !$usedManual) {
            return 'api';
        }
        if ($apiStats === null) {
            return 'manual';
        }

        return 'mixed';
    }

    private static function ensureTables(): void
    {
        static $done = false;
        if ($done) {
            return;
        }

        DB::pdo()->exec(
            'CREATE TABLE IF NOT EXISTS manual_match_stats (
                match_id INT NOT NULL PRIMARY KEY,
                home_corners SMALLINT NULL,
                away_corners SMALLINT NULL,
                total_corners SMALLINT NULL,
                total_yellow_cards SMALLINT NULL,
                total_red_cards SMALLINT NULL,
                total_cards SMALLINT NULL,
                total_goals SMALLINT NULL,
                btts TINYINT(1) NULL,
                note VARCHAR(255) NULL,
                updated_by INT NULL,
                updated_at DATETIME NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );

        $done = true;
    }
}
