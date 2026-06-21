<?php
declare(strict_types=1);

use App\Core\Url;
use App\Helpers\MatchView;

/** @var int $season */
/** @var array{
 *   asesors: list<array<string, mixed>>,
 *   matches: list<array<string, mixed>>,
 *   entries: array<int, array<int, array{exact:string,trend:string,props:string}>>,
 *   champions: array<int, array{name:string,code:string|null,registered_at:string|null}>
 * } $report */

$asesors = $report['asesors'];
$matches = $report['matches'];
$entries = $report['entries'];
$champions = $report['champions'];
$accordionId = 'audit-matches';

$auditPayload = [
    'asesors' => array_map(static fn (array $a): array => [
        'id' => (int)$a['id'],
        'name' => (string)$a['name'],
        'username' => (string)$a['username'],
    ], $asesors),
    'entries' => $entries,
];
?>

<div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2">
  <div>
    <h5 class="mb-0">Auditoría de pronósticos — Mundial <?= (int)$season ?></h5>
    <div class="small text-muted">Partido por partido: marcador exacto, tendencia/avance y props de cada asesor.</div>
  </div>
  <div class="d-flex gap-2 flex-wrap">
    <a class="btn btn-outline-light btn-sm" href="<?= htmlspecialchars(Url::to('/admin/users')) ?>">← Usuarios</a>
    <a class="btn btn-outline-light btn-sm" href="<?= htmlspecialchars(Url::to('/')) ?>">Inicio</a>
  </div>
</div>

<div class="row g-3">
  <div class="col-lg-4">
    <?php if ($asesors === []): ?>
      <div class="card p-3 text-muted">No hay asesores activos registrados.</div>
    <?php else: ?>
      <div class="card p-0 overflow-hidden h-100">
        <div class="p-3 border-bottom border-secondary border-opacity-25">
          <h6 class="mb-0">Pronóstico campeón</h6>
          <div class="small text-muted">Mundial <?= (int)$season ?> · equipo elegido por asesor</div>
        </div>
        <div class="list-group list-group-flush">
          <?php foreach ($asesors as $a): ?>
            <?php
              $userId = (int)$a['id'];
              $pick = $champions[$userId] ?? null;
              $hasPick = $pick !== null;
            ?>
            <div class="list-group-item">
              <div class="d-flex justify-content-between align-items-start gap-2 mb-1">
                <div class="small">
                  <div class="fw-semibold"><?= htmlspecialchars((string)$a['name']) ?></div>
                  <div class="text-muted"><?= htmlspecialchars((string)$a['username']) ?></div>
                </div>
                <?php if ($hasPick): ?>
                  <span class="badge badge-ft">Sí</span>
                <?php else: ?>
                  <span class="badge badge-live">No</span>
                <?php endif; ?>
              </div>
              <div class="small">
                <?php if ($hasPick): ?>
                  <span class="fw-semibold"><?= htmlspecialchars($pick['name']) ?></span>
                  <?php if (!empty($pick['code'])): ?>
                    <span class="text-muted">(<?= htmlspecialchars((string)$pick['code']) ?>)</span>
                  <?php endif; ?>
                <?php else: ?>
                  <span class="text-muted">No registró campeón</span>
                <?php endif; ?>
              </div>
              <?php if ($hasPick && !empty($pick['registered_at'])): ?>
                <div class="small text-muted mt-1"><?= htmlspecialchars((string)$pick['registered_at']) ?></div>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endif; ?>
  </div>

  <div class="col-lg-8">
    <div class="mb-3">
      <input type="search"
             class="form-control form-control-sm"
             id="audit-search"
             placeholder="Buscar por equipo, asesor o fase…"
             autocomplete="off">
    </div>

    <?php if (!$matches): ?>
      <div class="card p-3 text-muted">No hay partidos cargados para la temporada <?= (int)$season ?>.</div>
    <?php elseif (!$asesors): ?>
      <div class="card p-3 text-muted">No hay asesores para mostrar pronósticos por partido.</div>
    <?php else: ?>
      <div class="accordion audit-accordion" id="<?= $accordionId ?>">
        <?php foreach ($matches as $match): ?>
          <?php
            $matchId = (int)$match['id'];
            $collapseId = 'audit-match-' . $matchId;
            $home = (string)($match['home_name'] ?? '');
            $away = (string)($match['away_name'] ?? '');
            $stage = MatchView::stageLabel((string)($match['stage'] ?? ''));
            $kickoff = MatchView::formatKickoff((string)($match['kickoff_at'] ?? ''));
            $status = (string)($match['status'] ?? 'NS');
            $searchText = strtolower($home . ' ' . $away . ' ' . $stage . ' ' . $kickoff);
          ?>
          <div class="accordion-item audit-match-item" data-search="<?= htmlspecialchars($searchText) ?>" data-match-id="<?= $matchId ?>">
            <h2 class="accordion-header">
              <button class="accordion-button collapsed py-2"
                      type="button"
                      data-bs-toggle="collapse"
                      data-bs-target="#<?= $collapseId ?>"
                      aria-expanded="false"
                      aria-controls="<?= $collapseId ?>">
                <span class="me-2 small audit-kickoff"><?= htmlspecialchars($kickoff) ?></span>
                <span class="fw-semibold"><?= htmlspecialchars($home) ?> vs <?= htmlspecialchars($away) ?></span>
                <span class="ms-2 badge badge-ft"><?= htmlspecialchars($stage) ?></span>
                <span class="ms-2 <?= MatchView::statusBadgeClass($status) ?>"><?= htmlspecialchars(MatchView::statusLabel($status)) ?></span>
              </button>
            </h2>
            <div id="<?= $collapseId ?>" class="accordion-collapse collapse" data-bs-parent="#<?= $accordionId ?>">
              <div class="accordion-body p-0">
                <div class="audit-table-slot" data-match-id="<?= $matchId ?>"></div>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
      <div id="audit-no-results" class="card p-3 text-muted d-none mt-2">Ningún partido coincide con la búsqueda.</div>
    <?php endif; ?>
  </div>
