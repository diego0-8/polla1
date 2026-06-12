<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\DB;
use App\Services\MatchDataMapper;
use App\Services\WorldCupVenueResolver;
use DateInterval;
use DateTimeImmutable;

final class MatchModel
{
    private const KNOCKOUT_STAGES = [
        'LAST_32', 'LAST_16', 'QUARTER_FINALS', 'SEMI_FINALS', 'THIRD_PLACE', 'FINAL', 'PLAYOFFS',
    ];

    public static function findById(int $id): ?array
    {
        $st = DB::pdo()->prepare(
            'SELECT m.*,
                    th.name AS home_name, th.code AS home_code, th.logo_url AS home_logo,
                    th.api_team_id AS home_api_team_id,
                    ta.name AS away_name, ta.code AS away_code, ta.logo_url AS away_logo,
                    ta.api_team_id AS away_api_team_id
             FROM matches m
             JOIN teams th ON th.id = m.home_team_id
             JOIN teams ta ON ta.id = m.away_team_id
             WHERE m.id = :id'
        );
        $st->execute(['id' => $id]);
        $row = $st->fetch();
        return $row ?: null;
    }

    public static function today(): array
    {
        $st = DB::pdo()->prepare(
            'SELECT m.*,
                    th.name AS home_name, th.code AS home_code, th.logo_url AS home_logo,
                    ta.name AS away_name, ta.code AS away_code, ta.logo_url AS away_logo
             FROM matches m
             JOIN teams th ON th.id = m.home_team_id
             JOIN teams ta ON ta.id = m.away_team_id
             WHERE DATE(m.kickoff_at) = CURDATE()
               AND YEAR(m.kickoff_at) = :year
             ORDER BY m.kickoff_at ASC
             LIMIT 50'
        );
        $st->execute(['year' => self::seasonYear()]);
        return $st->fetchAll() ?: [];
    }

    public static function upcoming(int $limit = 10): array
    {
        return self::upcomingForSeason($limit);
    }

    public static function upcomingAndLive(int $limit): array
    {
        $st = DB::pdo()->prepare(
            'SELECT m.*,
                    th.name AS home_name, th.code AS home_code, th.logo_url AS home_logo,
                    ta.name AS away_name, ta.code AS away_code, ta.logo_url AS away_logo
             FROM matches m
             JOIN teams th ON th.id = m.home_team_id
             JOIN teams ta ON ta.id = m.away_team_id
             WHERE m.kickoff_at >= (NOW() - INTERVAL 6 HOUR)
             ORDER BY m.kickoff_at ASC
             LIMIT :lim'
        );
        $st->bindValue('lim', $limit, \PDO::PARAM_INT);
        $st->execute();
        return $st->fetchAll() ?: [];
    }

    public static function seasonYear(): int
    {
        $cfg = require __DIR__ . '/../Config/app.php';
        return (int)($cfg['football_data']['season'] ?? 2026);
    }

    public static function forSeason(?int $year = null, int $limit = 500): array
    {
        $year = $year ?? self::seasonYear();
        $st = DB::pdo()->prepare(
            'SELECT m.*,
                    th.name AS home_name, th.code AS home_code, th.logo_url AS home_logo,
                    ta.name AS away_name, ta.code AS away_code, ta.logo_url AS away_logo
             FROM matches m
             JOIN teams th ON th.id = m.home_team_id
             JOIN teams ta ON ta.id = m.away_team_id
             WHERE YEAR(m.kickoff_at) = :year
             ORDER BY m.kickoff_at ASC
             LIMIT :lim'
        );
        $st->bindValue('year', $year, \PDO::PARAM_INT);
        $st->bindValue('lim', $limit, \PDO::PARAM_INT);
        $st->execute();
        return $st->fetchAll() ?: [];
    }

    public static function allByKickoff(int $limit = 500): array
    {
        return self::forSeason(self::seasonYear(), $limit);
    }

    public static function upcomingForSeason(int $limit = 10, ?int $year = null): array
    {
        $year = $year ?? self::seasonYear();
        $st = DB::pdo()->prepare(
            'SELECT m.*,
                    th.name AS home_name, th.code AS home_code, th.logo_url AS home_logo,
                    ta.name AS away_name, ta.code AS away_code, ta.logo_url AS away_logo
             FROM matches m
             JOIN teams th ON th.id = m.home_team_id
             JOIN teams ta ON ta.id = m.away_team_id
             WHERE YEAR(m.kickoff_at) = :year AND m.kickoff_at >= NOW()
             ORDER BY m.kickoff_at ASC
             LIMIT :lim'
        );
        $st->bindValue('year', $year, \PDO::PARAM_INT);
        $st->bindValue('lim', $limit, \PDO::PARAM_INT);
        $st->execute();
        $rows = $st->fetchAll() ?: [];
        if ($rows !== []) {
            return $rows;
        }
        return self::forSeason($year, $limit);
    }

