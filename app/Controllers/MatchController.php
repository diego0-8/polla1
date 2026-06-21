<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\View;
use App\Helpers\MatchView;
use App\Models\GroupStanding;
use App\Models\ManualMatchUpdate;
use App\Models\MatchEvent;
use App\Models\MatchModel;
use App\Models\MatchStats;
use App\Models\Prediction;
use App\Models\PropPrediction;
use App\Services\MatchLiveRefreshService;
use App\Services\WorldCupVenueResolver;
final class MatchController extends Controller
{
    public function index(): void
    {
        MatchLiveRefreshService::refreshIndexIfNeeded();

        $season = MatchModel::seasonYear();
        $matches = ManualMatchUpdate::applyToMatches(MatchModel::forSeason($season, 500));
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

        MatchLiveRefreshService::refreshIfNeeded($match);
        $match = MatchModel::findById($id) ?? $match;

        $apiEvents = MatchEvent::forMatch($id);
        $match = ManualMatchUpdate::applyToMatch($match, $apiEvents);
        $events = ManualMatchUpdate::eventsForDisplay($id, $apiEvents);
        $matchStats = MatchStats::forMatch($id);
        $events = MatchView::eventsWithStatsSummary($events, $match, $matchStats);
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
        if ($groupCode !== '') {
            MatchLiveRefreshService::refreshStandingsIfNeeded();
        }
        $groupStandings = $groupCode !== ''
            ? GroupStanding::forGroup($season, $groupCode)
            : [];

        $match['venue'] = WorldCupVenueResolver::resolveForDbRow($match);
        if ($match['venue'] !== 'Por confirmar' && trim((string)($match['venue'] ?? '')) !== '') {
            MatchModel::persistVenueIfEmpty((int)$match['id'], $match['venue']);
        }

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
            'matchStats' => $matchStats,
            'allowsPropPredictions' => MatchModel::allowsPropPredictions($match),
            'isKnockout' => MatchModel::isKnockout($match),
            'groupStandings' => $groupStandings,
            'groupCode' => $groupCode !== '' ? $groupCode : null,
        ]);
    }
}

