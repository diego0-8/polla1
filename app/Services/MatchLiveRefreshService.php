<?php
declare(strict_types=1);

namespace App\Services;

use App\Models\MatchModel;
use App\Services\SettleService;

/**
 * Refresca un partido desde la API al ver la ficha (con throttle para no saturar el plan free).
 */
final class MatchLiveRefreshService
{
    private const THROTTLE_SECONDS = 45;
    private const INDEX_THROTTLE_SECONDS = 60;

    /** @return array{synced:bool,reason:string,detail?:array} */
    public static function refreshIfNeeded(array $match): array
    {
        self::settleIfNeeded();

        if (!self::shouldRefresh($match)) {
            return ['synced' => false, 'reason' => 'no_requiere_sync'];
        }

        $matchId = (int)$match['id'];
        if (!self::acquireThrottle($matchId)) {
            return ['synced' => false, 'reason' => 'throttle_activo'];
        }

        $apiFixtureId = (int)($match['api_fixture_id'] ?? 0);
        if ($apiFixtureId <= 0) {
            return ['synced' => false, 'reason' => 'sin_api_fixture_id'];
        }

        try {
            $sync = self::buildSyncService();
            $events = $sync->syncMatchEvents($apiFixtureId, $matchId);

            return [
                'synced' => true,
                'reason' => 'ok',
                'detail' => ['events_synced' => $events],
            ];
        } catch (\Throwable $e) {
            return ['synced' => false, 'reason' => 'error: ' . $e->getMessage()];
        }
    }

    public static function refreshLeaderboardIfNeeded(): void
    {
        self::settleIfNeeded();
    }

    public static function shouldRefresh(array $match): bool
    {
        $status = strtoupper((string)($match['status'] ?? 'NS'));
        if (in_array($status, ['LIVE', 'HT', 'FT', 'PEN', 'AET'], true)) {
            return true;
        }

        $kickoffRaw = (string)($match['kickoff_at'] ?? '');
        if ($kickoffRaw === '') {
            return false;
        }

        try {
            $kickoff = MatchDataMapper::kickoffFromStorage($kickoffRaw);
            $now = new \DateTimeImmutable('now', MatchDataMapper::appTimezone());
            $windowStart = $now->modify('-5 hours');
            $windowEnd = $now->modify('+15 minutes');

            return $kickoff >= $windowStart && $kickoff <= $windowEnd;
        } catch (\Throwable) {
            return false;
        }
    }

    /** @return array{synced:bool,reason:string,detail?:array} */
    public static function refreshIndexIfNeeded(): array
    {
        if (!self::acquireThrottle(0, self::INDEX_THROTTLE_SECONDS)) {
            return ['synced' => false, 'reason' => 'throttle_activo'];
        }

        try {
            $sync = self::buildSyncService();
            $cfg = require dirname(__DIR__) . '/Config/app.php';
            $season = (int)($cfg['football_data']['season'] ?? 2026);
            $today = (new \DateTimeImmutable('today', MatchDataMapper::appTimezone()))->format('Y-m-d');
            $sync->syncSchedule($today, $today, $season);
            $result = $sync->syncLive();
            self::settleIfNeeded();
            self::refreshStandingsIfNeeded();
            return ['synced' => true, 'reason' => 'ok', 'detail' => $result];
        } catch (\Throwable $e) {
            return ['synced' => false, 'reason' => 'error: ' . $e->getMessage()];
        }
    }

    private static function acquireThrottle(int $matchId, int $seconds = self::THROTTLE_SECONDS): bool
    {
        $dir = dirname(__DIR__, 2) . '/storage/match_sync';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $file = $matchId > 0 ? $dir . '/match_' . $matchId . '.stamp' : $dir . '/index.stamp';
        $now = time();

        if (is_file($file)) {
            $last = (int)file_get_contents($file);
            if ($now - $last < $seconds) {
                return false;
            }
        }

        file_put_contents($file, (string)$now);
        return true;
    }

    private static function settleIfNeeded(): void
    {
        $dir = dirname(__DIR__, 2) . '/storage/match_sync';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $file = $dir . '/settle.stamp';
        $now = time();
        if (is_file($file)) {
            $last = (int)file_get_contents($file);
            if ($now - $last < 120) {
                return;
            }
        }

        file_put_contents($file, (string)$now);
        SettleService::settleFinishedMatches();
    }

    public static function refreshStandingsIfNeeded(): void
    {
        $dir = dirname(__DIR__, 2) . '/storage/match_sync';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $file = $dir . '/standings.stamp';
        $now = time();
        if (is_file($file)) {
            $last = (int)file_get_contents($file);
            if ($now - $last < 120) {
                return;
            }
        }

        file_put_contents($file, (string)$now);

        try {
            $cfg = require dirname(__DIR__) . '/Config/app.php';
            $fd = $cfg['football_data'];
            $api = new FootballDataClient(
                (string)$fd['base_url'],
                (string)$fd['token'],
                (int)($fd['request_soft_limit_per_minute'] ?? 9),
            );
            $gs = new GroupStandingService(
                $api,
                (string)($fd['competition_code'] ?? 'WC'),
                (int)($fd['season'] ?? 2026),
            );
            $gs->sync();
        } catch (\Throwable) {
            // No bloquear la vista si falla el recálculo de grupos.
        }
    }

    private static function buildSyncService(): FootballDataSyncService
    {
        $cfg = require dirname(__DIR__) . '/Config/app.php';
        $fd = $cfg['football_data'];
        $api = new FootballDataClient(
            (string)$fd['base_url'],
            (string)$fd['token'],
            (int)($fd['request_soft_limit_per_minute'] ?? 9),
        );

        return new FootballDataSyncService(
            $api,
            (string)($fd['competition_code'] ?? 'WC'),
            (int)($fd['season'] ?? 2026),
            (int)($fd['season_fallback'] ?? 2022),
            (int)($fd['live_max_detail_requests'] ?? 8),
            (int)($fd['backfill_batch_per_minute'] ?? 8),
            (int)($fd['request_soft_limit_per_minute'] ?? 9),
        );
    }
}
