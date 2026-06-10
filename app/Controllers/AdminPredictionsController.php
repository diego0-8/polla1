<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\View;
use App\Models\AdminPredictionReport;
use App\Models\MatchModel;

final class AdminPredictionsController extends Controller
{
    public function index(): void
    {
        $season = MatchModel::seasonYear();
        $report = AdminPredictionReport::forSeason($season);

        View::render('admin/predictions/index', [
            'season' => $season,
            'report' => $report,
        ]);
    }
}
