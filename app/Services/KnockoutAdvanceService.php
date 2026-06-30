<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\DB;
use App\Models\MatchModel;

/**
 * Rellena cruces TBD en eliminatorias con el ganador del partido anterior
 * según el cuadro fijo FIFA (no emparejamiento secuencial por kickoff).
 */
final class KnockoutAdvanceService
{
    /** @var list<string> */
    private const STAGE_ORDER = [
        'LAST_16',
        'QUARTER_FINALS',
        'SEMI_FINALS',
        'FINAL',
        'THIRD_PLACE',
    ];

    /**
     * Corrige slots mal ubicados y propaga ganadores a TBD.
     *
     * @return list<array{match_id:int,stage_key:string,slot:string,team_id:int,team_name:string,action:string}>
     */
    public static function propagate(?int $season = null): array
    {
        $season ??= MatchModel::seasonYear();
        $bracket = require dirname(__DIR__) . '/Config/wc2026_knockout_bracket.php';
        $byApi = self::loadMatchesByApiId($season);
        $updates = [];

        foreach (self::STAGE_ORDER as $stageKey) {
            $feeds = $bracket[$stageKey] ?? [];
            if ($feeds === []) {
                continue;
            }

            foreach ($feeds as $targetApi => $slots) {
                $target = $byApi[(int)$targetApi] ?? null;
                if ($target === null) {
                    continue;
                }

                foreach (['home', 'away'] as $slot) {
                    $sourceKey = $stageKey === 'THIRD_PLACE'
                        ? ($slot === 'home' ? 'home_loser' : 'away_loser')
                        : $slot;

                    if (!isset($slots[$sourceKey])) {
                        continue;
                    }

                    $sourceApi = (int)$slots[$sourceKey];
                    $source = $byApi[$sourceApi] ?? null;
                    $expectedTeamId = $stageKey === 'THIRD_PLACE'
                        ? self::resolveLoserTeamId($source)
                        : self::resolveWinnerTeamId($source);

                    $currentTeamId = (int)$target[$slot . '_team_id'];
                    $isTbd = self::isTbdSlot($target, $slot);

                    if ($expectedTeamId !== null) {
                        if ($currentTeamId !== $expectedTeamId) {
                            self::assignTeam((int)$target['id'], $slot, $expectedTeamId);
                            $updates[] = self::updateMeta(
                                $target,
                                $slot,
                                $expectedTeamId,
                                $stageKey,
                                $isTbd ? 'propagate' : 'fix',
                            );
                            $target[$slot . '_team_id'] = $expectedTeamId;
                            $byApi[(int)$targetApi] = $target;
                        }
                        continue;
                    }

                    if (!$isTbd && self::isMisplacedTeam($currentTeamId, (int)$targetApi, $slot, $stageKey, $bracket, $byApi)) {
                        self::resetToTbd((int)$target['id'], $slot);
                        $updates[] = [
                            'match_id' => (int)$target['id'],
                            'stage_key' => $stageKey,
                            'slot' => $slot,
                            'team_id' => 0,
                            'team_name' => 'TBD',
                            'action' => 'reset',
                        ];
                    }
                }
            }
        }

        return $updates;
    }

    /** @return array<int, array<string, mixed>> */
    private static function loadMatchesByApiId(int $season): array
    {
        [$from, $to] = MatchModel::seasonKickoffBounds($season);
        $st = DB::pdo()->prepare(
            "SELECT m.id, m.api_fixture_id, m.stage_key, m.status, m.home_score, m.away_score,
                    m.winner_team_id, m.home_team_id, m.away_team_id,
                    th.name AS home_name, th.api_team_id AS home_api_team_id,
                    ta.name AS away_name, ta.api_team_id AS away_api_team_id
             FROM matches m
             JOIN teams th ON th.id = m.home_team_id
             JOIN teams ta ON ta.id = m.away_team_id
             WHERE m.kickoff_at >= :from AND m.kickoff_at < :to
               AND m.stage_key IN ('LAST_32','LAST_16','QUARTER_FINALS','SEMI_FINALS','FINAL','THIRD_PLACE')
             ORDER BY m.kickoff_at ASC, m.api_fixture_id ASC"
        );
        $st->execute(['from' => $from, 'to' => $to]);

        $byApi = [];
        foreach ($st->fetchAll() ?: [] as $row) {
            $apiId = (int)($row['api_fixture_id'] ?? 0);
            if ($apiId > 0) {
                $byApi[$apiId] = $row;
            }
        }

        return $byApi;
    }

