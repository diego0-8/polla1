<?php
declare(strict_types=1);

/**
 * Verifica el flujo completo: predicciones (show.php) → resultado real → liquidación → leaderboard.
 *
 * Uso:
 *   php scripts/verify_prediction_flow.php
 *   php scripts/verify_prediction_flow.php --verbose
 *   php scripts/verify_prediction_flow.php --match-id=129
 *   php scripts/verify_prediction_flow.php --settle
 *   php scripts/verify_prediction_flow.php --compare-api
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Config\AppConfig;
use App\Core\DB;
use App\Models\ManualMatchUpdate;
use App\Models\MatchEvent;
use App\Models\MatchModel;
use App\Models\MatchStats;
use App\Models\PropPrediction;
use App\Models\Prediction;
use App\Models\User;
use App\Services\FootballDataClient;
use App\Services\MatchDataMapper;
use App\Services\ScoringService;
use App\Services\SettleService;

AppConfig::boot();

$opts = getopt('', ['verbose', 'match-id:', 'settle', 'compare-api', 'help']);

if (isset($opts['help'])) {
    echo <<<HELP
Verificación del flujo de predicciones → puntos → leaderboard

  php scripts/verify_prediction_flow.php              Resumen
  php scripts/verify_prediction_flow.php --verbose    Detalle por pronóstico
  php scripts/verify_prediction_flow.php --match-id=129   Solo un partido
  php scripts/verify_prediction_flow.php --settle     Liquida antes de verificar
  php scripts/verify_prediction_flow.php --compare-api  Compara BD vs Football-Data.org

HELP;
    exit(0);
}

$verbose = isset($opts['verbose']);
$matchFilter = isset($opts['match-id']) ? (int)$opts['match-id'] : 0;
$compareApi = isset($opts['compare-api']);

$cfg = require dirname(__DIR__) . '/app/Config/app.php';
$season = (int)($cfg['football_data']['season'] ?? 2026);

$errors = [];
$warnings = [];
$ok = [];

function out(string $msg, bool $verboseOnly = false): void
{
    global $verbose;
    if (!$verboseOnly || $verbose) {
        echo $msg . PHP_EOL;
    }
}

function section(string $title): void
{
    echo PHP_EOL . '=== ' . $title . ' ===' . PHP_EOL;
}

/** @return array{real_home:int,real_away:int,match_status:string,source:string} */
function resolveMatchResult(int $matchId, array $row): array
{
    $match = [
        'id' => $matchId,
        'status' => (string)($row['status'] ?? 'NS'),
        'home_score' => (int)($row['home_score'] ?? 0),
        'away_score' => (int)($row['away_score'] ?? 0),
        'home_team_id' => (int)($row['home_team_id'] ?? 0),
        'away_team_id' => (int)($row['away_team_id'] ?? 0),
        'winner_team_id' => $row['winner_team_id'] ?? null,
    ];

    $rawHome = (int)($row['regular_home_score'] ?? $row['home_score'] ?? 0);
    $rawAway = (int)($row['regular_away_score'] ?? $row['away_score'] ?? 0);

    $resolved = ManualMatchUpdate::applyToMatch([
        ...$match,
        'home_score' => $rawHome,
        'away_score' => $rawAway,
    ], MatchEvent::forMatch($matchId));

    $source = 'bd';
    if (($resolved['data_source'] ?? 'api') === 'manual') {
        $source = 'manual';
    } elseif ($rawHome !== (int)$resolved['home_score'] || $rawAway !== (int)$resolved['away_score']) {
        $source = 'manual+api';
    }

    return [
        'real_home' => (int)$resolved['home_score'],
        'real_away' => (int)$resolved['away_score'],
        'match_status' => (string)$resolved['status'],
        'source' => $source,
    ];
}

section('Flujo del sistema');
out('1. show.php → POST /predictions y /prop-predictions (cierra 5 min antes del kickoff)');
out('2. Partido finaliza (FT/PEN/AET) → resultado en matches (+ fallback manual si API incompleta)');
out('3. SettleService → points_ledger (exact, outcome, advance, props, campeón)');
out('4. user_points recalculado → leaderboard/index.php');

