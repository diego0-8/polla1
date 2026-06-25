<?php
declare(strict_types=1);

/**
 * Verifica y opcionalmente sincroniza datos de Football-Data.org,
 * liquidación de puntos de asesores y tablas de grupo.
 *
 * Uso:
 *   php scripts/validate_sync.php
 *   php scripts/validate_sync.php --sync
 *   php scripts/validate_sync.php --sync --settle --standings
 *   php scripts/validate_sync.php --verbose
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Config\AppConfig;
use App\Core\DB;
use App\Models\GroupStanding;
use App\Models\ManualMatchStats;
use App\Models\ManualMatchUpdate;
use App\Models\MatchEvent;
use App\Models\MatchModel;
use App\Models\MatchStats;
use App\Models\User;
use App\Services\FootballDataClient;
use App\Services\FootballDataSyncService;
use App\Services\GroupStandingService;
use App\Services\MatchDataMapper;
use App\Services\ScoringService;
use App\Services\SettleService;
use App\Services\WorldCupVenueResolver;

AppConfig::boot();

$opts = getopt('', ['sync', 'settle', 'standings', 'verbose', 'help']);

if (isset($opts['help'])) {
    echo <<<HELP
Polla — validación de sync, puntos y posiciones

  php scripts/validate_sync.php              Solo verifica (sin cambios)
  php scripts/validate_sync.php --sync       Sincroniza calendario, estadios y live
  php scripts/validate_sync.php --settle     Liquida pronósticos pendientes
  php scripts/validate_sync.php --standings  Recalcula tablas de grupo
  php scripts/validate_sync.php --verbose    Más detalle en consola

HELP;
    exit(0);
}

$verbose = isset($opts['verbose']);
$doSync = isset($opts['sync']);
$doSettle = isset($opts['settle']);
$doStandings = isset($opts['standings']);

$cfg = require dirname(__DIR__) . '/app/Config/app.php';
$fdCfg = $cfg['football_data'];
$season = (int)($fdCfg['season'] ?? 2026);
$competition = (string)($fdCfg['competition_code'] ?? 'WC');

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

section('Configuración');
out('Temporada: ' . $season);
out('Competición: ' . $competition);

// --- Base de datos ---
section('Base de datos');
try {
    $pdo = DB::pdo();
    $ok[] = 'Conexión MySQL OK';
    out('Conexión MySQL: OK');

    $tables = ['users', 'teams', 'matches', 'predictions', 'prop_predictions', 'points_ledger', 'user_points', 'group_standings'];
    foreach ($tables as $table) {
        $exists = (bool)$pdo->query("SHOW TABLES LIKE " . $pdo->quote($table))->fetchColumn();
        if (!$exists) {
            $errors[] = "Falta la tabla `$table`. Importa polla.sql o ejecuta database/schema.sql";
            out('Tabla ' . $table . ': FALTA', true);
        }
    }

    $statsCols = array_column($pdo->query('SHOW COLUMNS FROM match_stats')->fetchAll() ?: [], 'Field');
    foreach (['total_yellow_cards', 'total_red_cards'] as $col) {
        if (!in_array($col, $statsCols, true)) {
            $warnings[] = "match_stats.$col ausente — ejecuta database/migrations/001_align_production.sql antes del sync de stats";
        }
    }

    $dupPred = (int)$pdo->query(
        'SELECT COUNT(*) FROM (
            SELECT user_id, match_id, pred_type FROM predictions
            GROUP BY user_id, match_id, pred_type HAVING COUNT(*) > 1
         ) d'
    )->fetchColumn();
    if ($dupPred > 0) {
        $warnings[] = "$dupPred combinaciones user/match/tipo con pronósticos duplicados — aplicar migración 001";
    }
} catch (Throwable $e) {
    $errors[] = 'MySQL: ' . $e->getMessage() . ' — Ejecuta database/schema.sql';
    out('MySQL: ERROR — ' . $e->getMessage());
    printSummary($ok, $warnings, $errors);
    exit(1);
}

// --- API ---
section('API Football-Data.org');
$api = null;
try {
    $api = new FootballDataClient(
        (string)$fdCfg['base_url'],
        (string)$fdCfg['token'],
        (int)($fdCfg['request_soft_limit_per_minute'] ?? 9),
    );
    $sample = $api->get('/competitions/' . $competition . '/matches', ['season' => $season, 'limit' => 5]);
    $apiMatches = $sample['matches'] ?? [];
    $ok[] = 'API Football-Data responde';
    out('API: OK (' . count($apiMatches) . ' partidos en muestra)');

    $withApiVenue = 0;
    $withFallback = 0;
    foreach ($apiMatches as $m) {
        if (MatchDataMapper::venueName($m) !== null) {
            $withApiVenue++;
        } elseif (WorldCupVenueResolver::byApiId((int)($m['id'] ?? 0)) !== null) {
            $withFallback++;
        }
    }

    if ($withApiVenue === 0 && $withFallback > 0) {
        $warnings[] = 'La API devuelve venue=null para WC 2026; se usa mapa FIFA local (' . count(require dirname(__DIR__) . '/app/Config/wc2026_venues.php') . ' estadios)';
        out('Estadios API: null (esperado para WC 2026). Fallback FIFA: activo');
    } elseif ($withApiVenue > 0) {
        $ok[] = 'La API incluye nombres de estadio';
        out('Estadios API: disponibles en listado');
    } else {
        $warnings[] = 'Sin estadios en API ni en fallback para la muestra';
        out('Estadios: sin datos en muestra');
    }
} catch (Throwable $e) {
    $errors[] = 'API: ' . $e->getMessage();
    out('API: ERROR — ' . $e->getMessage());
}

// --- Sync opcional ---
if ($doSync && $api !== null) {
    section('Sincronización');
    try {
        $sync = new FootballDataSyncService(
            $api,
            $competition,
            $season,
            (int)($fdCfg['season_fallback'] ?? 2022),
            (int)($fdCfg['live_max_detail_requests'] ?? 8),
            (int)($fdCfg['backfill_batch_per_minute'] ?? 8),
            (int)($fdCfg['request_soft_limit_per_minute'] ?? 9),
        );

        $from = $season . '-01-01';
        $to = $season . '-12-31';
        $count = $sync->syncSchedule($from, $to, $season);
        out('Calendario sincronizado: ' . $count . ' partidos');

        $venues = MatchModel::backfillVenues();
        out('Estadios rellenados desde fallback FIFA: ' . $venues);

        $live = $sync->syncLive();
        out('Live: ' . json_encode($live, JSON_UNESCAPED_UNICODE));

        $window = $sync->syncKickoffWindow(48, 8);
        out('Ventana kickoff: ' . json_encode($window, JSON_UNESCAPED_UNICODE));

        $stale = $sync->syncStaleUnfinished(7, 12);
        out('Partidos atrasados sin FT: ' . json_encode($stale, JSON_UNESCAPED_UNICODE));

        $ok[] = 'Sync completado';
    } catch (Throwable $e) {
        $errors[] = 'Sync: ' . $e->getMessage();
        out('Sync: ERROR — ' . $e->getMessage());
    }
}

// --- Estadios en BD ---
section('Estadios en base de datos');
try {
    $st = DB::pdo()->query(
        "SELECT COUNT(*) AS total,
                SUM(CASE WHEN venue IS NOT NULL AND TRIM(venue) <> '' THEN 1 ELSE 0 END) AS with_venue
         FROM matches WHERE YEAR(kickoff_at) = " . (int)$season
    );
    $row = $st->fetch() ?: ['total' => 0, 'with_venue' => 0];
    $total = (int)$row['total'];
    $withVenue = (int)$row['with_venue'];

    out("Partidos {$season}: {$total}, con estadio: {$withVenue}");

    if ($total === 0) {
        $warnings[] = 'No hay partidos en BD. Ejecuta con --sync';
    } elseif ($withVenue < $total) {
        $missing = $total - $withVenue;
        $warnings[] = "{$missing} partidos sin estadio en BD. Ejecuta --sync para rellenar fallback FIFA";
        if ($doSync) {
            $filled = MatchModel::backfillVenues();
            out('Backfill adicional: ' . $filled . ' estadios', true);
        }
    } else {
        $ok[] = 'Todos los partidos tienen estadio';
    }
} catch (Throwable $e) {
    $errors[] = 'Estadios BD: ' . $e->getMessage();
}

// --- Tablas de grupo ---
section('Tablas de grupo');
if ($doStandings && $api !== null) {
    try {
        $gs = new GroupStandingService($api, $competition, $season);
        $result = $gs->sync();
        out('Standings sync: ' . json_encode($result, JSON_UNESCAPED_UNICODE));
        $ok[] = 'Tablas de grupo sincronizadas (fuente: ' . $result['source'] . ')';
    } catch (Throwable $e) {
        $errors[] = 'Standings sync: ' . $e->getMessage();
    }
}

try {
    $groups = DB::pdo()->prepare(
        'SELECT group_code, COUNT(*) AS teams
         FROM group_standings WHERE season = :season
         GROUP BY group_code ORDER BY group_code'
    );
    $groups->execute(['season' => $season]);
    $rows = $groups->fetchAll() ?: [];

    if ($rows === []) {
        $warnings[] = 'group_standings vacío. Ejecuta --sync --standings';
        out('Tablas de grupo: sin datos');
    } else {
        $summary = array_map(static fn ($r) => $r['group_code'] . '=' . $r['teams'], $rows);
        out('Grupos en BD: ' . implode(', ', $summary));
        foreach ($rows as $r) {
            if ((int)$r['teams'] !== 4) {
                $warnings[] = 'Grupo ' . $r['group_code'] . ' tiene ' . $r['teams'] . ' equipos (esperado 4)';
            }
        }
        $ok[] = count($rows) . ' grupos con posiciones';
    }

    // Validar coherencia local vs partidos finalizados
    $groupCodes = DB::pdo()->prepare(
        'SELECT DISTINCT group_code FROM matches
         WHERE group_code IS NOT NULL AND YEAR(kickoff_at) = :season ORDER BY group_code'
    );
    $groupCodes->execute(['season' => $season]);
    foreach ($groupCodes->fetchAll() ?: [] as $gc) {
        $code = (string)$gc['group_code'];
        $standings = GroupStanding::forGroup($season, $code);
        if ($standings === []) {
            continue;
        }
        validateGroupStandings($code, $season, $standings, $errors, $warnings, $verbose);
    }
} catch (Throwable $e) {
    $errors[] = 'Standings: ' . $e->getMessage();
}

// --- Matriz API → liquidación ---
section('Matriz API → liquidación (show.php)');
try {
    validateSettlementMatrix($season, $errors, $warnings, $ok, $verbose);
} catch (Throwable $e) {
    $errors[] = 'Matriz liquidación: ' . $e->getMessage();
}

// --- Liquidación de puntos ---
section('Pronósticos y puntos (asesores)');
if ($doSettle) {
    try {
        $result = SettleService::settleFinishedMatches();
        out('Liquidación: ' . json_encode($result, JSON_UNESCAPED_UNICODE));
        $ok[] = 'Liquidación ejecutada';
    } catch (Throwable $e) {
        $errors[] = 'Settle: ' . $e->getMessage();
    }
}

try {
    $asesors = User::activeByRole('asesor');
    out('Asesores activos: ' . count($asesors));

    validateAdvisorScoring($season, $asesors, $errors, $warnings, $ok, $verbose);
} catch (Throwable $e) {
    $errors[] = 'Scoring: ' . $e->getMessage();
}

// --- Resumen ---
printSummary($ok, $warnings, $errors);
exit($errors === [] ? 0 : 1);

/** @param list<string> $ok @param list<string> $warnings @param list<string> $errors */
function printSummary(array $ok, array $warnings, array $errors): void
{
    section('Resumen');
    echo 'OK (' . count($ok) . '):' . PHP_EOL;
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
    if ($errors === [] && $warnings === []) {
        echo "Todo correcto.\n";
    } elseif ($errors === []) {
        echo "Verificación completada con advertencias.\n";
    } else {
        echo "Verificación fallida.\n";
    }
}

