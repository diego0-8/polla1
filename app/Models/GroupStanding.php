<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\DB;
use App\Helpers\TeamName;

final class GroupStanding
{
    public static function forGroup(int $season, string $groupCode): array
    {
        $st = DB::pdo()->prepare(
            'SELECT gs.*, t.name AS team_name, t.code AS team_code, t.logo_url AS team_logo, t.api_team_id AS team_api_id
             FROM group_standings gs
             JOIN teams t ON t.id = gs.team_id
             WHERE gs.season = :season AND gs.group_code = :group_code
             ORDER BY gs.position ASC, gs.points DESC, gs.goal_difference DESC, gs.goals_for DESC, t.name ASC'
        );
        $st->execute(['season' => $season, 'group_code' => $groupCode]);
        $rows = $st->fetchAll() ?: [];
        foreach ($rows as $idx => $row) {
            $rows[$idx] = TeamName::applyToTeamField($row, 'team_name', 'team_code', 'team_api_id');
        }

        return $rows;
    }
}
