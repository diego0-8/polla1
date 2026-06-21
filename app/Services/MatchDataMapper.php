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
        return match ($winner) {
            'HOME_TEAM' => 'H',
            'AWAY_TEAM' => 'A',
            default => null,
        };
    }

    /**
     * @return list<array{type:?string,detail:?string,minute:int,extra_minute:?int,team_api_id:?int,player_name:?string,assist_name:?string,raw:array}>
     */
    public static function normalizeEvents(array $matchDetail): array
    {
        $events = [];

        foreach (($matchDetail['goals'] ?? []) as $g) {
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
