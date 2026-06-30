<?php
declare(strict_types=1);

namespace App\Services;

final class MatchDataMapper
{
    private const KNOCKOUT_STAGES = [
        'LAST_32', 'LAST_16', 'QUARTER_FINALS', 'SEMI_FINALS', 'THIRD_PLACE', 'FINAL', 'PLAYOFFS',
    ];

    public static function mapStatus(string $fdStatus): string
    {
        return match (strtoupper($fdStatus)) {
            'FINISHED', 'AWARDED' => 'FT',
            'IN_PLAY', 'LIVE', 'PAUSED', 'SUSPENDED' => 'LIVE',
            'HALF_TIME' => 'HT',
            'SCHEDULED', 'TIMED' => 'NS',
            'POSTPONED' => 'PST',
            'CANCELLED' => 'CANC',
            default => strtoupper($fdStatus) !== '' ? strtoupper(substr($fdStatus, 0, 10)) : 'NS',
        };
    }

    /** Estado interno según status API + score.duration (prórroga / penales). */
    public static function mapStatusFromMatch(array $match): string
    {
        $status = self::mapStatus((string)($match['status'] ?? 'NS'));
        if ($status !== 'FT') {
            return $status;
        }

        $duration = strtoupper((string)($match['score']['duration'] ?? ''));
        return match ($duration) {
            'PENALTY_SHOOTOUT' => 'PEN',
            'EXTRA_TIME' => 'AET',
            default => 'FT',
        };
    }

    public static function isPenaltyShootout(array $match): bool
    {
        return strtoupper((string)($match['score']['duration'] ?? '')) === 'PENALTY_SHOOTOUT';
    }

    public static function isExtraTimeFinish(array $match): bool
    {
        return strtoupper((string)($match['score']['duration'] ?? '')) === 'EXTRA_TIME';
    }

    /**
     * Marcador de penales (tanda). fullTime suele traer el resultado final de penales.
     *
     * @return array{home:int,away:int}|null
     */
    public static function penaltyScores(array $match): ?array
    {
        if (!self::isPenaltyShootout($match)) {
            return null;
        }

        $score = $match['score'] ?? [];
        $ft = $score['fullTime'] ?? [];
        $pen = $score['penalties'] ?? [];

        if (isset($ft['home'], $ft['away']) && (int)$ft['home'] !== (int)$ft['away']) {
            return ['home' => (int)$ft['home'], 'away' => (int)$ft['away']];
        }

        if (isset($pen['home'], $pen['away'])) {
            return ['home' => (int)$pen['home'], 'away' => (int)$pen['away']];
        }

        return null;
    }

    /** @return array{home:int,away:int} */
    public static function regularTimeFromApi(array $match): array
    {
        $score = $match['score'] ?? [];
        $reg = $score['regularTime'] ?? [];
        if (isset($reg['home'], $reg['away'])) {
            return ['home' => (int)$reg['home'], 'away' => (int)$reg['away']];
        }

        return self::reconcileScores($match);
    }

    /**
     * Marcadores para guardar en BD (90 min / prórroga vs penales por separado).
     *
     * @return array{
     *   home:int,away:int,
     *   regular_home:int,regular_away:int,
     *   penalty_home:?int,penalty_away:?int
     * }
     */
    public static function resolveStoredScores(array $match): array
    {
        if (self::isPenaltyShootout($match)) {
            $reg = self::regularTimeFromApi($match);
            $pen = self::penaltyScores($match);

            return [
                'home' => $reg['home'],
                'away' => $reg['away'],
                'regular_home' => $reg['home'],
                'regular_away' => $reg['away'],
                'penalty_home' => $pen['home'] ?? null,
                'penalty_away' => $pen['away'] ?? null,
            ];
        }

        $scores = self::reconcileScores($match);
        $regular = self::regularTimeScores($match);

        return [
            'home' => $scores['home'],
            'away' => $scores['away'],
            'regular_home' => $regular['home'] ?? $scores['home'],
            'regular_away' => $regular['away'] ?? $scores['away'],
            'penalty_home' => null,
            'penalty_away' => null,
        ];
    }

