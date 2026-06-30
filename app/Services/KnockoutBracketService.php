<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\DB;
use App\Helpers\TeamName;
use App\Models\MatchModel;

final class KnockoutBracketService
{
    private const SIDE_ROUNDS = ['LAST_32', 'LAST_16', 'QUARTER_FINALS', 'SEMI_FINALS'];

    /** @var array<string, array{left:list<int>, right:list<int>}>|null */
    private static ?array $displayOrder = null;

    /** @var array<int, array<string, array{api:int, type:string}>>|null */
    private static ?array $slotFeeds = null;

    /**
     * @return array{
     *   season:int,
     *   left:array<string, list<array<string,mixed>|null>>,
     *   right:array<string, list<array<string,mixed>|null>>,
     *   final:?array<string,mixed>,
     *   third_place:?array<string,mixed>
     * }
     */
    public static function forSeason(?int $season = null): array
    {
        $season ??= MatchModel::seasonYear();
        [$from, $to] = MatchModel::seasonKickoffBounds($season);

        $stageKeys = array_merge(self::SIDE_ROUNDS, ['FINAL', 'THIRD_PLACE']);
        $ph = implode(',', array_fill(0, count($stageKeys), '?'));

        $st = DB::pdo()->prepare(
            "SELECT m.*,
                    th.id AS home_team_id, th.name AS home_name, th.code AS home_code,
                    th.logo_url AS home_logo, th.api_team_id AS home_api_team_id,
                    ta.id AS away_team_id, ta.name AS away_name, ta.code AS away_code,
                    ta.logo_url AS away_logo, ta.api_team_id AS away_api_team_id
             FROM matches m
             JOIN teams th ON th.id = m.home_team_id
             JOIN teams ta ON ta.id = m.away_team_id
             WHERE m.kickoff_at >= ? AND m.kickoff_at < ?
               AND m.stage_key IN ($ph)
             ORDER BY m.kickoff_at ASC, m.api_fixture_id ASC"
        );
        $st->execute(array_merge([$from, $to], $stageKeys));

        $rawByApi = [];
        foreach ($st->fetchAll() ?: [] as $row) {
            $row = TeamName::applyToMatch($row);
            $apiId = (int)($row['api_fixture_id'] ?? 0);
            if ($apiId > 0) {
                $rawByApi[$apiId] = $row;
            }
        }

        $byApi = [];
        foreach ($rawByApi as $apiId => $row) {
            $formatted = self::formatMatch($row);
            $byApi[$apiId] = self::applyFeederSlots($formatted, (int)$apiId, $rawByApi);
        }

        $left = [];
        $right = [];
        foreach (self::SIDE_ROUNDS as $round) {
            $order = self::displayOrder()[$round] ?? ['left' => [], 'right' => []];
            $left[$round] = self::pickOrdered($byApi, $order['left']);
            $right[$round] = self::pickOrdered($byApi, $order['right']);
        }

        return [
            'season' => $season,
            'left' => $left,
            'right' => $right,
            'final' => $byApi[537390] ?? null,
            'third_place' => $byApi[537389] ?? null,
        ];
    }

    /** @return array<string, array{left:list<int>, right:list<int>}> */
    private static function displayOrder(): array
    {
        if (self::$displayOrder === null) {
            $bracket = require dirname(__DIR__) . '/Config/wc2026_knockout_bracket.php';
            self::$displayOrder = $bracket['display_order'] ?? [];
        }

        return self::$displayOrder;
    }

    /**
     * @return array<int, array<string, array{api:int, type:string}>>
     */
    private static function slotFeeds(): array
    {
        if (self::$slotFeeds !== null) {
            return self::$slotFeeds;
        }

        $bracket = require dirname(__DIR__) . '/Config/wc2026_knockout_bracket.php';
        $feeds = [];

        foreach (['LAST_16', 'QUARTER_FINALS', 'SEMI_FINALS', 'FINAL'] as $stage) {
            foreach ($bracket[$stage] ?? [] as $targetApi => $slots) {
                foreach (['home', 'away'] as $slot) {
                    if (!isset($slots[$slot])) {
                        continue;
                    }
                    $feeds[(int)$targetApi][$slot] = [
                        'api' => (int)$slots[$slot],
                        'type' => 'winner',
                    ];
                }
            }
        }

        foreach ($bracket['THIRD_PLACE'] ?? [] as $targetApi => $slots) {
            if (isset($slots['home_loser'])) {
                $feeds[(int)$targetApi]['home'] = [
                    'api' => (int)$slots['home_loser'],
                    'type' => 'loser',
                ];
            }
            if (isset($slots['away_loser'])) {
                $feeds[(int)$targetApi]['away'] = [
                    'api' => (int)$slots['away_loser'],
                    'type' => 'loser',
                ];
            }
        }

        self::$slotFeeds = $feeds;

        return self::$slotFeeds;
    }

