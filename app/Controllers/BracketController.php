<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\View;
use App\Models\MatchModel;
use App\Models\TournamentPick;
use App\Services\KnockoutBracketService;

final class BracketController extends Controller
{
    public function index(): void
    {
        $userId = (int)Auth::id();
        $season = MatchModel::seasonYear();
        $bracket = KnockoutBracketService::forSeason($season);
        $championPick = TournamentPick::forUserSeason($userId, $season);

        View::render('cruces/index', [
            'season' => $season,
            'bracket' => $bracket,
            'championPick' => $championPick,
        ]);
    }
}
