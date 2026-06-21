<?php
declare(strict_types=1);

use App\Core\Auth;
use App\Core\Url;
use App\Helpers\MatchView;
use App\Models\PropPrediction;

/** @var array $match */
/** @var bool $allowsPropPredictions */
/** @var bool $isKnockout */
/** @var bool $isOpen */
/** @var array<string, array<string, mixed>|null> $propPredictions */
/** @var array|null $matchStats */
/** @var string|null $propFlash */
/** @var string|null $propError */

if (!$allowsPropPredictions) {
    return;
}

$user = Auth::user();
$showOuPoints = Auth::canSeePropOuPoints();
$markets = [
    'btts' => ['title' => 'Ambos marcan', 'isOu' => false],
    'goals_ou' => ['title' => 'Goles Más/Menos', 'isOu' => true],
    'corners_ou' => ['title' => 'Córners Más/Menos', 'isOu' => true],
    'cards_ou' => ['title' => 'Tarjetas Más/Menos', 'isOu' => true],
];
?>

<div class="mt-3">
  <h6 class="mb-2">Pronósticos especiales</h6>
  <p class="small text-muted mb-2">
    Disponibles en todo el fixture: grupos, dieciseisavos, octavos, cuartos, semifinales, tercer puesto y final.
    <?php if ($isKnockout): ?>
      En eliminatorias cuenta el resultado de los 90 minutos más adición.
    <?php endif; ?>
  </p>

  <?php if (!empty($propFlash)): ?>
    <div class="alert alert-success py-2 small mb-2"><?= htmlspecialchars($propFlash) ?></div>
  <?php endif; ?>
  <?php if (!empty($propError)): ?>
    <div class="alert alert-danger py-2 small mb-2"><?= htmlspecialchars($propError) ?></div>
  <?php endif; ?>

  <?php if ($matchStats): ?>
    <div class="card p-2 mb-2">
      <div class="d-flex justify-content-between align-items-start gap-2 mb-1">
        <div class="small text-muted">Resultado real del partido</div>
        <span class="<?= htmlspecialchars(MatchView::statsSourceBadgeClass($matchStats)) ?> small">
          <?= htmlspecialchars(MatchView::statsSourceLabel($matchStats)) ?>
        </span>
      </div>
      <div class="small">
        Córners: <?= (int)($matchStats['total_corners'] ?? 0) ?>
        · Tarjetas: <?= (int)($matchStats['total_cards'] ?? 0) ?>
        <?php if (isset($matchStats['total_yellow_cards']) || isset($matchStats['total_red_cards'])): ?>
          (<?= (int)($matchStats['total_yellow_cards'] ?? 0) ?> amarillas · <?= (int)($matchStats['total_red_cards'] ?? 0) ?> rojas)
        <?php endif; ?>
        · BTTS: <?= !empty($matchStats['btts']) ? 'Sí' : 'No' ?>
        · Goles: <?= (int)($matchStats['total_goals'] ?? 0) ?>
      </div>
      <div class="small text-muted mt-1">Las rojas cuentan en el total para Tarjetas Más/Menos.</div>
    </div>
  <?php else: ?>
    <div class="small text-muted mb-2">
      Stats del partido pendientes. Los props se liquidan cuando la API o el admin publiquen córners, tarjetas y BTTS.
    </div>
  <?php endif; ?>

  <div class="vstack gap-2">
    <?php foreach ($markets as $market => $meta): ?>
      <?php
        $saved = $propPredictions[$market] ?? null;
        $lines = PropPrediction::allowedLines($market);
        $isOu = (bool)$meta['isOu'];
      ?>
      <div class="card p-3">
        <div class="d-flex justify-content-between align-items-center mb-2">
          <h6 class="mb-0 small"><?= htmlspecialchars($meta['title']) ?></h6>
          <?php if (!$isOu): ?>
            <span class="badge badge-ns"><?= PropPrediction::pointsForMarket('btts') ?> pts</span>
          <?php endif; ?>
        </div>

        <?php if (!$user): ?>
          <div class="text-muted small">Inicia sesión para registrar.</div>
        <?php elseif ($saved): ?>
          <div class="d-flex align-items-center justify-content-between rounded px-2 py-2 bg-dark bg-opacity-25">
            <span class="fw-semibold small"><?= htmlspecialchars(PropPrediction::label($saved)) ?></span>
            <span class="badge badge-ft">Guardado</span>
          </div>
        <?php elseif (!$isOpen): ?>
          <div class="badge badge-live">Cerrado</div>
          <div class="text-muted small mt-1">No registraste a tiempo.</div>
        <?php else: ?>
          <form method="post" action="<?= htmlspecialchars(Url::to('/prop-predictions')) ?>" class="vstack gap-2 prop-prediction-form">
            <input type="hidden" name="match_id" value="<?= (int)$match['id'] ?>">
            <input type="hidden" name="market" value="<?= htmlspecialchars($market) ?>">

            <?php if ($market === 'btts'): ?>
              <label class="prediction-outcome-option">
                <input type="radio" name="pick" value="yes" class="form-check-input me-2" required>
                <span class="fw-semibold">Sí</span>
                <span class="badge badge-ns ms-1"><?= PropPrediction::pointsForMarket('btts') ?> pts</span>
              </label>
              <label class="prediction-outcome-option">
                <input type="radio" name="pick" value="no" class="form-check-input me-2">
                <span class="fw-semibold">No</span>
                <span class="badge badge-ns ms-1"><?= PropPrediction::pointsForMarket('btts') ?> pts</span>
              </label>
            <?php else: ?>
              <div>
                <label class="form-label small text-muted mb-1">Línea</label>
                <select
                  class="form-select form-select-sm prop-ou-line"
                  name="line"
                  required
                  <?php if ($showOuPoints): ?>
                    data-ou-points="<?= htmlspecialchars(json_encode(PropPrediction::ouPointsMatrix($market), JSON_UNESCAPED_UNICODE)) ?>"
                  <?php endif; ?>
                >
                  <option value="">Selecciona línea</option>
                  <?php foreach ($lines as $line): ?>
                    <option value="<?= htmlspecialchars((string)$line) ?>">
                      <?= htmlspecialchars((string)$line) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
              <label class="prediction-outcome-option">
                <input type="radio" name="pick" value="over" class="form-check-input me-2" required>
                <span class="fw-semibold">Más</span>
                <?php if ($showOuPoints): ?>
                  <span class="badge badge-ns ms-1 prop-ou-pts-over" hidden></span>
                <?php endif; ?>
              </label>
              <label class="prediction-outcome-option">
                <input type="radio" name="pick" value="under" class="form-check-input me-2">
                <span class="fw-semibold">Menos</span>
                <?php if ($showOuPoints): ?>
                  <span class="badge badge-ns ms-1 prop-ou-pts-under" hidden></span>
                <?php endif; ?>
              </label>
            <?php endif; ?>

            <button class="btn btn-light w-100 btn-sm">Guardar</button>
          </form>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>
