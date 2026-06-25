<?php
declare(strict_types=1);

namespace App\Services;

final class UserPredictionBreakdown
{
    /**
     * @param array<string, mixed> $matchRow
     * @param array{predictions:list<array<string,mixed>>,props:list<array<string,mixed>>} $bets
     * @param list<array{points:int,prediction_id:?int,prop_prediction_id:?int}> $ledgerRows
     * @return array<string, mixed>
     */
    public static function forMatch(int $userId, array $matchRow, array $bets, array $ledgerRows): array
    {
        $matchId = (int)($matchRow['id'] ?? $matchRow['match_id'] ?? 0);
        $status = strtoupper((string)($matchRow['status'] ?? 'NS'));
        $finished = in_array($status, ['FT', 'PEN', 'AET'], true);

        $pointsByPredId = [];
        $pointsByPropId = [];
        foreach ($ledgerRows as $entry) {
            if ($entry['prediction_id'] !== null) {
                $pointsByPredId[$entry['prediction_id']] = $entry['points'];
            }
            if ($entry['prop_prediction_id'] !== null) {
                $pointsByPropId[$entry['prop_prediction_id']] = $entry['points'];
            }
        }

        $columnDefs = [
            'exact' => ['label' => 'Exacto', 'predicted' => false, 'points' => null, 'pick' => null],
            'gana' => ['label' => 'Gana', 'predicted' => false, 'points' => null, 'pick' => null],
            'btts' => ['label' => 'Ambos marcan', 'predicted' => false, 'points' => null, 'pick' => null],
            'goals' => ['label' => 'Goles', 'predicted' => false, 'points' => null, 'pick' => null],
            'corners' => ['label' => 'Corners', 'predicted' => false, 'points' => null, 'pick' => null],
            'cards' => ['label' => 'Tarjetas', 'predicted' => false, 'points' => null, 'pick' => null],
        ];

        foreach ($bets['predictions'] as $pred) {
            $predId = (int)$pred['id'];
            $predType = (string)$pred['pred_type'];
            $colKey = match ($predType) {
                'exact' => 'exact',
                'outcome', 'advance' => 'gana',
                default => null,
            };
            if ($colKey === null) {
                continue;
            }

            $columnDefs[$colKey]['predicted'] = true;
            $columnDefs[$colKey]['pick'] = self::predictionPickLabel($pred, $matchRow);
            if ($finished && array_key_exists($predId, $pointsByPredId)) {
                $columnDefs[$colKey]['points'] = $pointsByPredId[$predId];
            }
        }

        $propColMap = [
            'btts' => 'btts',
            'goals_ou' => 'goals',
            'corners_ou' => 'corners',
            'cards_ou' => 'cards',
        ];
        foreach ($bets['props'] as $prop) {
            $propId = (int)$prop['id'];
            $market = (string)$prop['market'];
            $colKey = $propColMap[$market] ?? null;
            if ($colKey === null) {
                continue;
            }

            $columnDefs[$colKey]['predicted'] = true;
            $columnDefs[$colKey]['pick'] = \App\Models\PropPrediction::label($prop);
            if ($finished && array_key_exists($propId, $pointsByPropId)) {
                $columnDefs[$colKey]['points'] = $pointsByPropId[$propId];
            }
        }

        $columns = [];
        $total = 0;
        $anyPredicted = false;
        foreach ($columnDefs as $col) {
            if (!$col['predicted']) {
                continue;
            }
            $anyPredicted = true;
            $points = $col['points'];
            if ($points !== null) {
                $total += $points;
            }
            $columns[] = [
                'label' => $col['label'],
                'points' => $points,
                'pick' => $col['pick'],
            ];
        }

        $hasBet = $anyPredicted;
        $isSettled = $finished && $hasBet && array_reduce(
            $columns,
            static fn (bool $ok, array $c): bool => $ok && $c['points'] !== null,
            true,
        );

        $homeName = (string)($matchRow['home_name'] ?? '');
        $awayName = (string)($matchRow['away_name'] ?? '');

        return [
            'match_id' => $matchId,
            'label' => $homeName . ' vs ' . $awayName,
            'home_name' => $homeName,
            'away_name' => $awayName,
            'kickoff_at' => (string)($matchRow['kickoff_at'] ?? ''),
            'kickoff_date' => substr((string)($matchRow['kickoff_at'] ?? ''), 0, 10),
            'status' => $status,
            'stage' => (string)($matchRow['stage'] ?? ''),
            'group_code' => (string)($matchRow['group_code'] ?? ''),
            'home_score' => (int)($matchRow['home_score'] ?? 0),
            'away_score' => (int)($matchRow['away_score'] ?? 0),
            'has_bet' => $hasBet,
            'is_finished' => $finished,
            'is_settled' => $isSettled,
            'columns' => $columns,
            'total' => $total,
            'search_text' => strtolower(implode(' ', array_filter([
                $homeName,
                $awayName,
                (string)($matchRow['home_code'] ?? ''),
                (string)($matchRow['away_code'] ?? ''),
                (string)($matchRow['stage'] ?? ''),
                (string)($matchRow['group_code'] ?? ''),
            ]))),
        ];
    }

    /** @param array<string, mixed> $pred @param array<string, mixed> $matchRow */
    private static function predictionPickLabel(array $pred, array $matchRow): string
    {
        $type = (string)($pred['pred_type'] ?? '');
        return match ($type) {
            'exact' => (int)($pred['pred_home'] ?? 0) . ' : ' . (int)($pred['pred_away'] ?? 0),
            'outcome' => \App\Models\Prediction::outcomeLabel((string)($pred['pred_outcome'] ?? ''), $matchRow),
            'advance' => \App\Models\Prediction::advanceLabel($pred),
            default => '—',
        };
    }
}
