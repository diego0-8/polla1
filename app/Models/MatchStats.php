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
        $row = $st->fetch() ?: null;

        return ManualMatchStats::mergeWithApi($matchId, $row);
    }

    /** Stats para liquidación: completa goles/BTTS desde el marcador si el partido ya finalizó. */
    public static function forSettlement(int $matchId): ?array
    {
        return self::enrichFromFinishedMatch($matchId, self::forMatch($matchId));
    }

    /**
     * Actualiza solo total_goals y btts en match_stats desde el marcador reglamentario del partido.
     * No modifica córners ni tarjetas (esos son manuales).
     */
    public static function syncGoalsAndBttsFromMatch(int $matchId): bool
    {
        $match = MatchModel::findById($matchId);
        if ($match === null) {
            return false;
        }

        $status = strtoupper((string)($match['status'] ?? ''));
        if (!in_array($status, ['FT', 'PEN', 'AET'], true)) {
            return false;
        }

        $scoring = MatchDataMapper::scoresForSettlement($match);
        $totalGoals = $scoring['home'] + $scoring['away'];
        $btts = ($scoring['home'] > 0 && $scoring['away'] > 0) ? 1 : 0;

        $existing = self::apiRowForMatch($matchId);
        if ($existing === null) {
            DB::pdo()->prepare(
                'INSERT INTO match_stats (match_id, total_goals, btts, synced_at)
                 VALUES (:match_id, :total_goals, :btts, NOW())'
            )->execute([
                'match_id' => $matchId,
                'total_goals' => $totalGoals,
                'btts' => $btts,
            ]);
        } else {
            DB::pdo()->prepare(
                'UPDATE match_stats
                 SET total_goals = :total_goals, btts = :btts, synced_at = NOW()
                 WHERE match_id = :match_id'
            )->execute([
                'match_id' => $matchId,
                'total_goals' => $totalGoals,
                'btts' => $btts,
            ]);
        }

        return true;
    }

    /**
     * En partidos FT/PEN/AET, deriva total_goals y btts del marcador cuando faltan en match_stats
     * (común en 0-0 sin eventos en la API).
     *
     * @param array<string, mixed>|null $stats
     * @return array<string, mixed>|null
     */
    public static function enrichFromFinishedMatch(int $matchId, ?array $stats): ?array
    {
        $match = MatchModel::findById($matchId);
        if ($match === null) {
            return $stats;
        }

        $status = strtoupper((string)($match['status'] ?? ''));
        if (!in_array($status, ['FT', 'PEN', 'AET'], true)) {
            return $stats;
        }

        $scoring = MatchDataMapper::scoresForSettlement($match);
        $home = $scoring['home'];
        $away = $scoring['away'];

        if ($stats === null) {
            $stats = ['match_id' => $matchId];
        }

        if (!isset($stats['total_goals']) || $stats['total_goals'] === null) {
            $stats['total_goals'] = $home + $away;
        }
        if (!array_key_exists('btts', $stats) || $stats['btts'] === null) {
            $stats['btts'] = ($home > 0 && $away > 0) ? 1 : 0;
        }

        return $stats;
    }

    /** @return array<string, mixed>|null Fila cruda de match_stats sin fallback manual. */
    public static function apiRowForMatch(int $matchId): ?array
    {
        $st = DB::pdo()->prepare('SELECT * FROM match_stats WHERE match_id = :match_id');
        $st->execute(['match_id' => $matchId]);
        $row = $st->fetch();
        return $row ?: null;
    }

    /** @param array<string, mixed>|null $stats */
    public static function hasStatsForMarket(?array $stats, string $market): bool
    {
        return ManualMatchStats::hasStatsForMarket($stats, $market);
    }

    public static function upsertFromApiDetail(int $matchId, array $detail): bool
    {
        $stats = MatchDataMapper::extractMatchStats($detail);
        if ($stats === null) {
            return false;
        }

        $params = [
            'match_id' => $matchId,
            'home_corners' => $stats['home_corners'],
            'away_corners' => $stats['away_corners'],
            'total_corners' => $stats['total_corners'],
            'total_cards' => $stats['total_cards'],
            'total_goals' => $stats['total_goals'],
            'btts' => $stats['btts'] ? 1 : 0,
        ];

        $columns = self::tableColumns();
        $hasCardBreakdown = in_array('total_yellow_cards', $columns, true)
            && in_array('total_red_cards', $columns, true);

        if ($hasCardBreakdown) {
            $params['total_yellow_cards'] = $stats['total_yellow_cards'] ?? null;
            $params['total_red_cards'] = $stats['total_red_cards'] ?? null;
            $sql = 'INSERT INTO match_stats (
                match_id, home_corners, away_corners, total_corners,
                total_cards, total_yellow_cards, total_red_cards,
                total_goals, btts, synced_at
             ) VALUES (
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
                synced_at = NOW()';
        } else {
            $sql = 'INSERT INTO match_stats (
                match_id, home_corners, away_corners, total_corners,
                total_cards, total_goals, btts, synced_at
             ) VALUES (
                :match_id, :home_corners, :away_corners, :total_corners,
                :total_cards, :total_goals, :btts, NOW()
             )
             ON DUPLICATE KEY UPDATE
                home_corners = VALUES(home_corners),
                away_corners = VALUES(away_corners),
                total_corners = VALUES(total_corners),
                total_cards = VALUES(total_cards),
                total_goals = VALUES(total_goals),
                btts = VALUES(btts),
                synced_at = NOW()';
        }

        DB::pdo()->prepare($sql)->execute($params);

        return true;
    }

    /** @return list<string> */
    private static function tableColumns(): array
    {
        static $columns = null;
        if ($columns !== null) {
            return $columns;
        }

        $rows = DB::pdo()->query('SHOW COLUMNS FROM match_stats')->fetchAll() ?: [];
        $columns = array_column($rows, 'Field');

        return $columns;
    }
}
