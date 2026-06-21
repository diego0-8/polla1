<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\DB;
use App\Models\MatchEvent;
use App\Models\MatchModel;
use App\Models\MatchStats;

final class FootballDataSyncService
{
    private const LIVE_STATUSES = ['IN_PLAY', 'PAUSED', 'HALF_TIME', 'LIVE', 'SUSPENDED'];

    public function __construct(
        private readonly FootballDataClient $api,
        private readonly string $competitionCode,
        private readonly int $season,
        private readonly int $seasonFallback,
        private readonly int $liveMaxDetailRequests,
        private readonly int $backfillBatchPerMinute,
        private readonly int $softLimitPerMinute = 9,
    ) {}

    public function discoverCompetition(): array
    {
        return $this->api->get('/competitions/' . $this->competitionCode);
    }

    public function listMatches(?int $season = null, ?string $dateFrom = null, ?string $dateTo = null, ?string $status = null): array
    {
        $query = array_filter([
            'season' => $season ?? $this->season,
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
            'status' => $status,
        ], fn ($v) => $v !== null && $v !== '');

        $path = '/competitions/' . $this->competitionCode . '/matches';
        $resp = $this->api->get($path, $query);
        return $resp['matches'] ?? [];
    }

    public function getMatchDetail(int $apiMatchId): array
    {
        return $this->api->get('/matches/' . $apiMatchId);
    }

    public function syncSchedule(string $from, string $to, ?int $season = null, ?string $stageKey = null): int
    {
        $season = $season ?? $this->resolveSeasonForDateRange($from);
        $matches = $this->fetchScheduleMatches($season, $from, $to);

        if ($matches === [] && $season !== $this->seasonFallback) {
            $matches = $this->fetchScheduleMatches($this->seasonFallback, $from, $to);
        }

        if ($stageKey !== null && $stageKey !== '') {
            $stageKey = strtoupper($stageKey);
            $matches = array_values(array_filter(
                $matches,
                fn (array $match) => MatchDataMapper::stageKey($match) === $stageKey,
            ));
        }

        $count = 0;
        foreach ($matches as $match) {
            MatchModel::upsertFromFootballData($match);
            $count++;
        }
        MatchModel::backfillVenues();

        return $count;
    }

    public function syncStage(string $stageKey, ?string $from = null, ?string $to = null, ?int $season = null): int
    {
        $season = $season ?? $this->season;
        $matches = $from !== null && $to !== null
            ? $this->fetchScheduleMatches($season, $from, $to)
            : $this->listMatches($season);

        $stageKey = strtoupper($stageKey);
        $count = 0;
        foreach ($matches as $match) {
            if (MatchDataMapper::stageKey($match) !== $stageKey) {
                continue;
            }
            MatchModel::upsertFromFootballData($match);
            $count++;
        }
        return $count;
    }

    public function syncLive(): array
    {
        $matches = $this->fetchLiveMatches();
        $result = [
            'live_total' => count($matches),
            'updated_matches' => 0,
            'events_calls' => 0,
            'detail_skipped' => 0,
        ];

        $detailBudget = $this->liveMaxDetailRequests;

        foreach ($matches as $match) {
            $apiId = (int)($match['id'] ?? 0);
            if ($apiId <= 0) {
                continue;
            }

            $before = MatchModel::findByApiFixtureId($apiId);
            $scores = MatchDataMapper::scores($match);
            $status = MatchDataMapper::mapStatus((string)($match['status'] ?? 'NS'));
            $newSnap = MatchDataMapper::liveSnapshotKey($scores['home'], $scores['away'], $status);

            $oldSnap = null;
            if ($before) {
                $oldSnap = MatchDataMapper::liveSnapshotKey(
                    (int)$before['home_score'],
                    (int)$before['away_score'],
                    (string)$before['status'],
                );
            }

            $matchId = MatchModel::upsertFromFootballData($match);
            $result['updated_matches']++;

            $shouldFetchDetail = $detailBudget > 0 && (
                in_array($status, ['LIVE', 'HT', 'FT'], true)
                || ($scores['home'] + $scores['away']) > 0
                || ($before && self::kickoffRecentlyStarted((string)$before['kickoff_at']))
            );

            if ($shouldFetchDetail) {
                try {
                    $this->syncMatchEvents($apiId, $matchId);
                    $result['events_calls']++;
                    $detailBudget--;
                } catch (\Throwable) {
                    // Continuar con otros partidos en vivo.
                }
            } elseif (in_array($status, ['LIVE', 'HT'], true)) {
                $result['detail_skipped']++;
            }
        }

        $window = $this->syncKickoffWindow(48, max(0, $detailBudget));
        $result['kickoff_window'] = $window;
        $result['events_calls'] += $window['synced'];

        $stale = $this->syncStaleUnfinished(7, max(0, $detailBudget - $window['synced']));
        $result['stale_unfinished'] = $stale;
        $result['events_calls'] += $stale['synced'];

        return $result;
    }

