<?php
declare(strict_types=1);

use App\Core\Url;

/** @var int $season */
/** @var list<array<string, mixed>> $teams */
/** @var array|null $pick */
/** @var bool $isOpen */
/** @var string $lockedAt */
/** @var string|null $flash */
/** @var string|null $error */
?>

<div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2">
  <div>
    <h5 class="mb-0">Pronóstico de campeón — Mundial <?= (int)$season ?></h5>
    <div class="small text-muted">Bono final: 20 puntos si aciertas el campeón.</div>
  </div>
  <a class="btn btn-outline-light btn-sm" href="<?= htmlspecialchars(Url::to('/')) ?>">Inicio</a>
</div>

<?php if (!empty($flash)): ?>
  <div class="alert alert-success py-2 small"><?= htmlspecialchars($flash) ?></div>
<?php endif; ?>
<?php if (!empty($error)): ?>
  <div class="alert alert-danger py-2 small"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<div class="card p-3">
  <p class="text-muted small mb-3">
    Debes elegir tu campeón antes del primer partido del Mundial. Cierre:
    <span class="fw-semibold"><?= htmlspecialchars($lockedAt) ?></span>.
  </p>

  <?php if (!\App\Core\Auth::check()): ?>
    <div class="text-muted mb-2">Para elegir campeón necesitas iniciar sesión.</div>
    <a class="btn btn-light btn-sm" href="<?= htmlspecialchars(Url::to('/login')) ?>">Entrar</a>
  <?php elseif ($pick): ?>
    <div class="d-flex align-items-center gap-2 rounded px-2 py-2 bg-dark bg-opacity-25">
      <?php if (!empty($pick['champion_logo'])): ?>
        <img class="team-logo" src="<?= htmlspecialchars((string)$pick['champion_logo']) ?>" alt="">
      <?php endif; ?>
      <div>
        <div class="fw-semibold"><?= htmlspecialchars((string)$pick['champion_name']) ?></div>
        <div class="small text-muted">Guardado y bloqueado</div>
      </div>
    </div>
  <?php elseif (!$isOpen): ?>
    <div class="badge badge-live mb-2">Cerrado</div>
    <div class="text-muted">El pronóstico de campeón ya no está disponible.</div>
  <?php elseif (!$teams): ?>
    <div class="text-muted">
      Aún no hay selecciones cargadas. Sincroniza la fase de grupos primero.
    </div>
  <?php else: ?>
    <form method="post" action="<?= htmlspecialchars(Url::to('/tournament-pick')) ?>" class="vstack gap-3">
      <div>
        <label class="form-label small text-muted">Campeón</label>
        <select class="form-select" name="champion_team_id" required>
          <option value="">Selecciona una selección</option>
          <?php foreach ($teams as $team): ?>
            <option value="<?= (int)$team['id'] ?>">
              <?= htmlspecialchars((string)$team['name']) ?>
              <?= !empty($team['code']) ? ' · ' . htmlspecialchars((string)$team['code']) : '' ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <button class="btn btn-light">Guardar campeón</button>
    </form>
  <?php endif; ?>
</div>
