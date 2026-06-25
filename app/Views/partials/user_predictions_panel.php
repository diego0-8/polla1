<?php
declare(strict_types=1);

use App\Core\Url;
use App\Helpers\MatchView;

/** @var int $season */
/** @var array{
 *   rows:list<array<string,mixed>>,
 *   total:int,
 *   page:int,
 *   pages:int,
 *   per_page:int,
 *   filters:array{q:string,date:string,bet:string}
 * } $overview */
/** @var string $panelBaseUrl */
/** @var string $embedMode */
/** @var string|null $panelTitle */
/** @var int|null $userId */

$userId = $userId ?? null;
$rows = $overview['rows'];
$filters = $overview['filters'];
$page = (int)$overview['page'];
$pages = (int)$overview['pages'];
$total = (int)$overview['total'];
$isModal = $embedMode === 'modal';
$accordionId = $isModal ? 'pronosticos-modal-accordion' : 'pronosticos-accordion';
$panelDomId = $isModal ? 'user-predictions-panel-modal' : 'user-predictions-panel';
$mainLabels = ['Exacto', 'Gana', 'Ambos marcan', 'Goles', 'Corners', 'Tarjetas'];

$buildQuery = static function (array $overrides = []) use ($filters, $page, $userId, $isModal): string {
    $params = array_merge([
        'q' => $filters['q'],
        'date' => $filters['date'],
        'bet' => $filters['bet'],
        'page' => (string)$page,
    ], $overrides);

    if ($userId !== null) {
        $params['user_id'] = (string)$userId;
    }
    if ($isModal) {
        $params['embed'] = 'modal';
    }

    $parts = [];
    foreach ($params as $key => $value) {
        if ($value === '' || $value === null) {
            continue;
        }
        $parts[] = rawurlencode((string)$key) . '=' . rawurlencode((string)$value);
    }

    return $parts === [] ? '' : '?' . implode('&', $parts);
};
?>

