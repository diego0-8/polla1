<?php
declare(strict_types=1);

use App\Core\Url;

/** @var array<string, mixed>|null $match */
$match = $match ?? null;
$side = $side ?? 'left';
?>

<?php if ($match === null): ?>
  <div class="bracket-slot bracket-slot--empty">
    <div class="bracket-team bracket-team--tbd"><span class="bracket-team-name">TBD</span></div>
    <div class="bracket-team bracket-team--tbd"><span class="bracket-team-name">TBD</span></div>
  </div>
<?php else: ?>
  <a class="bracket-slot text-decoration-none"
     href="<?= htmlspecialchars(Url::to('/matches/show?id=' . (int)$match['match_id'])) ?>">
    <?php foreach (['home', 'away'] as $teamKey): ?>
      <?php
        $team = $match[$teamKey];
        $winnerClass = !empty($team['is_winner']) ? ' bracket-team--winner' : '';
        $tbdClass = ($team['name'] === 'TBD') ? ' bracket-team--tbd' : '';
      ?>
      <div class="bracket-team<?= $winnerClass ?><?= $tbdClass ?>">
        <?php if (!empty($team['logo'])): ?>
          <img class="bracket-team-logo" src="<?= htmlspecialchars((string)$team['logo']) ?>" alt="">
        <?php endif; ?>
        <span class="bracket-team-name"><?= htmlspecialchars((string)$team['name']) ?></span>
        <?php if (!empty($match['is_finished'])): ?>
          <span class="bracket-team-score"><?= (int)$match[$teamKey === 'home' ? 'home_score' : 'away_score'] ?></span>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </a>
<?php endif; ?>