if (isset($opts['settle'])) {
    section('Liquidación');
    $result = SettleService::settleFinishedMatches();
    out('Resultado: ' . json_encode($result, JSON_UNESCAPED_UNICODE));
    $ok[] = 'Liquidación ejecutada';
}

section('Partidos finalizados — resultado usado para puntos');
$pdo = DB::pdo();
$sql = "SELECT m.*, th.name AS home_name, ta.name AS away_name
        FROM matches m
        INNER JOIN teams th ON th.id = m.home_team_id
        INNER JOIN teams ta ON ta.id = m.away_team_id
        WHERE YEAR(m.kickoff_at) = :season AND m.status IN ('FT','PEN','AET')";
$params = ['season' => $season];
if ($matchFilter > 0) {
    $sql .= ' AND m.id = :match_id';
    $params['match_id'] = $matchFilter;
}
$sql .= ' ORDER BY m.kickoff_at ASC';

$st = $pdo->prepare($sql);
$st->execute($params);
$finishedMatches = $st->fetchAll() ?: [];

if ($finishedMatches === []) {
    $warnings[] = 'No hay partidos finalizados en la temporada ' . $season;
    out('Sin partidos FT/PEN/AET.');
} else {
    out('Partidos finalizados: ' . count($finishedMatches));
}

$apiClient = null;
if ($compareApi) {
    try {
        $fd = $cfg['football_data'];
        $apiClient = new FootballDataClient(
            (string)$fd['base_url'],
            (string)$fd['token'],
            (int)($fd['request_soft_limit_per_minute'] ?? 9),
        );
        $ok[] = 'API disponible para comparación';
    } catch (Throwable $e) {
        $warnings[] = 'No se pudo conectar a la API: ' . $e->getMessage();
        $compareApi = false;
    }
}

foreach ($finishedMatches as $m) {
    $matchId = (int)$m['id'];
    $resolved = resolveMatchResult($matchId, $m);
    $bdScore = (int)($m['regular_home_score'] ?? $m['home_score']) . ':' . (int)($m['regular_away_score'] ?? $m['away_score']);

    out(sprintf(
        '#%d %s vs %s | BD=%s %s | Liquidación=%d:%d (fuente: %s)',
        $matchId,
        $m['home_name'],
        $m['away_name'],
        $bdScore,
        $m['status'],
        $resolved['real_home'],
        $resolved['real_away'],
        $resolved['source'],
    ));

    if ($compareApi && $apiClient !== null) {
        $apiId = (int)($m['api_fixture_id'] ?? 0);
        if ($apiId > 0) {
            try {
                $detail = $apiClient->get('/matches/' . $apiId);
                $apiScores = MatchDataMapper::scores($detail);
                $apiStatus = MatchDataMapper::mapStatus((string)($detail['status'] ?? 'NS'));
                $apiLine = $apiScores['home'] . ':' . $apiScores['away'] . ' ' . $apiStatus;
                out('  API detalle: ' . $apiLine, true);

                if ($apiScores['home'] !== $resolved['real_home'] || $apiScores['away'] !== $resolved['real_away']) {
                    if ($resolved['source'] === 'manual') {
                        $warnings[] = "#{$matchId}: API ({$apiLine}) difiere; se usa fallback manual {$resolved['real_home']}:{$resolved['real_away']}";
                    } else {
                        $errors[] = "#{$matchId}: BD liquidación ({$resolved['real_home']}:{$resolved['real_away']}) ≠ API detalle ({$apiLine})";
                    }
                }
            } catch (Throwable $e) {
                $warnings[] = "#{$matchId}: error API — " . $e->getMessage();
            }
        }
    }
}

section('Pronósticos clásicos (exact / outcome / advance)');
$predSql = "SELECT p.*, u.name AS user_name,
                   m.status AS match_status, m.home_team_id, m.away_team_id, m.winner_team_id,
                   COALESCE(m.regular_home_score, m.home_score) AS db_home,
                   COALESCE(m.regular_away_score, m.away_score) AS db_away,
                   th.name AS home_name, ta.name AS away_name,
                   pl.points AS ledger_points, pl.reason AS ledger_reason, pl.id AS ledger_id
            FROM predictions p
            INNER JOIN matches m ON m.id = p.match_id
            INNER JOIN users u ON u.id = p.user_id
            LEFT JOIN teams th ON th.id = m.home_team_id
            LEFT JOIN teams ta ON ta.id = m.away_team_id
            LEFT JOIN points_ledger pl ON pl.prediction_id = p.id
            WHERE YEAR(m.kickoff_at) = :season";
