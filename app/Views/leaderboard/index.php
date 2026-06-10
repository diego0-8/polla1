<?php
declare(strict_types=1);

use App\Core\Url;

/** @var array $rows */
/** @var int $season */
/** @var array<string, mixed> $prizes */
/** @var bool $showPrizes */
/** @var list<array{position:int,amount:int}> $prizeList */
/** @var int $prizeCount */

$prizeLabels = ['1.er lugar', '2.º lugar', '3.er lugar', '4.º lugar', '5.º lugar'];
?>

<div class="d-flex justify-content-between align-items-center mb-2">
  <div>
    <h5 class="mb-0">Tabla de posiciones — Mundial <?= (int)$season ?></h5>
    <div class="small text-muted">Desempates: exactos, tendencias, props acertados y primer pronóstico registrado.</div>
  </div>
  <a class="btn btn-outline-light btn-sm" href="<?= htmlspecialchars(Url::to('/')) ?>">Inicio</a>
</div>

<?php if ($showPrizes): ?>
  <div class="card p-3 mb-3 leaderboard-prizes">
    <h6 class="mb-2">Premios en juego (COP)</h6>
    <div class="d-flex flex-wrap gap-2">
      <?php foreach ($prizeList as $p): ?>
        <?php $pos = (int)$p['position']; ?>
        <span class="badge leaderboard-prize-badge leaderboard-prize-badge--<?= $pos ?>">
          <?= $prizeLabels[$pos - 1] ?? ($pos . 'º lugar') ?>:
          $<?= number_format((int)$p['amount'], 0, ',', '.') ?>
        </span>
      <?php endforeach; ?>
    </div>
  </div>
<?php endif; ?>

<div class="card p-0 overflow-hidden">
  <div class="table-responsive">
    <table class="table table-dark table-striped mb-0 align-middle">
      <thead>
        <tr>
          <th style="width:80px;">#</th>
          <th>Usuario</th>
          <th class="text-end">Exactos</th>
          <th class="text-end">Tendencias</th>
          <th class="text-end">Props</th>
          <th class="text-muted">Primer pronóstico</th>
          <th class="text-end">Puntos</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$rows): ?>
          <tr><td colspan="7" class="text-muted">Aún no hay puntajes.</td></tr>
        <?php endif; ?>
        <?php foreach ($rows as $i => $r): ?>
          <?php
            $pos = $i + 1;
            $unpaid = (int)($r['has_paid'] ?? 0) === 0;
            $isPrizeRow = $showPrizes && $pos <= $prizeCount;
          ?>
          <tr class="<?= $unpaid ? 'leaderboard-row--unpaid' : '' ?>">
            <td class="<?= $isPrizeRow ? 'leaderboard-pos leaderboard-pos--' . $pos : 'text-muted' ?>">
              <?= $pos ?>
            </td>
            <td><?= htmlspecialchars((string)$r['name']) ?></td>
            <td class="text-end"><?= (int)$r['exact_hits'] ?></td>
            <td class="text-end"><?= (int)$r['trend_hits'] ?></td>
            <td class="text-end"><?= (int)($r['prop_hits'] ?? 0) ?></td>
            <td class="small text-muted">
              <?= !empty($r['first_prediction_at']) ? htmlspecialchars((string)$r['first_prediction_at']) : '—' ?>
            </td>
            <td class="text-end fw-semibold"><?= (int)$r['points_total'] ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