</div>

<?php if ($matches !== [] && $asesors !== []): ?>
<script type="application/json" id="audit-data"><?= json_encode($auditPayload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE) ?></script>
<script>
(function () {
  const dataEl = document.getElementById('audit-data');
  if (!dataEl) return;

  const auditData = JSON.parse(dataEl.textContent || '{}');
  const asesors = auditData.asesors || [];
  const entries = auditData.entries || {};
  const rendered = new Set();

  function escapeHtml(value) {
    return String(value)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  function renderAuditTable(matchId) {
    if (rendered.has(matchId)) return;
    const slot = document.querySelector('.audit-table-slot[data-match-id="' + matchId + '"]');
    if (!slot) return;

    const matchEntries = entries[matchId] || entries[String(matchId)] || {};
    let rows = '';
    asesors.forEach(function (a) {
      const userId = String(a.id);
      const cell = matchEntries[a.id] || matchEntries[userId] || { exact: '—', trend: '—', props: '—' };
      rows += '<tr>'
        + '<td><div>' + escapeHtml(a.name) + '</div><div class="text-muted">' + escapeHtml(a.username) + '</div></td>'
        + '<td>' + escapeHtml(cell.exact) + '</td>'
        + '<td>' + escapeHtml(cell.trend) + '</td>'
        + '<td>' + escapeHtml(cell.props) + '</td>'
        + '</tr>';
    });

    slot.innerHTML =
      '<div class="table-responsive">'
      + '<table class="table table-dark table-striped mb-0 align-middle small">'
      + '<thead><tr><th>Asesor</th><th>Marcador exacto</th><th>Tendencia / Avance</th><th>Props</th></tr></thead>'
      + '<tbody>' + rows + '</tbody>'
      + '</table></div>';

    rendered.add(matchId);
  }

  document.querySelectorAll('.audit-accordion .accordion-collapse').forEach(function (collapseEl) {
    collapseEl.addEventListener('show.bs.collapse', function () {
      const item = collapseEl.closest('.audit-match-item');
      if (!item) return;
      renderAuditTable(item.dataset.matchId);
    });
  });

  const input = document.getElementById('audit-search');
  const items = document.querySelectorAll('.audit-match-item');
  const noResults = document.getElementById('audit-no-results');
  if (input && items.length) {
    input.addEventListener('input', function () {
      const q = input.value.trim().toLowerCase();
      let visible = 0;
      items.forEach(function (el) {
        const hay = (el.dataset.search || '').includes(q);
        el.classList.toggle('d-none', !hay);
        if (hay) visible++;
      });
      if (noResults) {
        noResults.classList.toggle('d-none', visible > 0 || q === '');
      }
    });
  }
})();
</script>
<?php endif; ?>
