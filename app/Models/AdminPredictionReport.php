<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\DB;

final class AdminPredictionReport
{
    /**
     * @return array{
     *   asesors: list<array<string, mixed>>,
     *   matches: list<array<string, mixed>>,
     *   entries: array<int, array<int, array{exact:string,trend:string,props:string}>>,
     *   champions: array<int, array{name:string,code:string|null,registered_at:string|null}>
     * }
     */
    public static function forSeason(int $season): array
    {
        $asesors = User::activeByRole('asesor');
        $matches = MatchModel::forSeason($season, 500);
        $asesorIds = array_map(static fn (array $u): int => (int)$u['id'], $asesors);

        $entries = [];
        foreach ($matches as $match) {
            $matchId = (int)$match['id'];
            foreach ($asesorIds as $userId) {
                $entries[$matchId][$userId] = [
                    'exact' => '—',
                    'trend' => '—',
                    'props' => '—',
                ];
            }
        }

        $champions = self::loadChampions($season, $asesorIds);

        if ($asesorIds === []) {
            return [
                'asesors' => $asesors,
                'matches' => $matches,
                'entries' => $entries,
                'champions' => $champions,
            ];
        }

        $matchesById = [];
        foreach ($matches as $match) {
            $matchesById[(int)$match['id']] = $match;
        }

        self::loadPredictions($season, $asesorIds, $matchesById, $entries);
        self::loadProps($season, $asesorIds, $entries);

        return [
            'asesors' => $asesors,
            'matches' => $matches,
            'entries' => $entries,
            'champions' => $champions,
        ];
    }

    /** @param list<int> $asesorIds */
    /** @return array<int, array{name:string,code:string|null,registered_at:string|null}> */
    private static function loadChampions(int $season, array $asesorIds): array
    {
        if ($asesorIds === []) {
            return [];
        }

        $ph = implode(',', array_fill(0, count($asesorIds), '?'));
        $st = DB::pdo()->prepare(
            "SELECT tp.user_id, tp.created_at AS registered_at,
                    t.name AS champion_name, t.code AS champion_code
             FROM tournament_picks tp
             INNER JOIN teams t ON t.id = tp.champion_team_id
             WHERE tp.season = ? AND tp.user_id IN ($ph)"
        );
        $st->execute(array_merge([$season], $asesorIds));

        $champions = [];
        foreach ($st->fetchAll() ?: [] as $row) {
            $champions[(int)$row['user_id']] = [
                'name' => (string)$row['champion_name'],
                'code' => $row['champion_code'] !== null ? (string)$row['champion_code'] : null,
                'registered_at' => $row['registered_at'] !== null ? (string)$row['registered_at'] : null,
            ];
        }

        return $champions;
    }

    /**
     * @param list<int> $asesorIds
     * @param array<int, array<string, mixed>> $matchesById
     * @param array<int, array<int, array{exact:string,trend:string,props:string}>> $entries
     */
    private static function loadPredictions(
        int $season,
        array $asesorIds,
        array $matchesById,
        array &$entries,
    ): void {
        $ph = implode(',', array_fill(0, count($asesorIds), '?'));
        $st = DB::pdo()->prepare(
            "SELECT p.user_id, p.match_id, p.pred_type, p.pred_home, p.pred_away, p.pred_outcome,
                    t.name AS advances_team_name, t.code AS advances_team_code
             FROM predictions p
             INNER JOIN matches m ON m.id = p.match_id
             LEFT JOIN teams t ON t.id = p.advances_team_id
             WHERE YEAR(m.kickoff_at) = ? AND p.user_id IN ($ph)"
        );
        $st->execute(array_merge([$season], $asesorIds));

        foreach ($st->fetchAll() ?: [] as $row) {
            $matchId = (int)$row['match_id'];
            $userId = (int)$row['user_id'];
            if (!isset($entries[$matchId][$userId])) {
                continue;
            }

            $match = $matchesById[$matchId] ?? null;
            $type = (string)$row['pred_type'];

            if ($type === 'exact') {
                $entries[$matchId][$userId]['exact'] =
                    (int)$row['pred_home'] . ' : ' . (int)$row['pred_away'];
            } elseif ($type === 'outcome' && $match !== null) {
                $entries[$matchId][$userId]['trend'] = Prediction::outcomeLabel(
                    (string)($row['pred_outcome'] ?? ''),
                    $match,
                );
            } elseif ($type === 'advance') {
                $entries[$matchId][$userId]['trend'] = Prediction::advanceLabel($row);
            }
        }
    }

    /**
     * @param list<int> $asesorIds
     * @param array<int, array<int, array{exact:string,trend:string,props:string}>> $entries
     */
    private static function loadProps(int $season, array $asesorIds, array &$entries): void
    {
        $ph = implode(',', array_fill(0, count($asesorIds), '?'));
        $st = DB::pdo()->prepare(
            "SELECT pp.user_id, pp.match_id, pp.market, pp.line, pp.pick
             FROM prop_predictions pp
             INNER JOIN matches m ON m.id = pp.match_id
             WHERE YEAR(m.kickoff_at) = ? AND pp.user_id IN ($ph)
             ORDER BY pp.match_id, pp.user_id, pp.market"
        );
        $st->execute(array_merge([$season], $asesorIds));

        $propsByCell = [];
        foreach ($st->fetchAll() ?: [] as $row) {
            $matchId = (int)$row['match_id'];
            $userId = (int)$row['user_id'];
            if (!isset($entries[$matchId][$userId])) {
                continue;
            }
            $key = $matchId . ':' . $userId;
            $propsByCell[$key][] = PropPrediction::label($row);
        }

        foreach ($propsByCell as $key => $labels) {
            [$matchId, $userId] = array_map('intval', explode(':', $key, 2));
            if (isset($entries[$matchId][$userId])) {
                $entries[$matchId][$userId]['props'] = implode(' · ', $labels);
            }
        }
    }
}