$predParams = ['season' => $season];
if ($matchFilter > 0) {
    $predSql .= ' AND m.id = :match_id';
    $predParams['match_id'] = $matchFilter;
}
$predSql .= " ORDER BY m.kickoff_at, u.name, p.pred_type";

$predSt = $pdo->prepare($predSql);
$predSt->execute($predParams);
$predictions = $predSt->fetchAll() ?: [];

$predChecked = 0;
$predUnsettled = 0;
$predOk = 0;

foreach ($predictions as $p) {
    $matchId = (int)$p['match_id'];
    $status = strtoupper((string)$p['match_status']);
    $isFinished = in_array($status, ['FT', 'PEN', 'AET'], true);

    $resolved = resolveMatchResult($matchId, [
        'status' => $p['match_status'],
        'home_score' => $p['db_home'],
        'away_score' => $p['db_away'],
        'regular_home_score' => $p['db_home'],
        'regular_away_score' => $p['db_away'],
        'home_team_id' => $p['home_team_id'],
        'away_team_id' => $p['away_team_id'],
        'winner_team_id' => $p['winner_team_id'],
    ]);

    $predType = (string)$p['pred_type'];
    $label = match ($predType) {
        'exact' => sprintf('Exacto %d:%d', (int)$p['pred_home'], (int)$p['pred_away']),
        'outcome' => 'Tendencia ' . Prediction::outcomeLabel((string)($p['pred_outcome'] ?? ''), $p),
        'advance' => 'Avanza #' . (int)($p['advances_team_id'] ?? 0),
        default => $predType,
    };

    if (!$isFinished) {
        out("  [ABIERTO] pred #{$p['id']} {$p['user_name']} — {$label} (partido {$status})", true);
        continue;
    }

    if ($predType === 'advance') {
        $winnerId = $p['winner_team_id'] !== null
            ? (int)$p['winner_team_id']
            : ($resolved['real_home'] > $resolved['real_away']
                ? (int)$p['home_team_id']
                : ($resolved['real_away'] > $resolved['real_home'] ? (int)$p['away_team_id'] : null));
        $expected = ScoringService::advancerPoints(
            isset($p['advances_team_id']) ? (int)$p['advances_team_id'] : null,
            $winnerId,
        );
    } else {
        $expected = ScoringService::points(
            $predType,
            (int)$p['pred_home'],
            (int)$p['pred_away'],
            isset($p['pred_outcome']) ? (string)$p['pred_outcome'] : null,
            $resolved['real_home'],
            $resolved['real_away'],
        );
    }

    $ledgerPoints = $p['ledger_points'] !== null ? (int)$p['ledger_points'] : null;
    $realLine = $resolved['real_home'] . ':' . $resolved['real_away'];
    $ptsLabel = $expected['points'] . ' pts (' . $expected['reason'] . ')';

    if ($ledgerPoints === null) {
        $predUnsettled++;
        $warnings[] = "Sin liquidar: pred #{$p['id']} {$p['user_name']} — {$label} vs real {$realLine}";
        out("  [PENDIENTE] #{$p['id']} {$p['user_name']} | {$label} | real {$realLine} | esperado {$ptsLabel}", $verbose);
        continue;
    }

    $predChecked++;
    $matchOk = $ledgerPoints === $expected['points'] && (string)$p['ledger_reason'] === $expected['reason'];

    if ($matchOk) {
        $predOk++;
        out("  [OK] #{$p['id']} {$p['user_name']} | {$label} | real {$realLine} | ledger {$ledgerPoints}/{$p['ledger_reason']}", $verbose);
    } else {
        $errors[] = sprintf(
            'Pred #%d (%s): ledger %d/%s vs esperado %d/%s (real %s)',
            (int)$p['id'],
            (string)$p['user_name'],
            $ledgerPoints,
            (string)$p['ledger_reason'],
            $expected['points'],
            $expected['reason'],
            $realLine,
        );
        out("  [ERROR] #{$p['id']} {$p['user_name']} | {$label} | real {$realLine} | ledger {$ledgerPoints}/{$p['ledger_reason']} | esperado {$ptsLabel}", true);
    }
}