    /**
     * Marcador reglamentario para liquidar exacto, gana y props basados en goles.
     * PEN: empate 90 min + prórroga (nunca la tanda de penales).
     * AET: marcador tras prórroga.
     * FT: tiempo reglamentario (90 min).
     *
     * @param array<string, mixed> $dbMatch Fila de matches (status, home_score, regular_*, penalty_*).
     * @return array{home:int, away:int}
     */
    public static function scoresForSettlement(array $dbMatch): array
    {
        if (($dbMatch['data_source'] ?? '') === 'manual') {
            return [
                'home' => (int)($dbMatch['home_score'] ?? 0),
                'away' => (int)($dbMatch['away_score'] ?? 0),
            ];
        }

        $status = strtoupper((string)($dbMatch['status'] ?? ''));

        if ($status === 'PEN') {
            return [
                'home' => (int)($dbMatch['regular_home_score'] ?? $dbMatch['home_score'] ?? 0),
                'away' => (int)($dbMatch['regular_away_score'] ?? $dbMatch['away_score'] ?? 0),
            ];
        }

        if ($status === 'AET') {
            return [
                'home' => (int)($dbMatch['home_score'] ?? 0),
                'away' => (int)($dbMatch['away_score'] ?? 0),
            ];
        }

        return [
            'home' => (int)($dbMatch['regular_home_score'] ?? $dbMatch['home_score'] ?? 0),
            'away' => (int)($dbMatch['regular_away_score'] ?? $dbMatch['away_score'] ?? 0),
        ];
    }

    public static function parseGroupCode(array $match): ?string
    {
        $group = strtoupper(trim((string)($match['group'] ?? '')));
        if ($group === '') {
            return null;
        }
        if (preg_match('/^GROUP_([A-Z])$/', $group, $m)) {
            return $m[1];
        }
        if (strlen($group) === 1 && ctype_alpha($group)) {
            return $group;
        }
        return null;
    }

    public static function buildStage(array $match): string
    {
        $stageKey = self::stageKey($match);
        $stageEs = self::stageTypeLabel($stageKey);
        $group = isset($match['group']) ? (string)$match['group'] : '';
        if ($group !== '' && str_starts_with(strtoupper($group), 'GROUP_')) {
            $group = 'Grupo ' . substr($group, 6);
        } elseif ($group !== '') {
            $group = 'Grupo ' . $group;
        }

        $parts = array_filter([
            $stageEs !== '' ? $stageEs : null,
            $group !== '' ? $group : null,
            isset($match['matchday']) ? 'Jornada ' . (string)$match['matchday'] : null,
        ]);
        return implode(' · ', $parts);
    }

    public static function stageKey(array $match): string
    {
        $stage = strtoupper(trim((string)($match['stage'] ?? '')));
        return $stage !== '' ? substr($stage, 0, 32) : '';
    }

    public static function matchday(array $match): ?int
    {
        return isset($match['matchday']) ? (int)$match['matchday'] : null;
    }

    public static function isKnockoutStage(string $stageKey): bool
    {
        return in_array(strtoupper($stageKey), self::KNOCKOUT_STAGES, true);
    }

    public static function stageTypeLabel(string $stage): string
    {
        return match (strtoupper($stage)) {
            'GROUP_STAGE' => 'Fase de grupos',
            'LAST_16' => 'Octavos de final',
            'LAST_32' => 'Dieciseisavos de final',
            'QUARTER_FINALS' => 'Cuartos de final',
            'SEMI_FINALS' => 'Semifinal',
            'THIRD_PLACE' => 'Tercer puesto',
            'FINAL' => 'Final',
            'PLAYOFFS' => 'Repesca',
            'REGULAR_SEASON' => 'Fase regular',
            default => $stage !== '' ? ucwords(strtolower(str_replace('_', ' ', $stage))) : '',
        };
    }

    public static function venueName(array $match): ?string
    {
        $venue = $match['venue'] ?? null;

        if (is_string($venue)) {
            $venue = trim($venue);
            if ($venue !== '' && !in_array(strtoupper($venue), ['HOME', 'AWAY'], true)) {
                return $venue;
            }
        }

        if (is_array($venue)) {
            foreach (['name', 'stadium', 'venue'] as $key) {
                if (isset($venue[$key]) && is_string($venue[$key]) && trim($venue[$key]) !== '') {
                    return trim($venue[$key]);
                }
            }
        }

        return null;
    }

