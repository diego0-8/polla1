<?php
declare(strict_types=1);

namespace App\Helpers;

final class TeamName
{
    private static ?array $map = null;

    public static function toSpanish(?int $apiTeamId, ?string $code, string $name): string
    {
        $name = trim($name);
        if ($name === '' || $name === 'TBD') {
            return $name;
        }

        $map = self::map();

        if ($apiTeamId !== null && $apiTeamId > 0 && isset($map['by_api_id'][$apiTeamId])) {
            return $map['by_api_id'][$apiTeamId];
        }

        $codeKey = strtoupper(trim((string)$code));
        if ($codeKey !== '' && isset($map['by_code'][$codeKey])) {
            return $map['by_code'][$codeKey];
        }

        $nameKey = self::normalize($name);
        if (isset($map['by_name'][$nameKey])) {
            return $map['by_name'][$nameKey];
        }

        return $name;
    }

    /** @param array<string, mixed> $match */
    public static function applyToMatch(array $match): array
    {
        if (isset($match['home_name'])) {
            $match['home_name'] = self::toSpanish(
                isset($match['home_api_team_id']) ? (int)$match['home_api_team_id'] : null,
                isset($match['home_code']) ? (string)$match['home_code'] : null,
                (string)$match['home_name'],
            );
        }

        if (isset($match['away_name'])) {
            $match['away_name'] = self::toSpanish(
                isset($match['away_api_team_id']) ? (int)$match['away_api_team_id'] : null,
                isset($match['away_code']) ? (string)$match['away_code'] : null,
                (string)$match['away_name'],
            );
        }

        return $match;
    }

    /** @param list<array<string, mixed>> $matches @return list<array<string, mixed>> */
    public static function applyToMatches(array $matches): array
    {
        foreach ($matches as $idx => $match) {
            $matches[$idx] = self::applyToMatch($match);
        }

        return $matches;
    }

    /** @param array<string, mixed> $row */
    public static function applyToTeamField(
        array $row,
        string $nameKey,
        ?string $codeKey = null,
        ?string $apiKey = null,
    ): array {
        if (!isset($row[$nameKey])) {
            return $row;
        }

        $row[$nameKey] = self::toSpanish(
            $apiKey !== null && isset($row[$apiKey]) ? (int)$row[$apiKey] : null,
            $codeKey !== null && isset($row[$codeKey]) ? (string)$row[$codeKey] : null,
            (string)$row[$nameKey],
        );

        return $row;
    }

    /** @return array{by_api_id: array<int, string>, by_code: array<string, string>, by_name: array<string, string>} */
    private static function map(): array
    {
        if (self::$map === null) {
            self::$map = require dirname(__DIR__) . '/Config/team_names_es.php';
        }

        return self::$map;
    }

    private static function normalize(string $name): string
    {
        $name = mb_strtolower(trim($name), 'UTF-8');
        $name = str_replace(['’', "'"], '', $name);
        $name = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name) ?: $name;
        $name = preg_replace('/[^a-z0-9\s-]/', '', $name) ?? $name;
        $name = preg_replace('/\s+/', ' ', $name) ?? $name;

        return trim($name);
    }
}