out("Clásicos: {$predOk} OK, {$predUnsettled} sin liquidar, " . ($predChecked - $predOk) . ' inconsistentes');

section('Pronósticos especiales (props)');
$propSql = "SELECT pp.*, u.name AS user_name, m.status AS match_status,
                   pl.points AS ledger_points, pl.reason AS ledger_reason
            FROM prop_predictions pp
            INNER JOIN matches m ON m.id = pp.match_id
            INNER JOIN users u ON u.id = pp.user_id
            LEFT JOIN points_ledger pl ON pl.prop_prediction_id = pp.id
            WHERE YEAR(m.kickoff_at) = :season";
$propParams = ['season' => $season];
if ($matchFilter > 0) {
    $propSql .= ' AND m.id = :match_id';
    $propParams['match_id'] = $matchFilter;
}
$propSql .= ' ORDER BY m.kickoff_at, u.name, pp.market';

$propSt = $pdo->prepare($propSql);
$propSt->execute($propParams);
$props = $propSt->fetchAll() ?: [];

$propOk = 0;
$propUnsettled = 0;
$propPendingStats = 0;

foreach ($props as $pp) {
    $matchId = (int)$pp['match_id'];
    $market = (string)$pp['market'];
    $status = strtoupper((string)$pp['match_status']);
    $isFinished = in_array($status, ['FT', 'PEN', 'AET'], true);
    $label = PropPrediction::label($pp);

    if (!$isFinished) {
        out("  [ABIERTO] prop #{$pp['id']} {$pp['user_name']} — {$label}", true);
        continue;
    }

    $statsRow = MatchStats::forMatch($matchId);
    if (!MatchStats::hasStatsForMarket($statsRow, $market)) {
        $propPendingStats++;
        $warnings[] = "Prop #{$pp['id']} ({$market}): partido finalizado pero sin stats para liquidar";
        out("  [SIN STATS] prop #{$pp['id']} {$pp['user_name']} — {$label}", $verbose);
        continue;
    }

    $stats = [
        'total_corners' => $statsRow['total_corners'] ?? null,
        'total_cards' => $statsRow['total_cards'] ?? null,
        'total_goals' => $statsRow['total_goals'] ?? null,
        'btts' => (bool)($statsRow['btts'] ?? false),
    ];
    $line = $pp['line'] !== null ? (float)$pp['line'] : null;
    $expected = ScoringService::propPoints($market, $line, (string)$pp['pick'], $stats);
    $ledgerPoints = $pp['ledger_points'] !== null ? (int)$pp['ledger_points'] : null;

    if ($ledgerPoints === null) {
        $propUnsettled++;
        $warnings[] = "Prop sin liquidar: #{$pp['id']} {$pp['user_name']} — {$label}";
        continue;
    }

    if ($ledgerPoints === $expected['points'] && (string)$pp['ledger_reason'] === $expected['reason']) {
        $propOk++;
    } else {
        $errors[] = sprintf(
            'Prop #%d (%s): ledger %d/%s vs esperado %d/%s',
            (int)$pp['id'],
            (string)$pp['user_name'],
            $ledgerPoints,
            (string)$pp['ledger_reason'],
            $expected['points'],
            $expected['reason'],
        );
    }
}

out("Props: {$propOk} OK, {$propUnsettled} sin liquidar, {$propPendingStats} esperando stats");

