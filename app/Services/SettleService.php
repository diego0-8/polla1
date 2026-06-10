<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\DB;

final class SettleService
{
    private const FINISHED_STATUSES = ['FT', 'PEN', 'AET'];

    /**
     * @return array{matches:int,predictions:int,prop_predictions:int,tournament_picks:int,points_awarded:int,users_recalculated:int}
     */
    public static function settleFinishedMatches(bool $allSeasons = false): array
    {
        $pdo = DB::pdo();
        $statusPh = implode(',', array_fill(0, count(self::FINISHED_STATUSES), '?'));

        $sql = "SELECT p.id AS prediction_id, p.user_id, p.match_id, p.pred_home, p.pred_away,
                       p.pred_type, p.pred_outcome, p.advances_team_id,
                       COALESCE(m.regular_home_score, m.home_score) AS real_home,
                       COALESCE(m.regular_away_score, m.away_score) AS real_away,
                       m.home_team_id, m.away_team_id, m.status AS match_status,
                       m.winner_team_id
                FROM predictions p
                INNER JOIN matches m ON m.id = p.match_id
                LEFT JOIN points_ledger pl ON pl.prediction_id = p.id
                WHERE pl.id IS NULL
                  AND m.status IN ($statusPh)";

        $params = self::FINISHED_STATUSES;

        if (!$allSeasons) {
            $cfg = require dirname(__DIR__) . '/Config/app.php';
            $seasonYear = (int)($cfg['football_data']['season'] ?? 2026);
            $sql .= ' AND YEAR(m.kickoff_at) = ?';
            $params[] = $seasonYear;
        }

        $st = $pdo->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll() ?: [];

        $insert = $pdo->prepare(
            'INSERT INTO points_ledger (user_id, match_id, prediction_id, tournament_pick_id, points, reason, created_at)
             VALUES (:user_id, :match_id, :prediction_id, NULL, :points, :reason, NOW())'
        );

        $matchIds = [];
        $userIds = [];
        $predictionsSettled = 0;
        $pointsAwarded = 0;

        foreach ($rows as $row) {
            $predType = (string)($row['pred_type'] ?? 'outcome');
            if ($predType === 'advance') {
                $winnerTeamId = $row['winner_team_id'] !== null
                    ? (int)$row['winner_team_id']
                    : self::inferWinnerTeamId($row);
                if ($winnerTeamId === null) {
                    continue;
                }
                $result = ScoringService::advancerPoints(
                    isset($row['advances_team_id']) ? (int)$row['advances_team_id'] : null,
                    $winnerTeamId,
                );
            } else {
                $result = ScoringService::points(
                    $predType,
                    (int)$row['pred_home'],
                    (int)$row['pred_away'],
                    isset($row['pred_outcome']) ? (string)$row['pred_outcome'] : null,
                    (int)$row['real_home'],
                    (int)$row['real_away'],
                );
            }

            $insert->execute([
                'user_id' => (int)$row['user_id'],
                'match_id' => (int)$row['match_id'],
                'prediction_id' => (int)$row['prediction_id'],
                'points' => $result['points'],
                'reason' => $result['reason'],
            ]);

            $matchIds[(int)$row['match_id']] = true;
            $userIds[(int)$row['user_id']] = true;
            $predictionsSettled++;
            $pointsAwarded += $result['points'];
        }

        $propResult = self::settlePropPredictions($allSeasons);
        foreach ($propResult['match_ids'] as $matchId) {
            $matchIds[$matchId] = true;
        }
        foreach ($propResult['user_ids'] as $userId) {
            $userIds[$userId] = true;
        }
        $pointsAwarded += $propResult['points_awarded'];

        $championResult = self::settleChampionBonus($allSeasons);
        foreach ($championResult['user_ids'] as $userId) {
            $userIds[$userId] = true;
        }
        $pointsAwarded += $championResult['points_awarded'];

        $usersRecalculated = self::recalculateUserTotals(array_keys($userIds));

        return [
            'matches' => count($matchIds),
            'predictions' => $predictionsSettled,
            'prop_predictions' => $propResult['settled'],
            'tournament_picks' => $championResult['picks'],
            'points_awarded' => $pointsAwarded,
            'users_recalculated' => $usersRecalculated,
        ];
    }

    /**
     * @return array{settled:int,points_awarded:int,user_ids:list<int>,match_ids:list<int>}
     */
    private static function settlePropPredictions(bool $allSeasons): array
    {
        $pdo = DB::pdo();
        $statusPh = implode(',', array_fill(0, count(self::FINISHED_STATUSES), '?'));

        $sql = "SELECT pp.id AS prop_prediction_id, pp.user_id, pp.match_id, pp.market,
                       pp.line, pp.pick,
                       ms.total_corners, ms.total_cards, ms.total_goals, ms.btts
                FROM prop_predictions pp
                INNER JOIN matches m ON m.id = pp.match_id
                INNER JOIN match_stats ms ON ms.match_id = pp.match_id
                LEFT JOIN points_ledger pl ON pl.prop_prediction_id = pp.id
                WHERE pl.id IS NULL
                  AND m.status IN ($statusPh)";

        $params = self::FINISHED_STATUSES;

        if (!$allSeasons) {
            $cfg = require dirname(__DIR__) . '/Config/app.php';
            $seasonYear = (int)($cfg['football_data']['season'] ?? 2026);
            $sql .= ' AND YEAR(m.kickoff_at) = ?';
            $params[] = $seasonYear;
        }

        $st = $pdo->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll() ?: [];

        $insert = $pdo->prepare(
            'INSERT INTO points_ledger (user_id, match_id, prediction_id, tournament_pick_id, prop_prediction_id, points, reason, created_at)
             VALUES (:user_id, :match_id, NULL, NULL, :prop_prediction_id, :points, :reason, NOW())'
        );

        $settled = 0;
        $pointsAwarded = 0;
        $userIds = [];
        $matchIds = [];

        foreach ($rows as $row) {
            $line = $row['line'] !== null ? (float)$row['line'] : null;
            $stats = [
                'total_corners' => $row['total_corners'],
                'total_cards' => $row['total_cards'],
                'total_goals' => $row['total_goals'],
                'btts' => (bool)$row['btts'],
            ];

            $result = ScoringService::propPoints(
                (string)$row['market'],
                $line,
                (string)$row['pick'],
                $stats,
            );

            $insert->execute([
                'user_id' => (int)$row['user_id'],
                'match_id' => (int)$row['match_id'],
                'prop_prediction_id' => (int)$row['prop_prediction_id'],
                'points' => $result['points'],
                'reason' => $result['reason'],
            ]);

            $settled++;
            $pointsAwarded += $result['points'];
            $userIds[] = (int)$row['user_id'];
            $matchIds[] = (int)$row['match_id'];
        }

        return [
            'settled' => $settled,
            'points_awarded' => $pointsAwarded,
            'user_ids' => $userIds,
            'match_ids' => $matchIds,
        ];
    }

