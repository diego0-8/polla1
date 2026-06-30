<?php
declare(strict_types=1);

use App\Core\Url;
use App\Helpers\MatchView;

/** @var array $m */
/** @var bool $compact */
/** @var bool $showBetStatus */
$compact = $compact ?? false;
$showBetStatus = $showBetStatus ?? false;
$status = (string)($m['status'] ?? 'NS');
$scoreLine = MatchView::scorePresentation($m);
$finished = in_array($status, ['FT', 'PEN', 'AET'], true);
$showScore = in_array($status, ['FT', 'LIVE', 'HT', 'PEN', 'AET'], true)
    || ((int)($m['home_score'] ?? 0) + (int)($m['away_score'] ?? 0)) > 0;
$hasBet = !empty($m['has_bet']);
?>

<a class="text-decoration-none d-block" href="<?= htmlspecialchars(Url::to('/matches/show') . '?id=' . (int)$m['id']) ?>">
  <div class="card p-3<?= $compact ? '' : ' h-100' ?>">
    <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
      <div class="d-flex align-items-center gap-2 flex-grow-1 min-w-0">
        <?php if (!empty($m['home_logo'])): ?>
          <img class="team-logo" src="<?= htmlspecialchars((string)$m['home_logo']) ?>" alt="">
        <?php endif; ?>
        <div class="min-w-0">
          <div class="fw-semibold text-truncate"><?= htmlspecialchars((string)$m['home_name']) ?></div>
          <?php if (!empty($m['home_code'])): ?>
            <div class="small text-muted"><?= htmlspecialchars((string)$m['home_code']) ?></div>
          <?php endif; ?>
        </div>
      </div>

      <div class="text-center px-2">
        <?php if ($showScore): ?>
          <div class="fw-semibold fs-5"><?= $scoreLine['home'] ?> : <?= $scoreLine['away'] ?></div>
          <?php if ($scoreLine['show_penalties']): ?>
            <div class="small text-muted"><?= htmlspecialchars((string)$scoreLine['pen_line']) ?></div>
          <?php endif; ?>
        <?php else: ?>
          <div class="fw-semibold text-muted">vs</div>
        <?php endif; ?>
        <span class="<?= htmlspecialchars(MatchView::statusBadgeClass($status)) ?>">
          <?= htmlspecialchars(MatchView::statusLabel($status)) ?>
        </span>
        <?php if (($m['data_source'] ?? 'api') === 'manual'): ?>
          <div class="mt-1">
            <span class="<?= htmlspecialchars(MatchView::dataSourceBadgeClass($m)) ?>">Manual</span>
          </div>
        <?php endif; ?>
      </div>

      <div class="d-flex align-items-center gap-2 flex-grow-1 min-w-0 justify-content-end">
        <div class="min-w-0 text-end">
          <div class="fw-semibold text-truncate"><?= htmlspecialchars((string)$m['away_name']) ?></div>
          <?php if (!empty($m['away_code'])): ?>
            <div class="small text-muted"><?= htmlspecialchars((string)$m['away_code']) ?></div>
          <?php endif; ?>
        </div>
        <?php if (!empty($m['away_logo'])): ?>
          <img class="team-logo" src="<?= htmlspecialchars((string)$m['away_logo']) ?>" alt="">
        <?php endif; ?>
      </div>
    </div>

    <div class="d-flex justify-content-between align-items-center mt-2 small text-muted flex-wrap gap-1">
      <span><?= htmlspecialchars(MatchView::formatKickoff((string)$m['kickoff_at'])) ?></span>
      <div class="d-flex align-items-center gap-2 ms-lg-auto">
        <?php if ($showBetStatus && !$finished && array_key_exists('has_bet', $m)): ?>
          <span class="match-bet-badge <?= $hasBet ? 'match-bet-badge--yes' : 'match-bet-badge--no' ?>">
            <?= $hasBet ? 'Pronosticado' : 'No pronosticado' ?>
          </span>
        <?php endif; ?>
        <?php if (!empty($m['stage'])): ?>
          <span class="text-truncate"><?= htmlspecialchars((string)$m['stage']) ?></span>
        <?php endif; ?>
      </div>
    </div>
  </div>
</a>
