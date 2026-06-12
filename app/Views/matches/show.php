<?php
declare(strict_types=1);

use App\Core\Auth;
use App\Core\Url;
use App\Helpers\MatchView;
use App\Models\MatchModel;
use App\Models\Prediction;

/** @var array $match */
/** @var array $events */
/** @var array|null $exactPrediction */
/** @var array|null $outcomePrediction */
/** @var array|null $advancePrediction */
/** @var string|null $predictionFlash */
/** @var string|null $predictionError */
/** @var string|null $propFlash */
/** @var string|null $propError */
/** @var array<string, array<string, mixed>|null> $propPredictions */
/** @var array|null $matchStats */
/** @var bool $allowsPropPredictions */
/** @var array $groupStandings */
/** @var string|null $groupCode */

$user = Auth::user();
$isOpen = MatchModel::isPredictionOpen($match);
$isKnockout = MatchModel::isKnockout($match);
$canPredictAdvance = MatchModel::canPredictAdvance($match);
$isFinal = MatchModel::isFinal($match);
$status = (string)($match['status'] ?? 'NS');
$outcomeCode = $outcomePrediction ? (string)($outcomePrediction['pred_outcome'] ?? '') : '';
$advanceTitle = $isFinal ? '¿Quién gana la final?' : '¿Quién avanza?';
$advanceClosedText = $isFinal ? 'No registraste ganador de la final a tiempo.' : 'No registraste clasificado a tiempo.';
$autoRefresh = MatchView::shouldAutoRefreshMatch($match);
?>

<?php if ($autoRefresh): ?>
  <script>
    window.setTimeout(function () {
      window.location.reload();
    }, 30000);
  </script>
<?php endif; ?>

