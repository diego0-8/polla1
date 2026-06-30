<?php
declare(strict_types=1);

use App\Controllers\AdminPredictionsController;
use App\Controllers\AdminMatchDataController;
use App\Controllers\AdminTotalPController;
use App\Controllers\AdminUserController;
use App\Controllers\AuthController;
use App\Controllers\BracketController;
use App\Controllers\HomeController;
use App\Controllers\LeaderboardController;
use App\Controllers\MatchController;
use App\Controllers\MyPredictionsController;
use App\Controllers\PredictionController;
use App\Controllers\PropPredictionController;
use App\Controllers\TournamentPickController;
use App\Middlewares\AdminMiddleware;
use App\Middlewares\AsesorMiddleware;
use App\Middlewares\AuthMiddleware;

/** @var \App\Core\Router $router */

$router->get('/', [HomeController::class, 'index']);
$router->get('/matches', [MatchController::class, 'index']);
$router->get('/matches/show', [MatchController::class, 'show']); // ?id=...
$router->get('/cruces', [BracketController::class, 'index'], [AuthMiddleware::class]);
$router->get('/leaderboard', [LeaderboardController::class, 'index']);
$router->get('/tournament-pick', [TournamentPickController::class, 'show']);

$router->get('/login', [AuthController::class, 'showLogin']);
$router->post('/login', [AuthController::class, 'login']);
$router->get('/register', [AuthController::class, 'showRegister']);
$router->post('/register', [AuthController::class, 'register']);
$router->post('/logout', [AuthController::class, 'logout'], [AuthMiddleware::class]);
$router->post('/payment-modal/dismiss', [AuthController::class, 'dismissPaymentModal'], [AuthMiddleware::class]);

$router->post('/predictions', [PredictionController::class, 'store'], [AuthMiddleware::class]);
$router->post('/prop-predictions', [PropPredictionController::class, 'store'], [AuthMiddleware::class]);
$router->post('/tournament-pick', [TournamentPickController::class, 'store'], [AuthMiddleware::class]);

$asesorMw = [AuthMiddleware::class, AsesorMiddleware::class];
$router->get('/pronosticos', [MyPredictionsController::class, 'index'], $asesorMw);

$adminMw = [AuthMiddleware::class, AdminMiddleware::class];
$router->get('/admin/matches/manual', [AdminMatchDataController::class, 'index'], $adminMw);
$router->post('/admin/matches/manual/save', [AdminMatchDataController::class, 'saveMatch'], $adminMw);
$router->post('/admin/matches/manual/stats', [AdminMatchDataController::class, 'saveStats'], $adminMw);
$router->get('/admin/predictions', [AdminPredictionsController::class, 'index'], $adminMw);
$router->get('/admin/total-p', [AdminTotalPController::class, 'index'], $adminMw);
$router->get('/admin/total-p/predictions', [AdminTotalPController::class, 'predictions'], $adminMw);
$router->get('/admin/users', [AdminUserController::class, 'index'], $adminMw);
$router->get('/admin/users/edit', [AdminUserController::class, 'edit'], $adminMw);
$router->post('/admin/users/update', [AdminUserController::class, 'update'], $adminMw);
$router->post('/admin/users/disable', [AdminUserController::class, 'disable'], $adminMw);
$router->post('/admin/users/enable', [AdminUserController::class, 'enable'], $adminMw);
$router->post('/admin/users/toggle-paid', [AdminUserController::class, 'togglePaid'], $adminMw);
$router->post('/admin/users/prizes', [AdminUserController::class, 'savePrizes'], $adminMw);

