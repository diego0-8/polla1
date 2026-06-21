<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\DB;
use App\Helpers\TeamName;

final class Prediction
{
    public static function outcomeForUserMatch(int $userId, int $matchId): ?array
    {
        $st = DB::pdo()->prepare(
            'SELECT * FROM predictions
             WHERE user_id = :user_id AND match_id = :match_id AND pred_type = \'outcome\'
             LIMIT 1'
        );
        $st->execute(['user_id' => $userId, 'match_id' => $matchId]);
        $row = $st->fetch();
        return $row ?: null;
    }

    public static function advanceForUserMatch(int $userId, int $matchId): ?array
    {
        $st = DB::pdo()->prepare(
            'SELECT p.*, t.name AS advances_team_name, t.code AS advances_team_code, t.api_team_id AS advances_team_api_id
             FROM predictions p
             LEFT JOIN teams t ON t.id = p.advances_team_id
             WHERE p.user_id = :user_id AND p.match_id = :match_id AND p.pred_type = \'advance\'
             LIMIT 1'
        );
        $st->execute(['user_id' => $userId, 'match_id' => $matchId]);
        $row = $st->fetch();

        return $row
            ? TeamName::applyToTeamField($row, 'advances_team_name', 'advances_team_code', 'advances_team_api_id')
            : null;
    }

    public static function exactForUserMatch(int $userId, int $matchId): ?array
    {
        $st = DB::pdo()->prepare(
            'SELECT * FROM predictions
             WHERE user_id = :user_id AND match_id = :match_id AND pred_type = \'exact\'
             ORDER BY created_at ASC
             LIMIT 1'
        );
        $st->execute(['user_id' => $userId, 'match_id' => $matchId]);
        $row = $st->fetch();
        return $row ?: null;
    }

    public static function myOutcomeForMatch(int $matchId): ?array
    {
        $userId = (int)($_SESSION['user_id'] ?? 0);
        if ($userId <= 0) {
            return null;
        }
        return self::outcomeForUserMatch($userId, $matchId);
    }

    public static function myAdvanceForMatch(int $matchId): ?array
    {
        $userId = (int)($_SESSION['user_id'] ?? 0);
        if ($userId <= 0) {
            return null;
        }
        return self::advanceForUserMatch($userId, $matchId);
    }

    public static function myExactForMatch(int $matchId): ?array
    {
        $userId = (int)($_SESSION['user_id'] ?? 0);
        if ($userId <= 0) {
            return null;
        }
        return self::exactForUserMatch($userId, $matchId);
    }

    public static function createExact(
        int $userId,
        int $matchId,
        int $predHome,
        int $predAway,
        string $lockedAt,
    ): void {
        if (self::exactForUserMatch($userId, $matchId) !== null) {
            throw new \RuntimeException('Ya registraste el marcador exacto para este partido. No se puede modificar.');
        }

        $st = DB::pdo()->prepare(
            'INSERT INTO predictions (user_id, match_id, pred_home, pred_away, pred_type, pred_outcome, locked_at, created_at, updated_at)
             VALUES (:user_id, :match_id, :pred_home, :pred_away, \'exact\', NULL, :locked_at, NOW(), NOW())'
        );
        $st->execute([
            'user_id' => $userId,
            'match_id' => $matchId,
            'pred_home' => $predHome,
            'pred_away' => $predAway,
            'locked_at' => $lockedAt,
        ]);
    }

    public static function createOutcome(
        int $userId,
        int $matchId,
        string $predOutcome,
        string $lockedAt,
    ): void {
        if (self::outcomeForUserMatch($userId, $matchId) !== null) {
            throw new \RuntimeException('Ya registraste ganador/empate para este partido. No se puede modificar.');
        }

        $st = DB::pdo()->prepare(
            'INSERT INTO predictions (user_id, match_id, pred_home, pred_away, pred_type, pred_outcome, locked_at, created_at, updated_at)
             VALUES (:user_id, :match_id, 0, 0, \'outcome\', :pred_outcome, :locked_at, NOW(), NOW())'
        );
        $st->execute([
            'user_id' => $userId,
            'match_id' => $matchId,
            'pred_outcome' => $predOutcome,
            'locked_at' => $lockedAt,
        ]);
    }

    public static function createAdvance(
        int $userId,
        int $matchId,
        int $advancesTeamId,
        string $lockedAt,
    ): void {
        if (self::advanceForUserMatch($userId, $matchId) !== null) {
            throw new \RuntimeException('Ya registraste quién avanza para este partido. No se puede modificar.');
        }

        $st = DB::pdo()->prepare(
            'INSERT INTO predictions (
                user_id, match_id, pred_home, pred_away, pred_type, pred_outcome,
                advances_team_id, locked_at, created_at, updated_at
             )
             VALUES (
                :user_id, :match_id, 0, 0, \'advance\', NULL,
                :advances_team_id, :locked_at, NOW(), NOW()
             )'
        );
        $st->execute([
            'user_id' => $userId,
            'match_id' => $matchId,
            'advances_team_id' => $advancesTeamId,
            'locked_at' => $lockedAt,
        ]);
    }

    public static function outcomeLabel(string $code, array $match): string
    {
        return match (strtoupper($code)) {
            'H' => 'Local · ' . (string)($match['home_name'] ?? ''),
            'A' => 'Visitante · ' . (string)($match['away_name'] ?? ''),
            'D' => 'Empate',
            default => $code,
        };
    }

    public static function advanceLabel(array $prediction): string
    {
        $name = trim((string)($prediction['advances_team_name'] ?? ''));
        return $name !== '' ? $name : 'Equipo seleccionado';
    }
}