    /**
     * @param array<int, array<string, mixed>> $rawByApi
     * @param array<string, mixed> $formatted
     * @return array<string, mixed>
     */
    private static function applyFeederSlots(array $formatted, int $apiId, array $rawByApi): array
    {
        $feeds = self::slotFeeds()[$apiId] ?? null;
        if ($feeds === null) {
            return $formatted;
        }

        foreach (['home', 'away'] as $slot) {
            $feed = $feeds[$slot] ?? null;
            if ($feed === null) {
                continue;
            }

            $source = $rawByApi[$feed['api']] ?? null;
            if ($source === null || !KnockoutAdvanceService::isKnockoutDecided($source)) {
                $formatted[$slot] = self::tbdTeamSlot();
                continue;
            }

            $teamId = $feed['type'] === 'loser'
                ? KnockoutAdvanceService::loserTeamId($source)
                : KnockoutAdvanceService::winnerTeamId($source);

            if ($teamId === null || $teamId <= 0) {
                $formatted[$slot] = self::tbdTeamSlot();
                continue;
            }

            $formatted[$slot] = self::teamSlotForTeamId($source, $teamId);
        }

        return $formatted;
    }

    /**
     * @param array<int, array<string, mixed>> $byApi
     * @param list<int> $apiIds
     * @return list<array<string, mixed>|null>
     */
    private static function pickOrdered(array $byApi, array $apiIds): array
    {
        $out = [];
        foreach ($apiIds as $apiId) {
            $out[] = $byApi[(int)$apiId] ?? null;
        }

        return $out;
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private static function formatMatch(array $row): array
    {
        $status = strtoupper((string)($row['status'] ?? 'NS'));
        $finished = KnockoutAdvanceService::isKnockoutDecided($row);
        $winnerId = KnockoutAdvanceService::winnerTeamId($row) ?? 0;
        $homeId = (int)$row['home_team_id'];
        $awayId = (int)$row['away_team_id'];

        return [
            'match_id' => (int)$row['id'],
            'api_fixture_id' => (int)($row['api_fixture_id'] ?? 0),
            'status' => $status,
            'is_finished' => $finished,
            'home_score' => (int)($row['home_score'] ?? 0),
            'away_score' => (int)($row['away_score'] ?? 0),
            'home' => self::teamSlot($row, 'home', $winnerId, $homeId, $finished),
            'away' => self::teamSlot($row, 'away', $winnerId, $awayId, $finished),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $formatted
     * @return array{name:string,code:string,logo:?string,is_winner:bool}
     */
    private static function teamSlotForTeamId(array $row, int $teamId): array
    {
        $homeId = (int)($row['home_team_id'] ?? 0);
        $awayId = (int)($row['away_team_id'] ?? 0);
        $side = $teamId === $homeId ? 'home' : ($teamId === $awayId ? 'away' : null);
        if ($side === null) {
            return self::tbdTeamSlot();
        }
        $name = (string)($row[$side . '_name'] ?? 'TBD');
        $code = (string)($row[$side . '_code'] ?? '');
        $logo = isset($row[$side . '_logo']) && $row[$side . '_logo'] !== ''
            ? (string)$row[$side . '_logo']
            : null;

        if ($name === 'TBD' || $name === '') {
            return self::tbdTeamSlot();
        }

        return [
            'name' => $name,
            'code' => $code,
            'logo' => $logo,
            'is_winner' => false,
        ];
    }

    /**
     * @return array{name:string,code:string,logo:?string,is_winner:bool}
     */
    private static function tbdTeamSlot(): array
    {
        return [
            'name' => 'TBD',
            'code' => '',
            'logo' => null,
            'is_winner' => false,
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array{name:string,code:string,logo:?string,is_winner:bool}
     */
    private static function teamSlot(
        array $row,
        string $side,
        int $winnerId,
        int $teamId,
        bool $finished,
    ): array {
        $name = (string)($row[$side . '_name'] ?? 'TBD');
        $code = (string)($row[$side . '_code'] ?? '');
        $logo = isset($row[$side . '_logo']) && $row[$side . '_logo'] !== ''
            ? (string)$row[$side . '_logo']
            : null;

        return [
            'name' => $name,
            'code' => $code,
            'logo' => $logo,
            'is_winner' => $finished && $winnerId > 0 && $winnerId === $teamId,
        ];
    }
}
