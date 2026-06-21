<?php
declare(strict_types=1);

use App\Core\Url;
use App\Helpers\MatchView;

/** @var int $season */
/** @var list<array<string, mixed>> $pendingMatches */
/** @var int $selectedId */
/** @var array<string, mixed>|null $match */
/** @var array<string, mixed>|null $manual */
/** @var list<array<string, mixed>> $apiEvents */
/** @var bool $apiLocked */
/** @var array<string, mixed>|null $manualStats */
/** @var array<string, mixed>|null $apiStats */
/** @var string|null $flash */
/** @var string|null $error */

$statusOptions = [
    'LIVE' => 'En juego',
    'HT' => 'Entretiempo',
    'FT' => 'Finalizado',
    'PEN' => 'Penales',
    'AET' => 'Tiempo extra',
    'NS' => 'Programado',
];
$manualStatus = (string)($manual['status'] ?? 'LIVE');
?>

<div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2">
  <div>
    <h5 class="mb-0">Carga manual de partido</h5>
    <div class="small text-muted">
      Complementa córners y tarjetas en partidos finalizados. El marcador lo define la API cuando el partido cierra.
    </div>
  </div>
  <div class="d-flex gap-2 flex-wrap">
    <?php if ($match): ?>
      <a class="btn btn-outline-light btn-sm" href="<?= htmlspecialchars(Url::to('/matches/show') . '?id=' . (int)$selectedId) ?>">Ver partido</a>
    <?php endif; ?>
    <a class="btn btn-outline-light btn-sm" href="<?= htmlspecialchars(Url::to('/admin/users')) ?>">Admin</a>
  </div>
</div>

<?php if (!empty($flash)): ?>
  <div class="alert alert-success py-2 small"><?= htmlspecialchars($flash) ?></div>
<?php endif; ?>
<?php if (!empty($error)): ?>
  <div class="alert alert-danger py-2 small"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<div class="card p-3 mb-3">
  <form method="get" action="<?= htmlspecialchars(Url::to('/admin/matches/manual')) ?>" class="row g-2 align-items-end">
    <div class="col-12 col-lg-10">
      <label class="form-label small text-muted">Partidos finalizados pendientes (temporada <?= (int)$season ?>)</label>
      <?php if ($pendingMatches === []): ?>
        <div class="text-muted small">No hay partidos finalizados pendientes de córners y amarillas.</div>
        <?php if ($selectedId > 0): ?>
          <input type="hidden" name="id" value="<?= (int)$selectedId ?>">
        <?php endif; ?>
      <?php else: ?>
        <select name="id" class="form-select">
          <?php foreach ($pendingMatches as $m): ?>
            <option value="<?= (int)$m['id'] ?>" <?= (int)$m['id'] === $selectedId ? 'selected' : '' ?>>
              #<?= (int)$m['id'] ?> · <?= htmlspecialchars(MatchView::formatKickoff((string)$m['kickoff_at'])) ?>
              · <?= htmlspecialchars((string)$m['home_name']) ?> vs <?= htmlspecialchars((string)$m['away_name']) ?>
              · <?= htmlspecialchars(MatchView::statusLabel((string)$m['status'])) ?>
            </option>
          <?php endforeach; ?>
        </select>
      <?php endif; ?>
    </div>
  <?php if ($pendingMatches !== []): ?>
    <div class="col-12 col-lg-2 d-grid">
      <button class="btn btn-light">Abrir</button>
    </div>
  <?php endif; ?>
  </form>
</div>

<?php if (!$match): ?>
  <div class="card p-3 text-muted">Selecciona un partido pendiente o abre uno por URL con <code>?id=</code>.</div>
