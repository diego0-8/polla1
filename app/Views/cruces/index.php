<?php
declare(strict_types=1);

use App\Core\Url;

/** @var int $season */
/** @var array $bracket */
/** @var array|null $championPick */

$left = $bracket['left'];
$right = $bracket['right'];
$final = $bracket['final'] ?? null;
$thirdPlace = $bracket['third_place'] ?? null;

$leftRounds = ['LAST_32', 'LAST_16', 'QUARTER_FINALS', 'SEMI_FINALS'];
$rightRounds = ['SEMI_FINALS', 'QUARTER_FINALS', 'LAST_16', 'LAST_32'];

$roundLabels = [
    'LAST_32' => '16avos',
    'LAST_16' => 'Octavos',
    'QUARTER_FINALS' => 'Cuartos',
    'SEMI_FINALS' => 'Semis',
];

$asset = static fn (string $p): string => Url::basePath() . '/' . ltrim($p, '/');
?>

<div class="bracket-page">
<div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2">
  <div>
    <h5 class="mb-0">Cruces — Mundial <?= (int)$season ?></h5>
    <div class="small text-muted">Eliminatorias · tu posible campeón en el centro</div>
  </div>
  <a class="btn btn-outline-light btn-sm" href="<?= htmlspecialchars(Url::to('/matches')) ?>">Partidos</a>
</div>

<div class="bracket-scroll" tabindex="0" aria-label="Cuadro eliminatorio — desliza horizontalmente">
  <div class="bracket-poster">
    <div class="bracket-side bracket-side--left">
      <?php foreach ($leftRounds as $round): ?>
        <div class="bracket-round bracket-round--<?= strtolower(str_replace('_', '-', $round)) ?>">
          <div class="bracket-round-label"><?= htmlspecialchars($roundLabels[$round] ?? $round) ?></div>
          <div class="bracket-round-matches">
            <?php foreach ($left[$round] ?? [] as $match): ?>
              <div class="bracket-match-wrap">
                <?php require __DIR__ . '/../partials/bracket_match_slot.php'; ?>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>

    <div class="bracket-center">
      <div class="bracket-center-head">
        <div class="bracket-center-title">Posible campeón</div>

        <div class="bracket-champion-pick">
          <?php if ($championPick !== null): ?>
            <?php if (!empty($championPick['champion_logo'])): ?>
              <img class="bracket-champion-logo"
                   src="<?= htmlspecialchars((string)$championPick['champion_logo']) ?>"
                   alt="">
            <?php endif; ?>
            <div class="bracket-champion-name"><?= htmlspecialchars((string)$championPick['champion_name']) ?></div>
          <?php else: ?>
            <a class="bracket-champion-unknown text-decoration-none"
               href="<?= htmlspecialchars(Url::to('/tournament-pick')) ?>"
               title="Elegir campeón">?</a>
          <?php endif; ?>
        </div>

        <img class="bracket-trophy"
             src="<?= htmlspecialchars($asset('img/logo-Photoroom.png')) ?>"
             alt="Copa del Mundo">
      </div>

      <div class="bracket-center-finals">
        <?php if ($final !== null): ?>
          <div class="bracket-final-label">Final</div>
          <div class="bracket-match-wrap bracket-match-wrap--final">
            <?php $match = $final; require __DIR__ . '/../partials/bracket_match_slot.php'; ?>
          </div>
        <?php else: ?>
          <div class="bracket-final-label">Final</div>
          <div class="bracket-match-wrap bracket-match-wrap--final">
            <?php $match = null; require __DIR__ . '/../partials/bracket_match_slot.php'; ?>
          </div>
        <?php endif; ?>

        <div class="bracket-bronze-label">Medalla de bronce</div>
        <div class="bracket-match-wrap bracket-match-wrap--bronze">
          <?php $match = $thirdPlace; require __DIR__ . '/../partials/bracket_match_slot.php'; ?>
        </div>
      </div>
    </div>

    <div class="bracket-side bracket-side--right">
      <?php foreach ($rightRounds as $round): ?>
        <div class="bracket-round bracket-round--<?= strtolower(str_replace('_', '-', $round)) ?>">
          <div class="bracket-round-label"><?= htmlspecialchars($roundLabels[$round] ?? $round) ?></div>
          <div class="bracket-round-matches">
            <?php foreach ($right[$round] ?? [] as $match): ?>
              <div class="bracket-match-wrap">
                <?php require __DIR__ . '/../partials/bracket_match_slot.php'; ?>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>
<p class="bracket-scroll-hint d-lg-none">Desliza ← → para ver todo el cuadro</p>

<?php if ($championPick === null): ?>
  <div class="small text-muted mt-2 text-center">
    Aún no has elegido campeón.
    <a href="<?= htmlspecialchars(Url::to('/tournament-pick')) ?>">Elegir ahora</a>
  </div>
<?php endif; ?>
</div>
