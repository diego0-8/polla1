<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\View;
use App\Models\GroupStanding;
use App\Models\MatchEvent;
use App\Models\MatchModel;
use App\Models\MatchStats;
use App\Models\Prediction;
use App\Models\PropPrediction;

final class MatchController extends Controller
{
    public function index(): void
    {
        $season = MatchModel::seasonYear();
        $matches = MatchModel::forSeason($season, 500);
        View::render('matches/index', [
            'matches' => $matches,
            'season' => $season,
            'grouped' => \App\Helpers\MatchView::groupByStage($matches),
        ]);
    }

    public function show(): void
    {
        $id = (int)($_GET['id'] ?? 0);
        $match = $id ? MatchModel::findById($id) : null;
        if (!$match) {
            http_response_code(404);
            echo 'Partido no encontrado';
            return;
        }

        $events = MatchEvent::forMatch($id);
        $exactPrediction = Prediction::myExactForMatch($id);
        $outcomePrediction = Prediction::myOutcomeForMatch($id);
        $advancePrediction = Prediction::myAdvanceForMatch($id);
        $predictionFlash = $_SESSION['prediction_ok'] ?? null;
        $predictionError = $_SESSION['prediction_error'] ?? null;
        $propFlash = $_SESSION['prop_prediction_ok'] ?? null;
        $propError = $_SESSION['prop_prediction_error'] ?? null;
        unset(
            $_SESSION['prediction_ok'],
            $_SESSION['prediction_error'],
            $_SESSION['prop_prediction_ok'],
            $_SESSION['prop_prediction_error'],
        );

        $season = MatchModel::seasonYear();
        $groupCode = trim((string)($match['group_code'] ?? ''));
        $groupStandings = $groupCode !== ''
            ? GroupStanding::forGroup($season, $groupCode)
            : [];

        View::render('matches/show', [
            'match' => $match,
            'events' => $events,
            'exactPrediction' => $exactPrediction,
            'outcomePrediction' => $outcomePrediction,
            'advancePrediction' => $advancePrediction,
            'predictionFlash' => $predictionFlash,
            'predictionError' => $predictionError,
            'propFlash' => $propFlash,
            'propError' => $propError,
            'propPredictions' => PropPrediction::myForMatch($id),
            'matchStats' => MatchStats::forMatch($id),
            'allowsPropPredictions' => MatchModel::allowsPropPredictions($match),
            'isKnockout' => MatchModel::isKnockout($match),
            'groupStandings' => $groupStandings,
            'groupCode' => $groupCode !== '' ? $groupCode : null,
        ]);
    }
}

