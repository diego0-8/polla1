<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Models\MatchModel;
use App\Models\Prediction;

final class PredictionController extends Controller
{
    public function store(): void
    {
        $user = Auth::user();
        if (!$user) {
            $this->redirect($this->url('/login'));
        }

        $matchId = (int)($_POST['match_id'] ?? 0);
        $predType = (string)($_POST['pred_type'] ?? '');
        $userId = (int)$user['id'];

        $match = MatchModel::findById($matchId);
        if (!$match) {
            $this->redirect($this->url('/matches'));
        }

        $redirect = $this->url('/matches/show') . '?id=' . $matchId;

        if (!MatchModel::isPredictionOpen($match)) {
            $_SESSION['prediction_error'] = 'El pronóstico cerró 5 minutos antes del kickoff.';
            $this->redirect($redirect);
        }

        $lockedAt = MatchModel::lockedAt($match);
        $isKnockout = MatchModel::isKnockout($match);

        try {
            if ($predType === 'exact') {
                $predHome = max(0, (int)($_POST['pred_home'] ?? 0));
                $predAway = max(0, (int)($_POST['pred_away'] ?? 0));
                Prediction::createExact($userId, $matchId, $predHome, $predAway, $lockedAt);
                $_SESSION['prediction_ok'] = 'Marcador exacto registrado. No se puede modificar.';
            } elseif ($predType === 'outcome') {
                if ($isKnockout) {
                    $_SESSION['prediction_error'] = 'En fases de eliminación usa el pronóstico de quién avanza.';
                    $this->redirect($redirect);
                }
                $outcome = strtoupper((string)($_POST['pred_outcome'] ?? ''));
                if (!in_array($outcome, ['H', 'D', 'A'], true)) {
                    $_SESSION['prediction_error'] = 'Elige Local, Empate o Visitante.';
                    $this->redirect($redirect);
                }
                Prediction::createOutcome($userId, $matchId, $outcome, $lockedAt);
                $_SESSION['prediction_ok'] = 'Ganador/empate registrado. No se puede modificar.';
            } elseif ($predType === 'advance') {
                if (!$isKnockout) {
                    $_SESSION['prediction_error'] = 'Quién avanza solo aplica en fases de eliminación.';
                    $this->redirect($redirect);
                }
                if (!MatchModel::canPredictAdvance($match)) {
                    $_SESSION['prediction_error'] = 'Aún no se pueden registrar avances: equipos por confirmar.';
                    $this->redirect($redirect);
                }

                $advancesTeamId = (int)($_POST['advances_team_id'] ?? 0);
                $validTeamIds = [(int)$match['home_team_id'], (int)$match['away_team_id']];
                if (!in_array($advancesTeamId, $validTeamIds, true)) {
                    $_SESSION['prediction_error'] = 'Elige local o visitante como clasificado.';
                    $this->redirect($redirect);
                }

                Prediction::createAdvance($userId, $matchId, $advancesTeamId, $lockedAt);
                $_SESSION['prediction_ok'] = MatchModel::isFinal($match)
                    ? 'Ganador de la final registrado. No se puede modificar.'
                    : 'Clasificado registrado. No se puede modificar.';
            } else {
                $_SESSION['prediction_error'] = 'Tipo de pronóstico inválido.';
            }
        } catch (\RuntimeException $e) {
            $_SESSION['prediction_error'] = $e->getMessage();
        }

        $this->redirect($redirect);
    }
}
