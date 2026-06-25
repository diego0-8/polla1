<?php
declare(strict_types=1);

/**
 * Diagnóstico de eventos de un partido: compara BD vs API y opcionalmente sincroniza.
 *
 * Uso:
 *   php scripts/diagnose_match_events.php --id=129
 *   php scripts/diagnose_match_events.php --api=537327
 *   php scripts/diagnose_match_events.php --id=129 --sync
 *   php scripts/diagnose_match_events.php --today
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Config\AppConfig;
use App\Core\DB;
use App\Models\MatchEvent;
use App\Models\MatchModel;
use App\Services\FootballDataClient;
use App\Services\FootballDataSyncService;
use App\Services\MatchDataMapper;
use App\Services\MatchLiveRefreshService;

AppConfig::boot();

$opts = getopt('', ['id:', 'api:', 'sync', 'today', 'help']);

if (isset($opts['help']) || ($opts === [])) {
    echo <<<HELP
Diagnóstico de eventos de partido (Football-Data.org → BD → vista show)

  php scripts/diagnose_match_events.php --id=129
  php scripts/diagnose_match_events.php --api=537327
  php scripts/diagnose_match_events.php --id=129 --sync
  php scripts/diagnose_match_events.php --today

HELP;
    exit(isset($opts['help']) ? 0 : 1);
}

$cfg = require dirname(__DIR__) . '/app/Config/app.php';
$fd = $cfg['football_data'];
$api = new FootballDataClient(
    (string)$fd['base_url'],
    (string)$fd['token'],
    (int)($fd['request_soft_limit_per_minute'] ?? 9),
);

if (isset($opts['today'])) {
    diagnoseToday($api, (int)($fd['season'] ?? 2026), $fd, isset($opts['sync']));
    exit(0);
}

$match = null;
if (isset($opts['id'])) {
    $match = MatchModel::findById((int)$opts['id']);
} elseif (isset($opts['api'])) {
    $match = MatchModel::findByApiFixtureId((int)$opts['api']);
    if ($match) {
        $match = MatchModel::findById((int)$match['id']);
    }
}

if (!$match) {
    fwrite(STDERR, "Partido no encontrado en BD.\n");
    exit(1);
}

$doSync = isset($opts['sync']);
diagnoseMatch($match, $api, $fd, $doSync);

function diagnoseToday(FootballDataClient $api, int $season, array $fd, bool $doSync): void
{
    $today = (new DateTimeImmutable('today'))->format('Y-m-d');
    section("Partidos del {$today} (temporada {$season})");

    try {
        $resp = $api->get('/competitions/WC/matches', [
            'season' => $season,
            'dateFrom' => $today,
            'dateTo' => $today,
        ]);
    } catch (Throwable $e) {
        echo "Error API: {$e->getMessage()}\n";
        exit(1);
    }

    foreach ($resp['matches'] ?? [] as $apiMatch) {
        $apiId = (int)($apiMatch['id'] ?? 0);
        $dbRow = MatchModel::findByApiFixtureId($apiId);
        if ($dbRow) {
            $full = MatchModel::findById((int)$dbRow['id']);
            if ($full) {
                diagnoseMatch($full, $api, $fd, $doSync);
                echo str_repeat('-', 60) . "\n";
            }
        } else {
            echo "API #{$apiId} " . ($apiMatch['homeTeam']['name'] ?? '?') . ' vs '
                . ($apiMatch['awayTeam']['name'] ?? '?')
                . ' — no está en BD (ejecuta --sync en validate_sync.php)' . "\n\n";
        }
    }
}

/** @param array<string,mixed> $match @param array<string,mixed> $fd */
function diagnoseMatch(array $match, FootballDataClient $api, array $fd, bool $doSync): void
{
    $matchId = (int)$match['id'];
    $apiFixtureId = (int)$match['api_fixture_id'];

    section("Partido BD #{$matchId} · API {$apiFixtureId}");
    echo "{$match['home_name']} vs {$match['away_name']}\n";
    echo "Kickoff BD: {$match['kickoff_at']}\n";
    echo "Estado BD: {$match['status']} · Marcador BD: {$match['home_score']}:{$match['away_score']}\n";

    $dbEvents = MatchEvent::forMatch($matchId);
    echo "Eventos en BD: " . count($dbEvents) . "\n";

    $shouldRefresh = MatchLiveRefreshService::shouldRefresh($match);
    echo "¿Debería refrescar al abrir show? " . ($shouldRefresh ? 'SÍ' : 'NO') . "\n";

    section('API Football-Data.org (detalle del partido)');
    try {
        $detail = $api->get('/matches/' . $apiFixtureId);
    } catch (Throwable $e) {
        echo "ERROR al consultar API: {$e->getMessage()}\n";
        echo "\nPosibles causas:\n";
        echo "  - Token inválido en app/Config/local.php\n";
        echo "  - Rate limit (429): espera 1 minuto\n";
        return;
    }

    $apiStatus = (string)($detail['status'] ?? '?');
    $mappedStatus = MatchDataMapper::mapStatus($apiStatus);
    $scores = MatchDataMapper::scores($detail);
    $goals = count($detail['goals'] ?? []);
    $bookings = count($detail['bookings'] ?? []);
    $subs = count($detail['substitutions'] ?? []);
    $normalized = MatchDataMapper::normalizeEvents($detail);

    echo "Estado API: {$apiStatus} → mapeado: {$mappedStatus}\n";
    echo "Marcador API: {$scores['home']}:{$scores['away']}\n";
    echo "Minuto API: " . json_encode($detail['minute'] ?? null) . "\n";
    echo "Goles API: {$goals} · Tarjetas API: {$bookings} · Cambios API: {$subs}\n";
    echo "Eventos normalizados: " . count($normalized) . "\n";

    if ($goals === 0 && $bookings === 0 && in_array($apiStatus, ['TIMED', 'SCHEDULED', 'NS'], true)) {
        echo "\n⚠ La API aún no publica eventos en vivo para este partido.\n";
        echo "  Football-Data.org puede retrasar datos del Mundial en plan gratuito.\n";
        echo "  Cuando la API actualice a IN_PLAY/FINISHED, el sync los guardará.\n";
    }

    if ($goals > 0 || $bookings > 0) {
        echo "\nPrimeros eventos API:\n";
        foreach (array_slice($normalized, 0, 5) as $ev) {
            echo "  {$ev['minute']}' {$ev['type']} {$ev['detail']} — {$ev['player_name']}\n";
        }
    }

    section('Diagnóstico');
    $issues = [];

    if (count($dbEvents) === 0 && count($normalized) > 0) {
        $issues[] = 'La API tiene eventos pero la BD está vacía → falta sync (--sync).';
    }
    if (count($dbEvents) === 0 && count($normalized) === 0 && $shouldRefresh) {
        $issues[] = 'Partido en ventana de juego pero API sin eventos aún.';
    }
    if ($match['status'] === 'NS' && in_array($mappedStatus, ['LIVE', 'HT', 'FT'], true)) {
        $issues[] = 'BD desactualizada (status NS pero API dice ' . $mappedStatus . ') → ejecuta sync.';
    }
    if ((int)$match['home_score'] !== $scores['home'] || (int)$match['away_score'] !== $scores['away']) {
        $issues[] = 'Marcador BD difiere del API → ejecuta sync.';
    }

    $statsCols = array_column(DB::pdo()->query('SHOW COLUMNS FROM match_stats')->fetchAll() ?: [], 'Field');
    if (!in_array('total_yellow_cards', $statsCols, true)) {
        $issues[] = 'Tabla match_stats sin columnas total_yellow_cards/total_red_cards (legacy OK tras fix).';
    }

    if ($issues === []) {
        echo "Sin inconsistencias detectadas.\n";
    } else {
        foreach ($issues as $i) {
            echo "  • {$i}\n";
        }
    }

    if ($doSync) {
        section('Sincronizando…');
        $sync = new FootballDataSyncService(
            $api,
            (string)($fd['competition_code'] ?? 'WC'),
            (int)($fd['season'] ?? 2026),
            (int)($fd['season_fallback'] ?? 2022),
            (int)($fd['live_max_detail_requests'] ?? 8),
            (int)($fd['backfill_batch_per_minute'] ?? 8),
            (int)($fd['request_soft_limit_per_minute'] ?? 9),
        );

        try {
            $eventsSynced = $sync->syncMatchEvents($apiFixtureId, $matchId);
            $refreshed = MatchModel::findById($matchId);
            $dbEventsAfter = MatchEvent::forMatch($matchId);
            echo "Sync OK. Eventos importados: {$eventsSynced}\n";
            if ($refreshed) {
                echo "Estado BD tras sync: {$refreshed['status']} · {$refreshed['home_score']}:{$refreshed['away_score']}\n";
            }
            echo "Eventos en BD tras sync: " . count($dbEventsAfter) . "\n";
        } catch (Throwable $e) {
            echo "ERROR sync: {$e->getMessage()}\n";
        }
    } else {
        echo "\nEjecuta con --sync para importar eventos ahora.\n";
    }
}

function section(string $title): void
{
    echo "\n=== {$title} ===\n";
}