    public static function scores(array $match): array
    {
        $score = $match['score'] ?? [];
        $ft = $score['fullTime'] ?? [];
        $home = $ft['home'] ?? null;
        $away = $ft['away'] ?? null;
        if ($home === null && $away === null) {
            $home = $score['regularTime']['home'] ?? 0;
            $away = $score['regularTime']['away'] ?? 0;
        }

        return [
            'home' => (int)($home ?? 0),
            'away' => (int)($away ?? 0),
        ];
    }

    /**
     * Marcador coherente con goles listados y desglose HT/ET (goles anulados por VAR).
     *
     * @return array{home:int,away:int}
     */
    public static function reconcileScores(array $match): array
    {
        if (self::isPenaltyShootout($match)) {
            return self::regularTimeFromApi($match);
        }

        $scores = self::scores($match);
        $fromGoals = self::scoreFromGoalsList($match);
        $goalsListed = $match['goals'] ?? [];
        $listedCount = is_array($goalsListed) ? count($goalsListed) : 0;
        $scoreTotal = $scores['home'] + $scores['away'];

        if ($fromGoals !== null && $listedCount > 0) {
            $goalTotal = $fromGoals['home'] + $fromGoals['away'];
            if ($fromGoals['home'] !== $scores['home'] || $fromGoals['away'] !== $scores['away']) {
                return $fromGoals;
            }
            if ($goalTotal < $scoreTotal) {
                return $fromGoals;
            }
        }

        $scoreBlock = $match['score'] ?? [];
        $ft = $scoreBlock['fullTime'] ?? [];
        $et = $scoreBlock['extraTime'] ?? [];
        if (!isset($ft['home'], $ft['away'])) {
            return $scores;
        }

        $etHome = (int)($et['home'] ?? 0);
        $etAway = (int)($et['away'] ?? 0);
        $etTotal = $etHome + $etAway;
        if ($etTotal <= 0) {
            return $scores;
        }

        $ftHome = (int)$ft['home'];
        $ftAway = (int)$ft['away'];
        $goalsInExtra = self::countExtraTimeGoalsListed($goalsListed);

        // extraTime indica goles en prórroga pero la lista no los tiene (VAR / retraso API)
        if ($goalsInExtra < $etTotal && $listedCount < $ftHome + $ftAway) {
            return [
                'home' => max(0, $ftHome - $etHome),
                'away' => max(0, $ftAway - $etAway),
            ];
        }

        return $scores;
    }

    /**
     * @return array{home:int,away:int}|null
     */
    public static function scoreFromGoalsList(array $match): ?array
    {
        $goals = $match['goals'] ?? [];
        if (!is_array($goals) || $goals === []) {
            return null;
        }

        $homeApi = (int)($match['homeTeam']['id'] ?? 0);
        $awayApi = (int)($match['awayTeam']['id'] ?? 0);
        if ($homeApi <= 0 || $awayApi <= 0) {
            return null;
        }

        $home = 0;
        $away = 0;
        foreach ($goals as $g) {
            if (!is_array($g) || self::isDisallowedGoalEntry($g)) {
                continue;
            }

            $type = strtoupper((string)($g['type'] ?? 'REGULAR'));
            if (in_array($type, ['PENALTY_SHOOTOUT'], true)) {
                continue;
            }
            $teamId = (int)($g['team']['id'] ?? 0);
            $isOwn = $type === 'OWN_GOAL';

            if ($isOwn) {
                if ($teamId === $homeApi) {
                    $away++;
                } elseif ($teamId === $awayApi) {
                    $home++;
                }
                continue;
            }

            if ($teamId === $homeApi) {
                $home++;
            } elseif ($teamId === $awayApi) {
                $away++;
            }
        }

        return ['home' => $home, 'away' => $away];
    }

    /** @param array<string, mixed> $goal */
    public static function isDisallowedGoalEntry(array $goal): bool
    {
        $type = strtoupper((string)($goal['type'] ?? ''));
        $disallowedTypes = ['CANCELLED', 'DISALLOWED', 'VAR', 'NULLIFIED', 'NO_GOAL'];
        if (in_array($type, $disallowedTypes, true)) {
            return true;
        }

        foreach (['description', 'detail', 'reason'] as $key) {
            $text = strtoupper((string)($goal[$key] ?? ''));
            if ($text !== '' && (str_contains($text, 'DISALLOW') || str_contains($text, 'VAR') || str_contains($text, 'ANUL'))) {
                return true;
            }
        }

        return false;
    }

