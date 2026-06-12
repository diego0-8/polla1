<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\DB;

final class ManualMatchUpdate
{
    private const MANUAL_STATUSES = ['NS', 'LIVE', 'HT', 'FT', 'PEN', 'AET'];
    private const EVENT_TYPES = ['Goal', 'Card', 'subst'];

    public static function forMatch(int $matchId): ?array
    {
        self::ensureTables();
        $st = DB::pdo()->prepare('SELECT * FROM manual_match_updates WHERE match_id = :match_id');
        $st->execute(['match_id' => $matchId]);
        $row = $st->fetch();
        return $row ?: null;
    }

    public static function upsertMatch(
        int $matchId,
        int $homeScore,
        int $awayScore,
        string $status,
        ?string $note,
        int $userId,
    ): void {
        self::ensureTables();
        $status = strtoupper($status);
        if (!in_array($status, self::MANUAL_STATUSES, true)) {
            throw new \RuntimeException('Estado manual inválido.');
        }
        if ($homeScore < 0 || $awayScore < 0) {
            throw new \RuntimeException('El marcador no puede ser negativo.');
        }

        $st = DB::pdo()->prepare(
            'INSERT INTO manual_match_updates (
                match_id, home_score, away_score, status, note, updated_by, updated_at
             ) VALUES (
                :match_id, :home_score, :away_score, :status, :note, :updated_by, NOW()
             )
             ON DUPLICATE KEY UPDATE
                home_score = VALUES(home_score),
                away_score = VALUES(away_score),
                status = VALUES(status),
                note = VALUES(note),
                updated_by = VALUES(updated_by),
                updated_at = NOW()'
        );
        $st->execute([
            'match_id' => $matchId,
            'home_score' => $homeScore,
            'away_score' => $awayScore,
            'status' => $status,
            'note' => $note !== null && trim($note) !== '' ? trim($note) : null,
            'updated_by' => $userId,
        ]);

