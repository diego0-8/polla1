<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\DB;
use App\Models\Team;

final class GroupStandingService
{
    private const FINISHED = ['FT', 'PEN', 'AET'];

    public function __construct(
        private readonly ?FootballDataClient $api,
        private readonly string $competitionCode,
        private readonly int $season,
    ) {}

    /**
     * @return array{source:string,groups:int,rows:int}
     */
    public function sync(): array
    {
        if ($this->api !== null) {
            try {
                $rows = $this->importFromApi();
                if ($rows > 0) {
                    return ['source' => 'api', 'groups' => $this->countGroups(), 'rows' => $rows];
                }
            } catch (\Throwable) {
                // WC tipo CUP suele devolver 404; continuar con cálculo local.
            }
        }

        $rows = $this->computeFromMatches();
        return ['source' => 'matches', 'groups' => $this->countGroups(), 'rows' => $rows];
    }

    private function importFromApi(): int
    {
        $resp = $this->api->get('/competitions/' . $this->competitionCode . '/standings', [
            'season' => $this->season,
        ]);
        $standings = $resp['standings'] ?? [];
        $total = 0;

        foreach ($standings as $block) {
            $groupRaw = (string)($block['group'] ?? '');
            $groupCode = MatchDataMapper::parseGroupCode(['group' => $groupRaw]);
            if ($groupCode === null) {
                continue;
            }
            foreach (($block['table'] ?? []) as $row) {
                $team = $row['team'] ?? [];
                $apiTeamId = (int)($team['id'] ?? 0);
                if ($apiTeamId <= 0) {
                    continue;
                }
                $teamId = Team::upsertFromApi(
                    $apiTeamId,
                    (string)($team['name'] ?? 'TBD'),
                    isset($team['tla']) ? (string)$team['tla'] : null,
                    isset($team['crest']) ? (string)$team['crest'] : null,
                );
                $this->upsertRow($groupCode, $teamId, $row);
                $total++;
            }
        }

        return $total;
    }

    public function computeFromMatches(): int
    {
        $pdo = DB::pdo();
        $groups = $pdo->prepare(
            'SELECT DISTINCT group_code FROM matches
             WHERE group_code IS NOT NULL AND YEAR(kickoff_at) = :year
             ORDER BY group_code'
        );
        $groups->execute(['year' => $this->season]);
        $groupCodes = array_column($groups->fetchAll() ?: [], 'group_code');

        $total = 0;
        foreach ($groupCodes as $groupCode) {
            if ($groupCode === null || $groupCode === '') {
                continue;
            }
            $total += $this->computeGroup((string)$groupCode);
        }

        return $total;
    }

    private function computeGroup(string $groupCode): int
    {
        $pdo = DB::pdo();
        $teamIds = $this->teamIdsInGroup($groupCode);
        if ($teamIds === []) {
            return 0;
        }

        $stats = [];
        foreach ($teamIds as $tid) {
            $stats[$tid] = [
                'played' => 0, 'won' => 0, 'draw' => 0, 'lost' => 0,
                'gf' => 0, 'ga' => 0, 'pts' => 0,
            ];
        }

        $statusPh = implode(',', array_fill(0, count(self::FINISHED), '?'));
        $st = $pdo->prepare(
            "SELECT home_team_id, away_team_id, home_score, away_score
             FROM matches
             WHERE group_code = ?
               AND YEAR(kickoff_at) = ?
               AND status IN ($statusPh)"
        );
        $st->execute(array_merge([$groupCode, $this->season], self::FINISHED));

        foreach ($st->fetchAll() ?: [] as $m) {
            $home = (int)$m['home_team_id'];
            $away = (int)$m['away_team_id'];
            $hs = (int)$m['home_score'];
            $as = (int)$m['away_score'];
            if (!isset($stats[$home], $stats[$away])) {
                continue;
            }
            $stats[$home]['played']++;
            $stats[$away]['played']++;
            $stats[$home]['gf'] += $hs;
            $stats[$home]['ga'] += $as;
            $stats[$away]['gf'] += $as;
            $stats[$away]['ga'] += $hs;

            if ($hs > $as) {
                $stats[$home]['won']++;
                $stats[$away]['lost']++;
                $stats[$home]['pts'] += 3;
            } elseif ($hs < $as) {
                $stats[$away]['won']++;
                $stats[$home]['lost']++;
                $stats[$away]['pts'] += 3;
            } else {
                $stats[$home]['draw']++;
                $stats[$away]['draw']++;
                $stats[$home]['pts'] += 1;
                $stats[$away]['pts'] += 1;
            }
        }

        $rows = [];
        foreach ($stats as $teamId => $s) {
            $rows[] = [
                'team_id' => $teamId,
                'played_games' => $s['played'],
                'won' => $s['won'],
                'draw' => $s['draw'],
                'lost' => $s['lost'],
                'goals_for' => $s['gf'],
                'goals_against' => $s['ga'],
                'goal_difference' => $s['gf'] - $s['ga'],
                'points' => $s['pts'],
            ];
        }

        usort($rows, static function (array $a, array $b): int {
            return [$b['points'], $b['goal_difference'], $b['goals_for'], $a['team_id']]
                <=> [$a['points'], $a['goal_difference'], $a['goals_for'], $b['team_id']];
        });

        $pos = 1;
        foreach ($rows as $row) {
            $this->upsertRow($groupCode, (int)$row['team_id'], array_merge($row, ['position' => $pos++]));
        }

        return count($rows);
    }

