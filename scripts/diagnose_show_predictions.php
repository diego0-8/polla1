<?php
declare(strict_types=1);

/**
 * Diagnóstico detallado: cada dato guardado desde matches/show.php
 * y por qué sí o no se refleja en leaderboard/index.php.
 *
 * Cubre los mismos formularios que show.php:
 *   - Marcador exacto (pred_type=exact)
 *   - Ganador/empate (pred_type=outcome) o Quién avanza (pred_type=advance)
 *   - Props: btts, goals_ou, corners_ou, cards_ou
 *   - Tabla de posiciones de selecciones (group_standings_table.php)
 *
 * Uso:
 *   php scripts/diagnose_show_predictions.php
 *   php scripts/diagnose_show_predictions.php --match-id=129
 *   php scripts/diagnose_show_predictions.php --user=Diego
 *   php scripts/diagnose_show_predictions.php --settle
 *   php scripts/diagnose_show_predictions.php --compare-api
 *   php scripts/diagnose_show_predictions.php --standings   Recalcula grupos antes de diagnosticar
 *   php scripts/diagnose_show_predictions.php --json > reporte.json
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Config\AppConfig;
use App\Core\DB;
use App\Models\GroupStanding;
use App\Models\ManualMatchStats;
use App\Models\ManualMatchUpdate;
use App\Models\MatchEvent;
use App\Models\MatchStats;
use App\Models\PropPrediction;
use App\Models\Prediction;
use App\Services\FootballDataClient;
use App\Services\GroupStandingService;
use App\Services\MatchDataMapper;
use App\Services\ScoringService;
use App\Services\SettleService;

AppConfig::boot();

$opts = getopt('', ['match-id:', 'user:', 'settle', 'standings', 'compare-api', 'json', 'help']);

if (isset($opts['help'])) {
    echo <<<HELP
Diagnóstico predicciones show.php → leaderboard

  php scripts/diagnose_show_predictions.php
  php scripts/diagnose_show_predictions.php --match-id=129
  php scripts/diagnose_show_predictions.php --user=Diego
  php scripts/diagnose_show_predictions.php --settle          Liquida antes de diagnosticar
  php scripts/diagnose_show_predictions.php --compare-api     Contrasta Football-Data.org
  php scripts/diagnose_show_predictions.php --standings       Recalcula group_standings
  php scripts/diagnose_show_predictions.php --json            Salida JSON

HELP;
    exit(0);
}

$matchFilter = isset($opts['match-id']) ? (int)$opts['match-id'] : 0;
$userFilter = isset($opts['user']) ? trim((string)$opts['user']) : '';
$compareApi = isset($opts['compare-api']);
$asJson = isset($opts['json']);

$cfg = require dirname(__DIR__) . '/app/Config/app.php';
$season = (int)($cfg['football_data']['season'] ?? 2026);

if (isset($opts['settle'])) {
    $settleResult = SettleService::settleFinishedMatches();
    if (!$asJson) {
        echo 'Liquidación ejecutada: ' . json_encode($settleResult, JSON_UNESCAPED_UNICODE) . PHP_EOL;
    }
}

if (isset($opts['standings'])) {
    try {
        $fd = $cfg['football_data'];
        $api = new FootballDataClient(
            (string)$fd['base_url'],
            (string)$fd['token'],
            (int)($fd['request_soft_limit_per_minute'] ?? 9),
        );
        $gs = new GroupStandingService($api, (string)($fd['competition_code'] ?? 'WC'), $season);
        $syncResult = $gs->sync();
        if (!$asJson) {
            echo 'Tablas de grupo recalculadas: ' . json_encode($syncResult, JSON_UNESCAPED_UNICODE) . PHP_EOL;
        }
    } catch (Throwable $e) {
        if (!$asJson) {
            echo 'Error recalculando grupos: ' . $e->getMessage() . PHP_EOL;
        }
    }
}

/**
 * @return array<int, array{pj:int,pg:int,pe:int,pp:int,gf:int,gc:int,pts:int}>
 */
