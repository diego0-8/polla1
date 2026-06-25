<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\DB;
use App\Models\MatchModel;

final class UserPredictionOverviewService
{
    public const PER_PAGE = 6;

    /**
     * @param array{q?:string,date?:string,bet?:string,page?:int} $filters
     * @return array{
     *   rows:list<array<string,mixed>>,
     *   total:int,
     *   page:int,
     *   pages:int,
     *   per_page:int,
     *   filters:array{q:string,date:string,bet:string}
     * }
     */
    public static function paginatedForUser(int $userId, ?int $season = null, array $filters = []): array
    {
        $season ??= MatchModel::seasonYear();
        $q = trim((string)($filters['q'] ?? ''));
        $date = trim((string)($filters['date'] ?? ''));
        $bet = (string)($filters['bet'] ?? 'all');
        if (!in_array($bet, ['all', 'predicted', 'unpredicted'], true)) {
            $bet = 'all';
        }
        $page = max(1, (int)($filters['page'] ?? 1));

        $matches = MatchModel::forSeason($season, 500);
        $predictionsByMatch = self::loadPredictionsByMatch($userId, $season);
        $propsByMatch = self::loadPropsByMatch($userId, $season);
        $ledgerByMatch = self::loadLedgerByMatch($userId, $season);

        $rows = [];
        foreach ($matches as $match) {
            $matchId = (int)$match['id'];
            $bets = [
                'predictions' => $predictionsByMatch[$matchId] ?? [],
                'props' => $propsByMatch[$matchId] ?? [],
            ];
            $row = UserPredictionBreakdown::forMatch(
                $userId,
                $match,
                $bets,
                $ledgerByMatch[$matchId] ?? [],
            );

            if ($q !== '' && !str_contains($row['search_text'], strtolower($q))) {
                continue;
            }
            if ($date !== '' && $row['kickoff_date'] !== $date) {
                continue;
            }
            if ($bet === 'predicted' && !$row['has_bet']) {
                continue;
            }
            if ($bet === 'unpredicted' && $row['has_bet']) {
                continue;
            }
            if ($bet !== 'all' && empty($row['is_finished'])) {
                continue;
            }

            $rows[] = $row;
        }

        $total = count($rows);
        $pages = max(1, (int)ceil($total / self::PER_PAGE));
        if ($page > $pages) {
            $page = $pages;
        }
        $offset = ($page - 1) * self::PER_PAGE;
        $pageRows = array_slice($rows, $offset, self::PER_PAGE);

        return [
            'rows' => $pageRows,
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'per_page' => self::PER_PAGE,
            'filters' => [
                'q' => $q,
                'date' => $date,
                'bet' => $bet,
            ],
        ];
    }

    /** @return array<int, list<array<string, mixed>>> */
    private static function loadPredictionsByMatch(int $userId, int $season): array
    {
        [$from, $to] = MatchModel::seasonKickoffBounds($season);
        $st = DB::pdo()->prepare(
            "SELECT p.*
             FROM predictions p
             INNER JOIN matches m ON m.id = p.match_id
             LEFT JOIN teams t ON t.id = p.advances_team_id
             WHERE p.user_id = :user_id
               AND m.kickoff_at >= :from AND m.kickoff_at < :to"
        );
        $st->execute(['user_id' => $userId, 'from' => $from, 'to' => $to]);
        $rows = $st->fetchAll() ?: [];

        $advanceIds = [];
        foreach ($rows as $row) {
            if ((string)($row['pred_type'] ?? '') === 'advance' && !empty($row['advances_team_id'])) {
                $advanceIds[(int)$row['advances_team_id']] = true;
            }
        }

        $teamNames = [];
        if ($advanceIds !== []) {
            $ph = implode(',', array_fill(0, count($advanceIds), '?'));
            $tSt = DB::pdo()->prepare("SELECT id, name, code, api_team_id FROM teams WHERE id IN ($ph)");
            $tSt->execute(array_keys($advanceIds));
            foreach ($tSt->fetchAll() ?: [] as $t) {
                $teamNames[(int)$t['id']] = $t;
            }
        }

        $byMatch = [];
        foreach ($rows as $row) {
            $matchId = (int)$row['match_id'];
            if ((string)($row['pred_type'] ?? '') === 'advance') {
                $tid = (int)($row['advances_team_id'] ?? 0);
                if (isset($teamNames[$tid])) {
                    $row['advances_team_name'] = $teamNames[$tid]['name'];
                    $row['advances_team_code'] = $teamNames[$tid]['code'];
                }
            }
            $byMatch[$matchId][] = $row;
        }

        return $byMatch;
    }

    /** @return array<int, list<array<string, mixed>>> */
    private static function loadPropsByMatch(int $userId, int $season): array
    {
        [$from, $to] = MatchModel::seasonKickoffBounds($season);
        $st = DB::pdo()->prepare(
            "SELECT pp.*
             FROM prop_predictions pp
             INNER JOIN matches m ON m.id = pp.match_id
             WHERE pp.user_id = :user_id
               AND m.kickoff_at >= :from AND m.kickoff_at < :to"
        );
        $st->execute(['user_id' => $userId, 'from' => $from, 'to' => $to]);

        $byMatch = [];
        foreach ($st->fetchAll() ?: [] as $row) {
            $byMatch[(int)$row['match_id']][] = $row;
        }

        return $byMatch;
    }

    /** @return array<int, list<array{points:int,prediction_id:?int,prop_prediction_id:?int}>> */
    private static function loadLedgerByMatch(int $userId, int $season): array
    {
        [$from, $to] = MatchModel::seasonKickoffBounds($season);
        $st = DB::pdo()->prepare(
            "SELECT pl.match_id, pl.points, pl.prediction_id, pl.prop_prediction_id
             FROM points_ledger pl
             INNER JOIN matches m ON m.id = pl.match_id
             WHERE pl.user_id = :user_id
               AND m.kickoff_at >= :from AND m.kickoff_at < :to"
        );
        $st->execute(['user_id' => $userId, 'from' => $from, 'to' => $to]);

        $byMatch = [];
        foreach ($st->fetchAll() ?: [] as $row) {
            $matchId = (int)$row['match_id'];
            $byMatch[$matchId][] = [
                'points' => (int)$row['points'],
                'prediction_id' => $row['prediction_id'] !== null ? (int)$row['prediction_id'] : null,
                'prop_prediction_id' => $row['prop_prediction_id'] !== null ? (int)$row['prop_prediction_id'] : null,
            ];
        }

        return $byMatch;
    }
}
