<?php
declare(strict_types=1);

/**
 * Diagnóstico del modal Total P (admin).
 * Uso: php scripts/diagnose_total_p_modal.php
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Config\AppConfig;
use App\Core\Url;
use App\Core\View;
use App\Models\User;
use App\Services\UserPredictionOverviewService;

AppConfig::boot();

function line(string $msg): void
{
    echo $msg . PHP_EOL;
}

line('=== Diagnóstico Total P modal ===');
line('base_path: ' . Url::basePath());
line('predictions URL: ' . Url::to('/admin/total-p/predictions'));

$partial = __DIR__ . '/../app/Views/partials/user_predictions_panel.php';
line('partial exists: ' . (is_file($partial) ? 'yes' : 'NO'));

$asesors = User::activeByRole('asesor');
line('asesores activos: ' . count($asesors));

if ($asesors === []) {
    line('[WARN] No hay asesores activos para probar.');
    exit(0);
}

$userId = (int)$asesors[0]['id'];
$userName = (string)$asesors[0]['name'];
line("probando asesor: {$userName} (id={$userId})");

$overview = UserPredictionOverviewService::paginatedForUser($userId, null, ['page' => 1, 'bet' => 'all']);
line('overview total partidos: ' . $overview['total']);
line('overview filas pagina: ' . count($overview['rows']));
line('overview pages: ' . $overview['pages']);

ob_start();
View::renderPartial('partials/user_predictions_panel', [
    'season' => (int)date('Y'),
    'overview' => $overview,
    'panelBaseUrl' => '/admin/total-p/predictions',
    'embedMode' => 'modal',
    'panelTitle' => $userName,
    'userId' => $userId,
]);
$html = ob_get_clean() ?: '';

line('renderPartial bytes: ' . strlen($html));
line('renderPartial tiene acordeon: ' . (str_contains($html, 'pronosticos-accordion') ? 'yes' : 'NO'));
line('renderPartial tiene script modal: ' . (str_contains($html, 'data-modal-form') ? 'yes' : 'NO'));

if (strlen($html) < 100) {
    line('[ERROR] HTML del partial demasiado corto.');
    line(substr($html, 0, 500));
    exit(1);
}

$hasRole = User::hasRole($userId, 'asesor');
$user = User::findById($userId);
$active = ($user['status'] ?? '') === 'active';
line('hasRole(asesor): ' . ($hasRole ? 'yes' : 'NO'));
line('status active: ' . ($active ? 'yes' : 'NO'));

if (!$hasRole || !$active) {
    line('[ERROR] El asesor de prueba no pasaria validacion del endpoint predictions.');
    exit(1);
}

line('');
line('OK backend: servicio y partial generan HTML correctamente.');
line('Si el modal no abre en el navegador, revisar que Bootstrap JS cargue ANTES del script del boton Ver.');