    public static function findByApiFixtureId(int $apiFixtureId): ?array
    {
        $st = DB::pdo()->prepare('SELECT * FROM matches WHERE api_fixture_id = :id');
        $st->execute(['id' => $apiFixtureId]);
        $row = $st->fetch();
        return $row ?: null;
    }

    public static function upsertFromFootballData(array $match): int
    {
        $pdo = DB::pdo();
        $apiFixtureId = (int)($match['id'] ?? 0);
        $kickoff = MatchDataMapper::kickoffToLocalStorage((string)($match['utcDate'] ?? ''));
        $status = MatchDataMapper::mapStatus((string)($match['status'] ?? 'NS'));
        $stage = MatchDataMapper::buildStage($match);
        $stageKey = MatchDataMapper::stageKey($match);
        $matchday = MatchDataMapper::matchday($match);
        $scores = MatchDataMapper::scores($match);
        $regularScores = MatchDataMapper::regularTimeScores($match);

        $homeTeam = $match['homeTeam'] ?? [];
        $awayTeam = $match['awayTeam'] ?? [];

        $homeTeamId = Team::upsertFromApi(
            (int)($homeTeam['id'] ?? 0),
            (string)($homeTeam['name'] ?? 'TBD'),
            isset($homeTeam['tla']) ? (string)$homeTeam['tla'] : null,
            isset($homeTeam['crest']) ? (string)$homeTeam['crest'] : null,
            $apiFixtureId * 10 + 1,
        );
        $awayTeamId = Team::upsertFromApi(
            (int)($awayTeam['id'] ?? 0),
            (string)($awayTeam['name'] ?? 'TBD'),
            isset($awayTeam['tla']) ? (string)$awayTeam['tla'] : null,
            isset($awayTeam['crest']) ? (string)$awayTeam['crest'] : null,
            $apiFixtureId * 10 + 2,
        );

        $venue = WorldCupVenueResolver::resolveFromApi($match);

        $groupCode = MatchDataMapper::parseGroupCode($match);
        $winnerSide = MatchDataMapper::winnerSide($match);
        $winnerTeamId = match ($winnerSide) {
            'H' => $homeTeamId,
            'A' => $awayTeamId,
            default => null,
        };

        $existing = self::findByApiFixtureId($apiFixtureId);
        if ($existing !== null) {
            [$status, $scores] = self::preserveScoreIfApiIncomplete(
                $existing,
                $status,
                $scores,
            );
            if ($winnerTeamId === null && in_array($status, ['FT', 'PEN', 'AET'], true)) {
                if ($scores['home'] > $scores['away']) {
                    $winnerTeamId = $homeTeamId;
                } elseif ($scores['away'] > $scores['home']) {
                    $winnerTeamId = $awayTeamId;
                }
            }
        }

        $st = $pdo->prepare(
            'INSERT INTO matches (
                api_fixture_id, home_team_id, away_team_id, kickoff_at, status,
                home_score, away_score, stage, stage_key, matchday, group_code,
                regular_home_score, regular_away_score, winner_team_id, venue, last_synced_at
             )
             VALUES (
                :api_fixture_id, :home_team_id, :away_team_id, :kickoff_at, :status,
                :home_score, :away_score, :stage, :stage_key, :matchday, :group_code,
                :regular_home_score, :regular_away_score, :winner_team_id, :venue, NOW()
             )
             ON DUPLICATE KEY UPDATE
                home_team_id = VALUES(home_team_id),
                away_team_id = VALUES(away_team_id),
                kickoff_at = VALUES(kickoff_at),
                status = VALUES(status),
                home_score = VALUES(home_score),
                away_score = VALUES(away_score),
                stage = VALUES(stage),
                stage_key = VALUES(stage_key),
                matchday = VALUES(matchday),
                group_code = VALUES(group_code),
                regular_home_score = VALUES(regular_home_score),
                regular_away_score = VALUES(regular_away_score),
                winner_team_id = VALUES(winner_team_id),
                venue = COALESCE(VALUES(venue), venue),
                last_synced_at = NOW()'
        );
        $st->execute([
            'api_fixture_id' => $apiFixtureId,
            'home_team_id' => $homeTeamId,
            'away_team_id' => $awayTeamId,
            'kickoff_at' => $kickoff,
            'status' => $status,
            'home_score' => $scores['home'],
            'away_score' => $scores['away'],
            'stage' => $stage,
            'stage_key' => $stageKey !== '' ? $stageKey : null,
            'matchday' => $matchday,
            'group_code' => $groupCode,
            'regular_home_score' => $regularScores['home'],
            'regular_away_score' => $regularScores['away'],
            'winner_team_id' => $winnerTeamId,
            'venue' => $venue,
        ]);

