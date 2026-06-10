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

            $changed = $oldSnap === null || $oldSnap !== $newSnap;
            $interesting = ($scores['home'] + $scores['away']) > 0
                || in_array($status, ['HT', 'FT', 'LIVE'], true);

            if ($changed && $interesting && $detailBudget > 0) {
                $this->syncMatchEvents($apiId, $matchId);
                $result['events_calls']++;
                $detailBudget--;
            } elseif ($changed && $interesting) {
                $result['detail_skipped']++;
            }
        }

        return $result;
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
        MatchStats::upsertFromApiDetail($matchId, $detail);

        $count = 0;
        foreach (MatchDataMapper::normalizeEvents($detail) as $event) {
            MatchEvent::upsertFromFootballData($matchId, $event);
            $count++;
        }
        return $count;
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