section('Leaderboard (user_points vs points_ledger)');
$lbSt = $pdo->query(
    "SELECT u.id, u.name, COALESCE(up.points_total, 0) AS points_total,
            COALESCE(SUM(pl.points), 0) AS ledger_sum,
            SUM(CASE WHEN pl.reason = 'exact_score' AND pl.points > 0 THEN 1 ELSE 0 END) AS exact_hits,
            SUM(CASE WHEN pl.reason IN ('correct_winner','correct_draw','correct_advancer') AND pl.points > 0 THEN 1 ELSE 0 END) AS trend_hits,
            SUM(CASE WHEN pl.reason IN ('correct_btts','correct_goals_ou','correct_corners_ou','correct_cards_ou') AND pl.points > 0 THEN 1 ELSE 0 END) AS prop_hits
     FROM users u
     INNER JOIN user_roles ur ON ur.user_id = u.id
     INNER JOIN roles r ON r.id = ur.role_id AND r.name = 'asesor'
     LEFT JOIN user_points up ON up.user_id = u.id
     LEFT JOIN points_ledger pl ON pl.user_id = u.id
     WHERE u.status = 'active'
     GROUP BY u.id, u.name, up.points_total
     ORDER BY points_total DESC, exact_hits DESC"
);

$lbRows = $lbSt->fetchAll() ?: [];
if ($lbRows === []) {
    $warnings[] = 'No hay asesores activos en leaderboard';
}

foreach ($lbRows as $row) {
    $total = (int)$row['points_total'];
    $sum = (int)$row['ledger_sum'];
    $line = sprintf(
        '%s | total=%d | exactos=%d tendencias=%d props=%d',
        $row['name'],
        $total,
        (int)$row['exact_hits'],
        (int)$row['trend_hits'],
        (int)$row['prop_hits'],
    );

    if ($total !== $sum) {
        $errors[] = "Leaderboard {$row['name']}: user_points={$total} ≠ sum(ledger)={$sum}";
        out('  [ERROR] ' . $line . " | ledger_sum={$sum}", true);
    } elseif ($total > 0) {
        out('  [OK] ' . $line, $verbose);
        $ok[] = "Leaderboard coherente: {$row['name']} ({$total} pts)";
    } else {
        out('  [—] ' . $line, $verbose);
    }
}

$suspendedSt = $pdo->query(
    "SELECT u.name, COALESCE(up.points_total, 0) AS pts
     FROM users u
     INNER JOIN user_roles ur ON ur.user_id = u.id
     INNER JOIN roles r ON r.id = ur.role_id AND r.name = 'asesor'
     LEFT JOIN user_points up ON up.user_id = u.id
     WHERE u.status <> 'active' AND COALESCE(up.points_total, 0) > 0"
);
foreach ($suspendedSt->fetchAll() ?: [] as $s) {
    $warnings[] = "Asesor suspendido con puntos (no aparece en leaderboard): {$s['name']} ({$s['pts']} pts)";
    out("  [SUSPENDIDO] {$s['name']} tiene {$s['pts']} pts pero status ≠ active", true);
}

section('Resumen del flujo');
$flowSteps = [
    'Guardado en show.php' => count($predictions) + count($props) > 0,
    'Partidos finalizados con marcador' => count($finishedMatches) > 0,
    'Liquidación en points_ledger' => $predChecked + $propOk + $propUnsettled > 0,
    'Totales en user_points' => array_sum(array_column($lbRows, 'points_total')) > 0,
];

foreach ($flowSteps as $step => $passed) {
    out(($passed ? '✓' : '○') . ' ' . $step);
    if (!$passed && $step !== 'Guardado en show.php') {
        $warnings[] = 'Paso incompleto: ' . $step;
    }
}

section('Resultado final');
echo 'OK (' . count($ok) . ')' . PHP_EOL;
foreach ($ok as $msg) {
    echo '  ✓ ' . $msg . PHP_EOL;
}
if ($warnings !== []) {
    echo PHP_EOL . 'Advertencias (' . count($warnings) . '):' . PHP_EOL;
    foreach ($warnings as $msg) {
        echo '  ! ' . $msg . PHP_EOL;
    }
}
if ($errors !== []) {
    echo PHP_EOL . 'Errores (' . count($errors) . '):' . PHP_EOL;
    foreach ($errors as $msg) {
        echo '  ✗ ' . $msg . PHP_EOL;
    }
}

echo PHP_EOL;
if ($errors === []) {
    echo $warnings === [] ? "Flujo de predicciones correcto.\n" : "Flujo correcto con advertencias.\n";
    exit(0);
}

echo "Flujo con errores — revisa liquidación, marcador o stats.\n";
exit(1);
