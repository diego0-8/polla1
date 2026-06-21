<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\View;
use App\Models\ManualMatchStats;
use App\Models\ManualMatchUpdate;
use App\Models\MatchEvent;
use App\Models\MatchModel;
use App\Models\MatchStats;
use App\Services\FootballDataClient;
use App\Services\GroupStandingService;
use App\Services\SettleService;

final class AdminMatchDataController extends Controller
{
    public function index(): void
    {
        $season = MatchModel::seasonYear();
        $pendingMatches = ManualMatchStats::finishedMatchesPendingCornersAndYellow($season);
        $pendingMatches = ManualMatchUpdate::applyToMatches($pendingMatches);

        $requestedId = (int)($_GET['id'] ?? 0);
        $selectedId = $requestedId;
        if ($selectedId <= 0 && $pendingMatches !== []) {
            $selectedId = (int)$pendingMatches[0]['id'];
        }

        $match = $selectedId ? MatchModel::findById($selectedId) : null;
        $manual = $selectedId ? ManualMatchUpdate::forMatch($selectedId) : null;
        $apiEvents = $selectedId ? MatchEvent::forMatch($selectedId) : [];
        $manualStats = $selectedId ? ManualMatchStats::forMatch($selectedId) : null;
        $apiStats = $selectedId ? MatchStats::apiRowForMatch($selectedId) : null;
        $apiLocked = false;

        if ($match) {
            $match = ManualMatchUpdate::applyToMatch($match, $apiEvents);
            $apiLocked = ManualMatchUpdate::apiHasScoreOrStatus($match, $apiEvents);
        }

        View::render('admin/matches/manual', [
            'season' => $season,
            'pendingMatches' => $pendingMatches,
            'selectedId' => $selectedId,
            'match' => $match,
            'manual' => $manual,
            'apiEvents' => $apiEvents,
            'apiLocked' => $apiLocked,
            'manualStats' => $manualStats,
            'apiStats' => $apiStats,
            'flash' => $_SESSION['admin_match_ok'] ?? null,
            'error' => $_SESSION['admin_match_error'] ?? null,
        ]);

        unset($_SESSION['admin_match_ok'], $_SESSION['admin_match_error']);
    }

    public function saveMatch(): void
    {
        $matchId = (int)($_POST['match_id'] ?? 0);
        $match = $matchId ? MatchModel::findById($matchId) : null;
        if (!$match) {
            $_SESSION['admin_match_error'] = 'Partido no encontrado.';
            $this->redirect($this->url('/admin/matches/manual'));
        }

        $apiEvents = MatchEvent::forMatch($matchId);
        if (ManualMatchUpdate::apiHasScoreOrStatus($match, $apiEvents)) {
            $_SESSION['admin_match_error'] = 'El marcador lo define la API; no se puede editar manualmente.';
            $this->redirect($this->url('/admin/matches/manual') . '?id=' . $matchId);
        }

        try {
            ManualMatchUpdate::upsertMatch(
                $matchId,
                max(0, (int)($_POST['home_score'] ?? 0)),
                max(0, (int)($_POST['away_score'] ?? 0)),
                (string)($_POST['status'] ?? 'LIVE'),
                null,
                (int)(Auth::id() ?? 0),
            );
            $_SESSION['admin_match_ok'] = $this->runPostManualSave('Marcador manual guardado.');
        } catch (\Throwable $e) {
            $_SESSION['admin_match_error'] = $e->getMessage();
        }

        $this->redirect($this->url('/admin/matches/manual') . '?id=' . $matchId);
    }

    public function saveStats(): void
    {
        $matchId = (int)($_POST['match_id'] ?? 0);
        $match = $matchId ? MatchModel::findById($matchId) : null;
        if (!$match) {
            $_SESSION['admin_match_error'] = 'Partido no encontrado.';
            $this->redirect($this->url('/admin/matches/manual'));
        }

        $homeCorners = $this->nullableInt($_POST['home_corners'] ?? null);
        $awayCorners = $this->nullableInt($_POST['away_corners'] ?? null);
        $totalYellow = $this->nullableInt($_POST['total_yellow_cards'] ?? null);
        $totalRed = $this->nullableInt($_POST['total_red_cards'] ?? null);

        $apiEvents = MatchEvent::forMatch($matchId);
        $displayMatch = ManualMatchUpdate::applyToMatch($match, $apiEvents);
        $homeScore = (int)($displayMatch['home_score'] ?? 0);
        $awayScore = (int)($displayMatch['away_score'] ?? 0);

        $totalGoals = ($homeScore + $awayScore) > 0 ? $homeScore + $awayScore : null;
        $btts = ($homeScore + $awayScore) > 0 ? ($homeScore > 0 && $awayScore > 0) : null;

        try {
            ManualMatchStats::upsert(
                $matchId,
                $homeCorners,
                $awayCorners,
                $totalYellow,
                $totalRed,
                $totalGoals,
                $btts,
                null,
                (int)(Auth::id() ?? 0),
            );
            $_SESSION['admin_match_ok'] = $this->runPostManualSave(
                'Stats manuales guardados. Goles y ambos marcan se tomaron del marcador.',
            );
        } catch (\Throwable $e) {
            $_SESSION['admin_match_error'] = $e->getMessage();
        }

        $this->redirect($this->url('/admin/matches/manual') . '?id=' . $matchId);
    }

    private function runPostManualSave(string $baseMessage): string
    {
        try {
            $this->refreshStandingsNow();
            $settle = SettleService::settleFinishedMatches();
            $predTotal = (int)$settle['predictions'] + (int)$settle['prop_predictions']
                + (int)$settle['tournament_picks'];
            if ($predTotal === 0 && (int)$settle['points_awarded'] === 0) {
                return $baseMessage;
            }

            return $baseMessage . sprintf(
                ' Liquidados: %d pronósticos, %d props, %d campeón, %d pts otorgados.',
                (int)$settle['predictions'],
                (int)$settle['prop_predictions'],
                (int)$settle['tournament_picks'],
                (int)$settle['points_awarded'],
            );
        } catch (\Throwable $e) {
            return $baseMessage . ' Liquidación: ' . $e->getMessage();
        }
    }

    private function refreshStandingsNow(): void
    {
        try {
            $cfg = require dirname(__DIR__) . '/Config/app.php';
            $fd = $cfg['football_data'];
            $api = new FootballDataClient(
                (string)$fd['base_url'],
                (string)$fd['token'],
                (int)($fd['request_soft_limit_per_minute'] ?? 9),
            );
            $gs = new GroupStandingService(
                $api,
                (string)($fd['competition_code'] ?? 'WC'),
                (int)($fd['season'] ?? 2026),
            );
            $gs->sync();
        } catch (\Throwable) {
            // No bloquear guardado manual si falla standings.
        }
    }

    private function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return max(0, (int)$value);
    }
}
