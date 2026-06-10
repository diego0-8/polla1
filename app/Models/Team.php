<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\DB;

final class Team
{
    public static function upsertFromApi(
        int $apiTeamId,
        string $name,
        ?string $code,
        ?string $logoUrl,
        ?int $placeholderKey = null,
    ): int {
        if ($apiTeamId <= 0) {
            $apiTeamId = $placeholderKey !== null ? -abs($placeholderKey) : -abs(crc32($name));
        }

        $pdo = DB::pdo();
        $st = $pdo->prepare(
            'INSERT INTO teams (api_team_id, name, code, logo_url)
             VALUES (:api_team_id, :name, :code, :logo_url)
             ON DUPLICATE KEY UPDATE name = VALUES(name), code = VALUES(code), logo_url = VALUES(logo_url)'
        );
        $st->execute([
            'api_team_id' => $apiTeamId,
            'name' => $name,
            'code' => $code,
            'logo_url' => $logoUrl,
        ]);

        $st2 = $pdo->prepare('SELECT id FROM teams WHERE api_team_id = :api_team_id');
        $st2->execute(['api_team_id' => $apiTeamId]);
        return (int)$st2->fetchColumn();
    }

    /** @return list<array<string, mixed>> */
    public static function forTournamentSeason(int $season): array
    {
        $st = DB::pdo()->prepare(
            'SELECT DISTINCT t.*
             FROM teams t
             INNER JOIN matches m ON m.home_team_id = t.id OR m.away_team_id = t.id
             WHERE YEAR(m.kickoff_at) = :season
               AND t.api_team_id > 0
             ORDER BY t.name ASC'
        );
        $st->execute(['season' => $season]);
        return $st->fetchAll() ?: [];
    }

    public static function findById(int $id): ?array
    {
        $st = DB::pdo()->prepare('SELECT * FROM teams WHERE id = :id');
        $st->execute(['id' => $id]);
        $row = $st->fetch();
        return $row ?: null;
    }

    public static function isInTournamentSeason(int $teamId, int $season): bool
    {
        $st = DB::pdo()->prepare(
            'SELECT 1 FROM teams t
             INNER JOIN matches m ON m.home_team_id = t.id OR m.away_team_id = t.id
             WHERE t.id = :id AND t.api_team_id > 0 AND YEAR(m.kickoff_at) = :season
             LIMIT 1'
        );
        $st->execute(['id' => $teamId, 'season' => $season]);
        return (bool)$st->fetchColumn();
    }
}