<div class="row g-3">
  <div class="col-12 col-lg-7">
    <div class="d-flex align-items-center justify-content-between mb-2 flex-wrap gap-2">
      <a class="btn btn-outline-light btn-sm" href="<?= htmlspecialchars(Url::to('/matches')) ?>">← Partidos</a>
      <span class="small text-muted">
        Mundial <?= MatchView::season() ?>
        <?php if ($autoRefresh): ?> · auto-sync 30s<?php endif; ?>
      </span>
    </div>

    <div class="card p-3">
      <div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
        <div class="d-flex align-items-center gap-2">
          <?php if (!empty($match['home_logo'])): ?><img class="team-logo" src="<?= htmlspecialchars((string)$match['home_logo']) ?>" alt=""><?php endif; ?>
          <div>
            <div class="fw-semibold"><?= htmlspecialchars((string)$match['home_name']) ?></div>
            <?php if (!empty($match['home_code'])): ?>
              <div class="small text-muted"><?= htmlspecialchars((string)$match['home_code']) ?></div>
            <?php endif; ?>
          </div>
        </div>
        <div class="text-center">
          <div class="fw-semibold fs-4"><?= (int)$match['home_score'] ?> : <?= (int)$match['away_score'] ?></div>
          <span class="<?= htmlspecialchars(MatchView::statusBadgeClass($status)) ?>">
            <?= htmlspecialchars(MatchView::statusLabel($status)) ?>
          </span>
          <?php if (($match['data_source'] ?? 'api') === 'manual'): ?>
            <div class="mt-1">
              <span class="<?= htmlspecialchars(MatchView::dataSourceBadgeClass($match)) ?>">
                <?= htmlspecialchars(MatchView::dataSourceLabel($match)) ?>
              </span>
            </div>
          <?php endif; ?>
        </div>
        <div class="d-flex align-items-center gap-2">
          <div class="text-end">
            <div class="fw-semibold"><?= htmlspecialchars((string)$match['away_name']) ?></div>
            <?php if (!empty($match['away_code'])): ?>
              <div class="small text-muted"><?= htmlspecialchars((string)$match['away_code']) ?></div>
            <?php endif; ?>
          </div>
          <?php if (!empty($match['away_logo'])): ?><img class="team-logo" src="<?= htmlspecialchars((string)$match['away_logo']) ?>" alt=""><?php endif; ?>
        </div>
      </div>
      <div class="small text-muted mt-2"><?= htmlspecialchars(MatchView::formatKickoff((string)$match['kickoff_at'])) ?></div>
      <?php if (!empty($match['stage'])): ?>
        <div class="small text-muted"><?= htmlspecialchars((string)$match['stage']) ?></div>
      <?php endif; ?>
    </div>

    <ul class="nav nav-pills mt-3" role="tablist">
      <li class="nav-item" role="presentation">
        <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tabEvents" type="button" role="tab">
          Cronología <?= $events ? '(' . count($events) . ')' : '' ?>
          <?php if (($match['events_source'] ?? 'api') === 'manual'): ?> · manual<?php endif; ?>
        </button>
      </li>
      <li class="nav-item" role="presentation">
        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tabInfo" type="button" role="tab">Info</button>
      </li>
    </ul>

    <div class="tab-content mt-2">
      <div class="tab-pane fade show active" id="tabEvents" role="tabpanel">
        <div class="card p-3">
          <?php if ($events): ?>
            <div class="d-flex justify-content-end mb-2">
              <span class="<?= htmlspecialchars(MatchView::eventsSourceBadgeClass($match)) ?> small">
                <?= htmlspecialchars(MatchView::eventsSourceLabel($match)) ?>
              </span>
            </div>
          <?php endif; ?>
          <?php if (!$events): ?>
            <div class="text-muted">
              <?= htmlspecialchars(MatchView::emptyEventsMessage($match, $events)) ?>
            </div>
          <?php else: ?>
            <div class="vstack gap-2">
              <?php foreach ($events as $e): ?>
                <?php
                  $eventType = strtolower((string)($e['type'] ?? ''));
                  $rowClass = match ($eventType) {
                      'goal' => 'event-row event-goal',
                      'card' => 'event-row event-card',
                      'subst' => 'event-row event-subst',
                      default => 'event-row',
                  };
                ?>
                <div class="d-flex justify-content-between align-items-start <?= $rowClass ?>">
                  <div class="small text-muted event-minute">
                    <?= (int)$e['minute'] ?><?= $e['extra_minute'] !== null ? '+' . (int)$e['extra_minute'] : '' ?>'
                  </div>
                  <div class="flex-grow-1 px-3">
                    <div class="fw-semibold"><?= htmlspecialchars(MatchView::eventTitle($e)) ?></div>
                    <div class="small text-muted">
                      <?= htmlspecialchars(MatchView::eventDetail($e)) ?>
                      <?php $players = MatchView::eventPlayersLine($e); ?>
                      <?php if ($players !== ''): ?>
                        · <?= htmlspecialchars($players) ?>
                      <?php endif; ?>
                    </div>
                  </div>
                </div>
                <hr class="my-1 border-light opacity-10">
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <div class="tab-pane fade" id="tabInfo" role="tabpanel">
        <div class="card p-3">
          <dl class="mb-0 match-info-dl">
            <dt class="small text-muted">Fecha</dt>
            <dd class="mb-2"><?= htmlspecialchars(MatchView::formatKickoffDate((string)$match['kickoff_at'])) ?></dd>

            <dt class="small text-muted">Hora del partido</dt>
            <dd class="mb-2"><?= htmlspecialchars(MatchView::formatKickoffTime((string)$match['kickoff_at'])) ?></dd>

            <dt class="small text-muted">Estadio</dt>
            <dd class="mb-2"><?= htmlspecialchars(MatchView::venueLabel($match)) ?></dd>

            <dt class="small text-muted">Etapa</dt>
            <dd class="mb-2"><?= htmlspecialchars(MatchView::stageLabel((string)($match['stage'] ?? ''))) ?></dd>

            <dt class="small text-muted">Última sincronización</dt>
            <dd class="mb-0"><?= htmlspecialchars(MatchView::formatLastSynced(isset($match['last_synced_at']) ? (string)$match['last_synced_at'] : null)) ?></dd>
            <?php if (($match['data_source'] ?? 'api') === 'manual' && !empty($match['manual_updated_at'])): ?>
              <dt class="small text-muted mt-2">Actualización manual</dt>
              <dd class="mb-0">
                <?= htmlspecialchars(MatchView::formatLastSynced((string)$match['manual_updated_at'])) ?>
                <?php if (!empty($match['manual_note'])): ?>
                  · <?= htmlspecialchars((string)$match['manual_note']) ?>
                <?php endif; ?>
              </dd>
            <?php endif; ?>
          </dl>
        </div>
      </div>
    </div>
  </div>

  <div class="col-12 col-lg-5">
    <?php if (!empty($predictionFlash)): ?>
      <div class="alert alert-success py-2 small mb-2"><?= htmlspecialchars($predictionFlash) ?></div>
    <?php endif; ?>
    <?php if (!empty($predictionError)): ?>
      <div class="alert alert-danger py-2 small mb-2"><?= htmlspecialchars($predictionError) ?></div>
    <?php endif; ?>

    <div class="card p-3 mb-3">
      <h6 class="mb-2">Marcador exacto <span class="text-muted fw-normal">(5 pts)</span></h6>
      <p class="small text-muted mb-2">
        Solo un pronóstico por partido. No se puede modificar después de guardarlo.
        <?php if ($isKnockout): ?>
          En eliminación directa cuenta el resultado de los 90 minutos más adición.
        <?php endif; ?>
        <?php if ($isOpen): ?>
          Cierra 5 minutos antes de iniciar el partido.
        <?php endif; ?>
      </p>

      <?php if (!$user): ?>
        <div class="text-muted mb-2">Para interactuar necesitas iniciar sesión.</div>
        <a class="btn btn-light btn-sm" href="<?= htmlspecialchars(Url::to('/login')) ?>">Entrar</a>
      <?php elseif ($exactPrediction): ?>
        <div class="d-flex align-items-center justify-content-between rounded px-2 py-2 bg-dark bg-opacity-25">
          <span class="fw-semibold">
            <?= (int)$exactPrediction['pred_home'] ?> : <?= (int)$exactPrediction['pred_away'] ?>
          </span>
          <span class="badge badge-ft">Guardado</span>
        </div>
      <?php elseif (!$isOpen): ?>
        <div class="badge badge-live">Cerrado</div>
        <div class="text-muted small mt-1">No registraste marcador exacto a tiempo.</div>
      <?php else: ?>
        <form method="post" action="<?= htmlspecialchars(Url::to('/predictions')) ?>" class="vstack gap-2">
          <input type="hidden" name="match_id" value="<?= (int)$match['id'] ?>">
          <input type="hidden" name="pred_type" value="exact">
          <div class="row g-2">
            <div class="col-6">
              <label class="form-label small text-muted mb-1"><?= htmlspecialchars((string)$match['home_name']) ?></label>
              <input class="form-control" type="number" min="0" name="pred_home" value="0" required>
            </div>
            <div class="col-6">
              <label class="form-label small text-muted mb-1"><?= htmlspecialchars((string)$match['away_name']) ?></label>
              <input class="form-control" type="number" min="0" name="pred_away" value="0" required>
            </div>
          </div>
          <button class="btn btn-light w-100 btn-sm">Guardar marcador</button>
        </form>
      <?php endif; ?>
    </div>

    <div class="card p-3">
      <h6 class="mb-2"><?= htmlspecialchars($isKnockout ? $advanceTitle : 'Ganador / Empate') ?></h6>
      <p class="small text-muted mb-2">
        <?php if ($isKnockout): ?>
          Solo puedes elegir local o visitante. Este punto extra se liquida por el equipo que clasifica.
        <?php else: ?>
          Solo un pronóstico por partido. No se puede modificar después de guardarlo.
        <?php endif; ?>
      </p>

      <?php if (!$user): ?>
        <div class="text-muted small">Inicia sesión para registrar.</div>
      <?php elseif ($isKnockout && $advancePrediction): ?>
        <div class="d-flex align-items-center justify-content-between rounded px-2 py-2 bg-dark bg-opacity-25">
          <span class="fw-semibold"><?= htmlspecialchars(Prediction::advanceLabel($advancePrediction)) ?></span>
          <span class="badge badge-ft">Guardado</span>
        </div>
      <?php elseif (!$isKnockout && $outcomePrediction): ?>
        <div class="d-flex align-items-center justify-content-between rounded px-2 py-2 bg-dark bg-opacity-25">
          <span class="fw-semibold"><?= htmlspecialchars(Prediction::outcomeLabel($outcomeCode, $match)) ?></span>
          <span class="badge badge-ft">Guardado</span>
        </div>
      <?php elseif ($isKnockout && $isOpen && !$canPredictAdvance): ?>
        <div class="text-muted small">Los equipos aún no están confirmados. Vuelve cuando se definan los rivales.</div>
      <?php elseif (!$isOpen): ?>
        <div class="badge badge-live">Cerrado</div>
        <div class="text-muted small mt-1"><?= htmlspecialchars($isKnockout ? $advanceClosedText : 'No registraste ganador/empate a tiempo.') ?></div>
      <?php elseif ($isKnockout): ?>
        <form method="post" action="<?= htmlspecialchars(Url::to('/predictions')) ?>" class="vstack gap-2">
          <input type="hidden" name="match_id" value="<?= (int)$match['id'] ?>">
          <input type="hidden" name="pred_type" value="advance">
          <label class="prediction-outcome-option">
            <input type="radio" name="advances_team_id" value="<?= (int)$match['home_team_id'] ?>" class="form-check-input me-2" required>
            <span class="fw-semibold"><?= htmlspecialchars((string)$match['home_name']) ?></span>
            <span class="badge badge-ns ms-1">2 pts</span>
          </label>
          <label class="prediction-outcome-option">
            <input type="radio" name="advances_team_id" value="<?= (int)$match['away_team_id'] ?>" class="form-check-input me-2">
            <span class="fw-semibold"><?= htmlspecialchars((string)$match['away_name']) ?></span>
            <span class="badge badge-ns ms-1">2 pts</span>
          </label>
          <button class="btn btn-light w-100 btn-sm mt-1">Guardar <?= htmlspecialchars(strtolower($advanceTitle)) ?></button>
        </form>
      <?php else: ?>
        <form method="post" action="<?= htmlspecialchars(Url::to('/predictions')) ?>" class="vstack gap-2">
          <input type="hidden" name="match_id" value="<?= (int)$match['id'] ?>">
          <input type="hidden" name="pred_type" value="outcome">
          <label class="prediction-outcome-option">
            <input type="radio" name="pred_outcome" value="H" class="form-check-input me-2" required>
            <span class="fw-semibold">Local</span>
            <span class="text-muted">· <?= htmlspecialchars((string)$match['home_name']) ?></span>
            <span class="badge badge-ns ms-1">3 pts</span>
          </label>
          <label class="prediction-outcome-option">
            <input type="radio" name="pred_outcome" value="D" class="form-check-input me-2">
            <span class="fw-semibold">Empate</span>
            <span class="badge badge-ft ms-1">3 pts</span>
          </label>
          <label class="prediction-outcome-option">
            <input type="radio" name="pred_outcome" value="A" class="form-check-input me-2">
            <span class="fw-semibold">Visitante</span>
            <span class="text-muted">· <?= htmlspecialchars((string)$match['away_name']) ?></span>
            <span class="badge badge-ns ms-1">3 pts</span>
          </label>
          <button class="btn btn-light w-100 btn-sm mt-1">Guardar ganador/empate</button>
        </form>
      <?php endif; ?>
    </div>

    <?php
      require __DIR__ . '/../partials/match_prop_predictions.php';
      require __DIR__ . '/../partials/group_standings_table.php';
    ?>
  </div>
</div>
