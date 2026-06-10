<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\DB;
use App\Services\MatchDataMapper;

final class MatchStats
{
    public static function forMatch(int $matchId): ?array
    {
        $st = DB::pdo()->prepare('SELECT * FROM match_stats WHERE match_id = :match_id');
        $st->execute(['match_id' => $matchId]);
        $row = $st->fetch();
        return $row ?: null;
    }

    public static function upsertFromApiDetail(int $matchId, array $detail): bool
    {
        $stats = MatchDataMapper::extractMatchStats($detail);
        if ($stats === null) {
            return false;
        }

        $st = DB::pdo()->prepare(
            'INSERT INTO match_stats (
                match_id, home_corners, away_corners, total_corners,
                total_cards, total_yellow_cards, total_red_cards,
                total_goals, btts, synced_at
             )
             VALUES (
                :match_id, :home_corners, :away_corners, :total_corners,
                :total_cards, :total_yellow_cards, :total_red_cards,
                :total_goals, :btts, NOW()
             )
             ON DUPLICATE KEY UPDATE
                home_corners = VALUES(home_corners),
                away_corners = VALUES(away_corners),
                total_corners = VALUES(total_corners),
                total_cards = VALUES(total_cards),
                total_yellow_cards = VALUES(total_yellow_cards),
                total_red_cards = VALUES(total_red_cards),
                total_goals = VALUES(total_goals),
                btts = VALUES(btts),
                synced_at = NOW()'
        );
        $st->execute([
            'match_id' => $matchId,
            'home_corners' => $stats['home_corners'],
            'away_corners' => $stats['away_corners'],
            'total_corners' => $stats['total_corners'],
            'total_cards' => $stats['total_cards'],
            'total_yellow_cards' => $stats['total_yellow_cards'] ?? null,
            'total_red_cards' => $stats['total_red_cards'] ?? null,
            'total_goals' => $stats['total_goals'],
            'btts' => $stats['btts'] ? 1 : 0,
        ]);

        return true;
    }
}
