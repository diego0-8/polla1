<?php
declare(strict_types=1);

/**
 * Diagnóstico: Ecuador vs Curaçao — por qué no se aplicaron puntos en la tabla de posiciones.
 *
 * Uso: php scripts/diagnose_ecuador_curacao.php [--fix]
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Config\AppConfig;
use App\Core\DB;
use App\Models\MatchEvent;
use App\Models\MatchModel;
use App\Models\MatchStats;
use App\Models\ManualMatchUpdate;
use App\Services\FootballDataClient;
use App\Services\FootballDataSyncService;
use App\Services\ScoringService;
use App\Services\SettleService;

AppConfig::boot();

$doFix = in_array('--fix', $argv ?? [], true);

function line(string $msg): void
{
    echo $msg . PHP_EOL;
}

function section(string $title): void
{
    echo PHP_EOL . str_repeat('=', 60) . PHP_EOL . $title . PHP_EOL . str_repeat('=', 60) . PHP_EOL;
}

section('Conexión a base de datos polla');
try {
    $pdo = DB::pdo();
    line('OK — conectado a MySQL');
} catch (Throwable $e) {
    line('ERROR: ' . $e->getMessage());
    line('Inicia MySQL en XAMPP y vuelve a ejecutar el script.');
    exit(1);
}

section('1. Partido Ecuador vs Curaçao en matches');
$st = $pdo->query(
    "SELECT m.id, m.api_fixture_id, m.status, m.home_score, m.away_score,
            m.regular_home_score, m.regular_away_score, m.kickoff_at, m.last_synced_at,
            th.name AS home_name, ta.name AS away_name
     FROM matches m
     JOIN teams th ON th.id = m.home_team_id
     JOIN teams ta ON ta.id = m.away_team_id
     WHERE (th.name LIKE '%Ecuador%' AND ta.name LIKE '%Cura%')
        OR m.id = 163
     ORDER BY m.kickoff_at DESC"
);
$matchRows = $st->fetchAll() ?: [];
if ($matchRows === []) {
    line('No se encontró el partido Ecuador vs Curaçao.');
    exit(1);
}

$match = $matchRows[0];
$matchId = (int)$match['id'];
foreach ($matchRows as $r) {
    line(sprintf(
        '  id=%d | %s vs %s | status=%s | marcador=%d:%d | kickoff=%s | last_sync=%s',
        (int)$r['id'],
        $r['home_name'],
        $r['away_name'],
        $r['status'],
        (int)$r['home_score'],
        (int)$r['away_score'],
        $r['kickoff_at'],
        $r['last_synced_at'] ?? 'NULL',
    ));
}

$status = strtoupper((string)$match['status']);
$realHome = (int)($match['regular_home_score'] ?? $match['home_score'] ?? 0);
$realAway = (int)($match['regular_away_score'] ?? $match['away_score'] ?? 0);
$totalGoals = $realHome + $realAway;
$realOutcome = ScoringService::realOutcome($realHome, $realAway);
$bttsActual = $totalGoals > 0 && $realHome > 0 && $realAway > 0;

line('');
line('Resultado interpretado por el sistema:');
line('  Goles totales: ' . $totalGoals);
line('  Outcome real (Gana/Empata): ' . ($realOutcome === 'D' ? 'Empate (D)' : ($realOutcome === 'H' ? 'Local (H)' : 'Visitante (A)')));
line('  Ambos marcan (BTTS): ' . ($bttsActual ? 'Sí' : 'No'));

$finishedStatuses = ['FT', 'PEN', 'AET'];
$isFinished = in_array($status, $finishedStatuses, true);

section('2. ¿Por qué no liquida SettleService?');
if (!$isFinished) {
    line('PROBLEMA PRINCIPAL: status = "' . $status . '" — NO está finalizado en BD.');
    line('SettleService solo liquida partidos con status FT, PEN o AET.');
    line('Mientras siga NS/LIVE, NO se insertan filas en points_ledger → la tabla de posiciones no cambia.');
} else {
    line('OK: status = ' . $status . ' — el partido SÍ está marcado como finalizado.');
}

$manual = ManualMatchUpdate::forMatch($matchId);
if ($manual !== null) {
    line('Override manual encontrado: ' . (int)$manual['home_score'] . ':' . (int)$manual['away_score'] . ' status=' . $manual['status']);
} else {
    line('Sin override manual en manual_match_updates.');
}

section('3. Pronósticos de usuarios (predictions)');
$st = $pdo->prepare(
    "SELECT p.id, p.user_id, u.name, p.pred_type, p.pred_home, p.pred_away, p.pred_outcome
     FROM predictions p
     JOIN users u ON u.id = p.user_id
     WHERE p.match_id = ?
     ORDER BY u.name, p.pred_type"
);
$st->execute([$matchId]);
$predictions = $st->fetchAll() ?: [];
line('Total pronósticos: ' . count($predictions));

foreach ($predictions as $p) {
    $predType = (string)$p['pred_type'];
    $expected = ScoringService::points(
        $predType,
        (int)$p['pred_home'],
        (int)$p['pred_away'],
        $p['pred_outcome'] !== null ? (string)$p['pred_outcome'] : null,
        $realHome,
        $realAway,
    );
    $outcomeLabel = match ((string)($p['pred_outcome'] ?? '')) {
        'D' => 'Empate',
        'H' => 'Gana local',
        'A' => 'Gana visitante',
        default => '—',
    };
    line(sprintf(
        '  [%s] %s | %s | pred=%s:%s outcome=%s → esperado %d pts (%s)',
        $predType,
        $p['name'],
        $p['id'],
        $p['pred_home'],
        $p['pred_away'],
        $outcomeLabel,
        $expected['points'],
        $expected['reason'],
    ));
}

section('4. Props (ambos marcan, goles, etc.)');
$st = $pdo->prepare(
    "SELECT pp.id, pp.user_id, u.name, pp.market, pp.line, pp.pick
     FROM prop_predictions pp
     JOIN users u ON u.id = pp.user_id
     WHERE pp.match_id = ?
     ORDER BY u.name, pp.market"
);
$st->execute([$matchId]);
$props = $st->fetchAll() ?: [];
$statsRow = MatchStats::forSettlement($matchId);
$stats = [
    'total_corners' => $statsRow['total_corners'] ?? null,
    'total_cards' => $statsRow['total_cards'] ?? null,
    'total_goals' => $statsRow['total_goals'] ?? $totalGoals,
    'btts' => $statsRow !== null ? (bool)(int)($statsRow['btts'] ?? 0) : $bttsActual,
];

line('match_stats: ' . ($statsRow !== null ? 'presente' : 'AUSENTE'));
if ($statsRow !== null) {
    line('  btts=' . ($stats['btts'] ? 'true' : 'false') . ' total_goals=' . ($stats['total_goals'] ?? 'null'));
}

foreach ($props as $pp) {
    $market = (string)$pp['market'];
    $expected = ScoringService::propPoints(
        $market,
        $pp['line'] !== null ? (float)$pp['line'] : null,
        (string)$pp['pick'],
        $stats,
    );
    $pickLabel = $market === 'btts'
        ? ((string)$pp['pick'] === 'yes' ? 'Sí' : 'No')
        : (string)$pp['pick'] . ' ' . ($pp['line'] ?? '');
    line(sprintf(
        '  %s | %s | %s → esperado %d pts (%s)',
        $pp['name'],
        $market,
        $pickLabel,
        $expected['points'],
        $expected['reason'],
    ));
}

section('5. points_ledger (lo que ya se liquidó)');
$st = $pdo->prepare(
    "SELECT pl.id, pl.user_id, u.name, pl.points, pl.reason, pl.prediction_id, pl.prop_prediction_id, pl.created_at
     FROM points_ledger pl
     JOIN users u ON u.id = pl.user_id
     WHERE pl.match_id = ?
     ORDER BY u.name, pl.id"
);
$st->execute([$matchId]);
$ledger = $st->fetchAll() ?: [];
if ($ledger === []) {
    line('VACÍO — ningún punto liquidado para este partido.');
    if (!$isFinished) {
        line('Causa: partido no finalizado en BD (status=' . $status . ').');
    } else {
        line('Causa posible: SettleService no se ejecutó tras marcar FT, o no había pronósticos.');
    }
} else {
    line('Entradas en ledger: ' . count($ledger));
    foreach ($ledger as $row) {
        line(sprintf(
            '  %s | +%d | %s | pred_id=%s prop_id=%s | %s',
            $row['name'],
            (int)$row['points'],
            $row['reason'],
            $row['prediction_id'] ?? '—',
            $row['prop_prediction_id'] ?? '—',
            $row['created_at'],
        ));
    }
}

section('6. user_points (tabla de posiciones)');
$st = $pdo->query(
    "SELECT u.name, COALESCE(up.points_total, 0) AS pts
     FROM users u
     LEFT JOIN user_points up ON up.user_id = u.id
     WHERE u.status = 'active'
     ORDER BY pts DESC, u.name
     LIMIT 15"
);
foreach ($st->fetchAll() ?: [] as $row) {
    line('  ' . $row['name'] . ': ' . (int)$row['pts'] . ' pts');
}

section('7. Resumen y acción');
if (!$isFinished) {
    line('El partido terminó 0-0 en la realidad pero en BD sigue como "' . $status . '".');
    line('La lógica de puntos es correcta para 0-0:');
    line('  • Empate (outcome D) → 3 pts si pronosticaste empate');
    line('  • Exacto 0-0 → 5 pts');
    line('  • BTTS No → 2 pts si apostaste "no" (ninguno marcó)');
    line('');
    line('Solución: sincronizar FT 0-0 desde API o admin manual, luego liquidar.');

    if ($doFix) {
        line('');
        line('Ejecutando --fix: sync API + liquidación...');
        try {
            $cfg = require dirname(__DIR__) . '/app/Config/app.php';
            $fd = $cfg['football_data'];
            $apiId = (int)$match['api_fixture_id'];
            if ($apiId > 0 && ($fd['token'] ?? '') !== '') {
                $api = new FootballDataClient(
                    (string)$fd['base_url'],
                    (string)$fd['token'],
                    (int)($fd['request_soft_limit_per_minute'] ?? 9),
                );
                $sync = new FootballDataSyncService(
                    $api,
                    (string)($fd['competition_code'] ?? 'WC'),
                    (int)($fd['season'] ?? 2026),
                    (int)($fd['season_fallback'] ?? 2022),
                    8, 8, 9,
                );
                $sync->syncMatchEvents($apiId, $matchId);
                line('Sync API completada.');
            } else {
                line('Sin token API — actualizando manualmente a FT 0-0...');
                $pdo->prepare(
                    "UPDATE matches SET status='FT', home_score=0, away_score=0,
                     regular_home_score=0, regular_away_score=0, winner_team_id=NULL, last_synced_at=NOW()
                     WHERE id=?"
                )->execute([$matchId]);
            }

            if ($statsRow === null) {
                $pdo->prepare(
                    "INSERT INTO match_stats (match_id, total_goals, btts, stats_source, updated_at)
                     VALUES (?, 0, 0, 'manual', NOW())
                     ON DUPLICATE KEY UPDATE total_goals=0, btts=0, updated_at=NOW()"
                )->execute([$matchId]);
                line('Stats 0-0 insertadas (btts=0).');
            }

            $result = SettleService::settleFinishedMatches();
            line('Liquidación: ' . json_encode($result, JSON_UNESCAPED_UNICODE));

            $match = MatchModel::findById($matchId) ?? $match;
            line('Estado final: status=' . $match['status'] . ' marcador=' . $match['home_score'] . ':' . $match['away_score']);
        } catch (Throwable $e) {
            line('ERROR en --fix: ' . $e->getMessage());
            exit(1);
        }
    } else {
        line('Para corregir automáticamente: php scripts/diagnose_ecuador_curacao.php --fix');
    }
} else {
    $unsettled = count($predictions) + count($props) - count($ledger);
    if ($ledger === [] && ($predictions !== [] || $props !== [])) {
        line('Partido FT pero sin ledger — ejecuta: php scripts/validate_sync.php --settle');
        if ($doFix) {
            $result = SettleService::settleFinishedMatches();
            line('Liquidación: ' . json_encode($result, JSON_UNESCAPED_UNICODE));
        }
    } else {
        line('Partido finalizado y puntos procesados según ledger arriba.');
    }
}

line('');
