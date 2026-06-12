<?php
declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Config\AppConfig;
use App\Core\DB;
use App\Services\FootballDataClient;
use App\Services\MatchDataMapper;

AppConfig::boot();

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
$rows = $pdo->query(
    "SELECT id, api_fixture_id, kickoff_at, status, home_score, away_score, last_synced_at
     FROM matches WHERE YEAR(kickoff_at) = 2026 ORDER BY kickoff_at ASC LIMIT 5"
)->fetchAll(PDO::FETCH_ASSOC);

echo "=== Comparación API vs BD (primeros 5 partidos 2026) ===\n\n";

foreach ($rows as $row) {
    $apiId = (int)$row['api_fixture_id'];
    echo "#{$row['id']} api_fixture_id={$apiId} kickoff={$row['kickoff_at']}\n";
    echo "  BD: status={$row['status']} score={$row['home_score']}:{$row['away_score']} synced={$row['last_synced_at']}\n";

    try {
        $detail = $api->get('/matches/' . $apiId);
        $scores = MatchDataMapper::scores($detail);
        $mapped = MatchDataMapper::mapStatus((string)($detail['status'] ?? 'NS'));
        $goals = count($detail['goals'] ?? []);
        $bookings = count($detail['bookings'] ?? []);
        echo "  API: raw_status={$detail['status']} mapped={$mapped} score={$scores['home']}:{$scores['away']}\n";
        echo "       goals={$goals} bookings={$bookings}\n";

        $stats = MatchDataMapper::extractMatchStats($detail);
        echo '       stats_corners=' . ($stats['total_corners'] ?? 'null')
            . ' stats_cards=' . ($stats['total_cards'] ?? 'null')
            . ' btts=' . (isset($stats['btts']) ? ($stats['btts'] ? '1' : '0') : 'null') . "\n";

        if ($mapped !== $row['status'] || $scores['home'] != $row['home_score'] || $scores['away'] != $row['away_score']) {
            echo "  >>> DESFASE: la API tiene datos distintos a la BD\n";
        } else {
            echo "  OK: BD alineada con API\n";
        }
    } catch (Throwable $e) {
        echo '  API ERROR: ' . $e->getMessage() . "\n";
    }
    echo "\n";
}

$counts = $pdo->query(
    "SELECT
        (SELECT COUNT(*) FROM matches WHERE YEAR(kickoff_at)=2026) AS matches_2026,
        (SELECT COUNT(*) FROM matches WHERE YEAR(kickoff_at)=2026 AND last_synced_at IS NOT NULL) AS synced,
        (SELECT COUNT(*) FROM match_events me JOIN matches m ON m.id=me.match_id WHERE YEAR(m.kickoff_at)=2026) AS events,
        (SELECT COUNT(*) FROM match_stats ms JOIN matches m ON m.id=ms.match_id WHERE YEAR(m.kickoff_at)=2026) AS stats,
        (SELECT COUNT(*) FROM matches WHERE YEAR(kickoff_at)=2026 AND status <> 'NS') AS not_ns"
)->fetch(PDO::FETCH_ASSOC);

echo "=== Totales 2026 ===\n";
foreach ($counts as $k => $v) {
    echo "  $k: $v\n";
}
