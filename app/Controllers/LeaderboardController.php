<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\View;
use App\Models\MatchModel;
use App\Models\SeasonPrize;
use App\Models\UserPoints;
use App\Services\MatchLiveRefreshService;

final class LeaderboardController extends Controller
{
    public function index(): void
    {
        MatchLiveRefreshService::refreshLeaderboardIfNeeded();

        $season = MatchModel::seasonYear();
        $prizes = SeasonPrize::getForSeason($season);
        $showPrizes = SeasonPrize::isConfigured($prizes);
        $prizeList = $showPrizes ? SeasonPrize::activeList($prizes) : [];
        $prizeCount = $showPrizes ? (int)($prizes['prize_count'] ?? 0) : 0;

        $rows = UserPoints::top(100, $season);
        View::render('leaderboard/index', [
            'rows' => $rows,
            'season' => $season,
            'prizes' => $prizes,
            'showPrizes' => $showPrizes,
            'prizeList' => $prizeList,
            'prizeCount' => $prizeCount,
        ]);
    }
}

