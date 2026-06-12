<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\DB;
use App\Services\TournamentTeamStatusService;

final class UserPoints
{
    public static function top(int $limit, ?int $season = null): array
    {
        $season ??= MatchModel::seasonYear();

        $st = DB::pdo()->prepare(
            'SELECT u.id, u.name, COALESCE(u.has_paid, 0) AS has_paid,
                    COALESCE(up.points_total, 0) AS points_total,
                    COALESCE(stats.exact_hits, 0) AS exact_hits,
                    COALESCE(stats.gana_hits, 0) AS gana_hits,
                    COALESCE(stats.btts_hits, 0) AS btts_hits,
                    COALESCE(stats.goals_hits, 0) AS goals_hits,
                    COALESCE(stats.corners_hits, 0) AS corners_hits,
                    COALESCE(stats.cards_hits, 0) AS cards_hits,
                    COALESCE(stats.champion_bonus_hits, 0) AS champion_bonus_hits,
                    tp.champion_team_id,
                    firsts.first_prediction_at
             FROM users u
             LEFT JOIN user_points up ON up.user_id = u.id
             LEFT JOIN tournament_picks tp ON tp.user_id = u.id AND tp.season = :season
             LEFT JOIN (
                SELECT user_id,
                       SUM(CASE WHEN reason = \'exact_score\' AND points > 0 THEN 1 ELSE 0 END) AS exact_hits,
                       SUM(CASE WHEN reason IN (\'correct_winner\', \'correct_draw\', \'correct_advancer\') AND points > 0 THEN 1 ELSE 0 END) AS gana_hits,
                       SUM(CASE WHEN reason = \'correct_btts\' AND points > 0 THEN 1 ELSE 0 END) AS btts_hits,
                       SUM(CASE WHEN reason = \'correct_goals_ou\' AND points > 0 THEN 1 ELSE 0 END) AS goals_hits,
                       SUM(CASE WHEN reason = \'correct_corners_ou\' AND points > 0 THEN 1 ELSE 0 END) AS corners_hits,
                       SUM(CASE WHEN reason = \'correct_cards_ou\' AND points > 0 THEN 1 ELSE 0 END) AS cards_hits,
                       SUM(CASE WHEN reason = \'champion_bonus\' AND points > 0 THEN 1 ELSE 0 END) AS champion_bonus_hits
                FROM points_ledger
                GROUP BY user_id
             ) stats ON stats.user_id = u.id
             LEFT JOIN (
                SELECT user_id, MIN(created_at) AS first_prediction_at
                FROM (
                    SELECT user_id, created_at FROM predictions
                    UNION ALL
                    SELECT user_id, created_at FROM tournament_picks
                ) all_predictions
                GROUP BY user_id
             ) firsts ON firsts.user_id = u.id
             WHERE u.status = \'active\'
               AND NOT EXISTS (
                   SELECT 1 FROM user_roles ur
                   INNER JOIN roles r ON r.id = ur.role_id
                   WHERE ur.user_id = u.id AND r.name = \'admin\'
               )
             ORDER BY points_total DESC,
                      exact_hits DESC,
                      gana_hits DESC,
                      btts_hits DESC,
                      goals_hits DESC,
                      corners_hits DESC,
                      cards_hits DESC,
                      first_prediction_at IS NULL ASC,
                      first_prediction_at ASC,
                      u.id ASC
             LIMIT :lim'
        );
        $st->bindValue('season', $season, \PDO::PARAM_INT);
        $st->bindValue('lim', $limit, \PDO::PARAM_INT);
        $st->execute();

        $rows = $st->fetchAll() ?: [];
        return self::attachChampionDisplay($rows, $season);
    }

    /** @param list<array<string, mixed>> $rows @return list<array<string, mixed>> */
    public static function attachChampionDisplay(array $rows, int $season): array
    {
        $inPlay = TournamentTeamStatusService::stillInPlayTeamIds($season);

        foreach ($rows as $idx => $row) {
            $teamId = isset($row['champion_team_id']) ? (int)$row['champion_team_id'] : 0;
            $bonusSettled = (int)($row['champion_bonus_hits'] ?? 0) > 0;
            $stillAlive = $teamId > 0 && isset($inPlay[$teamId]);

            if ($teamId <= 0) {
                $rows[$idx]['champion_alive'] = false;
                $rows[$idx]['champion_display'] = '—';
                $rows[$idx]['champion_status'] = 'none';
                continue;
            }

            $rows[$idx]['champion_display'] = '+20';
            if ($bonusSettled || $stillAlive) {
                $rows[$idx]['champion_alive'] = true;
                $rows[$idx]['champion_status'] = $bonusSettled ? 'won' : 'alive';
            } else {
                $rows[$idx]['champion_alive'] = false;
                $rows[$idx]['champion_status'] = 'eliminated';
            }
        }

        return $rows;
    }
}
