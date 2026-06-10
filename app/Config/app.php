<?php
declare(strict_types=1);

$config = [
    'base_path' => '/Polla',
    'timezone' => 'America/Bogota',
    'lock_minutes' => 5,
    'exact_score_points' => 5,
    'ko_advancer_points' => 2,
    'champion_bonus_points' => 20,

    'prop_points' => [
        'btts' => 2,
        'goals_ou' => 2,
        'corners_ou' => 2,
        'cards_ou' => 3,
    ],

    'prop_lines' => [
        'goals_ou' => [0.5, 1.5, 2.5, 3.5, 4.5],
        'corners_ou' => [8.5, 9.5, 10.5, 11.5],
        'cards_ou' => [2.5, 3.5, 4.5, 5.5],
    ],

    'football_data' => [
        'base_url' => 'https://api.football-data.org/v4',
        'token' => '',
        'competition_code' => 'WC',
        'season' => 2026,
        'season_fallback' => 2022,
        'request_soft_limit_per_minute' => 9,
        'live_max_detail_requests' => 8,
        'backfill_batch_per_minute' => 8,
    ],
];

$localPath = __DIR__ . '/local.php';
if (is_file($localPath)) {
    $local = require $localPath;
    if (isset($local['football_data']) && is_array($local['football_data'])) {
        $config['football_data'] = array_merge($config['football_data'], $local['football_data']);
    }
}

return $config;
