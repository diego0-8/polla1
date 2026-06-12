<?php
declare(strict_types=1);

use App\Core\Url;
use App\Helpers\MatchView;

/** @var int $season */
/** @var list<array<string, mixed>> $matches */
/** @var int $selectedId */
/** @var array<string, mixed>|null $match */
/** @var array<string, mixed>|null $manual */
/** @var list<array<string, mixed>> $apiEvents */
/** @var list<array<string, mixed>> $manualEvents */
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
      La API tiene prioridad en marcador. Stats y cronología manual se usan para liquidar props y mostrar eventos cuando la API no los publica.
    </div>
  </div>
  <div class="d-flex gap-2 flex-wrap">
    <a class="btn btn-outline-light btn-sm" href="<?= htmlspecialchars(Url::to('/matches/show') . '?id=' . (int)$selectedId) ?>">Ver partido</a>
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
      <label class="form-label small text-muted">Partido temporada <?= (int)$season ?></label>
      <select name="id" class="form-select">
        <?php foreach ($matches as $m): ?>
          <option value="<?= (int)$m['id'] ?>" <?= (int)$m['id'] === $selectedId ? 'selected' : '' ?>>
            #<?= (int)$m['id'] ?> · <?= htmlspecialchars(MatchView::formatKickoff((string)$m['kickoff_at'])) ?>
            · <?= htmlspecialchars((string)$m['home_name']) ?> vs <?= htmlspecialchars((string)$m['away_name']) ?>
            · <?= htmlspecialchars(MatchView::statusLabel((string)$m['status'])) ?>
            <?php if (($m['data_source'] ?? 'api') === 'manual'): ?> · Manual<?php endif; ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-12 col-lg-2 d-grid">
      <button class="btn btn-light">Abrir</button>
    </div>
  </form>
</div>

<?php if (!$match): ?>
  <div class="card p-3 text-muted">Selecciona un partido.</div>