    /** @param list<mixed> $goals */
    private static function countExtraTimeGoalsListed(array $goals): int
    {
        $count = 0;
        foreach ($goals as $g) {
            if (!is_array($g) || self::isDisallowedGoalEntry($g)) {
                continue;
            }
            $minute = (int)($g['minute'] ?? 0);
            $extra = (int)($g['injuryTime'] ?? 0);
            if ($minute > 90 || ($minute === 90 && $extra > 0) || $minute >= 91) {
                $count++;
            }
        }

        return $count;
    }

    public static function regularTimeScores(array $match): array
    {
        $score = $match['score'] ?? [];
        $regular = $score['regularTime'] ?? [];
        $home = $regular['home'] ?? null;
        $away = $regular['away'] ?? null;
        if ($home === null && $away === null) {
            $full = $score['fullTime'] ?? [];
            $home = $full['home'] ?? null;
            $away = $full['away'] ?? null;
        }

        return [
            'home' => $home !== null ? (int)$home : null,
            'away' => $away !== null ? (int)$away : null,
        ];
    }

    public static function winnerSide(array $match): ?string
    {
        $winner = strtoupper((string)($match['score']['winner'] ?? ''));
        $fromWinner = match ($winner) {
            'HOME_TEAM' => 'H',
            'AWAY_TEAM' => 'A',
            default => null,
        };
        if ($fromWinner !== null) {
            return $fromWinner;
        }

        $pens = self::penaltyScores($match);
        if ($pens !== null && $pens['home'] !== $pens['away']) {
            return $pens['home'] > $pens['away'] ? 'H' : 'A';
        }

        return null;
    }

    /**
     * @return list<array{type:?string,detail:?string,minute:int,extra_minute:?int,team_api_id:?int,player_name:?string,assist_name:?string,raw:array}>
     */
    public static function normalizeEvents(array $matchDetail): array
    {
        $events = [];

        foreach (($matchDetail['goals'] ?? []) as $g) {
            if (!is_array($g) || self::isDisallowedGoalEntry($g)) {
                continue;
            }
            $events[] = [
                'type' => 'Goal',
                'detail' => (string)($g['type'] ?? 'REGULAR'),
                'minute' => (int)($g['minute'] ?? 0),
                'extra_minute' => isset($g['injuryTime']) ? (int)$g['injuryTime'] : null,
                'team_api_id' => isset($g['team']['id']) ? (int)$g['team']['id'] : null,
                'player_name' => isset($g['scorer']['name']) ? (string)$g['scorer']['name'] : null,
                'assist_name' => isset($g['assist']['name']) ? (string)$g['assist']['name'] : null,
                'raw' => $g,
            ];
        }

        foreach (($matchDetail['bookings'] ?? []) as $b) {
            $events[] = [
                'type' => 'Card',
                'detail' => (string)($b['card'] ?? 'YELLOW'),
                'minute' => (int)($b['minute'] ?? 0),
                'extra_minute' => null,
                'team_api_id' => isset($b['team']['id']) ? (int)$b['team']['id'] : null,
                'player_name' => isset($b['player']['name']) ? (string)$b['player']['name'] : null,
                'assist_name' => null,
                'raw' => $b,
            ];
        }

        foreach (($matchDetail['substitutions'] ?? []) as $s) {
            $out = isset($s['playerOut']['name']) ? (string)$s['playerOut']['name'] : '?';
            $in = isset($s['playerIn']['name']) ? (string)$s['playerIn']['name'] : '?';
            $events[] = [
                'type' => 'subst',
                'detail' => 'Substitution',
                'minute' => (int)($s['minute'] ?? 0),
                'extra_minute' => null,
                'team_api_id' => isset($s['team']['id']) ? (int)$s['team']['id'] : null,
                'player_name' => $out,
                'assist_name' => $in,
                'raw' => $s,
            ];
        }

        return $events;
    }

    public static function liveSnapshotKey(int $homeScore, int $awayScore, string $status): string
    {
        return $homeScore . ':' . $awayScore . ':' . $status;
    }

