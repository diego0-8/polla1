<?php
declare(strict_types=1);

/**
 * Actualiza nombres de equipos en BD al español.
 * Uso: php scripts/localize_team_names.php
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Config\AppConfig;
use App\Core\DB;
use App\Helpers\TeamName;

AppConfig::boot();

$pdo = DB::pdo();
$st = $pdo->query('SELECT id, api_team_id, name, code FROM teams ORDER BY id');
$rows = $st->fetchAll() ?: [];

$updated = 0;
$update = $pdo->prepare('UPDATE teams SET name = :name WHERE id = :id');

foreach ($rows as $row) {
    $spanish = TeamName::toSpanish(
        (int)$row['api_team_id'],
        $row['code'] !== null ? (string)$row['code'] : null,
        (string)$row['name'],
    );
    if ($spanish === (string)$row['name']) {
        continue;
    }
    $update->execute(['name' => $spanish, 'id' => (int)$row['id']]);
    echo (int)$row['id'] . ': ' . $row['name'] . ' → ' . $spanish . PHP_EOL;
    $updated++;
}

echo PHP_EOL . "Equipos actualizados: {$updated} / " . count($rows) . PHP_EOL;