<?php else: ?>
  <div class="row g-3">
    <div class="col-12">
      <div class="card p-3">
        <h6 class="mb-2">Marcador</h6>
        <?php if ($apiLocked): ?>
          <p class="small text-muted mb-2">
            La API ya registró el resultado final. No se puede editar el marcador manualmente.
          </p>
        <?php else: ?>
          <p class="small text-muted mb-2">
            Usa esto solo si la API aún no trae marcador. Al guardar FT se liquida automáticamente.
          </p>
        <?php endif; ?>

        <div class="small text-muted mb-2">
          API actual: <?= htmlspecialchars(MatchView::statusLabel((string)$match['status'])) ?>
          · <?= (int)$match['home_score'] ?> : <?= (int)$match['away_score'] ?>
        </div>

        <?php if ($apiLocked): ?>
          <div class="row g-2 mb-2">
            <div class="col-6">
              <label class="form-label small text-muted"><?= htmlspecialchars((string)$match['home_name']) ?></label>
              <input class="form-control" type="number" value="<?= (int)$match['home_score'] ?>" readonly disabled>
            </div>
            <div class="col-6">
              <label class="form-label small text-muted"><?= htmlspecialchars((string)$match['away_name']) ?></label>
              <input class="form-control" type="number" value="<?= (int)$match['away_score'] ?>" readonly disabled>
            </div>
          </div>
          <div class="mb-0">
            <label class="form-label small text-muted">Estado</label>
            <input class="form-control" type="text" value="Finalizado" readonly disabled>
          </div>
        <?php else: ?>
          <form method="post" action="<?= htmlspecialchars(Url::to('/admin/matches/manual/save')) ?>" class="vstack gap-2">
            <input type="hidden" name="match_id" value="<?= (int)$match['id'] ?>">
            <div class="row g-2">
              <div class="col-6">
                <label class="form-label small text-muted"><?= htmlspecialchars((string)$match['home_name']) ?></label>
                <input class="form-control" type="number" min="0" name="home_score" value="<?= (int)($manual['home_score'] ?? $match['home_score'] ?? 0) ?>">
              </div>
              <div class="col-6">
                <label class="form-label small text-muted"><?= htmlspecialchars((string)$match['away_name']) ?></label>
                <input class="form-control" type="number" min="0" name="away_score" value="<?= (int)($manual['away_score'] ?? $match['away_score'] ?? 0) ?>">
              </div>
            </div>
            <div>
              <label class="form-label small text-muted">Estado manual</label>
              <select name="status" class="form-select">
                <?php foreach ($statusOptions as $value => $label): ?>
                  <option value="<?= htmlspecialchars($value) ?>" <?= $manualStatus === $value ? 'selected' : '' ?>>
                    <?= htmlspecialchars($label) ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
            <button class="btn btn-light">Guardar marcador manual</button>
          </form>
        <?php endif; ?>
      </div>

      <div class="card p-3 mt-3">
        <h6 class="mb-2">Stats para pronósticos especiales</h6>
        <p class="small text-muted">
          Ingresa córners y tarjetas. Goles totales y ambos marcan se calculan del marcador al guardar.
          La API tiene prioridad por campo cuando ya trae ese dato.
        </p>

        <?php
          $resolvedStats = $selectedId ? \App\Models\MatchStats::forMatch($selectedId) : null;
          $statsSource = (string)($resolvedStats['stats_source'] ?? 'api');
        ?>
        <div class="small text-muted mb-2">
          API stats:
          <?php if ($apiStats): ?>
            córners <?= $apiStats['total_corners'] ?? '—' ?>
            · tarjetas <?= $apiStats['total_cards'] ?? '—' ?>
          <?php else: ?>
            sin fila en BD
          <?php endif; ?>
          <?php if ($resolvedStats && $statsSource !== 'api'): ?>
            · <span class="badge badge-ns">fallback manual activo</span>
          <?php endif; ?>
        </div>

        <form method="post" action="<?= htmlspecialchars(Url::to('/admin/matches/manual/stats')) ?>" class="vstack gap-2">
          <input type="hidden" name="match_id" value="<?= (int)$match['id'] ?>">
          <div class="row g-2">
            <div class="col-6">
              <label class="form-label small text-muted">Córners local</label>
              <input class="form-control" type="number" min="0" name="home_corners"
                     value="<?= htmlspecialchars((string)($manualStats['home_corners'] ?? '')) ?>" placeholder="—">
            </div>
            <div class="col-6">
              <label class="form-label small text-muted">Córners visitante</label>
              <input class="form-control" type="number" min="0" name="away_corners"
                     value="<?= htmlspecialchars((string)($manualStats['away_corners'] ?? '')) ?>" placeholder="—">
            </div>
          </div>
          <div class="row g-2">
            <div class="col-6">
              <label class="form-label small text-muted">Amarillas (total partido)</label>
              <input class="form-control" type="number" min="0" name="total_yellow_cards"
                     value="<?= htmlspecialchars((string)($manualStats['total_yellow_cards'] ?? '')) ?>" placeholder="—">
            </div>
            <div class="col-6">
              <label class="form-label small text-muted">Rojas (total partido)</label>
              <input class="form-control" type="number" min="0" name="total_red_cards"
                     value="<?= htmlspecialchars((string)($manualStats['total_red_cards'] ?? '')) ?>" placeholder="—">
            </div>
          </div>
          <div class="small text-muted">Tarjetas totales = amarillas + rojas (las rojas cuentan en Más/Menos tarjetas).</div>
          <button class="btn btn-light">Guardar stats manuales</button>
        </form>
      </div>
    </div>
  </div>
<?php endif; ?>