/** @param list<string> $errors @param list<string> $warnings @param list<string> $ok */
function validateSettlementMatrix(int $season, array &$errors, array &$warnings, array &$ok, bool $verbose): void
{
    $pdo = DB::pdo();
    $finished = $pdo->prepare(
        "SELECT id, status, home_score, away_score, winner_team_id, group_code, stage_key
         FROM matches
         WHERE YEAR(kickoff_at) = :season AND status IN ('FT','PEN','AET')
         ORDER BY kickoff_at ASC"
    );
    $finished->execute(['season' => $season]);
    $matches = $finished->fetchAll() ?: [];

    if ($matches === []) {
        $warnings[] = 'Sin partidos finalizados para matriz de liquidación';
        out('Partidos FT: 0');
        return;
    }

    out('Partidos finalizados: ' . count($matches));

    $pendingPred = 0;
    $pendingProps = 0;
    $blockedNoStats = 0;
    $blockedNoWinner = 0;

    foreach ($matches as $m) {
        $matchId = (int)$m['id'];
        $apiEvents = MatchEvent::forMatch($matchId);
        $dbMatch = MatchModel::findById($matchId);
        if ($dbMatch === null) {
            continue;
        }

        $display = ManualMatchUpdate::applyToMatch($dbMatch, $apiEvents);
        $scoreSource = ($display['data_source'] ?? 'api') === 'manual' ? 'manual' : 'api';
        $statsRow = MatchStats::forMatch($matchId);
        $statsSource = (string)($statsRow['stats_source'] ?? 'pending');

        $predSt = $pdo->prepare(
            'SELECT COUNT(*) FROM predictions p
             LEFT JOIN points_ledger pl ON pl.prediction_id = p.id
             WHERE p.match_id = :id AND pl.id IS NULL'
        );
        $predSt->execute(['id' => $matchId]);
        $unsettledPred = (int)$predSt->fetchColumn();

        $propSt = $pdo->prepare(
            'SELECT pp.market, COUNT(*) AS cnt FROM prop_predictions pp
             LEFT JOIN points_ledger pl ON pl.prop_prediction_id = pp.id
             WHERE pp.match_id = :id AND pl.id IS NULL
             GROUP BY pp.market'
        );
        $propSt->execute(['id' => $matchId]);
        $unsettledProps = $propSt->fetchAll() ?: [];

        $pendingPred += $unsettledPred;
        foreach ($unsettledProps as $propRow) {
            $market = (string)$propRow['market'];
            $cnt = (int)$propRow['cnt'];
            $pendingProps += $cnt;
            if (!MatchStats::hasStatsForMarket($statsRow, $market)) {
                $blockedNoStats += $cnt;
                if ($verbose) {
                    out("  #{$matchId} prop {$market}: {$cnt} pendientes — NO_STATS (fuente stats: {$statsSource})", true);
                }
            }
        }

        if ($unsettledPred > 0 && $verbose) {
            out("  #{$matchId} marcador={$scoreSource} stats={$statsSource}: {$unsettledPred} pronósticos sin liquidar", true);
        }

        $advSt = $pdo->prepare(
            "SELECT COUNT(*) FROM predictions p
             LEFT JOIN points_ledger pl ON pl.prediction_id = p.id
             WHERE p.match_id = :id AND p.pred_type = 'advance' AND pl.id IS NULL"
        );
        $advSt->execute(['id' => $matchId]);
        $unsettledAdvance = (int)$advSt->fetchColumn();
        if ($unsettledAdvance > 0 && ($m['winner_team_id'] === null || $m['winner_team_id'] === '')) {
            $home = (int)($display['home_score'] ?? 0);
            $away = (int)($display['away_score'] ?? 0);
            if ($home === $away) {
                $blockedNoWinner += $unsettledAdvance;
                if ($verbose) {
                    out("  #{$matchId} advance: empate sin winner_team_id — NO_WINNER", true);
                }
            }
        }
    }

    out("Pronósticos pendientes de liquidar: {$pendingPred}");
    out("Props pendientes de liquidar: {$pendingProps}");

    if ($blockedNoStats > 0) {
        $warnings[] = "{$blockedNoStats} props bloqueados por falta de stats (cargar en Admin → Manual)";
    }
    if ($blockedNoWinner > 0) {
        $warnings[] = "{$blockedNoWinner} pronósticos advance bloqueados (falta winner_team_id en empate)";
    }
    if ($pendingPred === 0 && $pendingProps === 0) {
        $ok[] = 'Matriz liquidación: sin pronósticos/props pendientes en partidos FT';
    } elseif ($blockedNoStats === 0 && $blockedNoWinner === 0) {
        $warnings[] = 'Hay pronósticos pendientes — ejecuta --settle o visita /leaderboard';
    }
}

