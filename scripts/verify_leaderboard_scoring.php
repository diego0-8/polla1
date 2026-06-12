<?php
declare(strict_types=1);

/**
 * Verifica puntuación, leaderboard y bono campeón.
 *
 * Uso:
 *   php scripts/verify_leaderboard_scoring.php
 *   php scripts/verify_leaderboard_scoring.php --settle --verbose
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Config\AppConfig;
use App\Core\DB;
use App\Models\MatchModel;
use App\Models\PropPrediction;
use App\Models\User;
use App\Models\UserPoints;
use App\Services\ScoringService;
use App\Services\SettleService;
use App\Services\TournamentTeamStatusService;

AppConfig::boot();

$opts = getopt('', ['settle', 'verbose', 'help']);
if (isset($opts['help'])) {
    echo "php scripts/verify_leaderboard_scoring.php [--settle] [--verbose]\n";
    exit(0);
}

$verbose = isset($opts['verbose']);
$season = MatchModel::seasonYear();
$pdo = DB::pdo();
$errors = 0;
$warnings = 0;
$ok = 0;

function line(string $msg, bool $verboseOnly = false): void
{
    global $verbose;
    if (!$verboseOnly || $verbose) {
        echo $msg . PHP_EOL;
    }
}

echo "=== Verificación leaderboard y puntuación (temporada {$season}) ===" . PHP_EOL . PHP_EOL;

if (isset($opts['settle'])) {
    $result = SettleService::settleFinishedMatches();
    line('Liquidación: ' . json_encode($result, JSON_UNESCAPED_UNICODE));
}

// 1. Empate = 3 puntos
$draw = ScoringService::points('outcome', 0, 0, 'D', 1, 1);
if ($draw['points'] === 3 && $draw['reason'] === 'correct_draw') {
    line('[OK] Empate (ganador/empate) otorga 3 puntos');
    $ok++;
} else {
    line('[ERROR] Empate debería dar 3 pts, obtuvo: ' . json_encode($draw));
    $errors++;
}

$winner = ScoringService::points('outcome', 0, 0, 'H', 2, 1);
if ($winner['points'] === 3) {
    line('[OK] Ganador local otorga 3 puntos');
    $ok++;
} else {
    line('[ERROR] Ganador debería dar 3 pts');
    $errors++;
}

// 2. Props O/U asimétricos
$goalsUnder = PropPrediction::pointsForMarket('goals_ou', 0.5, 'under');
if ($goalsUnder === 4) {
    line('[OK] Goles menos 0.5 = 4 pts (matriz O/U)');
    $ok++;
} else {
    line("[ERROR] Goles menos 0.5 esperado 4, obtuvo {$goalsUnder}");
    $errors++;
}

// 3. user_points vs sum ledger
line('');
line('--- Totales por usuario (asesores activos) ---');
$asesors = User::activeByRole('asesor');
foreach ($asesors as $user) {
    $userId = (int)$user['id'];
    $sumSt = $pdo->prepare('SELECT COALESCE(SUM(points), 0) FROM points_ledger WHERE user_id = :id');
    $sumSt->execute(['id' => $userId]);
    $ledgerSum = (int)$sumSt->fetchColumn();

    $upSt = $pdo->prepare('SELECT COALESCE(points_total, 0) FROM user_points WHERE user_id = :id');
    $upSt->execute(['id' => $userId]);
    $cached = (int)$upSt->fetchColumn();

    if ($ledgerSum !== $cached) {
        line("[ERROR] {$user['name']}: user_points={$cached} vs ledger SUM={$ledgerSum}");
        $errors++;
    } elseif ($ledgerSum > 0) {
        line("[OK] {$user['name']}: {$cached} pts coherentes", true);
        $ok++;
    }
}

// 4. Columnas de aciertos vs ledger
line('');
line('--- Columnas de aciertos vs points_ledger ---');
$hitSt = $pdo->prepare(
    "SELECT user_id,
            SUM(CASE WHEN reason = 'exact_score' AND points > 0 THEN 1 ELSE 0 END) AS exact_hits,
            SUM(CASE WHEN reason IN ('correct_winner','correct_draw','correct_advancer') AND points > 0 THEN 1 ELSE 0 END) AS gana_hits,
            SUM(CASE WHEN reason = 'correct_btts' AND points > 0 THEN 1 ELSE 0 END) AS btts_hits,
            SUM(CASE WHEN reason = 'correct_goals_ou' AND points > 0 THEN 1 ELSE 0 END) AS goals_hits,
            SUM(CASE WHEN reason = 'correct_corners_ou' AND points > 0 THEN 1 ELSE 0 END) AS corners_hits,
            SUM(CASE WHEN reason = 'correct_cards_ou' AND points > 0 THEN 1 ELSE 0 END) AS cards_hits,
            SUM(CASE WHEN reason = 'champion_bonus' AND points > 0 THEN 1 ELSE 0 END) AS champion_hits
     FROM points_ledger
     GROUP BY user_id"
);
$hitSt->execute();
$ledgerHits = [];
foreach ($hitSt->fetchAll() ?: [] as $row) {
    $ledgerHits[(int)$row['user_id']] = $row;
}

$boardRows = UserPoints::top(100, $season);
foreach ($boardRows as $row) {
    $uid = (int)$row['id'];
    $expected = $ledgerHits[$uid] ?? null;
    if ($expected === null) {
        continue;
    }

    $cols = ['exact_hits', 'gana_hits', 'btts_hits', 'goals_hits', 'corners_hits', 'cards_hits'];
    $mismatch = false;
    foreach ($cols as $col) {
        if ((int)$row[$col] !== (int)$expected[$col]) {
            line("[ERROR] {$row['name']}: {$col} tabla=" . (int)$row[$col] . ' ledger=' . (int)$expected[$col]);
            $errors++;
            $mismatch = true;
        }
    }
    if (!$mismatch && (int)$expected['exact_hits'] + (int)$expected['gana_hits'] > 0) {
        line("[OK] {$row['name']}: columnas de aciertos coherentes", true);
        $ok++;
    }
}

// 5. Campeón: columna +20 vs selección viva
line('');
line('--- Columna Campeón (+20 si sigue en juego) ---');
$inPlay = TournamentTeamStatusService::stillInPlayTeamIds($season);
line('Selecciones vivas en torneo: ' . count($inPlay), true);

foreach ($boardRows as $row) {
    $teamId = isset($row['champion_team_id']) ? (int)$row['champion_team_id'] : 0;
    if ($teamId <= 0) {
        continue;
    }

    $bonusSettled = (int)($row['champion_bonus_hits'] ?? 0) > 0;
    $alive = isset($inPlay[$teamId]);
    $expectedDisplay = '+20';
    $expectedStatus = ($bonusSettled || $alive) ? ($bonusSettled ? 'won' : 'alive') : 'eliminated';
    $actualDisplay = (string)($row['champion_display'] ?? '');
    $actualStatus = (string)($row['champion_status'] ?? 'none');

    if ($actualDisplay !== $expectedDisplay || $actualStatus !== $expectedStatus) {
        line("[ERROR] {$row['name']}: campeón display='{$actualDisplay}' status='{$actualStatus}' "
            . "esperado '+20'/'{$expectedStatus}' (team {$teamId}, vivo=" . ($alive ? 'sí' : 'no') . ')');
        $errors++;
    } else {
        $color = $expectedStatus === 'eliminated' ? 'rojo' : 'verde';
        line("[OK] {$row['name']}: Campeón={$actualDisplay} ({$color}, team {$teamId})", true);
        $ok++;
    }
}

// 6. Bono campeón (20 pts) tras final
line('');
line('--- Bono campeón (20 pts al finalizar la final) ---');
$finalSt = $pdo->prepare(
    "SELECT id, winner_team_id, status FROM matches
     WHERE stage_key = 'FINAL' AND YEAR(kickoff_at) = :season
       AND status IN ('FT','PEN','AET') LIMIT 1"
);
$finalSt->execute(['season' => $season]);
$final = $finalSt->fetch();

if (!$final || empty($final['winner_team_id'])) {
    line('[INFO] Final aún sin ganador en BD — bono campeón pendiente de liquidar');
    $warnings++;
} else {
    $winnerId = (int)$final['winner_team_id'];
    $bonusSt = $pdo->prepare(
        "SELECT COUNT(*) FROM points_ledger pl
         INNER JOIN tournament_picks tp ON tp.id = pl.tournament_pick_id
         WHERE pl.reason = 'champion_bonus' AND pl.points = 20
           AND tp.champion_team_id = :winner AND tp.season = :season"
    );
    $bonusSt->execute(['winner' => $winnerId, 'season' => $season]);
    $winnersCount = (int)$bonusSt->fetchColumn();

    $pickSt = $pdo->prepare(
        'SELECT COUNT(*) FROM tournament_picks WHERE season = :season AND champion_team_id = :winner'
    );
    $pickSt->execute(['season' => $season, 'winner' => $winnerId]);
    $pickCount = (int)$pickSt->fetchColumn();

    if ($pickCount > 0 && $winnersCount !== $pickCount) {
        line("[WARN] Final FT: {$pickCount} acertaron campeón pero solo {$winnersCount} tienen 20 pts en ledger — ejecuta --settle");
        $warnings++;
    } elseif ($pickCount > 0) {
        line("[OK] {$winnersCount} usuario(s) con bono campeón de 20 pts liquidado");
        $ok++;
    }
}

line('');
line("=== Resumen: OK={$ok} | Errores={$errors} | Advertencias={$warnings} ===");
exit($errors > 0 ? 1 : 0);
