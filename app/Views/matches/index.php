<?php
declare(strict_types=1);

use App\Core\Url;
use App\Helpers\MatchView;

/** @var array $matches */
/** @var int $season */
/** @var array<string, array<int, array<string, mixed>>> $grouped */
$autoRefresh = MatchView::shouldAutoRefreshMatches($matches);
?>

<?php if ($autoRefresh): ?>
  <script>
    window.setTimeout(function () {
      window.location.reload();
    }, 45000);
  </script>
<?php endif; ?>

<div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2">
  <div>
    <h5 class="mb-0">Partidos — <?= htmlspecialchars(MatchView::competitionLabel()) ?></h5>
    <div class="small text-muted">
      Datos sincronizados
      <?php if ($autoRefresh): ?> · auto-sync 45s<?php endif; ?>
    </div>
  </div>
  <a class="btn btn-outline-light btn-sm" href="<?= htmlspecialchars(Url::to('/')) ?>">Inicio</a>
</div>

<?php if (!$matches): ?>
  <div class="card p-3">
    <div class="text-muted">
      No hay partidos del Mundial <?= (int)$season ?> en la base de datos.
      Ejecuta en la terminal:
      <code>php scripts/validate_sync.php --sync</code>
    </div>
  </div>
<?php else: ?>
  <div class="mb-3">
    <input type="search"
           class="form-control form-control-sm"
           id="matches-search"
           placeholder="Buscar por selección, fase, grupo o fecha…"
           autocomplete="off">
  </div>

  <div class="small text-muted mb-3" id="matches-count"><?= count($matches) ?> partidos · temporada <?= (int)$season ?></div>

  <?php foreach ($grouped as $stageName => $stageMatches): ?>
    <div class="matches-stage-group">
      <h6 class="text-muted mt-3 mb-2 matches-stage-heading"><?= htmlspecialchars((string)$stageName) ?></h6>
      <div class="row g-2 mb-2 matches-stage-row">
        <?php foreach ($stageMatches as $m): ?>
          <?php
            $home = (string)($m['home_name'] ?? '');
            $away = (string)($m['away_name'] ?? '');
            $homeCode = (string)($m['home_code'] ?? '');
            $awayCode = (string)($m['away_code'] ?? '');
            $stage = (string)($m['stage'] ?? $stageName);
            $kickoff = MatchView::formatKickoff((string)($m['kickoff_at'] ?? ''));
            $statusLabel = MatchView::statusLabel((string)($m['status'] ?? 'NS'));
            $groupCode = (string)($m['group_code'] ?? '');
            $searchText = strtolower(implode(' ', array_filter([
                $home, $away, $homeCode, $awayCode, $stage, $stageName, $kickoff, $statusLabel,
                $groupCode !== '' ? 'grupo ' . $groupCode : '',
            ])));
          ?>
          <div class="col-12 matches-search-item" data-search="<?= htmlspecialchars($searchText) ?>">
            <?php require __DIR__ . '/../partials/match_card.php'; ?>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endforeach; ?>

  <div id="matches-no-results" class="card p-3 text-muted d-none">Ningún partido coincide con la búsqueda.</div>

  <script>
  (function () {
    const input = document.getElementById('matches-search');
    const items = document.querySelectorAll('.matches-search-item');
    const stageGroups = document.querySelectorAll('.matches-stage-group');
    const noResults = document.getElementById('matches-no-results');
    const countEl = document.getElementById('matches-count');
    const total = items.length;
    if (!input || !items.length) return;

    function filterMatches() {
      const q = input.value.trim().toLowerCase();
      let visible = 0;

      items.forEach(function (el) {
        const hay = q === '' || (el.dataset.search || '').includes(q);
        el.classList.toggle('d-none', !hay);
        if (hay) visible++;
      });

      stageGroups.forEach(function (group) {
        const groupItems = group.querySelectorAll('.matches-search-item');
        const anyVisible = Array.from(groupItems).some(function (el) {
          return !el.classList.contains('d-none');
        });
        group.classList.toggle('d-none', !anyVisible);
      });

      if (noResults) {
        noResults.classList.toggle('d-none', visible > 0 || q === '');
      }
      if (countEl) {
        countEl.textContent = q === ''
          ? total + ' partidos · temporada <?= (int)$season ?>'
          : visible + ' de ' + total + ' partidos';
      }
    }

    input.addEventListener('input', filterMatches);
  })();
  </script>
<?php endif; ?>
