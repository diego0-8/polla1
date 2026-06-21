<?php
declare(strict_types=1);

use App\Helpers\MatchView;

/** @var array $groupStandings */
/** @var string|null $groupCode */
/** @var array $match */

if (!$groupCode) {
    return;
}

$hasPlayed = false;
foreach ($groupStandings as $r) {
    if ((int)$r['played_games'] > 0) {
        $hasPlayed = true;
        break;
    }
}
?>

<div class="card p-3 mt-3">
  <h6 class="mb-2">Grupo <?= htmlspecialchars($groupCode) ?></h6>
  <?php if (!$hasPlayed): ?>
    <div class="small text-muted mb-2">Posiciones se actualizan al jugar los partidos.</div>
  <?php endif; ?>
  <?php if ($groupStandings === []): ?>
    <div class="text-muted small">Sin datos del grupo aún. Ejecuta sync del calendario.</div>
  <?php else: ?>
    <div class="table-responsive">
      <table class="table table-dark table-sm table-striped mb-0 align-middle group-standings-table">
        <thead>
          <tr class="small text-muted">
            <th>#</th>
            <th>Selección</th>
            <th class="text-center">PJ</th>
            <th class="text-center">PG</th>
            <th class="text-center">PE</th>
            <th class="text-center">PP</th>
            <th class="text-center">GF</th>
            <th class="text-center">GC</th>
            <th class="text-center">DIF</th>
            <th class="text-center">PTS</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($groupStandings as $r): ?>
            <?php
              $highlight = (int)$r['team_id'] === (int)$match['home_team_id']
                  || (int)$r['team_id'] === (int)$match['away_team_id'];
            ?>
            <tr class="<?= $highlight ? 'group-standing-highlight' : '' ?>">
              <td class="text-muted"><?= (int)$r['position'] ?></td>
              <td>
                <div class="d-flex align-items-center gap-2">
                  <?php if (!empty($r['team_logo'])): ?>
                    <img class="team-logo" src="<?= htmlspecialchars((string)$r['team_logo']) ?>" alt="">
                  <?php endif; ?>
                  <span class="small"><?= htmlspecialchars((string)$r['team_name']) ?></span>
                </div>
              </td>
              <td class="text-center small"><?= (int)$r['played_games'] ?></td>
              <td class="text-center small"><?= (int)$r['won'] ?></td>
              <td class="text-center small"><?= (int)$r['draw'] ?></td>
              <td class="text-center small"><?= (int)$r['lost'] ?></td>
              <td class="text-center small"><?= (int)$r['goals_for'] ?></td>
              <td class="text-center small"><?= (int)$r['goals_against'] ?></td>
              <td class="text-center small"><?= (int)$r['goal_difference'] ?></td>
              <td class="text-center fw-semibold"><?= (int)$r['points'] ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>
