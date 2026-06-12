<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Config\AppConfig;
use App\Core\DB;
use App\Services\FootballDataClient;
use App\Services\MatchDataMapper;

AppConfig::boot();

$matchId = (int)($argv[1] ?? 129);
$cfg = require dirname(__DIR__) . '/app/Config/app.php';
$local = is_file(dirname(__DIR__) . '/app/Config/local.php')
    ? require dirname(__DIR__) . '/app/Config/local.php'
    : [];
$fd = array_merge($cfg['football_data'], $local['football_data'] ?? []);

$api = new FootballDataClient(
    (string)$fd['base_url'],
    (string)$fd['token'],
    (int)($fd['request_soft_limit_per_minute'] ?? 9),
);

$pdo = DB::pdo();
$row = $pdo->prepare('SELECT id, api_fixture_id, status, home_score, away_score FROM matches WHERE id = :id');
$row->execute(['id' => $matchId]);
$match = $row->fetch(PDO::FETCH_ASSOC);
if (!$match) {
    fwrite(STDERR, "Partido #$matchId no encontrado\n");
    exit(1);
}

$apiId = (int)$match['api_fixture_id'];
echo "=== Partido #{$match['id']} api_fixture_id=$apiId ===\n";
echo "BD: {$match['status']} {$match['home_score']}:{$match['away_score']}\n";

$evSt = $pdo->prepare('SELECT COUNT(*) FROM match_events WHERE match_id = :id');
$evSt->execute(['id' => $matchId]);
$apiEvCount = (int)$evSt->fetchColumn();

$manSt = $pdo->prepare('SELECT COUNT(*) FROM manual_match_events WHERE match_id = :id');
$manSt->execute(['id' => $matchId]);
$manEvCount = (int)$manSt->fetchColumn();

echo "BD match_events: $apiEvCount | manual_match_events: $manEvCount\n\n";

try {
    $detail = $api->get('/matches/' . $apiId);
    $scores = MatchDataMapper::scores($detail);
    echo 'API status: ' . ($detail['status'] ?? '?') . "\n";
    echo "API score: {$scores['home']}:{$scores['away']}\n";
    echo 'goals: ' . count($detail['goals'] ?? []) . "\n";
    echo 'bookings: ' . count($detail['bookings'] ?? []) . "\n";
    echo 'substitutions: ' . count($detail['substitutions'] ?? []) . "\n\n";

    if (!empty($detail['goals'])) {
        echo "API goals JSON:\n";
        echo json_encode($detail['goals'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n\n";
    }

    $events = MatchDataMapper::normalizeEvents($detail);
    echo 'Eventos normalizados: ' . count($events) . "\n";
    foreach ($events as $e) {
        $min = (int)$e['minute'];
        $extra = $e['extra_minute'] !== null ? '+' . (int)$e['extra_minute'] : '';
        echo "  {$min}{$extra}' {$e['type']} " . ($e['player_name'] ?? '') . ' [' . ($e['detail'] ?? '') . "]\n";
    }

    if (($argv[2] ?? '') === '--raw') {
        echo "\nClaves top-level API:\n";
        echo implode(', ', array_keys($detail)) . "\n";
        echo json_encode(
            array_intersect_key($detail, array_flip(['status', 'score', 'goals', 'bookings', 'substitutions'])),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
        ) . "\n";
    }
} catch (Throwable $e) {
    echo 'API ERROR: ' . $e->getMessage() . "\n";
    exit(1);
}

// Resumen global FT 2026
echo "\n=== Resumen WC 2026 ===\n";
$summary = $pdo->query(
    "SELECT
        (SELECT COUNT(*) FROM matches WHERE YEAR(kickoff_at)=2026 AND status IN ('FT','PEN','AET')) AS finished,
        (SELECT COUNT(DISTINCT me.match_id) FROM match_events me JOIN matches m ON m.id=me.match_id WHERE YEAR(m.kickoff_at)=2026) AS matches_with_api_events,
        (SELECT COUNT(DISTINCT mme.match_id) FROM manual_match_events mme JOIN matches m ON m.id=mme.match_id WHERE YEAR(m.kickoff_at)=2026) AS matches_with_manual_events
    "
)->fetch(PDO::FETCH_ASSOC);
foreach ($summary as $k => $v) {
    echo "  $k: $v\n";
}