<?php else: ?>
  <div class="row g-3">
    <div class="col-12 col-lg-5">
      <div class="card p-3">
        <h6 class="mb-2">Marcador manual</h6>
        <p class="small text-muted">
          Complementa la API cuando no trae marcador final. Al guardar FT se liquida automáticamente y se recalculan posiciones de grupo.
        </p>

        <div class="small text-muted mb-2">
          API actual: <?= htmlspecialchars(MatchView::statusLabel((string)$match['status'])) ?>
          · <?= (int)$match['home_score'] ?> : <?= (int)$match['away_score'] ?>
          · eventos API: <?= count($apiEvents) ?>
        </div>

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
          <div>
            <label class="form-label small text-muted">Nota interna</label>
            <input class="form-control" name="note" maxlength="255" value="<?= htmlspecialchars((string)($manual['note'] ?? '')) ?>" placeholder="Ej: cargado desde transmisión">
          </div>
          <button class="btn btn-light">Guardar marcador manual</button>
        </form>
      </div>

      <div class="card p-3 mt-3">
        <h6 class="mb-2">Stats para pronósticos especiales</h6>
        <p class="small text-muted">
          Córners, tarjetas, goles y BTTS para liquidar props (BTTS, Más/Menos goles, córners y tarjetas).
          La API tiene prioridad por campo cuando ya trae ese dato.
        </p>

        <?php
          $resolvedStats = $match && $selectedId ? \App\Models\MatchStats::forMatch($selectedId) : null;
          $statsSource = (string)($resolvedStats['stats_source'] ?? 'api');
        ?>
        <div class="small text-muted mb-2">
          API stats:
          <?php if ($apiStats): ?>
            córners <?= $apiStats['total_corners'] ?? '—' ?>
            · tarjetas <?= $apiStats['total_cards'] ?? '—' ?>
            · goles <?= $apiStats['total_goals'] ?? '—' ?>
            · BTTS <?= isset($apiStats['btts']) ? ((int)$apiStats['btts'] ? 'Sí' : 'No') : '—' ?>
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
          <div>
            <label class="form-label small text-muted">Goles totales (90 min + adición)</label>
            <input class="form-control" type="number" min="0" name="total_goals"
                   value="<?= htmlspecialchars((string)($manualStats['total_goals'] ?? '')) ?>"
                   placeholder="Vacío = usar marcador del partido">
          </div>
          <div>
            <label class="form-label small text-muted">Ambos marcan (BTTS)</label>
            <?php
              $bttsValue = 'auto';
              if ($manualStats !== null && array_key_exists('btts', $manualStats) && $manualStats['btts'] !== null) {
                  $bttsValue = (int)$manualStats['btts'] === 1 ? 'yes' : 'no';
              }
            ?>
            <select name="btts" class="form-select">
              <option value="auto" <?= $bttsValue === 'auto' ? 'selected' : '' ?>>Auto (desde marcador)</option>
              <option value="yes" <?= $bttsValue === 'yes' ? 'selected' : '' ?>>Sí</option>
              <option value="no" <?= $bttsValue === 'no' ? 'selected' : '' ?>>No</option>
            </select>
          </div>
          <div>
            <label class="form-label small text-muted">Nota interna stats</label>
            <input class="form-control" name="stats_note" maxlength="255"
                   value="<?= htmlspecialchars((string)($manualStats['note'] ?? '')) ?>"
                   placeholder="Ej: stats desde transmisión TV">
          </div>
          <button class="btn btn-light">Guardar stats manuales</button>
        </form>
      </div>
    </div>

    <div class="col-12 col-lg-7">
      <div class="card p-3">
        <h6 class="mb-2">Agregar evento manual</h6>
        <p class="small text-muted">
          Los eventos manuales aparecen solo si la API no entrega cronología para este partido.
        </p>
        <form method="post" action="<?= htmlspecialchars(Url::to('/admin/matches/manual/events')) ?>" class="row g-2 align-items-end">
          <input type="hidden" name="match_id" value="<?= (int)$match['id'] ?>">
          <div class="col-4 col-lg-2">
            <label class="form-label small text-muted">Min</label>
            <input class="form-control" type="number" min="0" max="130" name="minute" value="0" required>
          </div>
          <div class="col-4 col-lg-2">
            <label class="form-label small text-muted">+</label>
            <input class="form-control" type="number" min="0" max="30" name="extra_minute" placeholder="">
          </div>
          <div class="col-4 col-lg-3">
            <label class="form-label small text-muted">Tipo</label>
            <select class="form-select" name="type">
              <option value="Goal">Gol</option>
              <option value="Card">Tarjeta</option>
              <option value="subst">Cambio</option>
            </select>
          </div>
          <div class="col-12 col-lg-5">
            <label class="form-label small text-muted">Equipo</label>
            <select class="form-select" name="team_side">
              <option value="">Sin equipo</option>
              <option value="home"><?= htmlspecialchars((string)$match['home_name']) ?></option>
              <option value="away"><?= htmlspecialchars((string)$match['away_name']) ?></option>
            </select>
          </div>
          <div class="col-12 col-lg-4">
            <label class="form-label small text-muted">Detalle</label>
            <input class="form-control" name="detail" placeholder="REGULAR, PENALTY, YELLOW, RED">
          </div>
          <div class="col-12 col-lg-4">
            <label class="form-label small text-muted">Jugador</label>
            <input class="form-control" name="player_name" maxlength="120">
          </div>
          <div class="col-12 col-lg-4">
            <label class="form-label small text-muted">Asistencia / entra</label>
            <input class="form-control" name="assist_name" maxlength="120">
          </div>
          <div class="col-12 d-grid">
            <button class="btn btn-light">Agregar evento</button>
          </div>
        </form>
      </div>

      <div class="card p-3 mt-3">
        <h6 class="mb-2">Eventos manuales guardados (<?= count($manualEvents) ?>)</h6>
        <?php if (!$manualEvents): ?>
          <div class="small text-muted">No hay eventos manuales.</div>
        <?php else: ?>
          <div class="vstack gap-2">
            <?php foreach ($manualEvents as $e): ?>
              <div class="d-flex justify-content-between align-items-start gap-2">
                <div>
                  <span class="small text-muted"><?= (int)$e['minute'] ?><?= $e['extra_minute'] !== null ? '+' . (int)$e['extra_minute'] : '' ?>'</span>
                  <span class="fw-semibold ms-2"><?= htmlspecialchars(MatchView::eventTitle($e)) ?></span>
                  <span class="text-muted small">· <?= htmlspecialchars(MatchView::eventDetail($e)) ?></span>
                  <?php $players = MatchView::eventPlayersLine($e); ?>
                  <?php if ($players !== ''): ?>
                    <div class="small text-muted"><?= htmlspecialchars($players) ?></div>
                  <?php endif; ?>
                </div>
                <form method="post" action="<?= htmlspecialchars(Url::to('/admin/matches/manual/events/delete')) ?>" class="m-0">
                  <input type="hidden" name="match_id" value="<?= (int)$match['id'] ?>">
                  <input type="hidden" name="event_id" value="<?= (int)$e['id'] ?>">
                  <button class="btn btn-outline-danger btn-sm">Eliminar</button>
                </form>
              </div>
              <hr class="my-1 border-light opacity-10">
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
<?php endif; ?>