        self::publishToMatchIfApiIncomplete($matchId);
    }

    public static function publishToMatchIfApiIncomplete(int $matchId): void
    {
        $manual = self::forMatch($matchId);
        if ($manual === null) {
            return;
        }

        $match = MatchModel::findById($matchId);
        if ($match === null) {
            return;
        }

        $apiEvents = MatchEvent::forMatch($matchId);
        if (self::apiHasScoreOrStatus($match, $apiEvents)) {
            return;
        }

        $homeScore = (int)$manual['home_score'];
        $awayScore = (int)$manual['away_score'];

        $winnerTeamId = null;
        if ($homeScore > $awayScore) {
            $winnerTeamId = (int)$match['home_team_id'];
        } elseif ($awayScore > $homeScore) {
            $winnerTeamId = (int)$match['away_team_id'];
        }

        $st = DB::pdo()->prepare(
            'UPDATE matches
             SET home_score = :home_score,
                 away_score = :away_score,
                 status = :status,
                 winner_team_id = :winner_team_id,
                 last_synced_at = NOW()
             WHERE id = :id'
        );
        $st->execute([
            'home_score' => $homeScore,
            'away_score' => $awayScore,
            'status' => (string)$manual['status'],
            'winner_team_id' => $winnerTeamId,
            'id' => $matchId,
        ]);
    }

    public static function addEvent(
        int $matchId,
        int $minute,
        ?int $extraMinute,
        string $type,
        string $detail,
        ?int $teamApiId,
        ?string $playerName,
        ?string $assistName,
        int $userId,
    ): void {
        self::ensureTables();
        if ($minute < 0 || $minute > 130) {
            throw new \RuntimeException('Minuto inválido.');
        }
        if ($extraMinute !== null && ($extraMinute < 0 || $extraMinute > 30)) {
            throw new \RuntimeException('Adición inválida.');
        }
        if (!in_array($type, self::EVENT_TYPES, true)) {
            throw new \RuntimeException('Tipo de evento inválido.');
        }

        $st = DB::pdo()->prepare(
            'INSERT INTO manual_match_events (
                match_id, minute, extra_minute, team_api_id, player_name, assist_name,
                type, detail, created_by, updated_by, created_at, updated_at
             ) VALUES (
                :match_id, :minute, :extra_minute, :team_api_id, :player_name, :assist_name,
                :type, :detail, :created_by, :updated_by, NOW(), NOW()
             )'
        );
        $st->execute([
            'match_id' => $matchId,
            'minute' => $minute,
            'extra_minute' => $extraMinute,
            'team_api_id' => $teamApiId,
            'player_name' => $playerName !== null && trim($playerName) !== '' ? trim($playerName) : null,
            'assist_name' => $assistName !== null && trim($assistName) !== '' ? trim($assistName) : null,
            'type' => $type,
            'detail' => trim($detail) !== '' ? trim($detail) : self::defaultDetail($type),
            'created_by' => $userId,
            'updated_by' => $userId,
        ]);
    }

    public static function deleteEvent(int $eventId): void
    {
        self::ensureTables();
        $st = DB::pdo()->prepare('DELETE FROM manual_match_events WHERE id = :id');
        $st->execute(['id' => $eventId]);
    }

    public static function eventsForMatch(int $matchId): array
    {
        self::ensureTables();
        $st = DB::pdo()->prepare(
            'SELECT * FROM manual_match_events
             WHERE match_id = :match_id
             ORDER BY minute DESC, extra_minute DESC, id DESC'
        );
        $st->execute(['match_id' => $matchId]);
        return $st->fetchAll() ?: [];
    }

    /** @param array<string, mixed> $match */
    public static function applyToMatch(array $match, array $apiEvents = []): array
    {
        $matchId = (int)$match['id'];
        $manual = self::forMatch($matchId);
        if (!$manual) {
            $match['data_source'] = 'api';
            $match['events_source'] = self::resolveEventsSource($matchId, $apiEvents);

            return $match;
        }

        $apiHasScore = self::apiHasScoreOrStatus($match, $apiEvents);
        if (!$apiHasScore) {
            $match['home_score'] = (int)$manual['home_score'];
            $match['away_score'] = (int)$manual['away_score'];
            $match['status'] = (string)$manual['status'];
            $match['manual_note'] = $manual['note'];
            $match['manual_updated_at'] = $manual['updated_at'];
            $match['data_source'] = 'manual';
        } else {
            $match['data_source'] = 'api';
        }

        $match['events_source'] = self::resolveEventsSource($matchId, $apiEvents);

        return $match;
    }

    /** @param list<array<string, mixed>> $apiEvents */
    private static function resolveEventsSource(int $matchId, array $apiEvents): string
    {
        if ($apiEvents !== []) {
            return 'api';
        }

        return self::eventsForMatch($matchId) !== [] ? 'manual' : 'api';
    }

    /** @param list<array<string, mixed>> $matches */
    public static function applyToMatches(array $matches): array
    {
        foreach ($matches as $idx => $match) {
            $matches[$idx] = self::applyToMatch($match);
        }
        return $matches;
    }

    /** @param list<array<string, mixed>> $apiEvents */
    public static function eventsForDisplay(int $matchId, array $apiEvents): array
    {
        if ($apiEvents !== []) {
            return $apiEvents;
        }

        $events = self::eventsForMatch($matchId);
        foreach ($events as &$event) {
            $event['source'] = 'manual';
        }

        return $events;
    }

    /** @param array<string, mixed> $match @param list<array<string, mixed>> $apiEvents */
    public static function apiHasScoreOrStatus(array $match, array $apiEvents = []): bool
    {
        $status = strtoupper((string)($match['status'] ?? 'NS'));
        $total = (int)($match['home_score'] ?? 0) + (int)($match['away_score'] ?? 0);

        if (in_array($status, ['LIVE', 'HT'], true)) {
            return true;
        }

        if (in_array($status, ['FT', 'PEN', 'AET'], true)) {
            return $total > 0 || $apiEvents !== [];
        }

        return $total > 0;
    }

    public static function defaultDetail(string $type): string
    {
        return match ($type) {
            'Goal' => 'REGULAR',
            'Card' => 'YELLOW',
            'subst' => 'Substitution',
            default => '',
        };
    }

    private static function ensureTables(): void
    {
        static $done = false;
        if ($done) {
            return;
        }

        DB::pdo()->exec(
            'CREATE TABLE IF NOT EXISTS manual_match_updates (
                match_id INT NOT NULL PRIMARY KEY,
                home_score INT NOT NULL DEFAULT 0,
                away_score INT NOT NULL DEFAULT 0,
                status VARCHAR(10) NOT NULL DEFAULT "LIVE",
                note VARCHAR(255) NULL,
                updated_by INT NULL,
                updated_at DATETIME NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );

        DB::pdo()->exec(
            'CREATE TABLE IF NOT EXISTS manual_match_events (
                id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                match_id INT NOT NULL,
                minute SMALLINT NOT NULL DEFAULT 0,
                extra_minute SMALLINT NULL,
                team_api_id INT NULL,
                player_name VARCHAR(120) NULL,
                assist_name VARCHAR(120) NULL,
                type VARCHAR(40) NOT NULL,
                detail VARCHAR(80) NULL,
                created_by INT NULL,
                updated_by INT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL,
                KEY idx_manual_match_events_match (match_id, minute)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );

        $done = true;
    }
}