        $st2 = $pdo->prepare('SELECT id FROM matches WHERE api_fixture_id = :api_fixture_id');
        $st2->execute(['api_fixture_id' => $apiFixtureId]);
        return (int)$st2->fetchColumn();
    }

    /**
     * Evita que respuestas incompletas de la API (TIMED/NS 0:0) pisen un marcador ya conocido.
     *
     * @param array<string, mixed> $existing
     * @param array{home:int,away:int} $scores
     * @return array{0:string,1:array{home:int,away:int}}
     */
    private static function preserveScoreIfApiIncomplete(array $existing, string $status, array $scores): array
    {
        $existingStatus = strtoupper((string)($existing['status'] ?? 'NS'));
        $existingTotal = (int)($existing['home_score'] ?? 0) + (int)($existing['away_score'] ?? 0);
        $incomingTotal = (int)$scores['home'] + (int)$scores['away'];
        $finished = ['FT', 'PEN', 'AET'];

        if (in_array($existingStatus, $finished, true) && $status === 'NS') {
            $status = $existingStatus;
        }

        $incomingFinished = in_array($status, $finished, true);
        if (!$incomingFinished && $existingTotal > 0 && $incomingTotal === 0) {
            $scores = [
                'home' => (int)$existing['home_score'],
                'away' => (int)$existing['away_score'],
            ];
        }

        return [$status, $scores];
    }

    public static function upsertFromApi(array $fixture): int
    {
        $pdo = DB::pdo();
        $apiFixtureId = (int)($fixture['fixture']['id'] ?? 0);
        $kickoff = MatchDataMapper::kickoffToLocalStorage((string)($fixture['fixture']['date'] ?? ''));
        $status = (string)($fixture['fixture']['status']['short'] ?? 'NS');
        $stage = (string)($fixture['league']['round'] ?? '');

        $homeTeam = $fixture['teams']['home'] ?? [];
        $awayTeam = $fixture['teams']['away'] ?? [];

        $homeTeamId = Team::upsertFromApi(
            (int)($homeTeam['id'] ?? 0),
            (string)($homeTeam['name'] ?? 'TBD'),
            isset($homeTeam['code']) ? (string)$homeTeam['code'] : null,
            isset($homeTeam['logo']) ? (string)$homeTeam['logo'] : null,
            $apiFixtureId * 10 + 1,
        );
        $awayTeamId = Team::upsertFromApi(
            (int)($awayTeam['id'] ?? 0),
            (string)($awayTeam['name'] ?? 'TBD'),
            isset($awayTeam['code']) ? (string)$awayTeam['code'] : null,
            isset($awayTeam['logo']) ? (string)$awayTeam['logo'] : null,
            $apiFixtureId * 10 + 2,
        );

        $goals = $fixture['goals'] ?? [];
        $homeScore = isset($goals['home']) ? (int)$goals['home'] : 0;
        $awayScore = isset($goals['away']) ? (int)$goals['away'] : 0;

        $regularHome = $homeScore;
        $regularAway = $awayScore;

        $st = $pdo->prepare(
            'INSERT INTO matches (
                api_fixture_id, home_team_id, away_team_id, kickoff_at, status,
                home_score, away_score, stage, regular_home_score, regular_away_score, last_synced_at
             )
             VALUES (
                :api_fixture_id, :home_team_id, :away_team_id, :kickoff_at, :status,
                :home_score, :away_score, :stage, :regular_home_score, :regular_away_score, NOW()
             )
             ON DUPLICATE KEY UPDATE
                home_team_id = VALUES(home_team_id),
                away_team_id = VALUES(away_team_id),
                kickoff_at = VALUES(kickoff_at),
                status = VALUES(status),
                home_score = VALUES(home_score),
                away_score = VALUES(away_score),
                stage = VALUES(stage),
                regular_home_score = VALUES(regular_home_score),
                regular_away_score = VALUES(regular_away_score),
                last_synced_at = NOW()'
        );
        $st->execute([
            'api_fixture_id' => $apiFixtureId,
            'home_team_id' => $homeTeamId,
            'away_team_id' => $awayTeamId,
            'kickoff_at' => $kickoff,
            'status' => $status,
            'home_score' => $homeScore,
            'away_score' => $awayScore,
            'stage' => $stage,
            'regular_home_score' => $regularHome,
            'regular_away_score' => $regularAway,
        ]);

        $st2 = $pdo->prepare('SELECT id FROM matches WHERE api_fixture_id = :api_fixture_id');
        $st2->execute(['api_fixture_id' => $apiFixtureId]);
        return (int)$st2->fetchColumn();
    }

    public static function lockedAt(array $match): string
    {
        $cfg = require __DIR__ . '/../Config/app.php';
        $lockMinutes = (int)($cfg['lock_minutes'] ?? 5);
        $kickoff = MatchDataMapper::kickoffFromStorage((string)$match['kickoff_at']);
        $locked = $kickoff->sub(new DateInterval('PT' . $lockMinutes . 'M'));
        return $locked->format('Y-m-d H:i:s');
    }

    public static function isPredictionOpen(array $match): bool
    {
        $status = strtoupper((string)($match['status'] ?? 'NS'));
        if ($status !== 'NS') {
            return false;
        }

        $lockedAt = new DateTimeImmutable(self::lockedAt($match));
        $now = new DateTimeImmutable('now');
        return $now < $lockedAt;
    }

    public static function isGroupStage(array $match): bool
    {
        $stageKey = strtoupper((string)($match['stage_key'] ?? ''));
        if ($stageKey === 'GROUP_STAGE') {
            return true;
        }

        return trim((string)($match['group_code'] ?? '')) !== '';
    }

    /** Props disponibles en fase de grupos y todas las eliminatorias del torneo. */
    public static function allowsPropPredictions(array $match): bool
    {
        return self::isGroupStage($match) || self::isKnockout($match);
    }

    public static function isKnockout(array $match): bool
    {
        $stageKey = strtoupper((string)($match['stage_key'] ?? ''));
        if ($stageKey !== '') {
            return in_array($stageKey, self::KNOCKOUT_STAGES, true);
        }

        $stage = strtolower((string)($match['stage'] ?? ''));
        foreach (['dieciseisavos', 'octavos', 'cuartos', 'semifinal', 'tercer', 'repesca', 'final'] as $needle) {
            if (str_contains($stage, $needle)) {
                return true;
            }
        }
        return false;
    }

    public static function canPredictAdvance(array $match): bool
    {
        if (!self::isKnockout($match)) {
            return false;
        }
        if ((int)$match['home_team_id'] === (int)$match['away_team_id']) {
            return false;
        }
        return (int)($match['home_api_team_id'] ?? 0) > 0
            && (int)($match['away_api_team_id'] ?? 0) > 0;
    }

    public static function isFinal(array $match): bool
    {
        $stageKey = strtoupper((string)($match['stage_key'] ?? ''));
        if ($stageKey === 'FINAL') {
            return true;
        }
        $stage = strtolower((string)($match['stage'] ?? ''));
        return str_contains($stage, 'final') && !str_contains($stage, 'semifinal');
    }

    public static function firstKickoffForSeason(?int $year = null): ?string
    {
        $year = $year ?? self::seasonYear();
        $st = DB::pdo()->prepare(
            'SELECT kickoff_at FROM matches
             WHERE YEAR(kickoff_at) = :year
             ORDER BY kickoff_at ASC
             LIMIT 1'
        );
        $st->execute(['year' => $year]);
        $value = $st->fetchColumn();
        return $value !== false ? (string)$value : null;
    }

    public static function persistVenueIfEmpty(int $matchId, string $venue): void
    {
        $venue = trim($venue);
        if ($venue === '' || $venue === 'Por confirmar') {
            return;
        }

        $st = DB::pdo()->prepare(
            'UPDATE matches SET venue = :venue
             WHERE id = :id AND (venue IS NULL OR TRIM(venue) = \'\')'
        );
        $st->execute(['venue' => $venue, 'id' => $matchId]);
    }

    public static function backfillVenues(): int
    {
        $pdo = DB::pdo();
        $st = $pdo->query(
            'SELECT id, api_fixture_id, venue FROM matches WHERE venue IS NULL OR TRIM(venue) = \'\''
        );
        $update = $pdo->prepare('UPDATE matches SET venue = :venue WHERE id = :id');
        $count = 0;

        foreach ($st->fetchAll() ?: [] as $row) {
            $resolved = WorldCupVenueResolver::resolveForDbRow($row);
            if ($resolved === 'Por confirmar') {
                continue;
            }
            $update->execute(['venue' => $resolved, 'id' => (int)$row['id']]);
            $count++;
        }

        return $count;
    }
}