    /**
     * @param array<string, mixed> $bracket
     * @param array<int, array<string, mixed>> $byApi
     */
    private static function isMisplacedTeam(
        int $teamId,
        int $targetApi,
        string $slot,
        string $stageKey,
        array $bracket,
        array $byApi,
    ): bool {
        if ($teamId <= 0) {
            return false;
        }

        $feeds = $bracket[$stageKey] ?? [];
        foreach ($feeds as $api => $slots) {
            foreach (['home', 'away'] as $s) {
                $sourceKey = $stageKey === 'THIRD_PLACE'
                    ? ($s === 'home' ? 'home_loser' : 'away_loser')
                    : $s;

                if (!isset($slots[$sourceKey])) {
                    continue;
                }

                $source = $byApi[(int)$slots[$sourceKey]] ?? null;
                $winnerId = $stageKey === 'THIRD_PLACE'
                    ? self::resolveLoserTeamId($source)
                    : self::resolveWinnerTeamId($source);

                if ($winnerId === $teamId) {
                    return (int)$api !== $targetApi || $s !== $slot;
                }
            }
        }

        return false;
    }

    /** @param array<string, mixed>|null $match */
    public static function isKnockoutDecided(?array $match): bool
    {
        if ($match === null) {
            return false;
        }

        $status = strtoupper((string)($match['status'] ?? 'NS'));

        return in_array($status, ['FT', 'PEN', 'AET'], true);
    }

    /** @param array<string, mixed>|null $match */
    public static function winnerTeamId(?array $match): ?int
    {
        if ($match === null || !self::isKnockoutDecided($match)) {
            return null;
        }

        if (!empty($match['winner_team_id'])) {
            return (int)$match['winner_team_id'];
        }

        $homeScore = (int)($match['home_score'] ?? 0);
        $awayScore = (int)($match['away_score'] ?? 0);
        if ($homeScore > $awayScore) {
            return (int)$match['home_team_id'];
        }
        if ($awayScore > $homeScore) {
            return (int)$match['away_team_id'];
        }

        return null;
    }

    /** @param array<string, mixed>|null $match */
    public static function loserTeamId(?array $match): ?int
    {
        $winnerId = self::winnerTeamId($match);
        if ($winnerId === null || $match === null) {
            return null;
        }

        $homeId = (int)$match['home_team_id'];
        $awayId = (int)$match['away_team_id'];

        return $winnerId === $homeId ? $awayId : $homeId;
    }

    /** @param array<string, mixed>|null $match */
    private static function resolveWinnerTeamId(?array $match): ?int
    {
        return self::winnerTeamId($match);
    }

    /** @param array<string, mixed>|null $match */
    private static function resolveLoserTeamId(?array $match): ?int
    {
        return self::loserTeamId($match);
    }

    /** @param array<string, mixed> $match */
    private static function isTbdSlot(array $match, string $slot): bool
    {
        $name = (string)($match[$slot . '_name'] ?? '');
        $apiId = (int)($match[$slot . '_api_team_id'] ?? 0);

        return $name === 'TBD' || $apiId <= 0;
    }

    private static function assignTeam(int $matchId, string $slot, int $teamId): void
    {
        $column = $slot === 'home' ? 'home_team_id' : 'away_team_id';
        $st = DB::pdo()->prepare("UPDATE matches SET {$column} = :team_id WHERE id = :id");
        $st->execute(['team_id' => $teamId, 'id' => $matchId]);
    }

    private static function resetToTbd(int $matchId, string $slot): void
    {
        $st = DB::pdo()->prepare(
            'SELECT id FROM teams WHERE name = :name AND api_team_id = 0 ORDER BY id ASC LIMIT 1'
        );
        $st->execute(['name' => 'TBD']);
        $tbdId = (int)($st->fetchColumn() ?: 0);
        if ($tbdId <= 0) {
            return;
        }

        self::assignTeam($matchId, $slot, $tbdId);
    }

    /**
     * @param array<string, mixed> $match
     * @return array{match_id:int,stage_key:string,slot:string,team_id:int,team_name:string,action:string}
     */
    private static function updateMeta(
        array $match,
        string $slot,
        int $teamId,
        string $stageKey,
        string $action,
    ): array {
        $st = DB::pdo()->prepare('SELECT name FROM teams WHERE id = :id');
        $st->execute(['id' => $teamId]);
        $name = (string)($st->fetchColumn() ?: '');

        return [
            'match_id' => (int)$match['id'],
            'stage_key' => $stageKey,
            'slot' => $slot,
            'team_id' => $teamId,
            'team_name' => $name,
            'action' => $action,
        ];
    }
}
