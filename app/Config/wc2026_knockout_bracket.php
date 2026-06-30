<?php
declare(strict_types=1);

/**
 * Cuadro eliminatorio fijo FIFA WC 2026.
 *
 * - display_order: orden visual del póster (pares adyacentes alimentan el octavo/cuarto siguiente).
 * - feeds: de qué api_fixture_id sale el ganador (o perdedor en tercer puesto) de cada slot.
 */
return [
    'display_order' => [
        'LAST_32' => [
            'left' => [
                537415, 537416, // M74 + M77 → M89
                537417, 537418, // M73 + M75 → M90 (Canadá vs ganador NED-MAR)
                537423, 537424, // M76 + M78 → M91 (Brasil vs ganador CIV-NOR)
                537425, 537426, // M79 + M80 → M92
            ],
            'right' => [
                537419, 537420, // M83 + M84 → M93
                537421, 537422, // M81 + M82 → M94
                537427, 537428, // M86 + M88 → M95
                537429, 537430, // M85 + M87 → M96
            ],
        ],
        'LAST_16' => [
            'left' => [537375, 537376, 537377, 537378], // M89–M92
            'right' => [537379, 537380, 537381, 537382], // M93–M96
        ],
        'QUARTER_FINALS' => [
            'left' => [537383, 537385],  // M97, M99 → M101
            'right' => [537386, 537384], // M100, M98 → M102 (de afuera hacia el centro)
        ],
        'SEMI_FINALS' => [
            'left' => [537387],  // M101
            'right' => [537388], // M102
        ],
    ],
    'LAST_16' => [
        537375 => ['home' => 537415, 'away' => 537416],
        537376 => ['home' => 537417, 'away' => 537418],
        537377 => ['home' => 537423, 'away' => 537424],
        537378 => ['home' => 537425, 'away' => 537426],
        537379 => ['home' => 537419, 'away' => 537420],
        537380 => ['home' => 537421, 'away' => 537422],
        537381 => ['home' => 537427, 'away' => 537428],
        537382 => ['home' => 537429, 'away' => 537430],
    ],
    'QUARTER_FINALS' => [
        537383 => ['home' => 537375, 'away' => 537376],
        537384 => ['home' => 537379, 'away' => 537380],
        537385 => ['home' => 537377, 'away' => 537378],
        537386 => ['home' => 537381, 'away' => 537382],
    ],
    'SEMI_FINALS' => [
        537387 => ['home' => 537383, 'away' => 537384],
        537388 => ['home' => 537385, 'away' => 537386],
    ],
    'FINAL' => [
        537390 => ['home' => 537387, 'away' => 537388],
    ],
    'THIRD_PLACE' => [
        537389 => ['home_loser' => 537387, 'away_loser' => 537388],
    ],
];
