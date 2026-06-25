<?php
declare(strict_types=1);

use App\Core\Url;

/** @var int $season */
/** @var array $overview */
/** @var string $panelBaseUrl */
/** @var string $embedMode */
/** @var string|null $panelTitle */
/** @var int|null $userId */
?>

<div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2">
  <div>
    <h5 class="mb-0">Mis pronósticos — Mundial <?= (int)$season ?></h5>
    <div class="small text-muted">Todos los partidos de la temporada con desglose de puntos por columna.</div>
  </div>
  <div class="d-flex gap-2 flex-wrap">
    <a class="btn btn-outline-light btn-sm" href="<?= htmlspecialchars(Url::to('/matches')) ?>">Partidos</a>
    <a class="btn btn-outline-light btn-sm" href="<?= htmlspecialchars(Url::to('/')) ?>">Inicio</a>
  </div>
</div>

<?php require __DIR__ . '/../partials/user_predictions_panel.php'; ?>
