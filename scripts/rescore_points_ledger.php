<?php
declare(strict_types=1);

/**
 * Recalcula puntos en points_ledger según config actual y actualiza user_points.
 *
 * Uso:
 *   php scripts/rescore_points_ledger.php
 *   php scripts/rescore_points_ledger.php --dry-run
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Config\AppConfig;
use App\Core\DB;
use App\Models\MatchStats;
use App\Models\PropPrediction;
use App\Services\ScoringService;
use App\Services\SettleService;

AppConfig::boot();

$dryRun = in_array('--dry-run', $argv ?? [], true);
$pdo = DB::pdo();
$updated = 0;
$unchanged = 0;
$errors = 0;

function out(string $msg): void
{
    echo $msg . PHP_EOL;
}

out($dryRun ? '=== Re-score (simulación) ===' : '=== Re-score points_ledger ===');

$predSt = $pdo->query(
    "SELECT pl.id AS ledger_id, pl.points AS old_points, pl.reason AS old_reason,
            p.id AS prediction_id, p.pred_type, p.pred_home, p.pred_away, p.pred_outcome,
            p.advances_team_id, p.match_id,
            COALESCE(m.regular_home_score, m.home_score) AS real_home,
            COALESCE(m.regular_away_score, m.away_score) AS real_away,
            m.winner_team_id, m.status
     FROM points_ledger pl
     INNER JOIN predictions p ON p.id = pl.prediction_id
     INNER JOIN matches m ON m.id = p.match_id
     WHERE pl.prediction_id IS NOT NULL"
);

$updatePred = $pdo->prepare(
    'UPDATE points_ledger SET points = :points, reason = :reason WHERE id = :id'
);

foreach ($predSt->fetchAll() ?: [] as $row) {
    $predType = (string)$row['pred_type'];
    if ($predType === 'advance') {
        $winnerTeamId = $row['winner_team_id'] !== null ? (int)$row['winner_team_id'] : null;
        $result = ScoringService::advancerPoints(
            isset($row['advances_team_id']) ? (int)$row['advances_team_id'] : null,
            $winnerTeamId,
        );
    } else {
        $result = ScoringService::points(
            $predType,
            (int)$row['pred_home'],
            (int)$row['pred_away'],
            isset($row['pred_outcome']) ? (string)$row['pred_outcome'] : null,
            (int)$row['real_home'],
            (int)$row['real_away'],
        );
    }

    $oldPts = (int)$row['old_points'];
    $newPts = (int)$result['points'];
    $oldReason = (string)$row['old_reason'];
    $newReason = (string)$result['reason'];

    if ($oldPts === $newPts && $oldReason === $newReason) {
        $unchanged++;
        continue;
    }

    if ($dryRun) {
        out("Pred #{$row['prediction_id']}: {$oldPts}/{$oldReason} → {$newPts}/{$newReason}");
    } else {
        $updatePred->execute([
            'points' => $newPts,
            'reason' => $newReason,
            'id' => (int)$row['ledger_id'],
        ]);
    }
    $updated++;
}

$propSt = $pdo->query(
    "SELECT pl.id AS ledger_id, pl.points AS old_points, pl.reason AS old_reason,
            pp.id AS prop_prediction_id, pp.match_id, pp.market, pp.line, pp.pick
     FROM points_ledger pl
     INNER JOIN prop_predictions pp ON pp.id = pl.prop_prediction_id
     WHERE pl.prop_prediction_id IS NOT NULL"
);

$updateProp = $pdo->prepare(
    'UPDATE points_ledger SET points = :points, reason = :reason WHERE id = :id'
);

foreach ($propSt->fetchAll() ?: [] as $row) {
    $matchId = (int)$row['match_id'];
    $market = (string)$row['market'];
    $statsRow = MatchStats::forMatch($matchId);
    if (!MatchStats::hasStatsForMarket($statsRow, $market)) {
        $errors++;
        continue;
    }

    $line = $row['line'] !== null ? (float)$row['line'] : null;
    $stats = [
        'total_corners' => $statsRow['total_corners'] ?? null,
        'total_cards' => $statsRow['total_cards'] ?? null,
        'total_goals' => $statsRow['total_goals'] ?? null,
        'btts' => (bool)($statsRow['btts'] ?? false),
    ];

    $result = ScoringService::propPoints($market, $line, (string)$row['pick'], $stats);
    $oldPts = (int)$row['old_points'];
    $newPts = (int)$result['points'];
    $oldReason = (string)$row['old_reason'];
    $newReason = (string)$result['reason'];

    if ($oldPts === $newPts && $oldReason === $newReason) {
        $unchanged++;
        continue;
    }

    if ($dryRun) {
        out("Prop #{$row['prop_prediction_id']} ({$market}): {$oldPts}/{$oldReason} → {$newPts}/{$newReason}");
    } else {
        $updateProp->execute([
            'points' => $newPts,
            'reason' => $newReason,
            'id' => (int)$row['ledger_id'],
        ]);
    }
    $updated++;
}

if (!$dryRun) {
    $recalculated = SettleService::recalculateUserTotals();
    out("Filas ledger actualizadas: {$updated} · sin cambio: {$unchanged} · props sin stats: {$errors}");
    out("user_points recalculados: {$recalculated}");
} else {
    out("Cambiarían {$updated} filas · sin cambio: {$unchanged} · props sin stats: {$errors}");
}

PropPrediction::assertUniqueOuMatrices();
out('Matrices O/U OK');
