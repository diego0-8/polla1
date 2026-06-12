<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Auth;
use App\Core\View;
use App\Models\MatchModel;
use App\Models\TournamentPick;
use App\Models\UserPoints;

final class HomeController extends Controller
{
    public function index(): void
    {
        $season = MatchModel::seasonYear();
        $matches = MatchModel::today();
        if ($matches === []) {
            $matches = MatchModel::upcomingForSeason(8, $season);
        }
        $top = UserPoints::top(10, $season);
        $user = Auth::user();
        $championPick = $user ? TournamentPick::forUserSeason((int)$user['id'], $season) : null;
        View::render('home/index', [
            'matches' => $matches,
            'top' => $top,
            'season' => $season,
            'championPick' => $championPick,
            'championPickOpen' => TournamentPick::isOpen($season),
        ]);
    }
}