function expectedGroupStatsFromMatches(PDO $pdo, string $groupCode, int $season): array
{
    $expected = [];
    $st = $pdo->prepare(
        "SELECT m.id, m.home_team_id, m.away_team_id, m.status,
                COALESCE(m.regular_home_score, m.home_score) AS hs,
                COALESCE(m.regular_away_score, m.away_score) AS `as`
         FROM matches m
         WHERE m.group_code = :g AND YEAR(m.kickoff_at) = :y AND m.status IN ('FT','PEN','AET')"
    );
    $st->execute(['g' => $groupCode, 'y' => $season]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $m) {
        $matchId = (int)$m['id'];
        $resolved = ManualMatchUpdate::applyToMatch([
            'id' => $matchId,
            'status' => (string)$m['status'],
            'home_score' => (int)$m['hs'],
            'away_score' => (int)$m['as'],
            'home_team_id' => (int)$m['home_team_id'],
            'away_team_id' => (int)$m['away_team_id'],
        ], MatchEvent::forMatch($matchId));
        $home = (int)$m['home_team_id'];
        $away = (int)$m['away_team_id'];
        $hs = (int)$resolved['home_score'];
        $as = (int)$resolved['away_score'];
        foreach ([$home, $away] as $tid) {
            if (!isset($expected[$tid])) {
                $expected[$tid] = ['pj' => 0, 'pg' => 0, 'pe' => 0, 'pp' => 0, 'gf' => 0, 'gc' => 0, 'pts' => 0];
            }
        }
        $expected[$home]['pj']++;
        $expected[$away]['pj']++;
        $expected[$home]['gf'] += $hs;
        $expected[$home]['gc'] += $as;
        $expected[$away]['gf'] += $as;
        $expected[$away]['gc'] += $hs;
        if ($hs > $as) {
            $expected[$home]['pg']++;
            $expected[$away]['pp']++;
            $expected[$home]['pts'] += 3;
        } elseif ($hs < $as) {
            $expected[$away]['pg']++;
            $expected[$home]['pp']++;
            $expected[$away]['pts'] += 3;
        } else {
            $expected[$home]['pe']++;
            $expected[$away]['pe']++;
            $expected[$home]['pts']++;
            $expected[$away]['pts']++;
        }
    }
    return $expected;
}

$pdo = DB::pdo();

/** @var array<string, mixed> */
$report = [
    'season' => $season,
    'generated_at' => date('c'),
    'summary' => [
        'total_entries' => 0,
        'reflected_in_leaderboard' => 0,
        'blocked' => 0,
        'blockers' => [],
    ],
    'api_notes' => [],
    'entries' => [],
    'group_standings' => [],
    'leaderboard' => [],
];

/** @param array<string, int> $blockers */
function countBlocker(array &$blockers, string $code): void
{
    $blockers[$code] = ($blockers[$code] ?? 0) + 1;
}

/** @return array{real_home:int,real_away:int,status:string,source:string,db_home:int,db_away:int} */
function resolveScores(array $matchRow): array
{
    $matchId = (int)$matchRow['id'];
    $dbHome = (int)($matchRow['regular_home_score'] ?? $matchRow['home_score'] ?? 0);
    $dbAway = (int)($matchRow['regular_away_score'] ?? $matchRow['away_score'] ?? 0);

    $resolved = ManualMatchUpdate::applyToMatch([
        'id' => $matchId,
        'status' => (string)$matchRow['status'],
        'home_score' => $dbHome,
        'away_score' => $dbAway,
        'home_team_id' => (int)($matchRow['home_team_id'] ?? 0),
        'away_team_id' => (int)($matchRow['away_team_id'] ?? 0),
        'winner_team_id' => $matchRow['winner_team_id'] ?? null,
    ], MatchEvent::forMatch($matchId));

    $source = ($resolved['data_source'] ?? 'api') === 'manual' ? 'manual' : 'bd_api';

    return [
        'real_home' => (int)$resolved['home_score'],
        'real_away' => (int)$resolved['away_score'],
        'status' => (string)$resolved['status'],
        'source' => $source,
        'db_home' => $dbHome,
        'db_away' => $dbAway,
    ];
}

/** @return list<string> */
function leaderboardBlockers(array $userRow, int $ledgerPoints, bool $isFinished): array
{
    $reasons = [];

    if (($userRow['role_name'] ?? '') === 'admin') {
        $reasons[] = 'BLOCK_USER_ADMIN';
    }
    if (($userRow['status'] ?? '') !== 'active') {
        $reasons[] = 'BLOCK_USER_' . strtoupper((string)$userRow['status']);
    }
    if (!$isFinished) {
        $reasons[] = 'BLOCK_MATCH_NOT_FINISHED';
    }
    if ($isFinished && $ledgerPoints === 0 && !in_array('BLOCK_USER_ADMIN', $reasons, true)) {
        // Puede ser pronóstico incorrecto o sin liquidar; se detalla aparte
    }

    return $reasons;
}

