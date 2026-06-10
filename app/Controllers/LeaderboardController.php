<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\View;
use App\Models\MatchModel;
use App\Models\SeasonPrize;
use App\Models\UserPoints;

final class LeaderboardController extends Controller
{
    public function index(): void
    {
        $season = MatchModel::seasonYear();
        $prizes = SeasonPrize::getForSeason($season);
        $showPrizes = SeasonPrize::isConfigured($prizes);
        $prizeList = $showPrizes ? SeasonPrize::activeList($prizes) : [];
        $prizeCount = $showPrizes ? (int)($prizes['prize_count'] ?? 0) : 0;

        $rows = UserPoints::top(100);
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

