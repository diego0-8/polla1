<?php
declare(strict_types=1);

use App\Core\Url;
use App\Helpers\MatchView;

/** @var array $matches */
/** @var int $season */
/** @var array<string, array<int, array<string, mixed>>> $grouped */
?>

<div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2">
  <div>
    <h5 class="mb-0">Partidos — <?= htmlspecialchars(MatchView::competitionLabel()) ?></h5>
    <div class="small text-muted">Datos sincronizados desde Football-Data.org</div>
  </div>
  <a class="btn btn-outline-light btn-sm" href="<?= htmlspecialchars(Url::to('/')) ?>">Inicio</a>
</div>

<?php if (!$matches): ?>
  <div class="card p-3">
    <div class="text-muted">
      No hay partidos del Mundial <?= (int)$season ?> en la base de datos.
      Ejecuta en la terminal:
      <code>php scripts/validate_sync.php --sync</code>
    </div>
  </div>
<?php else: ?>
  <div class="small text-muted mb-3"><?= count($matches) ?> partidos · temporada <?= (int)$season ?></div>

  <?php foreach ($grouped as $stageName => $stageMatches): ?>
    <h6 class="text-muted mt-3 mb-2"><?= htmlspecialchars((string)$stageName) ?></h6>
    <div class="row g-2 mb-2">
      <?php foreach ($stageMatches as $m): ?>
        <div class="col-12">
          <?php require __DIR__ . '/../partials/match_card.php'; ?>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endforeach; ?>
<?php endif; ?>
