<?php
declare(strict_types=1);

namespace App\Services;

final class ScoringService
{
    private static function configInt(string $key, int $default): int
    {
        $cfg = require dirname(__DIR__) . '/Config/app.php';
        return (int)($cfg[$key] ?? $default);
    }

    public static function realOutcome(int $realHome, int $realAway): string
    {
        if ($realHome > $realAway) {
            return 'H';
        }
        if ($realHome < $realAway) {
            return 'A';
        }
        return 'D';
    }

    /**
     * @return array{points:int, reason:string}
     */
    public static function points(
        string $predType,
        int $predHome,
        int $predAway,
        ?string $predOutcome,
        int $realHome,
        int $realAway,
    ): array {
        if ($predType === 'exact') {
            if ($predHome === $realHome && $predAway === $realAway) {
                return [
                    'points' => self::configInt('exact_score_points', 5),
                    'reason' => 'exact_score',
                ];
            }
            return ['points' => 0, 'reason' => 'none'];
        }

        $outcome = strtoupper((string)$predOutcome);
        $real = self::realOutcome($realHome, $realAway);

        if ($outcome !== $real) {
            return ['points' => 0, 'reason' => 'none'];
        }

        if ($outcome === 'D') {
            return ['points' => 2, 'reason' => 'correct_draw'];
        }

        return ['points' => 3, 'reason' => 'correct_winner'];
    }

    /**
     * @return array{points:int, reason:string}
     */
    public static function advancerPoints(?int $predictedTeamId, ?int $winnerTeamId): array
    {
        if ($predictedTeamId !== null && $winnerTeamId !== null && $predictedTeamId === $winnerTeamId) {
            return [
                'points' => self::configInt('ko_advancer_points', 2),
                'reason' => 'correct_advancer',
            ];
        }

        return ['points' => 0, 'reason' => 'none'];
    }

    /**
     * @return array{points:int, reason:string}
     */
    public static function championBonusPoints(int $predictedTeamId, int $championTeamId): array
    {
        if ($predictedTeamId === $championTeamId) {
            return [
                'points' => self::configInt('champion_bonus_points', 20),
                'reason' => 'champion_bonus',
            ];
        }

        return ['points' => 0, 'reason' => 'champion_none'];
    }

    /**
     * @param array<string, mixed> $stats
     * @return array{points:int, reason:string}
     */
    public static function propPoints(string $market, ?float $line, string $pick, array $stats): array
    {
        $pick = strtolower($pick);
        $correct = false;
        $reason = 'none';

        if ($market === 'btts') {
            $actual = (bool)($stats['btts'] ?? false);
            $predicted = $pick === 'yes';
            $correct = $actual === $predicted;
            $reason = $correct ? 'correct_btts' : 'none';
        } elseif ($market === 'goals_ou') {
            $total = (int)($stats['total_goals'] ?? 0);
            $correct = self::isOverUnderCorrect($total, $line, $pick);
            $reason = $correct ? 'correct_goals_ou' : 'none';
        } elseif ($market === 'corners_ou') {
            if ($stats['total_corners'] === null) {
                return ['points' => 0, 'reason' => 'none'];
            }
            $total = (int)$stats['total_corners'];
            $correct = self::isOverUnderCorrect($total, $line, $pick);
            $reason = $correct ? 'correct_corners_ou' : 'none';
        } elseif ($market === 'cards_ou') {
            if ($stats['total_cards'] === null) {
                return ['points' => 0, 'reason' => 'none'];
            }
            $total = (int)$stats['total_cards'];
            $correct = self::isOverUnderCorrect($total, $line, $pick);
            $reason = $correct ? 'correct_cards_ou' : 'none';
        }

        if (!$correct) {
            return ['points' => 0, 'reason' => 'none'];
        }

        return [
            'points' => \App\Models\PropPrediction::pointsForMarket($market, $line),
            'reason' => $reason,
        ];
    }

    private static function isOverUnderCorrect(int $total, ?float $line, string $pick): bool
    {
        if ($line === null) {
            return false;
        }

        $actualOver = (float)$total > $line;
        return $pick === 'over' ? $actualOver : !$actualOver;
    }
}