/** @param array<string, mixed> $entry */
function explainBlockers(array $entry): string
{
    $map = [
        'BLOCK_MATCH_NOT_FINISHED' => 'El partido aún no está FT/PEN/AET — no se pueden otorgar puntos.',
        'BLOCK_UNSETTLED' => 'No hay fila en points_ledger — falta ejecutar liquidación (SettleService).',
        'BLOCK_NO_STATS' => 'Faltan stats (córners/tarjetas/goles/BTTS) en BD/API para liquidar este prop.',
        'BLOCK_API_INCOMPLETE_BD' => 'La API no dejó marcador confiable en matches; se usa fallback manual o BD desactualizada.',
        'BLOCK_API_STATS_MISSING' => 'Football-Data.org no entrega estadísticas para WC 2026 en este partido.',
        'BLOCK_WRONG_PREDICTION' => 'Pronóstico liquidado pero incorrecto — 0 puntos (comportamiento esperado).',
        'BLOCK_USER_SUSPENDED' => 'Usuario suspendido: tiene puntos en user_points pero NO aparece en leaderboard (solo asesores activos).',
        'BLOCK_USER_ADMIN' => 'Usuario admin: excluido del leaderboard de asesores.',
        'OK_LEADERBOARD' => 'Puntos liquidados y usuario activo asesor — debe verse en /leaderboard.',
        'OK_NO_POINTS' => 'Liquidado correctamente con 0 pts (falló el pronóstico).',
    ];

    $lines = [];
    foreach ($entry['blockers'] as $code) {
        $lines[] = ($map[$code] ?? $code);
    }
    return implode(' ', $lines);
}

// --- API snapshot por partido ---
/** @var array<int, array<string, mixed>> */
$apiCache = [];
$apiClient = null;

if ($compareApi) {
    try {
        $fd = $cfg['football_data'];
        $apiClient = new FootballDataClient(
            (string)$fd['base_url'],
            (string)$fd['token'],
            (int)($fd['request_soft_limit_per_minute'] ?? 9),
        );
        $report['api_notes'][] = 'Comparación API activada.';
    } catch (Throwable $e) {
        $report['api_notes'][] = 'API no disponible: ' . $e->getMessage();
        $compareApi = false;
    }
}

function fetchApiMatch(?FootballDataClient $api, int $apiFixtureId): ?array
{
    if ($api === null || $apiFixtureId <= 0) {
        return null;
    }
    try {
        $detail = $api->get('/matches/' . $apiFixtureId);
        $scores = MatchDataMapper::scores($detail);
        $stats = MatchDataMapper::extractMatchStats($detail);
        return [
            'status' => MatchDataMapper::mapStatus((string)($detail['status'] ?? 'NS')),
            'raw_status' => (string)($detail['status'] ?? ''),
            'home' => $scores['home'],
            'away' => $scores['away'],
            'goals' => count($detail['goals'] ?? []),
            'bookings' => count($detail['bookings'] ?? []),
            'stats_corners' => $stats['total_corners'] ?? null,
            'stats_cards' => $stats['total_cards'] ?? null,
            'stats_goals' => $stats['total_goals'] ?? null,
            'btts' => isset($stats['btts']) ? ($stats['btts'] ? 1 : 0) : null,
        ];
    } catch (Throwable $e) {
        return ['error' => $e->getMessage()];
    }
}

// --- Usuarios con rol ---
$userSql = "SELECT u.id, u.name, u.username, u.status,
                   COALESCE(up.points_total, 0) AS points_total,
                   (SELECT r.name FROM user_roles ur
                    INNER JOIN roles r ON r.id = ur.role_id
                    WHERE ur.user_id = u.id ORDER BY r.name LIMIT 1) AS role_name
            FROM users u
            LEFT JOIN user_points up ON up.user_id = u.id";
$userParams = [];
if ($userFilter !== '') {
    $userSql .= ' WHERE u.name LIKE :name OR u.username LIKE :name';
    $userParams['name'] = '%' . $userFilter . '%';
}
$userSt = $pdo->prepare($userSql);
$userSt->execute($userParams);
/** @var array<int, array<string, mixed>> */
$usersById = [];
foreach ($userSt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $u) {
    $usersById[(int)$u['id']] = $u;
}

// --- Partidos con al menos una predicción ---
$matchSql = "SELECT DISTINCT m.*, th.name AS home_name, ta.name AS away_name
             FROM matches m
             INNER JOIN teams th ON th.id = m.home_team_id
             INNER JOIN teams ta ON ta.id = m.away_team_id
             WHERE YEAR(m.kickoff_at) = :season
               AND (
                    EXISTS (SELECT 1 FROM predictions p WHERE p.match_id = m.id)
                    OR EXISTS (SELECT 1 FROM prop_predictions pp WHERE pp.match_id = m.id)
               )";
$matchParams = ['season' => $season];
if ($matchFilter > 0) {
    $matchSql .= ' AND m.id = :match_id';
    $matchParams['match_id'] = $matchFilter;
}
$matchSql .= ' ORDER BY m.kickoff_at ASC';
$matchSt = $pdo->prepare($matchSql);
$matchSt->execute($matchParams);
$matches = $matchSt->fetchAll(PDO::FETCH_ASSOC) ?: [];