    /** @return list<int> */
    private function teamIdsInGroup(string $groupCode): array
    {
        $pdo = DB::pdo();
        $st = $pdo->prepare(
            'SELECT home_team_id AS tid FROM matches WHERE group_code = :g AND YEAR(kickoff_at) = :y
             UNION
             SELECT away_team_id AS tid FROM matches WHERE group_code = :g2 AND YEAR(kickoff_at) = :y2'
        );
        $st->execute(['g' => $groupCode, 'y' => $this->season, 'g2' => $groupCode, 'y2' => $this->season]);
        $ids = [];
        foreach ($st->fetchAll() ?: [] as $r) {
            $ids[] = (int)$r['tid'];
        }
        return array_values(array_unique($ids));
    }

    private function upsertRow(string $groupCode, int $teamId, array $row): void
    {
        $pdo = DB::pdo();
        $st = $pdo->prepare(
            'INSERT INTO group_standings (
                season, group_code, team_id, position, played_games, won, draw, lost,
                goals_for, goals_against, goal_difference, points, last_synced_at
             ) VALUES (
                :season, :group_code, :team_id, :position, :played, :won, :draw, :lost,
                :gf, :ga, :gd, :pts, NOW()
             )
             ON DUPLICATE KEY UPDATE
                position = VALUES(position),
                played_games = VALUES(played_games),
                won = VALUES(won),
                draw = VALUES(draw),
                lost = VALUES(lost),
                goals_for = VALUES(goals_for),
                goals_against = VALUES(goals_against),
                goal_difference = VALUES(goal_difference),
                points = VALUES(points),
                last_synced_at = NOW()'
        );
        $st->execute([
            'season' => $this->season,
            'group_code' => $groupCode,
            'team_id' => $teamId,
            'position' => (int)($row['position'] ?? 0),
            'played' => (int)($row['playedGames'] ?? $row['played_games'] ?? 0),
            'won' => (int)($row['won'] ?? 0),
            'draw' => (int)($row['draw'] ?? 0),
            'lost' => (int)($row['lost'] ?? 0),
            'gf' => (int)($row['goalsFor'] ?? $row['goals_for'] ?? 0),
            'ga' => (int)($row['goalsAgainst'] ?? $row['goals_against'] ?? 0),
            'gd' => (int)($row['goalDifference'] ?? $row['goal_difference'] ?? 0),
            'pts' => (int)($row['points'] ?? 0),
        ]);
    }

    private function countGroups(): int
    {
        $st = DB::pdo()->prepare(
            'SELECT COUNT(DISTINCT group_code) FROM group_standings WHERE season = :season'
        );
        $st->execute(['season' => $this->season]);
        return (int)$st->fetchColumn();
    }
}
