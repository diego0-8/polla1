<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Models\MatchModel;
use App\Models\PropPrediction;

final class PropPredictionController extends Controller
{
    public function store(): void
    {
        $user = Auth::user();
        if (!$user) {
            $this->redirect($this->url('/login'));
        }

        $matchId = (int)($_POST['match_id'] ?? 0);
        $market = (string)($_POST['market'] ?? '');
        $pick = strtolower((string)($_POST['pick'] ?? ''));
        $lineRaw = $_POST['line'] ?? null;
        $line = $lineRaw !== null && $lineRaw !== '' ? (float)$lineRaw : null;

        $match = MatchModel::findById($matchId);
        if (!$match) {
            $this->redirect($this->url('/matches'));
        }

        $redirect = $this->url('/matches/show') . '?id=' . $matchId;

        if (!MatchModel::allowsPropPredictions($match)) {
            $_SESSION['prop_prediction_error'] = 'Los pronósticos especiales no aplican a este partido.';
            $this->redirect($redirect);
        }

        if (!MatchModel::isPredictionOpen($match)) {
            $_SESSION['prop_prediction_error'] = 'El pronóstico cerró 5 minutos antes del kickoff.';
            $this->redirect($redirect);
        }

        if (!in_array($market, PropPrediction::MARKETS, true)) {
            $_SESSION['prop_prediction_error'] = 'Mercado inválido.';
            $this->redirect($redirect);
        }

        try {
            self::validatePick($market, $pick, $line);
            PropPrediction::create(
                (int)$user['id'],
                $matchId,
                $market,
                $line,
                $pick,
                MatchModel::lockedAt($match),
            );
            $_SESSION['prop_prediction_ok'] = 'Pronóstico especial registrado. No se puede modificar.';
        } catch (\RuntimeException $e) {
            $_SESSION['prop_prediction_error'] = $e->getMessage();
        }

        $this->redirect($redirect);
    }

    private static function validatePick(string $market, string $pick, ?float $line): void
    {
        if ($market === 'btts') {
            if (!in_array($pick, ['yes', 'no'], true)) {
                throw new \RuntimeException('Elige Sí o No para ambos marcan.');
            }
            return;
        }

        if (!in_array($pick, ['over', 'under'], true)) {
            throw new \RuntimeException('Elige Más o Menos.');
        }

        if ($line === null) {
            throw new \RuntimeException('Selecciona una línea válida.');
        }

        $allowed = PropPrediction::allowedLines($market);
        $lineKey = number_format($line, 1, '.', '');
        $allowedKeys = array_map(
            fn (float $l) => number_format($l, 1, '.', ''),
            $allowed,
        );

        if (!in_array($lineKey, $allowedKeys, true)) {
            throw new \RuntimeException('Línea no permitida para este mercado.');
        }
    }
}