if (!$asJson) {
    echo PHP_EOL . '=== Diagnóstico: predicciones de show.php → leaderboard ===' . PHP_EOL;
    echo "Temporada {$season} · partidos con pronósticos: " . count($matches) . PHP_EOL;
    echo PHP_EOL . 'Formularios en show.php:' . PHP_EOL;
    echo '  1) Marcador exacto      → predictions (exact)' . PHP_EOL;
    echo '  2) Ganador/empate       → predictions (outcome) [grupos]' . PHP_EOL;
    echo '  3) Quién avanza         → predictions (advance) [eliminatorias]' . PHP_EOL;
    echo '  4) Props especiales     → prop_predictions (btts, goals_ou, corners_ou, cards_ou)' . PHP_EOL;
}

foreach ($matches as $matchRow) {
    $matchId = (int)$matchRow['id'];
    $scores = resolveScores($matchRow);
    $isFinished = in_array(strtoupper($scores['status']), ['FT', 'PEN', 'AET'], true);

    $apiData = null;
    if ($compareApi) {
        $apiData = fetchApiMatch($apiClient, (int)($matchRow['api_fixture_id'] ?? 0));
        $apiCache[$matchId] = $apiData ?? [];
    }

    $hasManualScore = ManualMatchUpdate::forMatch($matchId) !== null;
    $hasManualStats = ManualMatchStats::forMatch($matchId) !== null;
    $eventCount = count(MatchEvent::forMatch($matchId));
    $statsRow = MatchStats::forMatch($matchId);

    if (!$asJson) {
        echo PHP_EOL . str_repeat('─', 72) . PHP_EOL;
        echo "PARTIDO #{$matchId}: {$matchRow['home_name']} vs {$matchRow['away_name']}" . PHP_EOL;
        echo "  Kickoff: {$matchRow['kickoff_at']} · Estado BD: {$matchRow['status']} {$scores['db_home']}:{$scores['db_away']}" . PHP_EOL;
        echo "  Liquidación usa: {$scores['real_home']}:{$scores['real_away']} (fuente: {$scores['source']})" . PHP_EOL;
        if ($apiData && !isset($apiData['error'])) {
            echo "  API detalle: {$apiData['home']}:{$apiData['away']} {$apiData['status']} · goles={$apiData['goals']} tarjetas={$apiData['bookings']}" . PHP_EOL;
            if ($apiData['stats_corners'] === null && $apiData['stats_cards'] === null) {
                echo "  API stats: NO entrega córners/tarjetas/BTTS para este partido" . PHP_EOL;
                $report['api_notes'][] = "#{$matchId}: API sin stats de props.";
            }
        } elseif ($apiData && isset($apiData['error'])) {
            echo "  API error: {$apiData['error']}" . PHP_EOL;
        }
        if ($scores['db_home'] !== $scores['real_home'] || $scores['db_away'] !== $scores['real_away']) {
            echo "  ⚠ BD cruda ≠ marcador liquidado (manual activo)" . PHP_EOL;
        }
        echo "  Eventos API en BD: {$eventCount} · manual_score=" . ($hasManualScore ? 'sí' : 'no') . " · manual_stats=" . ($hasManualStats ? 'sí' : 'no') . PHP_EOL;
    }

    // --- Predicciones clásicas ---
    $predSt = $pdo->prepare(
        "SELECT p.*, pl.points AS ledger_points, pl.reason AS ledger_reason, pl.id AS ledger_id
         FROM predictions p
         LEFT JOIN points_ledger pl ON pl.prediction_id = p.id
         WHERE p.match_id = :match_id
         ORDER BY p.user_id, p.pred_type"
    );
    $predSt->execute(['match_id' => $matchId]);
    foreach ($predSt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $p) {
        $userId = (int)$p['user_id'];
        $user = $usersById[$userId] ?? ['name' => 'user#' . $userId, 'status' => '?', 'role_name' => '?', 'points_total' => 0];
        $predType = (string)$p['pred_type'];

        $showSection = match ($predType) {
            'exact' => 'show.php → Marcador exacto (5 pts)',
            'outcome' => 'show.php → Ganador/empate',
            'advance' => 'show.php → Quién avanza (KO)',
            default => 'show.php → ' . $predType,
        };

        $predLabel = match ($predType) {
            'exact' => sprintf('%d:%d', (int)$p['pred_home'], (int)$p['pred_away']),
            'outcome' => Prediction::outcomeLabel((string)($p['pred_outcome'] ?? ''), $matchRow),
            'advance' => 'avanza team_id=' . (int)($p['advances_team_id'] ?? 0),
            default => $predType,
        };

        if ($predType === 'advance') {
            $winnerId = $matchRow['winner_team_id'] !== null ? (int)$matchRow['winner_team_id'] : null;
            if ($winnerId === null && $isFinished) {
                if ($scores['real_home'] > $scores['real_away']) {
                    $winnerId = (int)$matchRow['home_team_id'];
                } elseif ($scores['real_away'] > $scores['real_home']) {
                    $winnerId = (int)$matchRow['away_team_id'];
                }
            }
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
                $scores['real_home'],
                $scores['real_away'],
            );
        }

        $ledgerPoints = $p['ledger_points'] !== null ? (int)$p['ledger_points'] : null;
        $blockers = leaderboardBlockers($user, $ledgerPoints ?? 0, $isFinished);

        if (!$isFinished) {
            countBlocker($report['summary']['blockers'], 'BLOCK_MATCH_NOT_FINISHED');
        } elseif ($ledgerPoints === null) {
            $blockers[] = 'BLOCK_UNSETTLED';
            countBlocker($report['summary']['blockers'], 'BLOCK_UNSETTLED');
        } elseif ($ledgerPoints === 0) {
            $blockers[] = 'BLOCK_WRONG_PREDICTION';
            countBlocker($report['summary']['blockers'], 'BLOCK_WRONG_PREDICTION');
        }

        if ($scores['source'] === 'manual') {
            $blockers[] = 'BLOCK_API_INCOMPLETE_BD';
            countBlocker($report['summary']['blockers'], 'BLOCK_API_INCOMPLETE_BD');
        }

        if (($user['role_name'] ?? '') === 'admin') {
            countBlocker($report['summary']['blockers'], 'BLOCK_USER_ADMIN');
        }
        if (($user['status'] ?? '') === 'suspended') {
            $blockers[] = 'BLOCK_USER_SUSPENDED';
            countBlocker($report['summary']['blockers'], 'BLOCK_USER_SUSPENDED');
        }

        $visibleInLb = ($user['role_name'] ?? '') === 'asesor'
            && ($user['status'] ?? '') === 'active'
            && $ledgerPoints !== null;

        if ($visibleInLb && $ledgerPoints > 0) {
            $blockers[] = 'OK_LEADERBOARD';
            $report['summary']['reflected_in_leaderboard']++;
        } elseif ($visibleInLb && $ledgerPoints === 0) {
            $blockers[] = 'OK_NO_POINTS';
        } else {
            $report['summary']['blocked']++;
        }

        $entry = [
            'type' => 'classic',
            'show_section' => $showSection,
            'prediction_id' => (int)$p['id'],
            'match_id' => $matchId,
            'match' => $matchRow['home_name'] . ' vs ' . $matchRow['away_name'],
            'user' => $user['name'],
            'user_status' => $user['status'],
            'user_role' => $user['role_name'],
            'predicted' => $predLabel,
            'real_score' => $scores['real_home'] . ':' . $scores['real_away'],
            'match_status' => $scores['status'],
            'expected_points' => $expected['points'],
            'expected_reason' => $expected['reason'],
            'ledger_points' => $ledgerPoints,
            'ledger_reason' => $p['ledger_reason'],
            'visible_in_leaderboard' => $visibleInLb,
            'blockers' => array_values(array_unique($blockers)),
            'explanation' => '',
        ];
        $entry['explanation'] = explainBlockers($entry);
        $report['entries'][] = $entry;
        $report['summary']['total_entries']++;

        if (!$asJson) {
            $icon = $visibleInLb && ($ledgerPoints ?? 0) > 0 ? '✓' : '✗';
            echo PHP_EOL . "  [{$icon}] {$showSection}" . PHP_EOL;
            echo "      Usuario: {$user['name']} ({$user['role_name']}, {$user['status']})" . PHP_EOL;
            echo "      Pronóstico: {$predLabel}" . PHP_EOL;
            echo "      Real: {$scores['real_home']}:{$scores['real_away']} · Esperado: {$expected['points']} pts ({$expected['reason']})" . PHP_EOL;
            echo "      Ledger: " . ($ledgerPoints === null ? 'NO LIQUIDADO' : "{$ledgerPoints} pts ({$p['ledger_reason']})") . PHP_EOL;
            echo "      ¿Aparece en leaderboard? " . ($visibleInLb ? 'sí (si tiene puntos)' : 'NO') . PHP_EOL;
            echo "      Por qué: {$entry['explanation']}" . PHP_EOL;
        }
    }

    // --- Props ---
    $propSt = $pdo->prepare(
        "SELECT pp.*, pl.points AS ledger_points, pl.reason AS ledger_reason
         FROM prop_predictions pp
         LEFT JOIN points_ledger pl ON pl.prop_prediction_id = pp.id
         WHERE pp.match_id = :match_id
         ORDER BY pp.user_id, pp.market"
    );
    $propSt->execute(['match_id' => $matchId]);
    foreach ($propSt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $pp) {
        $userId = (int)$pp['user_id'];
        $user = $usersById[$userId] ?? ['name' => 'user#' . $userId, 'status' => '?', 'role_name' => '?', 'points_total' => 0];
        $market = (string)$pp['market'];
        $showSection = 'show.php → Prop: ' . match ($market) {
            'btts' => 'Ambos marcan',
            'goals_ou' => 'Goles Más/Menos',
            'corners_ou' => 'Córners Más/Menos',
            'cards_ou' => 'Tarjetas Más/Menos',
            default => $market,
        };

        $hasStats = MatchStats::hasStatsForMarket($statsRow, $market);
        $stats = [
            'total_corners' => $statsRow['total_corners'] ?? null,
            'total_cards' => $statsRow['total_cards'] ?? null,
            'total_goals' => $statsRow['total_goals'] ?? null,
            'btts' => (bool)($statsRow['btts'] ?? false),
        ];
        $line = $pp['line'] !== null ? (float)$pp['line'] : null;
        $expected = $hasStats
            ? ScoringService::propPoints($market, $line, (string)$pp['pick'], $stats)
            : ['points' => 0, 'reason' => 'none'];

        $ledgerPoints = $pp['ledger_points'] !== null ? (int)$pp['ledger_points'] : null;
        $blockers = leaderboardBlockers($user, $ledgerPoints ?? 0, $isFinished);

        if (!$isFinished) {
            countBlocker($report['summary']['blockers'], 'BLOCK_MATCH_NOT_FINISHED');
        } elseif (!$hasStats) {
            $blockers[] = 'BLOCK_NO_STATS';
            countBlocker($report['summary']['blockers'], 'BLOCK_NO_STATS');
            if ($apiData && !isset($apiData['error']) && $apiData['stats_corners'] === null) {
                $blockers[] = 'BLOCK_API_STATS_MISSING';
                countBlocker($report['summary']['blockers'], 'BLOCK_API_STATS_MISSING');
            }
        } elseif ($ledgerPoints === null) {
            $blockers[] = 'BLOCK_UNSETTLED';
            countBlocker($report['summary']['blockers'], 'BLOCK_UNSETTLED');
        } elseif ($ledgerPoints === 0) {
            $blockers[] = 'BLOCK_WRONG_PREDICTION';
            countBlocker($report['summary']['blockers'], 'BLOCK_WRONG_PREDICTION');
        }

        if (($user['status'] ?? '') === 'suspended') {
            $blockers[] = 'BLOCK_USER_SUSPENDED';
            countBlocker($report['summary']['blockers'], 'BLOCK_USER_SUSPENDED');
        }
        if (($user['role_name'] ?? '') === 'admin') {
            countBlocker($report['summary']['blockers'], 'BLOCK_USER_ADMIN');
        }

        $visibleInLb = ($user['role_name'] ?? '') === 'asesor'
            && ($user['status'] ?? '') === 'active'
            && $ledgerPoints !== null;

        if ($visibleInLb && $ledgerPoints > 0) {
            $blockers[] = 'OK_LEADERBOARD';
            $report['summary']['reflected_in_leaderboard']++;
        } elseif ($visibleInLb && $ledgerPoints === 0) {
            $blockers[] = 'OK_NO_POINTS';
        } else {
            $report['summary']['blocked']++;
        }

        $entry = [
            'type' => 'prop',
            'show_section' => $showSection,
            'prediction_id' => (int)$pp['id'],
            'match_id' => $matchId,
            'match' => $matchRow['home_name'] . ' vs ' . $matchRow['away_name'],
            'user' => $user['name'],
            'user_status' => $user['status'],
            'user_role' => $user['role_name'],
            'predicted' => PropPrediction::label($pp),
            'stats_used' => $stats,
            'has_stats' => $hasStats,
            'match_status' => $scores['status'],
            'expected_points' => $expected['points'],
            'expected_reason' => $expected['reason'],
            'ledger_points' => $ledgerPoints,
            'ledger_reason' => $pp['ledger_reason'],
            'visible_in_leaderboard' => $visibleInLb,
            'blockers' => array_values(array_unique($blockers)),
            'explanation' => '',
        ];
        $entry['explanation'] = explainBlockers($entry);
        $report['entries'][] = $entry;
        $report['summary']['total_entries']++;

        if (!$asJson) {
            $icon = $visibleInLb && ($ledgerPoints ?? 0) > 0 ? '✓' : '✗';
            echo PHP_EOL . "  [{$icon}] {$showSection}" . PHP_EOL;
            echo "      Usuario: {$user['name']} ({$user['role_name']}, {$user['status']})" . PHP_EOL;
            echo "      Pronóstico: " . PropPrediction::label($pp) . PHP_EOL;
            echo "      Stats: " . ($hasStats ? json_encode($stats, JSON_UNESCAPED_UNICODE) : 'FALTAN') . PHP_EOL;
            echo "      Esperado: {$expected['points']} pts · Ledger: " . ($ledgerPoints === null ? 'NO LIQUIDADO' : "{$ledgerPoints} pts") . PHP_EOL;
            echo "      ¿Aparece en leaderboard? " . ($visibleInLb ? 'sí (si tiene puntos)' : 'NO') . PHP_EOL;
            echo "      Por qué: {$entry['explanation']}" . PHP_EOL;
        }
    }
}