    /**
     * @return array{picks:int,points_awarded:int,user_ids:list<int>}
     */
    private static function settleChampionBonus(bool $allSeasons): array
    {
        $pdo = DB::pdo();
        $statusPh = implode(',', array_fill(0, count(self::FINISHED_STATUSES), '?'));

        $sql = "SELECT tp.id AS tournament_pick_id, tp.user_id, tp.champion_team_id,
                       m.id AS match_id, m.winner_team_id
                FROM tournament_picks tp
                INNER JOIN (
                    SELECT MIN(m2.id) AS match_id, YEAR(m2.kickoff_at) AS season_year
                    FROM matches m2
                    WHERE m2.stage_key = 'FINAL'
                      AND m2.status IN ($statusPh)
                      AND m2.winner_team_id IS NOT NULL
                    GROUP BY YEAR(m2.kickoff_at)
                ) fm ON fm.season_year = tp.season
                INNER JOIN matches m ON m.id = fm.match_id
                LEFT JOIN points_ledger pl
                    ON pl.tournament_pick_id = tp.id
                    AND pl.reason IN ('champion_bonus', 'champion_none')
                WHERE pl.id IS NULL";

        $params = self::FINISHED_STATUSES;
        if (!$allSeasons) {
            $cfg = require dirname(__DIR__) . '/Config/app.php';
            $sql .= ' AND tp.season = ?';
            $params[] = (int)($cfg['football_data']['season'] ?? 2026);
        }

        $st = $pdo->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll() ?: [];

        $insert = $pdo->prepare(
            'INSERT INTO points_ledger (user_id, match_id, prediction_id, tournament_pick_id, points, reason, created_at)
             VALUES (:user_id, :match_id, NULL, :tournament_pick_id, :points, :reason, NOW())'
        );

        $picks = 0;
        $pointsAwarded = 0;
        $userIds = [];

        foreach ($rows as $row) {
            $result = ScoringService::championBonusPoints(
                (int)$row['champion_team_id'],
                (int)$row['winner_team_id'],
            );

            $insert->execute([
                'user_id' => (int)$row['user_id'],
                'match_id' => (int)$row['match_id'],
                'tournament_pick_id' => (int)$row['tournament_pick_id'],
                'points' => $result['points'],
                'reason' => $result['reason'],
            ]);

            $picks++;
            $pointsAwarded += $result['points'];
            $userIds[] = (int)$row['user_id'];
        }

        return [
            'picks' => $picks,
            'points_awarded' => $pointsAwarded,
            'user_ids' => $userIds,
        ];
    }

    /** @param list<int> $userIds */
    public static function recalculateUserTotals(array $userIds = []): int
    {
        $pdo = DB::pdo();

        if ($userIds === []) {
            $pdo->exec(
                'INSERT INTO user_points (user_id, points_total)
                 SELECT DISTINCT pl.user_id, 0 FROM points_ledger pl
                 LEFT JOIN user_points up ON up.user_id = pl.user_id
                 WHERE up.user_id IS NULL'
            );
            return $pdo->exec(
                'UPDATE user_points up
                 SET points_total = (
                     SELECT COALESCE(SUM(pl.points), 0)
                     FROM points_ledger pl
                     WHERE pl.user_id = up.user_id
                 )'
            ) ?: 0;
        }

        $placeholders = implode(',', array_fill(0, count($userIds), '?'));

        $ensure = $pdo->prepare(
            "INSERT INTO user_points (user_id, points_total)
             SELECT u.id, 0 FROM users u WHERE u.id IN ($placeholders)
             ON DUPLICATE KEY UPDATE user_id = user_id"
        );
        $ensure->execute($userIds);

        $st = $pdo->prepare(
            "UPDATE user_points up
             SET points_total = (
                 SELECT COALESCE(SUM(pl.points), 0)
                 FROM points_ledger pl
                 WHERE pl.user_id = up.user_id
             )
             WHERE up.user_id IN ($placeholders)"
        );
        $st->execute($userIds);

        return $st->rowCount();
    }

    /** @param array<string, mixed> $row */
    private static function inferWinnerTeamId(array $row): ?int
    {
        $status = strtoupper((string)($row['match_status'] ?? ''));
        if (!in_array($status, self::FINISHED_STATUSES, true)) {
            return null;
        }

        $home = (int)($row['real_home'] ?? 0);
        $away = (int)($row['real_away'] ?? 0);
        if ($home > $away) {
            return (int)$row['home_team_id'];
        }
        if ($away > $home) {
            return (int)$row['away_team_id'];
        }

        return null;
    }
}