/** @param list<array<string,mixed>> $standings @param list<string> $errors @param list<string> $warnings */
function validateGroupStandings(string $groupCode, int $season, array $standings, array &$errors, array &$warnings, bool $verbose): void
{
    $pdo = DB::pdo();
    $st = $pdo->prepare(
        "SELECT home_team_id, away_team_id,
                COALESCE(regular_home_score, home_score) AS hs,
                COALESCE(regular_away_score, away_score) AS `as`
         FROM matches
         WHERE group_code = :g AND YEAR(kickoff_at) = :y AND status IN ('FT','PEN','AET')"
    );
    $st->execute(['g' => $groupCode, 'y' => $season]);

    $expected = [];
    foreach ($st->fetchAll() ?: [] as $m) {
        $home = (int)$m['home_team_id'];
        $away = (int)$m['away_team_id'];
        $hs = (int)$m['hs'];
        $as = (int)$m['as'];
        foreach ([$home, $away] as $tid) {
            $expected[$tid] = $expected[$tid] ?? ['pj' => 0, 'pg' => 0, 'pe' => 0, 'pp' => 0, 'gf' => 0, 'gc' => 0, 'pts' => 0];
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

    if ($expected === []) {
        return;
    }

    foreach ($standings as $row) {
        $tid = (int)$row['team_id'];
        if (!isset($expected[$tid])) {
            continue;
        }
        $exp = $expected[$tid];
        $mismatch = (
            (int)$row['played_games'] !== $exp['pj']
            || (int)$row['won'] !== $exp['pg']
            || (int)$row['draw'] !== $exp['pe']
            || (int)$row['lost'] !== $exp['pp']
            || (int)$row['goals_for'] !== $exp['gf']
            || (int)$row['goals_against'] !== $exp['gc']
            || (int)$row['points'] !== $exp['pts']
        );
        if ($mismatch) {
            $errors[] = "Grupo {$groupCode}: inconsistencia en equipo ID {$tid} (BD vs partidos jugados)";
            if ($verbose) {
                out("  Grupo {$groupCode} team {$tid}: BD pts=" . $row['points'] . " esperado=" . $exp['pts'], true);
            }
        }
    }
}

/** @param list<array<string,mixed>> $asesors @param list<string> $errors @param list<string> $warnings @param list<string> $ok */
function validateAdvisorScoring(int $season, array $asesors, array &$errors, array &$warnings, array &$ok, bool $verbose): void
{
    if ($asesors === []) {
        $warnings[] = 'No hay asesores activos registrados';
        return;
    }

    $pdo = DB::pdo();
    $st = $pdo->prepare(
        "SELECT p.id, p.user_id, p.match_id, p.pred_type, p.pred_home, p.pred_away, p.pred_outcome,
                p.advances_team_id, u.name AS user_name,
                COALESCE(m.regular_home_score, m.home_score) AS real_home,
                COALESCE(m.regular_away_score, m.away_score) AS real_away,
                m.winner_team_id, m.status,
                m.home_team_id, m.away_team_id,
                pl.points AS ledger_points, pl.reason AS ledger_reason
         FROM predictions p
         INNER JOIN matches m ON m.id = p.match_id
         INNER JOIN users u ON u.id = p.user_id
         INNER JOIN user_roles ur ON ur.user_id = u.id
         INNER JOIN roles r ON r.id = ur.role_id AND r.name = 'asesor'
         LEFT JOIN points_ledger pl ON pl.prediction_id = p.id
         WHERE YEAR(m.kickoff_at) = :season AND m.status IN ('FT','PEN','AET')
         ORDER BY p.match_id, p.user_id"
    );
    $st->execute(['season' => $season]);
    $rows = $st->fetchAll() ?: [];

    if ($rows === []) {
        $warnings[] = 'No hay pronósticos de asesores en partidos finalizados para validar';
        return;
    }

    $checked = 0;
    $mismatches = 0;
    $unsettled = 0;

    foreach ($rows as $row) {
        $resolved = [
            'real_home' => (int)$row['real_home'],
            'real_away' => (int)$row['real_away'],
        ];
        $matchId = (int)$row['match_id'];
        $resolvedMatch = ManualMatchUpdate::applyToMatch([
            'id' => $matchId,
            'status' => (string)$row['status'],
            'home_score' => (int)$row['real_home'],
            'away_score' => (int)$row['real_away'],
            'home_team_id' => 0,
            'away_team_id' => 0,
        ], MatchEvent::forMatch($matchId));
        $resolved['real_home'] = (int)$resolvedMatch['home_score'];
        $resolved['real_away'] = (int)$resolvedMatch['away_score'];

        $predType = (string)$row['pred_type'];
        if ($predType === 'advance') {
            $winnerId = $row['winner_team_id'] !== null ? (int)$row['winner_team_id'] : null;
            if ($winnerId === null && in_array(strtoupper((string)$row['status']), ['FT', 'PEN', 'AET'], true)) {
                if ($resolved['real_home'] > $resolved['real_away']) {
                    $winnerId = (int)$row['home_team_id'];
                } elseif ($resolved['real_away'] > $resolved['real_home']) {
                    $winnerId = (int)$row['away_team_id'];
                }
            }
            $expected = ScoringService::advancerPoints(
                isset($row['advances_team_id']) ? (int)$row['advances_team_id'] : null,
                $winnerId,
            );
        } else {
            $expected = ScoringService::points(
                $predType,
                (int)$row['pred_home'],
                (int)$row['pred_away'],
                isset($row['pred_outcome']) ? (string)$row['pred_outcome'] : null,
                $resolved['real_home'],
                $resolved['real_away'],
            );
        }

        $ledgerPoints = $row['ledger_points'] !== null ? (int)$row['ledger_points'] : null;

        if ($ledgerPoints === null) {
            $unsettled++;
            if ($verbose) {
                out('  Sin liquidar: pred #' . $row['id'] . ' (' . $row['user_name'] . ')', true);
            }
            continue;
        }

        $checked++;
        if ($ledgerPoints !== $expected['points'] || (string)$row['ledger_reason'] !== $expected['reason']) {
            $mismatches++;
            $errors[] = sprintf(
                'Puntos incorrectos pred #%d (%s): ledger=%d/%s esperado=%d/%s',
                (int)$row['id'],
                (string)$row['user_name'],
                $ledgerPoints,
                (string)$row['ledger_reason'],
                $expected['points'],
                $expected['reason'],
            );
        }
    }

    out("Pronósticos finalizados: {$checked} liquidados, {$unsettled} pendientes, {$mismatches} inconsistencias");

    if ($unsettled > 0) {
        $warnings[] = "{$unsettled} pronósticos de asesores sin liquidar. Ejecuta --settle";
    }
    if ($mismatches === 0 && $checked > 0) {
        $ok[] = "Puntos de asesores coherentes ({$checked} pronósticos verificados)";
    }

    // Validar totales user_points vs ledger
    $st2 = $pdo->query(
        "SELECT u.name, up.points_total, COALESCE(SUM(pl.points),0) AS ledger_sum
         FROM users u
         INNER JOIN user_roles ur ON ur.user_id = u.id
         INNER JOIN roles r ON r.id = ur.role_id AND r.name = 'asesor'
         LEFT JOIN user_points up ON up.user_id = u.id
         LEFT JOIN points_ledger pl ON pl.user_id = u.id
         GROUP BY u.id, u.name, up.points_total"
    );
    foreach ($st2->fetchAll() ?: [] as $u) {
        $total = (int)($u['points_total'] ?? 0);
        $sum = (int)$u['ledger_sum'];
        if ($total !== $sum) {
            $errors[] = 'Total puntos inconsistente para ' . $u['name'] . ": user_points={$total} ledger={$sum}";
        }
    }
}
