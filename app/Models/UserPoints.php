<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\DB;
use App\Helpers\TeamName;
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

    /** @param list<array<string, mixed>> $rows @return list<array<string, mixed>> */
    public static function attachParticipationStats(array $rows, int $season): array
    {
        if ($rows === []) {
            return $rows;
        }

        $userIds = array_map(static fn (array $r): int => (int)$r['id'], $rows);
        $ph = implode(',', array_fill(0, count($userIds), '?'));

        $st = DB::pdo()->prepare(
            "SELECT user_id, COUNT(DISTINCT match_id) AS matches_bet
             FROM (
                SELECT p.user_id, p.match_id
                FROM predictions p
                INNER JOIN matches m ON m.id = p.match_id
                WHERE YEAR(m.kickoff_at) = ?
                  AND m.status IN ('FT', 'PEN', 'AET')
                UNION
                SELECT pp.user_id, pp.match_id
                FROM prop_predictions pp
                INNER JOIN matches m ON m.id = pp.match_id
                WHERE YEAR(m.kickoff_at) = ?
                  AND m.status IN ('FT', 'PEN', 'AET')
             ) bets
             WHERE user_id IN ($ph)
             GROUP BY user_id"
        );
        $st->execute(array_merge([$season, $season], $userIds));

        $byUser = [];
        foreach ($st->fetchAll() ?: [] as $row) {
            $byUser[(int)$row['user_id']] = (int)$row['matches_bet'];
        }

        foreach ($rows as $idx => $row) {
            $rows[$idx]['matches_bet'] = $byUser[(int)$row['id']] ?? 0;
        }

        return $rows;
    }

    /** @param list<array<string, mixed>> $rows @return list<array<string, mixed>> */
    public static function attachLastMatchBreakdown(array $rows, int $season): array
    {
        if ($rows === []) {
            return $rows;
        }

        $userIds = array_map(static fn (array $r): int => (int)$r['id'], $rows);
        $lastMatches = self::loadLastPredictedMatches($userIds, $season);
        $predictionsByKey = self::loadPredictionsForUserMatches($lastMatches);
        $ledgerByKey = self::loadLedgerForUserMatches($lastMatches);

        foreach ($rows as $idx => $row) {
            $userId = (int)$row['id'];
            $rows[$idx]['last_match'] = self::buildLastMatchBreakdown(
                $userId,
                $lastMatches[$userId] ?? null,
                $predictionsByKey,
                $ledgerByKey,
            );
        }

        return $rows;
    }

    /** @param list<int> $userIds @return array<int, array<string, mixed>> */
    private static function loadLastPredictedMatches(array $userIds, int $season): array
    {
        if ($userIds === []) {
            return [];
        }

        $ph = implode(',', array_fill(0, count($userIds), '?'));
        $st = DB::pdo()->prepare(
            "SELECT lm.user_id, lm.match_id, m.kickoff_at, m.status,
                    th.name AS home_name, th.code AS home_code, th.api_team_id AS home_api_team_id,
                    ta.name AS away_name, ta.code AS away_code, ta.api_team_id AS away_api_team_id
             FROM (
                SELECT user_id, match_id
                FROM (
                    SELECT b.user_id, b.match_id, m.kickoff_at,
                           ROW_NUMBER() OVER (
                               PARTITION BY b.user_id
                               ORDER BY m.kickoff_at DESC, b.match_id DESC
                           ) AS rn
                    FROM (
                        SELECT user_id, match_id
                        FROM (
                            SELECT p.user_id, p.match_id
                            FROM predictions p
                            INNER JOIN matches m ON m.id = p.match_id
                            WHERE YEAR(m.kickoff_at) = ?
                              AND m.status IN ('FT', 'PEN', 'AET')
                            UNION
                            SELECT pp.user_id, pp.match_id
                            FROM prop_predictions pp
                            INNER JOIN matches m ON m.id = pp.match_id
                            WHERE YEAR(m.kickoff_at) = ?
                              AND m.status IN ('FT', 'PEN', 'AET')
                        ) all_bets
                    ) b
                    INNER JOIN matches m ON m.id = b.match_id
                ) ranked
                WHERE rn = 1
             ) lm
             INNER JOIN matches m ON m.id = lm.match_id
             INNER JOIN teams th ON th.id = m.home_team_id
             INNER JOIN teams ta ON ta.id = m.away_team_id
             WHERE lm.user_id IN ($ph)"
        );
        $st->execute(array_merge([$season, $season], $userIds));

        $result = [];
        foreach ($st->fetchAll() ?: [] as $row) {
            $result[(int)$row['user_id']] = TeamName::applyToMatch($row);
        }

        return $result;
    }

    /**
     * @param array<int, array<string, mixed>> $lastMatches
     * @return array<string, array{predictions:list<array<string,mixed>>,props:list<array<string,mixed>>}>
     */
    private static function loadPredictionsForUserMatches(array $lastMatches): array
    {
        if ($lastMatches === []) {
            return [];
        }

        $conditions = [];
        $params = [];
        foreach ($lastMatches as $userId => $match) {
            $conditions[] = '(user_id = ? AND match_id = ?)';
            $params[] = (int)$userId;
            $params[] = (int)$match['match_id'];
        }

        $where = implode(' OR ', $conditions);

        $predSt = DB::pdo()->prepare(
            "SELECT id, user_id, match_id, pred_type
             FROM predictions
             WHERE $where"
        );
        $predSt->execute($params);
        $predRows = $predSt->fetchAll() ?: [];

        $propSt = DB::pdo()->prepare(
            "SELECT id, user_id, match_id, market
             FROM prop_predictions
             WHERE $where"
        );
        $propSt->execute($params);
        $propRows = $propSt->fetchAll() ?: [];

        $result = [];
        foreach ($lastMatches as $userId => $match) {
            $key = (int)$userId . ':' . (int)$match['match_id'];
            $result[$key] = ['predictions' => [], 'props' => []];
        }

        foreach ($predRows as $row) {
            $key = (int)$row['user_id'] . ':' . (int)$row['match_id'];
            $result[$key]['predictions'][] = $row;
        }
        foreach ($propRows as $row) {
            $key = (int)$row['user_id'] . ':' . (int)$row['match_id'];
            $result[$key]['props'][] = $row;
        }

        return $result;
    }

    /**
     * @param array<int, array<string, mixed>> $lastMatches
     * @return array<string, list<array{points:int,prediction_id:?int,prop_prediction_id:?int}>>
     */
    private static function loadLedgerForUserMatches(array $lastMatches): array
    {
        if ($lastMatches === []) {
            return [];
        }

        $conditions = [];
        $params = [];
        foreach ($lastMatches as $userId => $match) {
            $conditions[] = '(pl.user_id = ? AND pl.match_id = ?)';
            $params[] = (int)$userId;
            $params[] = (int)$match['match_id'];
        }

        $st = DB::pdo()->prepare(
            'SELECT pl.user_id, pl.match_id, pl.points, pl.prediction_id, pl.prop_prediction_id
             FROM points_ledger pl
             WHERE ' . implode(' OR ', $conditions)
        );
        $st->execute($params);

        $result = [];
        foreach ($st->fetchAll() ?: [] as $row) {
            $key = (int)$row['user_id'] . ':' . (int)$row['match_id'];
            $result[$key][] = [
                'points' => (int)$row['points'],
                'prediction_id' => $row['prediction_id'] !== null ? (int)$row['prediction_id'] : null,
                'prop_prediction_id' => $row['prop_prediction_id'] !== null ? (int)$row['prop_prediction_id'] : null,
            ];
        }

        return $result;
    }

    /**
     * @param array<string, array{predictions:list<array<string,mixed>>,props:list<array<string,mixed>>}> $predictionsByKey
     * @param array<string, list<array{points:int,prediction_id:?int,prop_prediction_id:?int}>> $ledgerByKey
     */
    private static function buildLastMatchBreakdown(
        int $userId,
        ?array $matchRow,
        array $predictionsByKey,
        array $ledgerByKey,
    ): ?array {
        if ($matchRow === null) {
            return null;
        }

        $matchId = (int)$matchRow['match_id'];
        $status = strtoupper((string)($matchRow['status'] ?? 'NS'));
        $finished = in_array($status, ['FT', 'PEN', 'AET'], true);
        $key = $userId . ':' . $matchId;
        $bets = $predictionsByKey[$key] ?? ['predictions' => [], 'props' => []];
        $ledgerRows = $ledgerByKey[$key] ?? [];

        $pointsByPredId = [];
        $pointsByPropId = [];
        foreach ($ledgerRows as $entry) {
            if ($entry['prediction_id'] !== null) {
                $pointsByPredId[$entry['prediction_id']] = $entry['points'];
            }
            if ($entry['prop_prediction_id'] !== null) {
                $pointsByPropId[$entry['prop_prediction_id']] = $entry['points'];
            }
        }

        $columnDefs = [
            'exact' => ['label' => 'Exacto', 'predicted' => false, 'points' => null],
            'gana' => ['label' => 'Gana', 'predicted' => false, 'points' => null],
            'btts' => ['label' => 'Ambos marcan', 'predicted' => false, 'points' => null],
            'goals' => ['label' => 'Goles', 'predicted' => false, 'points' => null],
            'corners' => ['label' => 'Corners', 'predicted' => false, 'points' => null],
            'cards' => ['label' => 'Tarjetas', 'predicted' => false, 'points' => null],
        ];

        foreach ($bets['predictions'] as $pred) {
            $predId = (int)$pred['id'];
            $predType = (string)$pred['pred_type'];
            $colKey = match ($predType) {
                'exact' => 'exact',
                'outcome', 'advance' => 'gana',
                default => null,
            };
            if ($colKey === null) {
                continue;
            }

            $columnDefs[$colKey]['predicted'] = true;
            if ($finished && array_key_exists($predId, $pointsByPredId)) {
                $columnDefs[$colKey]['points'] = $pointsByPredId[$predId];
            }
        }

        $propColMap = [
            'btts' => 'btts',
            'goals_ou' => 'goals',
            'corners_ou' => 'corners',
            'cards_ou' => 'cards',
        ];
        foreach ($bets['props'] as $prop) {
            $propId = (int)$prop['id'];
            $market = (string)$prop['market'];
            $colKey = $propColMap[$market] ?? null;
            if ($colKey === null) {
                continue;
            }

            $columnDefs[$colKey]['predicted'] = true;
            if ($finished && array_key_exists($propId, $pointsByPropId)) {
                $columnDefs[$colKey]['points'] = $pointsByPropId[$propId];
            }
        }

        $columns = [];
        $total = 0;
        $anyPredicted = false;
        foreach ($columnDefs as $col) {
            if (!$col['predicted']) {
                continue;
            }
            $anyPredicted = true;
            $points = $col['points'];
            if ($points !== null) {
                $total += $points;
            }
            $columns[] = [
                'label' => $col['label'],
                'points' => $points,
            ];
        }

        $isSettled = $finished && $anyPredicted && array_reduce(
            $columns,
            static fn (bool $ok, array $c): bool => $ok && $c['points'] !== null,
            true,
        );

        return [
            'match_id' => $matchId,
            'label' => (string)$matchRow['home_name'] . ' vs ' . (string)$matchRow['away_name'],
            'kickoff_at' => (string)$matchRow['kickoff_at'],
            'status' => $status,
            'is_finished' => $finished,
            'is_settled' => $isSettled,
            'columns' => $columns,
            'total' => $total,
        ];
    }
}
