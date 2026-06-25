<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\DB;
use App\Core\View;
use App\Models\MatchModel;
use App\Models\User;
use App\Services\UserPredictionOverviewService;

final class AdminTotalPController extends Controller
{
    public function index(): void
    {
        $season = MatchModel::seasonYear();
        $asesors = User::activeByRole('asesor');
        $pointsByUser = self::loadPointsTotals($asesors);

        foreach ($asesors as $idx => $a) {
            $asesors[$idx]['points_total'] = $pointsByUser[(int)$a['id']] ?? 0;
        }

        View::render('admin/total_p/index', [
            'season' => $season,
            'asesors' => $asesors,
        ]);
    }

    public function predictions(): void
    {
        $userId = (int)($_GET['user_id'] ?? 0);
        $user = $userId > 0 ? User::findById($userId) : null;

        if ($user === null || !User::hasRole($userId, 'asesor') || ($user['status'] ?? '') !== 'active') {
            http_response_code(404);
            echo 'Asesor no encontrado';
            return;
        }

        $season = MatchModel::seasonYear();
        $filters = [
            'q' => (string)($_GET['q'] ?? ''),
            'date' => (string)($_GET['date'] ?? ''),
            'bet' => (string)($_GET['bet'] ?? 'all'),
            'page' => (int)($_GET['page'] ?? 1),
        ];

        $overview = UserPredictionOverviewService::paginatedForUser($userId, $season, $filters);
        $embedMode = (string)($_GET['embed'] ?? '') === 'modal' ? 'modal' : 'page';

        if ($embedMode === 'modal') {
            View::renderPartial('partials/user_predictions_panel', [
                'season' => $season,
                'overview' => $overview,
                'panelBaseUrl' => '/admin/total-p/predictions',
                'embedMode' => 'modal',
                'panelTitle' => (string)$user['name'],
                'userId' => $userId,
            ]);
            return;
        }

        View::render('pronosticos/index', [
            'season' => $season,
            'overview' => $overview,
            'panelBaseUrl' => '/admin/total-p/predictions',
            'embedMode' => 'page',
            'panelTitle' => (string)$user['name'],
            'userId' => $userId,
        ]);
    }

    /** @param list<array<string, mixed>> $asesors @return array<int, int> */
    private static function loadPointsTotals(array $asesors): array
    {
        if ($asesors === []) {
            return [];
        }

        $ids = array_map(static fn (array $a): int => (int)$a['id'], $asesors);
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $st = DB::pdo()->prepare(
            "SELECT user_id, COALESCE(points_total, 0) AS points_total
             FROM user_points
             WHERE user_id IN ($ph)"
        );
        $st->execute($ids);

        $result = [];
        foreach ($st->fetchAll() ?: [] as $row) {
            $result[(int)$row['user_id']] = (int)$row['points_total'];
        }

        return $result;
    }
}