// --- Tabla de posiciones de selecciones (show.php → group_standings_table) ---
if (!$asJson) {
    echo PHP_EOL . str_repeat('═', 72) . PHP_EOL;
    echo 'TABLA DE POSICIONES DE SELECCIONES (show.php → Grupo X)' . PHP_EOL;
    echo 'Fuente vista: tabla group_standings (NO se lee la API en tiempo real).' . PHP_EOL;
    echo 'Recálculo: GroupStandingService → solo al ejecutar --standings o al abrir show.php (throttle 2 min).' . PHP_EOL;
}

$groupListSql = "SELECT DISTINCT group_code FROM matches
                 WHERE group_code IS NOT NULL AND YEAR(kickoff_at) = :season ORDER BY group_code";
$groupListParams = ['season' => $season];
if ($matchFilter > 0) {
    $groupListSql = "SELECT DISTINCT group_code FROM matches
                     WHERE id = :match_id AND group_code IS NOT NULL";
    $groupListParams = ['match_id' => $matchFilter];
}
$groupSt = $pdo->prepare($groupListSql);
$groupSt->execute($groupListParams);
$groupCodes = array_column($groupSt->fetchAll(PDO::FETCH_ASSOC) ?: [], 'group_code');

$standingsIssues = 0;
foreach ($groupCodes as $groupCode) {
    if ($groupCode === null || $groupCode === '') {
        continue;
    }
    $groupCode = (string)$groupCode;
    $cntSt = $pdo->prepare(
        "SELECT COUNT(*) FROM matches WHERE group_code = :g AND YEAR(kickoff_at) = :y AND status IN ('FT','PEN','AET')"
    );
    $cntSt->execute(['g' => $groupCode, 'y' => $season]);
    $finishedCount = (int)$cntSt->fetchColumn();

    $standings = GroupStanding::forGroup($season, $groupCode);
    $expected = expectedGroupStatsFromMatches($pdo, $groupCode, $season);
    $lastSync = $standings[0]['last_synced_at'] ?? null;

    $groupReport = [
        'group_code' => $groupCode,
        'finished_matches' => $finishedCount,
        'last_synced_at' => $lastSync,
        'teams' => [],
        'blockers' => [],
    ];

    if (!$asJson) {
        echo PHP_EOL . "── Grupo {$groupCode} · partidos finalizados en BD: {$finishedCount}" . PHP_EOL;
        echo "   Última sync group_standings: " . ($lastSync ?? 'nunca') . PHP_EOL;
    }

    if ($finishedCount > 0 && ($lastSync === null || strtotime((string)$lastSync) < time() - 3600)) {
        $groupReport['blockers'][] = 'BLOCK_STANDINGS_STALE';
        countBlocker($report['summary']['blockers'], 'BLOCK_STANDINGS_STALE');
        $standingsIssues++;
        if (!$asJson) {
            echo "   ⚠ Tabla desactualizada — ejecuta: php scripts/diagnose_show_predictions.php --standings" . PHP_EOL;
        }
    }

    if ($finishedCount === 0) {
        $groupReport['blockers'][] = 'BLOCK_NO_FINISHED_MATCHES';
        if (!$asJson) {
            echo "   ○ Sin partidos finalizados — la tabla muestra 0 PJ (normal)." . PHP_EOL;
        }
    }

    if ($compareApi && $apiClient !== null) {
        try {
            $apiStandings = $apiClient->get('/competitions/' . ($cfg['football_data']['competition_code'] ?? 'WC') . '/standings', [
                'season' => $season,
            ]);
            $hasApiGroup = false;
            foreach ($apiStandings['standings'] ?? [] as $block) {
                $parsed = MatchDataMapper::parseGroupCode(['group' => (string)($block['group'] ?? '')]);
                if ($parsed === $groupCode) {
                    $hasApiGroup = true;
                    break;
                }
            }
            if (!$hasApiGroup) {
                $groupReport['blockers'][] = 'BLOCK_API_NO_GROUP_STANDINGS';
                if (!$asJson) {
                    echo "   API: /standings no publica grupo {$groupCode} (WC usa cálculo local desde matches)." . PHP_EOL;
                }
            }
        } catch (Throwable) {
            $groupReport['blockers'][] = 'BLOCK_API_STANDINGS_ERROR';
            if (!$asJson) {
                echo "   API standings: error o 404 — se calcula desde partidos finalizados en BD." . PHP_EOL;
            }
        }
    }

    foreach ($standings as $row) {
        $tid = (int)$row['team_id'];
        $exp = $expected[$tid] ?? ['pj' => 0, 'pg' => 0, 'pe' => 0, 'pp' => 0, 'gf' => 0, 'gc' => 0, 'pts' => 0];
        $mismatch = $finishedCount > 0 && (
            (int)$row['played_games'] !== $exp['pj']
            || (int)$row['won'] !== $exp['pg']
            || (int)$row['draw'] !== $exp['pe']
            || (int)$row['lost'] !== $exp['pp']
            || (int)$row['goals_for'] !== $exp['gf']
            || (int)$row['goals_against'] !== $exp['gc']
            || (int)$row['points'] !== $exp['pts']
        );

        $teamLine = [
            'team' => $row['team_name'],
            'bd' => [
                'pj' => (int)$row['played_games'], 'pts' => (int)$row['points'],
                'gf' => (int)$row['goals_for'], 'gc' => (int)$row['goals_against'],
            ],
            'expected' => $exp,
            'ok' => !$mismatch,
        ];
        $groupReport['teams'][] = $teamLine;

        if (!$asJson) {
            $icon = $mismatch ? '✗' : '✓';
            echo "   [{$icon}] {$row['team_name']}: BD PJ={$row['played_games']} PTS={$row['points']} GF={$row['goals_for']}";
            if ($finishedCount > 0) {
                echo " · esperado PJ={$exp['pj']} PTS={$exp['pts']} GF={$exp['gf']}";
            }
            echo PHP_EOL;
        }

        if ($mismatch) {
            $standingsIssues++;
            $report['summary']['blocked']++;
            countBlocker($report['summary']['blockers'], 'BLOCK_STANDINGS_MISMATCH');
        }
    }

    $report['group_standings'][] = $groupReport;
}