    private static function kickoffRecentlyStarted(string $kickoffAt): bool
    {
        try {
            $kickoff = MatchDataMapper::kickoffFromStorage($kickoffAt);
            $now = new \DateTimeImmutable('now', MatchDataMapper::appTimezone());
            return $kickoff <= $now && $kickoff >= $now->modify('-48 hours');
        } catch (\Throwable) {
            return false;
        }
    }

    public function syncMatchEvents(int $apiMatchId, ?int $matchId = null): int
    {
        if ($matchId === null || $matchId <= 0) {
            $row = MatchModel::findByApiFixtureId($apiMatchId);
            if (!$row) {
                throw new \RuntimeException("No existe partido api_fixture_id=$apiMatchId en BD.");
            }
            $matchId = (int)$row['id'];
        }

        $detail = $this->getMatchDetail($apiMatchId);
        MatchModel::upsertFromFootballData($detail);

        try {
            MatchStats::upsertFromApiDetail($matchId, $detail);
        } catch (\Throwable) {
            // No bloquear goles/tarjetas si falla stats (p. ej. columnas legacy en BD).
        }

        $count = 0;
        foreach (MatchDataMapper::normalizeEvents($detail) as $event) {
            MatchEvent::upsertFromFootballData($matchId, $event);
            $count++;
        }
        return $count;
    }

    /**
     * Sincroniza partidos que ya deberían haber empezado (kickoff pasado) aunque la API
     * aún los liste como TIMED/NS en el listado de vivo.
     *
     * @return array{checked:int,synced:int,events:int,errors:list<string>}
     */
    /**
     * Sincroniza partidos cuyo kickoff ya pasó pero siguen sin estado final en BD.
     *
     * @return array{checked:int,synced:int,events:int,errors:list<string>}
     */
    public function syncStaleUnfinished(int $daysBack = 7, int $maxDetailRequests = 12): array
    {
        $pdo = DB::pdo();
        $cfg = require dirname(__DIR__) . '/Config/app.php';
        $season = (int)($cfg['football_data']['season'] ?? 2026);

        $st = $pdo->prepare(
            "SELECT id, api_fixture_id, status, kickoff_at
             FROM matches
             WHERE YEAR(kickoff_at) = :season
               AND kickoff_at <= (NOW() - INTERVAL 2 HOUR)
               AND kickoff_at >= (NOW() - INTERVAL :days DAY)
               AND status NOT IN ('FT', 'PEN', 'AET', 'CANC', 'PST')
             ORDER BY kickoff_at DESC
             LIMIT 30"
        );
        $st->bindValue('season', $season, \PDO::PARAM_INT);
        $st->bindValue('days', $daysBack, \PDO::PARAM_INT);
        $st->execute();
        $rows = $st->fetchAll() ?: [];

        $result = ['checked' => count($rows), 'synced' => 0, 'events' => 0, 'errors' => []];
        $budget = $maxDetailRequests;

        foreach ($rows as $row) {
            if ($budget <= 0) {
                break;
            }
            $apiId = (int)$row['api_fixture_id'];
            $matchId = (int)$row['id'];
            if ($apiId <= 0) {
                continue;
            }
            try {
                $events = $this->syncMatchEvents($apiId, $matchId);
                $result['synced']++;
                $result['events'] += $events;
                $budget--;
            } catch (\Throwable $e) {
                $result['errors'][] = "match {$matchId}: " . $e->getMessage();
            }
        }

        return $result;
    }

