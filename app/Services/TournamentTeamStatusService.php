<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\DB;
use App\Models\GroupStanding;

/**
 * Determina qué selecciones siguen vivas en el torneo según partidos finalizados en BD (sync API).
 */
final class TournamentTeamStatusService
{
    private const FINISHED = ['FT', 'PEN', 'AET'];

    /** @return array<int, true> */
    public static function stillInPlayTeamIds(int $season): array
    {
        $eliminated = self::eliminatedTeamIds($season);
        $all = self::allTeamIdsInSeason($season);
        $inPlay = [];

        foreach ($all as $teamId) {
            if (!isset($eliminated[$teamId])) {
                $inPlay[$teamId] = true;
            }
        }

        return $inPlay;
    }

    public static function isStillInPlay(int $teamId, int $season): bool
    {
        if ($teamId <= 0) {
            return false;
        }

        return isset(self::stillInPlayTeamIds($season)[$teamId]);
    }

    /** @return array<int, true> */
    private static function eliminatedTeamIds(int $season): array
    {
        $eliminated = self::knockoutLosers($season);
        foreach (self::groupStageEliminated($season) as $teamId) {
            $eliminated[$teamId] = true;
        }

        return $eliminated;
    }

    /** @return list<int> */
    private static function allTeamIdsInSeason(int $season): array
    {
        $pdo = DB::pdo();
        $st = $pdo->prepare(
            'SELECT DISTINCT tid FROM (
                SELECT home_team_id AS tid FROM matches WHERE YEAR(kickoff_at) = :season
                UNION
                SELECT away_team_id AS tid FROM matches WHERE YEAR(kickoff_at) = :season2
             ) x WHERE tid IS NOT NULL'
        );
        $st->execute(['season' => $season, 'season2' => $season]);

        $ids = [];
        foreach ($st->fetchAll() ?: [] as $row) {
            $ids[] = (int)$row['tid'];
        }

        return $ids;
    }

    /** @return array<int, true> */
    private static function knockoutLosers(int $season): array
    {
        $pdo = DB::pdo();
        $statusPh = implode(',', array_fill(0, count(self::FINISHED), '?'));
        $params = array_merge([$season], self::FINISHED);

        $st = $pdo->prepare(
            "SELECT home_team_id, away_team_id, winner_team_id
             FROM matches
             WHERE YEAR(kickoff_at) = ?
               AND status IN ($statusPh)
               AND winner_team_id IS NOT NULL
               AND COALESCE(stage_key, '') NOT IN ('', 'GROUP_STAGE')"
        );
        $st->execute($params);

        $eliminated = [];
        foreach ($st->fetchAll() ?: [] as $row) {
            $winner = (int)$row['winner_team_id'];
            $home = (int)$row['home_team_id'];
            $away = (int)$row['away_team_id'];
            $loser = $winner === $home ? $away : $home;
            if ($loser > 0) {
                $eliminated[$loser] = true;
            }
        }

        return $eliminated;
    }

    /** @return array<int, true> */
    private static function groupStageEliminated(int $season): array
    {
        $pdo = DB::pdo();
        $groups = $pdo->prepare(
            'SELECT group_code,
                    COUNT(*) AS total,
                    SUM(CASE WHEN status IN (\'FT\',\'PEN\',\'AET\') THEN 1 ELSE 0 END) AS finished
             FROM matches
             WHERE group_code IS NOT NULL AND YEAR(kickoff_at) = :season
             GROUP BY group_code'
        );
        $groups->execute(['season' => $season]);

        $eliminated = [];
        foreach ($groups->fetchAll() ?: [] as $groupRow) {
            $total = (int)$groupRow['total'];
            $finished = (int)$groupRow['finished'];
            if ($total === 0 || $finished < $total) {
                continue;
            }

            $groupCode = (string)$groupRow['group_code'];
            $standings = GroupStanding::forGroup($season, $groupCode);
            foreach ($standings as $row) {
                if ((int)$row['position'] > 2) {
                    $eliminated[(int)$row['team_id']] = true;
                }
            }
        }

        return $eliminated;
    }
}
