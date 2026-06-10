<?php
declare(strict_types=1);

use App\Core\Url;
use App\Helpers\MatchView;

/** @var array $matches */
/** @var array $top */
/** @var int $season */
/** @var array|null $championPick */
/** @var bool $championPickOpen */
?>

<?php if (\App\Core\Auth::check() && !$championPick && $championPickOpen): ?>
  <div class="alert alert-warning py-2 small d-flex justify-content-between align-items-center flex-wrap gap-2">
    <span>Elige tu campeón antes del primer partido. Bono final: 20 puntos.</span>
    <a class="btn btn-sm btn-dark" href="<?= htmlspecialchars(Url::to('/tournament-pick')) ?>">Elegir campeón</a>
  </div>
<?php endif; ?>

<div class="row g-3">
  <div class="col-12 col-lg-7">
    <div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2">
      <div>
        <h5 class="mb-0">Mundial <?= (int)$season ?></h5>
        <div class="small text-muted">Próximos partidos · Football-Data.org</div>
      </div>
      <a class="btn btn-outline-light btn-sm" href="<?= htmlspecialchars(Url::to('/matches')) ?>">Ver todos</a>
    </div>

    <?php if (!$matches): ?>
      <div class="card p-3">
        <div class="text-muted">
          Aún no hay partidos del <?= (int)$season ?> cargados.
          Ejecuta: <code>php scripts/validate_sync.php --sync</code>
          (token en <code>app/Config/local.php</code>).
        </div>
      </div>
    <?php else: ?>
      <div class="vstack gap-2">
        <?php foreach ($matches as $m): ?>
          <?php $compact = true; require __DIR__ . '/../partials/match_card.php'; ?>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

  <div class="col-12 col-lg-5">
    <div class="d-flex justify-content-between align-items-center mb-2">
      <h5 class="mb-0">Tabla de posiciones</h5>
      <a class="btn btn-outline-light btn-sm" href="<?= htmlspecialchars(Url::to('/leaderboard')) ?>">Ver ranking</a>
    </div>

    <div class="card p-0 overflow-hidden">
      <div class="list-group list-group-flush">
        <?php if (!$top): ?>
          <div class="list-group-item">
            <div class="text-muted">Aún no hay puntajes calculados.</div>
          </div>
        <?php endif; ?>
        <?php foreach ($top as $i => $r): ?>
          <?php $unpaid = (int)($r['has_paid'] ?? 0) === 0; ?>
          <div class="list-group-item d-flex justify-content-between align-items-center<?= $unpaid ? ' leaderboard-row--unpaid' : '' ?>">
            <div class="d-flex gap-2">
              <div class="text-muted"><?= $i + 1 ?>.</div>
              <div>
                <div><?= htmlspecialchars((string)$r['name']) ?></div>
                <div class="small text-muted">
                  E:<?= (int)$r['exact_hits'] ?> · T:<?= (int)$r['trend_hits'] ?> · P:<?= (int)($r['prop_hits'] ?? 0) ?>
                </div>
              </div>
            </div>
            <div class="fw-semibold"><?= (int)$r['points_total'] ?></div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</div>