<div id="<?= htmlspecialchars($panelDomId) ?>" class="user-predictions-panel" data-embed="<?= htmlspecialchars($embedMode) ?>">
  <?php if ($panelTitle !== null && $panelTitle !== ''): ?>
    <div class="small text-muted mb-2"><?= htmlspecialchars($panelTitle) ?> · Mundial <?= (int)$season ?></div>
  <?php endif; ?>

  <form method="get"
        action="<?= $isModal ? '#' : htmlspecialchars(Url::to($panelBaseUrl)) ?>"
        class="pronosticos-filters row g-2 align-items-end mb-3"
        <?= $isModal ? 'data-modal-form="1"' : '' ?>>
    <?php if ($userId !== null): ?>
      <input type="hidden" name="user_id" value="<?= (int)$userId ?>">
    <?php endif; ?>
    <?php if ($isModal): ?>
      <input type="hidden" name="embed" value="modal">
    <?php endif; ?>
    <input type="hidden" name="page" value="1" data-filter-page>
    <div class="col-md-5">
      <label class="form-label small mb-1" for="<?= $panelDomId ?>-q">Selección / fase</label>
      <input type="search"
             class="form-control form-control-sm"
             id="<?= $panelDomId ?>-q"
             name="q"
             value="<?= htmlspecialchars($filters['q']) ?>"
             placeholder="Buscar por equipo, código, fase o grupo…"
             autocomplete="off">
    </div>
    <div class="col-md-3">
      <label class="form-label small mb-1" for="<?= $panelDomId ?>-date">Fecha</label>
      <input type="date"
             class="form-control form-control-sm"
             id="<?= $panelDomId ?>-date"
             name="date"
             value="<?= htmlspecialchars($filters['date']) ?>">
    </div>
    <div class="col-md-2">
      <label class="form-label small mb-1" for="<?= $panelDomId ?>-bet">Pronóstico</label>
      <select class="form-select form-select-sm" id="<?= $panelDomId ?>-bet" name="bet">
        <option value="all" <?= $filters['bet'] === 'all' ? 'selected' : '' ?>>Todos</option>
        <option value="predicted" <?= $filters['bet'] === 'predicted' ? 'selected' : '' ?>>Pronosticados</option>
        <option value="unpredicted" <?= $filters['bet'] === 'unpredicted' ? 'selected' : '' ?>>No pronosticados</option>
      </select>
    </div>
    <div class="col-md-2 d-flex gap-2">
      <button type="submit" class="btn btn-light btn-sm flex-grow-1">Filtrar</button>
      <?php if ($filters['q'] !== '' || $filters['date'] !== '' || $filters['bet'] !== 'all'): ?>
        <a class="btn btn-outline-light btn-sm"
           href="<?= htmlspecialchars(Url::to($panelBaseUrl) . $buildQuery(['q' => '', 'date' => '', 'bet' => 'all', 'page' => '1'])) ?>"
           <?= $isModal ? 'data-modal-reset="1"' : '' ?>>Limpiar</a>
      <?php endif; ?>
    </div>
  </form>

  <div class="small text-muted mb-3">
    <?= $total ?> partido<?= $total === 1 ? '' : 's' ?> · página <?= $page ?> de <?= $pages ?>
  </div>

  <?php if ($rows === []): ?>
    <div class="card p-3 text-muted">Ningún partido coincide con los filtros.</div>
  <?php else: ?>
    <div class="accordion audit-accordion pronosticos-accordion" id="<?= htmlspecialchars($accordionId) ?>">
      <?php foreach ($rows as $row): ?>
        <?php
          $matchId = (int)$row['match_id'];
          $collapseId = $accordionId . '-match-' . $matchId;
          $kickoff = MatchView::formatKickoff((string)$row['kickoff_at']);
          $status = (string)($row['status'] ?? 'NS');
          $stage = MatchView::stageLabel((string)($row['stage'] ?? ''));
          $hasBet = !empty($row['has_bet']);
          $finished = !empty($row['is_finished']);
          $missingFinished = !$hasBet && $finished;
          $score = $finished
              ? (int)$row['home_score'] . ' : ' . (int)$row['away_score']
              : null;
          $missingClass = $missingFinished ? ' pronosticos-row--missing' : '';
        ?>
        <div class="accordion-item audit-match-item<?= $missingClass ?>">
          <h2 class="accordion-header">
            <button class="accordion-button collapsed py-2"
                    type="button"
                    data-bs-toggle="collapse"
                    data-bs-target="#<?= htmlspecialchars($collapseId) ?>"
                    aria-expanded="false"
                    aria-controls="<?= htmlspecialchars($collapseId) ?>">
              <span class="me-2 small audit-kickoff"><?= htmlspecialchars($kickoff) ?></span>
              <span class="pronosticos-match-teams"><?= htmlspecialchars((string)$row['home_name']) ?> vs <?= htmlspecialchars((string)$row['away_name']) ?></span>
              <?php if ($score !== null): ?>
                <span class="ms-2 badge bg-dark border border-secondary"><?= htmlspecialchars($score) ?></span>
              <?php endif; ?>
              <?php if ($stage !== ''): ?>
                <span class="ms-2 badge badge-ft"><?= htmlspecialchars($stage) ?></span>
              <?php endif; ?>
              <span class="ms-2 <?= MatchView::statusBadgeClass($status) ?>"><?= htmlspecialchars(MatchView::statusLabel($status)) ?></span>
              <?php if ($missingFinished): ?>
                <span class="ms-2 badge bg-danger">Sin pronóstico</span>
              <?php elseif (!$hasBet): ?>
                <span class="ms-2 badge badge-ns">Sin pronóstico</span>
              <?php elseif (!empty($row['is_settled'])): ?>
                <span class="ms-2 badge bg-success"><?= (int)$row['total'] ?> pts</span>
              <?php endif; ?>
            </button>
          </h2>
          <div id="<?= htmlspecialchars($collapseId) ?>" class="accordion-collapse collapse" data-bs-parent="#<?= htmlspecialchars($accordionId) ?>">
            <div class="accordion-body p-0">
              <?php if (!$hasBet): ?>
                <div class="leaderboard-detail-panel px-3 py-3">
                  <?php if ($missingFinished): ?>
                    <div class="text-danger small fw-semibold mb-2">Sin pronóstico registrado para este partido (ya finalizado).</div>
                  <?php else: ?>
                    <div class="small text-muted mb-2">Aún no has pronosticado este partido.</div>
                  <?php endif; ?>
                  <a class="btn btn-outline-light btn-sm" href="<?= htmlspecialchars(Url::to('/matches/show?id=' . $matchId)) ?>">Ir al partido</a>
                </div>
              <?php elseif (!empty($row['columns'])): ?>
                <div class="leaderboard-detail-panel px-3 py-2">
                  <div class="table-responsive">
                    <table class="table table-sm table-dark mb-0 leaderboard-detail-cols align-middle">
                      <thead>
                        <tr>
                          <?php foreach ($mainLabels as $label): ?>
                            <th class="text-end"><?= htmlspecialchars($label) ?></th>
                          <?php endforeach; ?>
                          <th class="text-end">Total</th>
                        </tr>
                      </thead>
                      <tbody>
                        <tr>
                          <?php
                            $colByLabel = [];
                            $pickByLabel = [];
                            foreach ($row['columns'] as $col) {
                                $colByLabel[$col['label']] = $col['points'];
                                $pickByLabel[$col['label']] = $col['pick'] ?? null;
                            }
                            foreach ($mainLabels as $label):
                              $pts = $colByLabel[$label] ?? null;
                              $hasColBet = array_key_exists($label, $colByLabel);
                              $ptsClass = !$hasColBet ? 'text-muted' : ($pts === null ? 'text-muted' : ($pts > 0 ? 'text-success' : ''));
                              $pick = $pickByLabel[$label] ?? null;
                          ?>
                            <td class="text-end <?= $ptsClass ?>">
                              <?php if ($pick !== null && $pick !== ''): ?>
                                <div class="small text-muted fw-normal"><?= htmlspecialchars((string)$pick) ?></div>
                              <?php endif; ?>
                              <?php if (!$hasColBet): ?>
                                <span class="text-muted">·</span>
                              <?php elseif ($pts === null): ?>
                                —
                              <?php else: ?>
                                <span class="fw-semibold"><?= (int)$pts ?></span>
                              <?php endif; ?>
                            </td>
                          <?php endforeach; ?>
                          <td class="text-end fw-semibold">
                            <?php if (!empty($row['is_settled'])): ?>
                              <?= (int)$row['total'] ?>
                            <?php else: ?>
                              <span class="text-muted">—</span>
                            <?php endif; ?>
                          </td>
                        </tr>
                      </tbody>
                    </table>
                  </div>
                  <?php if (empty($row['is_settled'])): ?>
                    <div class="small text-muted mt-1">
                      <?= $finished ? 'Pendiente de liquidación' : 'Partido aún no finalizado' ?>
                    </div>
                  <?php endif; ?>
                </div>
              <?php else: ?>
                <div class="leaderboard-detail-panel px-3 py-2">
                  <div class="small text-muted">Sin columnas de pronóstico.</div>
                </div>
              <?php endif; ?>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>

    <?php if ($pages > 1): ?>
      <nav class="mt-3" aria-label="Paginación de pronósticos">
        <ul class="pagination pagination-sm justify-content-center mb-0 pronosticos-pagination">
          <?php
            $prevPage = max(1, $page - 1);
            $nextPage = min($pages, $page + 1);
          ?>
          <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
            <a class="page-link"
               href="<?= htmlspecialchars(Url::to($panelBaseUrl) . $buildQuery(['page' => (string)$prevPage])) ?>"
               <?= $isModal ? 'data-modal-page="1"' : '' ?>>«</a>
          </li>
          <?php for ($p = 1; $p <= $pages; $p++): ?>
            <li class="page-item <?= $p === $page ? 'active' : '' ?>">
              <a class="page-link"
                 href="<?= htmlspecialchars(Url::to($panelBaseUrl) . $buildQuery(['page' => (string)$p])) ?>"
                 <?= $isModal ? 'data-modal-page="1"' : '' ?>><?= $p ?></a>
            </li>
          <?php endfor; ?>
          <li class="page-item <?= $page >= $pages ? 'disabled' : '' ?>">
            <a class="page-link"
               href="<?= htmlspecialchars(Url::to($panelBaseUrl) . $buildQuery(['page' => (string)$nextPage])) ?>"
               <?= $isModal ? 'data-modal-page="1"' : '' ?>>»</a>
          </li>
        </ul>
      </nav>
    <?php endif; ?>
  <?php endif; ?>
</div>
