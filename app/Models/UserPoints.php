<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\DB;

final class UserPoints
{
    public static function top(int $limit): array
    {
        $st = DB::pdo()->prepare(
            'SELECT u.id, u.name, COALESCE(u.has_paid, 0) AS has_paid,
                    COALESCE(up.points_total, 0) AS points_total,
                    COALESCE(stats.exact_hits, 0) AS exact_hits,
                    COALESCE(stats.trend_hits, 0) AS trend_hits,
                    COALESCE(stats.prop_hits, 0) AS prop_hits,
                    firsts.first_prediction_at
             FROM users u
             LEFT JOIN user_points up ON up.user_id = u.id
             LEFT JOIN (
                SELECT user_id,
                       SUM(CASE WHEN reason = \'exact_score\' AND points > 0 THEN 1 ELSE 0 END) AS exact_hits,
                       SUM(CASE WHEN reason IN (\'correct_winner\', \'correct_draw\', \'correct_advancer\') AND points > 0 THEN 1 ELSE 0 END) AS trend_hits,
                       SUM(CASE WHEN reason IN (\'correct_btts\', \'correct_goals_ou\', \'correct_corners_ou\', \'correct_cards_ou\') AND points > 0 THEN 1 ELSE 0 END) AS prop_hits
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
                      trend_hits DESC,
                      prop_hits DESC,
                      first_prediction_at IS NULL ASC,
                      first_prediction_at ASC,
                      u.id ASC
             LIMIT :lim'
        );
        $st->bindValue('lim', $limit, \PDO::PARAM_INT);
        $st->execute();
        return $st->fetchAll() ?: [];
    }
}