    public function syncKickoffWindow(int $hoursBack = 48, int $maxDetailRequests = 8): array
    {
        $pdo = DB::pdo();
        $cfg = require dirname(__DIR__) . '/Config/app.php';
        $season = (int)($cfg['football_data']['season'] ?? 2026);

        $st = $pdo->prepare(
            "SELECT id, api_fixture_id, status, kickoff_at
             FROM matches
             WHERE YEAR(kickoff_at) = :season
               AND kickoff_at <= NOW()
               AND kickoff_at >= (NOW() - INTERVAL :hours HOUR)
               AND status NOT IN ('FT', 'PEN', 'AET', 'CANC', 'PST')
             ORDER BY kickoff_at DESC
             LIMIT 20"
        );
        $st->bindValue('season', $season, \PDO::PARAM_INT);
        $st->bindValue('hours', $hoursBack, \PDO::PARAM_INT);
        $st->execute();
        $rows = $st->fetchAll() ?: [];

        $result = ['checked' => count($rows), 'synced' => 0, 'events' => 0, 'errors' => []];
        $budget = $maxDetailRequests;

        foreach ($rows as $row) {
            if ($budget <= 0) {
                break;
            }
            $apiId = (int)$row['api_fixture_id'];
            $matchId = (int)$row['id'];
            if ($apiId <= 0) {
                continue;
            }
            try {
                $events = $this->syncMatchEvents($apiId, $matchId);
                $result['synced']++;
                $result['events'] += $events;
                $budget--;
            } catch (\Throwable $e) {
                $result['errors'][] = "match {$matchId}: " . $e->getMessage();
            }
        }

        return $result;
    }

    /**
     * Sincroniza eventos de partidos FINISHED sin eventos en BD (lotes por minuto).
     */
    public function syncEventsBackfill(?int $limit = null): array
    {
        $batch = $this->backfillBatchPerMinute;
        $limit = $limit ?? 500;

        $st = DB::pdo()->query(
            "SELECT m.id, m.api_fixture_id
             FROM matches m
             LEFT JOIN match_events e ON e.match_id = m.id
             WHERE m.status IN ('FT', 'PEN', 'AET')
             GROUP BY m.id, m.api_fixture_id
             HAVING COUNT(e.id) = 0
             ORDER BY m.kickoff_at ASC
             LIMIT " . (int)$limit
        );
        $rows = $st->fetchAll() ?: [];

        $processed = 0;
        $eventsTotal = 0;
        $batches = 0;

        foreach (array_chunk($rows, $batch) as $chunk) {
            $batches++;
            foreach ($chunk as $row) {
                $apiId = (int)$row['api_fixture_id'];
                $matchId = (int)$row['id'];
                $eventsTotal += $this->syncMatchEvents($apiId, $matchId);
                $processed++;
            }
            if ($processed < count($rows)) {
                while (!RateLimitService::canRequestPerMinute($this->softLimitPerMinute)) {
                    sleep(1);
                }
            }
        }

        return [
            'candidates' => count($rows),
            'processed' => $processed,
            'events' => $eventsTotal,
            'batches' => $batches,
        ];
    }

    private function resolveSeasonForDateRange(string $from): int
    {
        $year = (int)substr($from, 0, 4);
        if ($year === $this->seasonFallback) {
            return $this->seasonFallback;
        }
        if ($year === $this->season) {
            return $this->season;
        }
        return $this->season;
    }

    private function fetchScheduleMatches(int $season, string $from, string $to): array
    {
        try {
            return $this->listMatches($season, $from, $to);
        } catch (\RuntimeException $e) {
            if ($season !== $this->seasonFallback && (str_contains($e->getMessage(), '403') || str_contains($e->getMessage(), '404'))) {
                return $this->listMatches($this->seasonFallback, $from, $to);
            }
            throw $e;
        }
    }

    private function fetchLiveMatches(): array
    {
        try {
            $matches = $this->listMatches($this->season, null, null, 'IN_PLAY');
            if ($matches !== []) {
                return $matches;
            }
        } catch (\Throwable) {
            // Algunos planes no aceptan filtro status; continuar con fallback.
        }

        $today = (new \DateTimeImmutable('today'))->format('Y-m-d');
        $tomorrow = (new \DateTimeImmutable('today'))->modify('+1 day')->format('Y-m-d');
        $matches = $this->listMatches($this->season, $today, $tomorrow);

        return array_values(array_filter(
            $matches,
            fn (array $m) => in_array(strtoupper((string)($m['status'] ?? '')), self::LIVE_STATUSES, true),
        ));
    }
}
