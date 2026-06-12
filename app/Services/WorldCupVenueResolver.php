<?php
declare(strict_types=1);

namespace App\Services;

final class WorldCupVenueResolver
{
    /** @var array<int, string>|null */
    private static ?array $fallbackMap = null;

    public static function resolveFromApi(array $match): ?string
    {
        $fromApi = MatchDataMapper::venueName($match);
        if ($fromApi !== null) {
            return $fromApi;
        }

        $apiId = (int)($match['id'] ?? $match['api_fixture_id'] ?? 0);
        return self::byApiId($apiId);
    }

    public static function resolveForDbRow(array $matchRow): string
    {
        $venue = trim((string)($matchRow['venue'] ?? ''));
        if ($venue !== '') {
            return $venue;
        }

        $apiId = (int)($matchRow['api_fixture_id'] ?? 0);
        return self::byApiId($apiId) ?? 'Por confirmar';
    }

    public static function byApiId(int $apiFixtureId): ?string
    {
        if ($apiFixtureId <= 0) {
            return null;
        }

        $map = self::fallbackMap();
        return $map[$apiFixtureId] ?? null;
    }

    /** @return array<int, string> */
    private static function fallbackMap(): array
    {
        if (self::$fallbackMap === null) {
            $path = dirname(__DIR__) . '/Config/wc2026_venues.php';
            self::$fallbackMap = is_file($path) ? (require $path) : [];
        }

        return self::$fallbackMap;
    }
}
