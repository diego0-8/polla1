<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\View;
use App\Models\MatchModel;
use App\Models\Team;
use App\Models\TournamentPick;

final class TournamentPickController extends Controller
{
    public function show(): void
    {
        $season = MatchModel::seasonYear();
        $user = Auth::user();
        $pick = $user ? TournamentPick::forUserSeason((int)$user['id'], $season) : null;
        $flash = $_SESSION['tournament_pick_ok'] ?? null;
        $error = $_SESSION['tournament_pick_error'] ?? null;
        unset($_SESSION['tournament_pick_ok'], $_SESSION['tournament_pick_error']);

        View::render('tournament_pick/show', [
            'season' => $season,
            'teams' => Team::forTournamentSeason($season),
            'pick' => $pick,
            'isOpen' => TournamentPick::isOpen($season),
            'lockedAt' => TournamentPick::lockedAt($season),
            'flash' => $flash,
            'error' => $error,
        ]);
    }

    public function store(): void
    {
        $user = Auth::user();
        if (!$user) {
            $this->redirect($this->url('/login'));
        }

        $season = MatchModel::seasonYear();
        $teamId = (int)($_POST['champion_team_id'] ?? 0);

        try {
            if ($teamId <= 0 || !Team::isInTournamentSeason($teamId, $season)) {
                throw new \RuntimeException('Selecciona una selección válida del torneo.');
            }
            TournamentPick::create((int)$user['id'], $season, $teamId);
            $_SESSION['tournament_pick_ok'] = 'Campeón registrado. No se puede modificar.';
        } catch (\RuntimeException $e) {
            $_SESSION['tournament_pick_error'] = $e->getMessage();
        }

        $this->redirect($this->url('/tournament-pick'));
    }
}