</div>

<?php if ($showOuPoints && $user && $isOpen): ?>
  <script>
    (function () {
      function lineKey(value) {
        var n = parseFloat(value);
        if (Number.isNaN(n)) {
          return '';
        }
        return String(n);
      }

      function updateOuBadges(select) {
        var raw = select.getAttribute('data-ou-points');
        if (!raw) {
          return;
        }
        var matrix;
        try {
          matrix = JSON.parse(raw);
        } catch (e) {
          return;
        }

        var form = select.closest('form');
        if (!form) {
          return;
        }

        var overBadge = form.querySelector('.prop-ou-pts-over');
        var underBadge = form.querySelector('.prop-ou-pts-under');
        if (!overBadge || !underBadge) {
          return;
        }

        var key = lineKey(select.value);
        if (!key || !matrix.over || !matrix.under) {
          overBadge.hidden = true;
          underBadge.hidden = true;
          return;
        }

        var overPts = matrix.over[key];
        var underPts = matrix.under[key];
        if (overPts === undefined || underPts === undefined) {
          overBadge.hidden = true;
          underBadge.hidden = true;
          return;
        }

        overBadge.textContent = overPts + ' pts';
        underBadge.textContent = underPts + ' pts';
        overBadge.hidden = false;
        underBadge.hidden = false;
      }

      document.querySelectorAll('.prop-ou-line').forEach(function (select) {
        select.addEventListener('change', function () {
          updateOuBadges(select);
        });
        updateOuBadges(select);
      });
    })();
  </script>
<?php endif; ?>
