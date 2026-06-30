<?php

declare(strict_types=1);

use App\Core\Url;
use App\Helpers\MatchView;

/** @var array $rows */
/** @var int $season */
/** @var int $matchesPlayed */
/** @var array<string, mixed> $prizes */
/** @var bool $showPrizes */
/** @var list<array{position:int,amount:int}> $prizeList */
/** @var int $prizeCount */

$prizeLabels = ['1.er lugar', '2.º lugar', '3.er lugar', '4.º lugar', '5.º lugar'];
$colspan = 13;
?>

<div class="d-flex justify-content-between align-items-center mb-2">
  <div>
    <h5 class="mb-0">Tabla de posiciones — Mundial <?= (int)$season ?></h5>
    <div class="small text-muted">
      PJ = partidos finalizados del torneo · PA = partidos apostados que ya finalizaron.
      Exacto, Gana (fase de grupos), goles y ambos marcan usan el marcador al final del tiempo suplementario.
      En eliminatorias, «Gana» cuenta quien clasifica (penales si aplica).
      Campeón: +20 en verde si tu selección sigue en el torneo; +20 en rojo si fue eliminada.
    </div>
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
          <th style="width:56px;">#</th>
          <th>Usuario</th>
          <th class="text-end" style="width:48px;" title="Partidos jugados (finalizados)">PJ</th>
          <th class="text-end" style="width:48px;" title="Partidos apostados ya finalizados">PA</th>
          <th class="text-end">Exacto</th>
          <th class="text-end">Gana</th>
          <th class="text-end">Ambos marcan</th>
          <th class="text-end">Goles</th>
          <th class="text-end">Corners</th>
          <th class="text-end">Tarjetas</th>
          <th class="text-end">Campeón</th>
          <th class="text-end">Puntos</th>
          <th style="width:40px;"></th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$rows): ?>
          <tr><td colspan="<?= $colspan ?>" class="text-muted">Aún no hay puntajes.</td></tr>
        <?php endif; ?>
        <?php foreach ($rows as $i => $r): ?>
          <?php
            $pos = $i + 1;
            $userId = (int)$r['id'];
            $unpaid = (int)($r['has_paid'] ?? 0) === 0;
            $isPrizeRow = $showPrizes && $pos <= $prizeCount;
            $championDisplay = (string)($r['champion_display'] ?? '—');
            $championStatus = (string)($r['champion_status'] ?? 'none');
            $championClass = match ($championStatus) {
                'alive', 'won' => 'text-success fw-semibold',
                'eliminated' => 'text-danger fw-semibold',
                default => 'text-muted',
            };
            $lastMatch = $r['last_match'] ?? null;
            $detailId = 'leaderboard-detail-' . $userId;
            $canExpand = is_array($lastMatch);
          ?>
          <tr class="<?= $unpaid ? 'leaderboard-row--unpaid' : '' ?>">
            <td class="<?= $isPrizeRow ? 'leaderboard-pos leaderboard-pos--' . $pos : 'text-muted' ?>">
              <?= $pos ?>
            </td>
            <td><?= htmlspecialchars((string)$r['name']) ?></td>
            <td class="text-end text-muted"><?= (int)$matchesPlayed ?></td>
            <td class="text-end"><?= (int)($r['matches_bet'] ?? 0) ?></td>
            <td class="text-end"><?= (int)$r['exact_hits'] ?></td>
            <td class="text-end"><?= (int)$r['gana_hits'] ?></td>
            <td class="text-end"><?= (int)$r['btts_hits'] ?></td>
            <td class="text-end"><?= (int)$r['goals_hits'] ?></td>
            <td class="text-end"><?= (int)$r['corners_hits'] ?></td>
            <td class="text-end"><?= (int)$r['cards_hits'] ?></td>
            <td class="text-end <?= $championClass ?>"><?= htmlspecialchars($championDisplay) ?></td>
            <td class="text-end fw-semibold"><?= (int)$r['points_total'] ?></td>
            <td class="text-end p-0">
              <?php if ($canExpand): ?>
                <button type="button"
                        class="btn btn-link leaderboard-expand-btn collapsed p-1"
                        data-bs-toggle="collapse"
                        data-bs-target="#<?= htmlspecialchars($detailId) ?>"
                        aria-expanded="false"
                        aria-controls="<?= htmlspecialchars($detailId) ?>"
                        title="Último partido pronosticado ya finalizado">
                  <span class="leaderboard-chevron" aria-hidden="true">▼</span>
                </button>
              <?php endif; ?>
            </td>
          </tr>
          <?php if ($canExpand): ?>
            <tr>
              <td colspan="<?= $colspan ?>" class="p-0 border-0 leaderboard-detail-row">
                <div class="collapse" id="<?= htmlspecialchars($detailId) ?>">
                  <div class="leaderboard-detail-panel px-3 py-2">
                  <div class="small fw-semibold mb-1">Último partido pronosticado (finalizado)</div>
                  <div class="small mb-2">
                    <?= htmlspecialchars((string)$lastMatch['label']) ?>
                    <span class="text-muted">· <?= htmlspecialchars(MatchView::formatKickoff((string)$lastMatch['kickoff_at'])) ?></span>
                    <?php if (!empty($lastMatch['settlement_score']) && in_array(strtoupper((string)($lastMatch['status'] ?? '')), ['FT', 'PEN', 'AET'], true)): ?>
                      <span class="text-muted">· Resultado reglamentario: <strong><?= htmlspecialchars((string)$lastMatch['settlement_score']) ?></strong></span>
                      <?php if (strtoupper((string)($lastMatch['status'] ?? '')) === 'PEN'): ?>
                        <span class="text-muted">(def. penales)</span>
                      <?php endif; ?>
                    <?php endif; ?>
                  </div>
                  <?php if (!empty($lastMatch['columns'])): ?>
                    <div class="table-responsive">
                      <table class="table table-sm table-dark mb-0 leaderboard-detail-cols align-middle">
                        <thead>
                          <tr>
                            <th class="text-end">Exacto</th>
                            <th class="text-end">Gana</th>
                            <th class="text-end">Ambos marcan</th>
                            <th class="text-end">Goles</th>
                            <th class="text-end">Corners</th>
                            <th class="text-end">Tarjetas</th>
                            <th class="text-end">Total</th>
                          </tr>
                        </thead>
                        <tbody>
                          <tr>
                            <?php
                              $colByLabel = [];
                              foreach ($lastMatch['columns'] as $col) {
                                  $colByLabel[$col['label']] = $col['points'];
                              }
                              $mainLabels = ['Exacto', 'Gana', 'Ambos marcan', 'Goles', 'Corners', 'Tarjetas'];
                              foreach ($mainLabels as $label):
                                $pts = $colByLabel[$label] ?? null;
                                $hasBet = array_key_exists($label, $colByLabel);
                                $ptsClass = !$hasBet ? 'text-muted' : ($pts === null ? 'text-muted' : ($pts > 0 ? 'text-success' : ''));
                            ?>
                              <td class="text-end fw-semibold <?= $ptsClass ?>">
                                <?php if (!$hasBet): ?>
                                  <span class="text-muted">·</span>
                                <?php elseif ($pts === null): ?>
                                  —
                                <?php else: ?>
                                  <?= (int)$pts ?>
                                <?php endif; ?>
                              </td>
                            <?php endforeach; ?>
                            <td class="text-end fw-semibold">
                              <?php if (!empty($lastMatch['is_settled'])): ?>
                                <?= (int)$lastMatch['total'] ?>
                              <?php else: ?>
                                <span class="text-muted">—</span>
                              <?php endif; ?>
                            </td>
                          </tr>
                        </tbody>
                      </table>
                    </div>
                    <?php if (empty($lastMatch['is_settled'])): ?>
                      <div class="small text-muted mt-1">Pendiente de liquidación</div>
                    <?php endif; ?>
                  <?php else: ?>
                    <div class="small text-muted">Sin pronósticos en este partido</div>
                  <?php endif; ?>
                  </div>
                </div>
              </td>
            </tr>
          <?php endif; ?>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