if (!$asJson && $standingsIssues === 0 && $groupCodes !== []) {
    echo PHP_EOL . '   ✓ Tablas de grupo coherentes con partidos finalizados.' . PHP_EOL;
}

// --- Leaderboard actual ---
$lbSt = $pdo->query(
    "SELECT u.name, u.status, COALESCE(up.points_total, 0) AS pts,
            COALESCE(SUM(pl.points), 0) AS ledger_sum
     FROM users u
     INNER JOIN user_roles ur ON ur.user_id = u.id
     INNER JOIN roles r ON r.id = ur.role_id AND r.name = 'asesor'
     LEFT JOIN user_points up ON up.user_id = u.id
     LEFT JOIN points_ledger pl ON pl.user_id = u.id
     GROUP BY u.id, u.name, u.status, up.points_total
     ORDER BY pts DESC"
);
foreach ($lbSt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $lb) {
    $report['leaderboard'][] = $lb;
}

if ($asJson) {
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit($report['summary']['blocked'] > 0 ? 1 : 0);
}

echo PHP_EOL . str_repeat('═', 72) . PHP_EOL;
echo 'RESUMEN' . PHP_EOL;
echo "  Entradas verificadas (show.php): {$report['summary']['total_entries']}" . PHP_EOL;
echo "  Reflejadas en leaderboard:       {$report['summary']['reflected_in_leaderboard']}" . PHP_EOL;
echo "  Bloqueadas / sin reflejo:        {$report['summary']['blocked']}" . PHP_EOL;

