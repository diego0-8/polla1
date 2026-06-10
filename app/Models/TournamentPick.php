<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\DB;
use DateInterval;
use DateTimeImmutable;

final class TournamentPick
{
    public static function forUserSeason(int $userId, int $season): ?array
    {
        $st = DB::pdo()->prepare(
            'SELECT tp.*, t.name AS champion_name, t.code AS champion_code, t.logo_url AS champion_logo
             FROM tournament_picks tp
             INNER JOIN teams t ON t.id = tp.champion_team_id
             WHERE tp.user_id = :user_id AND tp.season = :season
             LIMIT 1'
        );
        $st->execute(['user_id' => $userId, 'season' => $season]);
        $row = $st->fetch();
        return $row ?: null;
    }

    public static function myForSeason(int $season): ?array
    {
        $userId = (int)($_SESSION['user_id'] ?? 0);
        if ($userId <= 0) {
            return null;
        }
        return self::forUserSeason($userId, $season);
    }

    public static function lockedAt(int $season): string
    {
        $firstKickoff = MatchModel::firstKickoffForSeason($season);
        if ($firstKickoff === null) {
            return (new DateTimeImmutable('now'))->format('Y-m-d H:i:s');
        }

        $cfg = require __DIR__ . '/../Config/app.php';
        $lockMinutes = (int)($cfg['lock_minutes'] ?? 5);
        return (new DateTimeImmutable($firstKickoff))
            ->sub(new DateInterval('PT' . $lockMinutes . 'M'))
            ->format('Y-m-d H:i:s');
    }

    public static function isOpen(int $season): bool
    {
        $firstKickoff = MatchModel::firstKickoffForSeason($season);
        if ($firstKickoff === null) {
            return false;
        }

        $now = new DateTimeImmutable('now');
        return $now < new DateTimeImmutable(self::lockedAt($season));
    }

    public static function create(int $userId, int $season, int $championTeamId): void
    {
        if (self::forUserSeason($userId, $season) !== null) {
            throw new \RuntimeException('Ya registraste tu campeón. No se puede modificar.');
        }

        if (!self::isOpen($season)) {
            throw new \RuntimeException('El pronóstico de campeón ya está cerrado.');
        }

        $st = DB::pdo()->prepare(
            'INSERT INTO tournament_picks (user_id, season, champion_team_id, locked_at, created_at, updated_at)
             VALUES (:user_id, :season, :champion_team_id, :locked_at, NOW(), NOW())'
        );
        $st->execute([
            'user_id' => $userId,
            'season' => $season,
            'champion_team_id' => $championTeamId,
            'locked_at' => self::lockedAt($season),
        ]);
    }
}
