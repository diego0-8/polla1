<?php
declare(strict_types=1);

$config = [
    'base_path' => '/polla',
    'timezone' => 'America/Bogota',
    'lock_minutes' => 5,
    'exact_score_points' => 5,
    'outcome_winner_points' => 3,
    'outcome_draw_points' => 3,
    'ko_advancer_points' => 3,
    'champion_bonus_points' => 20,

    'prop_points' => [
        'btts' => 2,
    ],

    'prop_ou_points' => [
        'goals_ou' => [
            'over' => ['0.5' => 1, '1.5' => 1, '2.5' => 2, '3.5' => 3, '4.5' => 4],
            'under' => ['0.5' => 4, '1.5' => 3, '2.5' => 2, '3.5' => 1, '4.5' => 1],
        ],
        'corners_ou' => [
            'over' => ['8.5' => 1, '9.5' => 2, '10.5' => 2, '11.5' => 3],
            'under' => ['8.5' => 4, '9.5' => 2, '10.5' => 2, '11.5' => 1],
        ],
        'cards_ou' => [
            'over' => ['2.5' => 1, '3.5' => 2, '4.5' => 2, '5.5' => 3],
            'under' => ['2.5' => 4, '3.5' => 2, '4.5' => 2, '5.5' => 1],
        ],
    ],

    'prop_lines' => [
        'goals_ou' => [0.5, 1.5, 2.5, 3.5, 4.5],
        'corners_ou' => [8.5, 9.5, 10.5, 11.5],
        'cards_ou' => [2.5, 3.5, 4.5, 5.5],
    ],

    'payment_reminder' => [
        'deadline' => '2026-07-01 18:00:00',
        'amount_cop' => 50000,
        'qr_image' => 'img/codigo-qr.jpg',
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
    if (isset($local['base_path']) && is_string($local['base_path'])) {
        $config['base_path'] = $local['base_path'];
    }
    if (isset($local['football_data']) && is_array($local['football_data'])) {
        $config['football_data'] = array_merge($config['football_data'], $local['football_data']);
    }
}

return $config;