if ($report['summary']['blockers'] !== []) {
    echo PHP_EOL . '  Causas más frecuentes:' . PHP_EOL;
    arsort($report['summary']['blockers']);
    foreach ($report['summary']['blockers'] as $code => $cnt) {
        echo "    {$code}: {$cnt}" . PHP_EOL;
    }
}

echo PHP_EOL . '  Leaderboard actual (/leaderboard):' . PHP_EOL;
foreach ($report['leaderboard'] as $lb) {
    $flag = ($lb['status'] ?? '') !== 'active' ? ' [NO VISIBLE - ' . $lb['status'] . ']' : '';
    echo "    {$lb['name']}: {$lb['pts']} pts (ledger={$lb['ledger_sum']}){$flag}" . PHP_EOL;
}

echo PHP_EOL . 'NOTA leaderboard: API/sync → matches → SettleService → points_ledger → user_points → /leaderboard' . PHP_EOL;
echo 'NOTA grupos: API/sync → matches (FT) → GroupStandingService → group_standings → show.php tabla Grupo X' . PHP_EOL;
echo '  La API de WC 2026 suele NO publicar /standings; se calcula desde partidos finalizados en BD.' . PHP_EOL;
echo PHP_EOL . 'Comandos útiles:' . PHP_EOL;
echo '  php scripts/diagnose_show_predictions.php --settle --standings --compare-api' . PHP_EOL;
echo '  php scripts/validate_sync.php --sync --settle --standings' . PHP_EOL;

exit($report['summary']['blocked'] > 0 ? 1 : 0);