    public static function appTimezone(): \DateTimeZone
    {
        $cfg = require dirname(__DIR__) . '/Config/app.php';
        return new \DateTimeZone($cfg['timezone'] ?? 'America/Bogota');
    }

    /** Convierte utcDate de Football-Data a hora local para guardar en MySQL. */
    public static function kickoffToLocalStorage(string $utcDate): string
    {
        $utcDate = trim($utcDate);
        if ($utcDate === '') {
            return date('Y-m-d H:i:s');
        }

        $utc = new \DateTimeImmutable($utcDate, new \DateTimeZone('UTC'));
        return $utc->setTimezone(self::appTimezone())->format('Y-m-d H:i:s');
    }

    /** Interpreta kickoff_at almacenado en BD como hora local de la polla. */
    public static function kickoffFromStorage(string $kickoffAt): \DateTimeImmutable
    {
        return new \DateTimeImmutable($kickoffAt, self::appTimezone());
    }

    /**
     * Extrae estadísticas agregadas del detalle de partido Football-Data v4.
     *
     * @return array{
     *   home_corners:?int, away_corners:?int, total_corners:?int,
     *   total_cards:?int, total_yellow_cards:?int, total_red_cards:?int,
     *   total_goals:?int, btts:?bool
     * }|null
     */
    public static function extractMatchStats(array $detail): ?array
    {
        $homeStats = $detail['homeTeam']['statistics'] ?? null;
        $awayStats = $detail['awayTeam']['statistics'] ?? null;

        if (!is_array($homeStats) && !is_array($awayStats)) {
            return null;
        }

        $homeCorners = isset($homeStats['corner_kicks']) ? (int)$homeStats['corner_kicks'] : null;
        $awayCorners = isset($awayStats['corner_kicks']) ? (int)$awayStats['corner_kicks'] : null;
        $totalCorners = ($homeCorners !== null && $awayCorners !== null)
            ? $homeCorners + $awayCorners
            : null;

        $homeYellow = self::teamYellowCount($homeStats);
        $awayYellow = self::teamYellowCount($awayStats);
        $homeRed = self::teamRedCount($homeStats);
        $awayRed = self::teamRedCount($awayStats);
        $totalYellow = $homeYellow + $awayYellow;
        $totalRed = $homeRed + $awayRed;
        $totalCards = $totalYellow + $totalRed;
        if ($totalCards === 0 && $homeStats === null && $awayStats === null) {
            $totalCards = null;
            $totalYellow = null;
            $totalRed = null;
        }

        $regular = self::regularTimeScores($detail);
        $homeGoals = $regular['home'];
        $awayGoals = $regular['away'];
        if ($homeGoals === null || $awayGoals === null) {
            $scores = self::scores($detail);
            $homeGoals = $scores['home'];
            $awayGoals = $scores['away'];
        }

        $totalGoals = (int)$homeGoals + (int)$awayGoals;
        $btts = ((int)$homeGoals > 0 && (int)$awayGoals > 0);

        return [
            'home_corners' => $homeCorners,
            'away_corners' => $awayCorners,
            'total_corners' => $totalCorners,
            'total_cards' => $totalCards,
            'total_yellow_cards' => $totalYellow,
            'total_red_cards' => $totalRed,
            'total_goals' => $totalGoals,
            'btts' => $btts,
        ];
    }

    /** @param array<string, mixed>|null $stats */
    private static function teamYellowCount(?array $stats): int
    {
        if ($stats === null) {
            return 0;
        }

        return (int)($stats['yellow_cards'] ?? 0);
    }

    /** @param array<string, mixed>|null $stats */
    private static function teamRedCount(?array $stats): int
    {
        if ($stats === null) {
            return 0;
        }

        $red = (int)($stats['red_cards'] ?? 0);
        $yellowRed = (int)($stats['yellow_red_cards'] ?? 0);

        return $red + ($yellowRed * 2);
    }

    /** Convierte un valor UTC ya guardado por error a hora local. */
    public static function kickoffUtcNaiveToLocal(string $utcNaive): string
    {
        $utc = new \DateTimeImmutable($utcNaive, new \DateTimeZone('UTC'));
        return $utc->setTimezone(self::appTimezone())->format('Y-m-d H:i:s');
    }
}
