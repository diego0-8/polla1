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
        $matches = ManualMatchUpdate::applyToMatches(MatchModel::forSeason($season, 500));
        $selectedId = (int)($_GET['id'] ?? ($matches[0]['id'] ?? 0));
        $match = $selectedId ? MatchModel::findById($selectedId) : null;
        $manual = $selectedId ? ManualMatchUpdate::forMatch($selectedId) : null;
        $apiEvents = $selectedId ? MatchEvent::forMatch($selectedId) : [];
        $manualEvents = $selectedId ? ManualMatchUpdate::eventsForMatch($selectedId) : [];
        $manualStats = $selectedId ? ManualMatchStats::forMatch($selectedId) : null;
        $apiStats = $selectedId ? MatchStats::apiRowForMatch($selectedId) : null;

        if ($match) {
            $match = ManualMatchUpdate::applyToMatch($match, $apiEvents);
        }

        View::render('admin/matches/manual', [
            'season' => $season,
            'matches' => $matches,
            'selectedId' => $selectedId,
            'match' => $match,
            'manual' => $manual,
            'apiEvents' => $apiEvents,
            'manualEvents' => $manualEvents,
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

        try {
            ManualMatchUpdate::upsertMatch(
                $matchId,
                max(0, (int)($_POST['home_score'] ?? 0)),
                max(0, (int)($_POST['away_score'] ?? 0)),
                (string)($_POST['status'] ?? 'LIVE'),
                isset($_POST['note']) ? (string)$_POST['note'] : null,
                (int)(Auth::id() ?? 0),
            );
            $_SESSION['admin_match_ok'] = $this->runPostManualSave('Marcador manual guardado.');
        } catch (\Throwable $e) {
            $_SESSION['admin_match_error'] = $e->getMessage();
        }

        $this->redirect($this->url('/admin/matches/manual') . '?id=' . $matchId);
    }

    public function addEvent(): void
    {
        $matchId = (int)($_POST['match_id'] ?? 0);
        $match = $matchId ? MatchModel::findById($matchId) : null;
        if (!$match) {
            $_SESSION['admin_match_error'] = 'Partido no encontrado.';
            $this->redirect($this->url('/admin/matches/manual'));
        }

        $teamSide = (string)($_POST['team_side'] ?? '');
        $teamApiId = match ($teamSide) {
            'home' => isset($match['home_api_team_id']) ? (int)$match['home_api_team_id'] : null,
            'away' => isset($match['away_api_team_id']) ? (int)$match['away_api_team_id'] : null,
            default => null,
        };

        try {
            ManualMatchUpdate::addEvent(
                $matchId,
                max(0, (int)($_POST['minute'] ?? 0)),
                $_POST['extra_minute'] !== '' ? max(0, (int)$_POST['extra_minute']) : null,
                (string)($_POST['type'] ?? 'Goal'),
                (string)($_POST['detail'] ?? ''),
                $teamApiId,
                isset($_POST['player_name']) ? (string)$_POST['player_name'] : null,
                isset($_POST['assist_name']) ? (string)$_POST['assist_name'] : null,
                (int)(Auth::id() ?? 0),
            );
            $_SESSION['admin_match_ok'] = 'Evento manual agregado.';
        } catch (\Throwable $e) {
            $_SESSION['admin_match_error'] = $e->getMessage();
        }

        $this->redirect($this->url('/admin/matches/manual') . '?id=' . $matchId);
    }

    public function deleteEvent(): void
    {
        $matchId = (int)($_POST['match_id'] ?? 0);
        $eventId = (int)($_POST['event_id'] ?? 0);
        if ($eventId > 0) {
            ManualMatchUpdate::deleteEvent($eventId);
            $_SESSION['admin_match_ok'] = 'Evento manual eliminado.';
        }

        $this->redirect($this->url('/admin/matches/manual') . ($matchId > 0 ? '?id=' . $matchId : ''));
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
        $totalGoals = $this->nullableInt($_POST['total_goals'] ?? null);
        $bttsRaw = (string)($_POST['btts'] ?? 'auto');
        $bttsProvided = $bttsRaw !== 'auto';

        $apiEvents = MatchEvent::forMatch($matchId);
        $displayMatch = ManualMatchUpdate::applyToMatch($match, $apiEvents);
        $homeScore = (int)($displayMatch['home_score'] ?? 0);
        $awayScore = (int)($displayMatch['away_score'] ?? 0);

        if ($totalGoals === null && ($homeScore + $awayScore) > 0) {
            $totalGoals = $homeScore + $awayScore;
        }

        $btts = match ($bttsRaw) {
            'yes' => true,
            'no' => false,
            default => null,
        };
        if (!$bttsProvided && ($homeScore > 0 || $awayScore > 0)) {
            $btts = $homeScore > 0 && $awayScore > 0;
        }

        try {
            ManualMatchStats::upsert(
                $matchId,
                $homeCorners,
                $awayCorners,
                $totalYellow,
                $totalRed,
                $totalGoals,
                $btts,
                isset($_POST['stats_note']) ? (string)$_POST['stats_note'] : null,
                (int)(Auth::id() ?? 0),
            );
            $_SESSION['admin_match_ok'] = $this->runPostManualSave(
                'Stats manuales guardados. Se usan para liquidar props si la API no los trae.',
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
