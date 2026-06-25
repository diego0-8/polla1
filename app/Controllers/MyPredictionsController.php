<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\View;
use App\Models\MatchModel;
use App\Services\UserPredictionOverviewService;

final class MyPredictionsController extends Controller
{
    public function index(): void
    {
        $userId = (int)Auth::id();
        $season = MatchModel::seasonYear();
        $filters = [
            'q' => (string)($_GET['q'] ?? ''),
            'date' => (string)($_GET['date'] ?? ''),
            'bet' => (string)($_GET['bet'] ?? 'all'),
            'page' => (int)($_GET['page'] ?? 1),
        ];

        $overview = UserPredictionOverviewService::paginatedForUser($userId, $season, $filters);

        View::render('pronosticos/index', [
            'season' => $season,
            'overview' => $overview,
            'panelBaseUrl' => '/pronosticos',
            'embedMode' => 'page',
            'panelTitle' => null,
            'userId' => null,
        ]);
    }
}
