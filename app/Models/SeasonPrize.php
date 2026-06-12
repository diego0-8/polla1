<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\DB;

final class SeasonPrize
{
    /** @return array<string, mixed> */
    public static function getForSeason(int $season): array
    {
        $st = DB::pdo()->prepare('SELECT * FROM season_prizes WHERE season = :season');
        $st->execute(['season' => $season]);
        $row = $st->fetch();

        if (!$row) {
            return [
                'season' => $season,
                'prize_count' => 1,
                'prize_1_cop' => null,
                'prize_2_cop' => null,
                'prize_3_cop' => null,
                'prize_4_cop' => null,
                'prize_5_cop' => null,
                'updated_at' => null,
            ];
        }

        return $row;
    }

    /** @param array<string, mixed> $prizes */
    public static function isConfigured(array $prizes): bool
    {
        if (empty($prizes['updated_at'])) {
            return false;
        }

        return ($prizes['prize_1_cop'] ?? null) !== null;
    }

    /**
     * @param array<string, mixed> $prizes
     * @return list<array{position:int,amount:int}>
     */
    public static function activeList(array $prizes): array
    {
        $count = (int)($prizes['prize_count'] ?? 0);
        $list = [];

        for ($i = 1; $i <= $count && $i <= 5; $i++) {
            $key = "prize_{$i}_cop";
            $amount = $prizes[$key] ?? null;
            if ($amount !== null) {
                $list[] = ['position' => $i, 'amount' => (int)$amount];
            }
        }

        return $list;
    }

    /** @param list<int|null> $amounts Montos COP para posiciones 1..5 */
    public static function save(int $season, int $prizeCount, array $amounts): void
    {
        if ($prizeCount < 1 || $prizeCount > 5) {
            throw new \RuntimeException('La cantidad de premios debe ser entre 1 y 5.');
        }

        $normalized = [];
        for ($i = 1; $i <= 5; $i++) {
            $value = $amounts[$i - 1] ?? null;
            if ($i <= $prizeCount) {
                if ($value === null || $value < 0) {
                    throw new \RuntimeException("El premio #{$i} debe ser un monto válido (≥ 0).");
                }
                $normalized[$i] = (int)$value;
            } else {
                $normalized[$i] = null;
            }
        }

        $st = DB::pdo()->prepare(
            'INSERT INTO season_prizes (
                season, prize_count,
                prize_1_cop, prize_2_cop, prize_3_cop, prize_4_cop, prize_5_cop,
                updated_at
             ) VALUES (
                :season, :prize_count,
                :p1, :p2, :p3, :p4, :p5,
                NOW()
             )
             ON DUPLICATE KEY UPDATE
                prize_count = VALUES(prize_count),
                prize_1_cop = VALUES(prize_1_cop),
                prize_2_cop = VALUES(prize_2_cop),
                prize_3_cop = VALUES(prize_3_cop),
                prize_4_cop = VALUES(prize_4_cop),
                prize_5_cop = VALUES(prize_5_cop),
                updated_at = NOW()'
        );
        $st->execute([
            'season' => $season,
            'prize_count' => $prizeCount,
            'p1' => $normalized[1],
            'p2' => $normalized[2],
            'p3' => $normalized[3],
            'p4' => $normalized[4],
            'p5' => $normalized[5],
        ]);
    }
}
