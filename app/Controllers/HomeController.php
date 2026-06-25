<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Auth;
use App\Core\View;
use App\Models\ManualMatchUpdate;
use App\Models\MatchModel;
use App\Models\Prediction;
use App\Models\TournamentPick;
use App\Models\UserPoints;
use App\Services\MatchLiveRefreshService;

final class HomeController extends Controller
{
    public function index(): void
    {
        MatchLiveRefreshService::refreshIndexIfNeeded();

        $season = MatchModel::seasonYear();
        $matches = MatchModel::today();
        if ($matches === []) {
            $matches = MatchModel::upcomingForSeason(8, $season);
        }
        $matches = ManualMatchUpdate::applyToMatches($matches);
        $top = UserPoints::top(10, $season);
        $user = Auth::user();
        $championPick = $user ? TournamentPick::forUserSeason((int)$user['id'], $season) : null;

        $showBetStatus = $user !== null;
        if ($showBetStatus) {
            $betMatchIds = Prediction::matchIdsWithBetsForUser(
                (int)$user['id'],
                array_map(static fn (array $m): int => (int)$m['id'], $matches),
            );
            foreach ($matches as $idx => $match) {
                $matches[$idx]['has_bet'] = isset($betMatchIds[(int)$match['id']]);
            }
        }

        View::render('home/index', [
            'matches' => $matches,
            'top' => $top,
            'season' => $season,
            'championPick' => $championPick,
            'championPickOpen' => TournamentPick::isOpen($season),
            'showBetStatus' => $showBetStatus,
        ]);
    }
}

