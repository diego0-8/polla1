<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\DB;
use App\Models\ManualMatchUpdate;
use App\Models\MatchEvent;
use App\Models\MatchModel;
use App\Services\TournamentTeamStatusService;

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
            $resolved = self::resolveMatchScores($row);
            $row['real_home'] = $resolved['real_home'];
            $row['real_away'] = $resolved['real_away'];
            $row['match_status'] = $resolved['match_status'];
            $row['winner_team_id'] = $resolved['winner_team_id'];

            $predType = (string)($row['pred_type'] ?? 'outcome');
            if ($predType === 'advance') {
                $winnerTeamId = $row['winner_team_id'] !== null
                    ? (int)$row['winner_team_id']
                    : null;
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
                       pp.line, pp.pick
                FROM prop_predictions pp
                INNER JOIN matches m ON m.id = pp.match_id
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
            $matchId = (int)$row['match_id'];
            $market = (string)$row['market'];
            $statsRow = \App\Models\MatchStats::forMatch($matchId);
            if (!\App\Models\MatchStats::hasStatsForMarket($statsRow, $market)) {
                continue;
            }

            $line = $row['line'] !== null ? (float)$row['line'] : null;
            $stats = [
                'total_corners' => $statsRow['total_corners'] ?? null,
                'total_cards' => $statsRow['total_cards'] ?? null,
                'total_goals' => $statsRow['total_goals'] ?? null,
                'btts' => (bool)($statsRow['btts'] ?? false),
            ];

            $result = ScoringService::propPoints(
                $market,
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
        $cfg = require dirname(__DIR__) . '/Config/app.php';
        $seasonYear = (int)($cfg['football_data']['season'] ?? 2026);

        $statusPh = implode(',', array_fill(0, count(self::FINISHED_STATUSES), '?'));
        $sql = "SELECT id, home_team_id, away_team_id, winner_team_id, status, kickoff_at,
                       COALESCE(regular_home_score, home_score) AS real_home,
                       COALESCE(regular_away_score, away_score) AS real_away
                FROM matches
                WHERE stage_key = 'FINAL'
                  AND status IN ($statusPh)";
        $params = self::FINISHED_STATUSES;
        if (!$allSeasons) {
            $sql .= ' AND YEAR(kickoff_at) = ?';
            $params[] = $seasonYear;
        }
        $sql .= ' ORDER BY kickoff_at DESC LIMIT 1';

        $st = $pdo->prepare($sql);
        $st->execute($params);
        $finalRow = $st->fetch();
        if (!$finalRow) {
            return ['picks' => 0, 'points_awarded' => 0, 'user_ids' => []];
        }

        $resolved = self::resolveMatchScores([
            'match_id' => (int)$finalRow['id'],
            'match_status' => (string)$finalRow['status'],
            'real_home' => (int)$finalRow['real_home'],
            'real_away' => (int)$finalRow['real_away'],
            'home_team_id' => (int)$finalRow['home_team_id'],
            'away_team_id' => (int)$finalRow['away_team_id'],
            'winner_team_id' => $finalRow['winner_team_id'],
        ]);
        $winnerTeamId = $resolved['winner_team_id'];
        if ($winnerTeamId === null) {
            return ['picks' => 0, 'points_awarded' => 0, 'user_ids' => []];
        }

        $finalMatchId = (int)$finalRow['id'];
        $finalSeason = (int)(new \DateTimeImmutable((string)$finalRow['kickoff_at']))->format('Y');

        $pickSql = "SELECT tp.id AS tournament_pick_id, tp.user_id, tp.champion_team_id
             FROM tournament_picks tp
             LEFT JOIN points_ledger pl
                ON pl.tournament_pick_id = tp.id
                AND pl.reason IN ('champion_bonus', 'champion_none')
             WHERE pl.id IS NULL";
        $pickParams = [];
        if (!$allSeasons) {
            $pickSql .= ' AND tp.season = ?';
            $pickParams[] = $finalSeason;
        }
        $pickSt = $pdo->prepare($pickSql);
        $pickSt->execute($pickParams);
        $rows = $pickSt->fetchAll() ?: [];

        $insert = $pdo->prepare(
            'INSERT INTO points_ledger (user_id, match_id, prediction_id, tournament_pick_id, points, reason, created_at)
             VALUES (:user_id, :match_id, NULL, :tournament_pick_id, :points, :reason, NOW())'
        );

        $picks = 0;
        $pointsAwarded = 0;
        $userIds = [];

        foreach ($rows as $row) {
            $pickTeamId = (int)$row['champion_team_id'];
            if ($pickTeamId !== $winnerTeamId) {
                $result = ['points' => 0, 'reason' => 'champion_none'];
            } elseif (!TournamentTeamStatusService::isStillInPlay($pickTeamId, $finalSeason)) {
                $result = ['points' => 0, 'reason' => 'champion_none'];
            } else {
                $result = ScoringService::championBonusPoints($pickTeamId, $winnerTeamId);
            }

            $insert->execute([
                'user_id' => (int)$row['user_id'],
                'match_id' => $finalMatchId,
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

    /** @param array<string, mixed> $row @return array{real_home:int,real_away:int,match_status:string,winner_team_id:?int} */
    private static function resolveMatchScores(array $row): array
    {
        $matchId = (int)$row['match_id'];
        $dbMatch = MatchModel::findById($matchId);

        if ($dbMatch !== null) {
            $homeScore = (int)($dbMatch['regular_home_score'] ?? $dbMatch['home_score'] ?? 0);
            $awayScore = (int)($dbMatch['regular_away_score'] ?? $dbMatch['away_score'] ?? 0);
            $match = [
                'id' => $matchId,
                'status' => (string)$dbMatch['status'],
                'home_score' => $homeScore,
                'away_score' => $awayScore,
                'home_team_id' => (int)$dbMatch['home_team_id'],
                'away_team_id' => (int)$dbMatch['away_team_id'],
                'winner_team_id' => $dbMatch['winner_team_id'] ?? null,
            ];
        } else {
            $match = [
                'id' => $matchId,
                'status' => (string)($row['match_status'] ?? 'NS'),
                'home_score' => (int)($row['real_home'] ?? 0),
                'away_score' => (int)($row['real_away'] ?? 0),
                'home_team_id' => (int)($row['home_team_id'] ?? 0),
                'away_team_id' => (int)($row['away_team_id'] ?? 0),
                'winner_team_id' => $row['winner_team_id'] ?? null,
            ];
        }

        $resolved = ManualMatchUpdate::applyToMatch($match, MatchEvent::forMatch($matchId));

        $winnerTeamId = isset($resolved['winner_team_id']) && $resolved['winner_team_id'] !== null
            ? (int)$resolved['winner_team_id']
            : null;
        if ($winnerTeamId === null) {
            $winnerTeamId = self::inferWinnerTeamId([
                'match_status' => (string)$resolved['status'],
                'real_home' => (int)$resolved['home_score'],
                'real_away' => (int)$resolved['away_score'],
                'home_team_id' => (int)$resolved['home_team_id'],
                'away_team_id' => (int)$resolved['away_team_id'],
            ]);
        }

        return [
            'real_home' => (int)$resolved['home_score'],
            'real_away' => (int)$resolved['away_score'],
            'match_status' => (string)$resolved['status'],
            'winner_team_id' => $winnerTeamId,
        ];
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
