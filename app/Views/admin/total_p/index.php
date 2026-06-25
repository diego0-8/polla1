<?php
declare(strict_types=1);

use App\Core\Url;

/** @var int $season */
/** @var list<array<string, mixed>> $asesors */
?>

<div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2">
  <div>
    <h5 class="mb-0">Total P — Mundial <?= (int)$season ?></h5>
    <div class="small text-muted">Asesores activos con puntos totales y detalle de pronósticos por partido.</div>
  </div>
  <div class="d-flex gap-2 flex-wrap">
    <a class="btn btn-outline-light btn-sm" href="<?= htmlspecialchars(Url::to('/admin/predictions')) ?>">Auditoría</a>
    <a class="btn btn-outline-light btn-sm" href="<?= htmlspecialchars(Url::to('/admin/users')) ?>">Usuarios</a>
  </div>
</div>

<?php if ($asesors === []): ?>
  <div class="card p-3 text-muted">No hay asesores activos registrados.</div>
<?php else: ?>
  <div class="table-responsive">
    <table class="table table-dark table-striped align-middle mb-0">
      <thead>
        <tr>
          <th class="text-muted" style="width:3rem">#</th>
          <th>Nombre</th>
          <th>Usuario</th>
          <th class="text-end">Puntos</th>
          <th class="text-end" style="width:6rem">Detalle</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($asesors as $idx => $a): ?>
          <tr>
            <td class="text-muted"><?= $idx + 1 ?></td>
            <td class="fw-semibold"><?= htmlspecialchars((string)$a['name']) ?></td>
            <td class="text-muted"><?= htmlspecialchars((string)$a['username']) ?></td>
            <td class="text-end fw-semibold"><?= (int)($a['points_total'] ?? 0) ?></td>
            <td class="text-end">
              <button type="button"
                      class="btn btn-outline-light btn-sm total-p-view-btn"
                      data-user-id="<?= (int)$a['id'] ?>"
                      data-user-name="<?= htmlspecialchars((string)$a['name'], ENT_QUOTES) ?>">
                Ver
              </button>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>

<div class="modal fade" id="totalPModal" tabindex="-1" aria-labelledby="totalPModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-scrollable">
    <div class="modal-content bg-dark text-light border-secondary">
      <div class="modal-header border-secondary">
        <h5 class="modal-title" id="totalPModalLabel">Pronósticos</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>
      <div class="modal-body">
        <div class="text-center text-muted py-4">Selecciona un asesor para ver sus pronósticos.</div>
      </div>
    </div>
  </div>
</div>

<?php if ($asesors !== []): ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
  const modalEl = document.getElementById('totalPModal');
  if (!modalEl) {
    console.error('Total P: modal #totalPModal no encontrado');
    return;
  }
  if (typeof bootstrap === 'undefined') {
    console.error('Total P: Bootstrap JS no cargado');
    return;
  }

  const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
  const titleEl = document.getElementById('totalPModalLabel');
  const bodyEl = modalEl.querySelector('.modal-body');
  const predictionsUrl = <?= json_encode(Url::to('/admin/total-p/predictions'), JSON_THROW_ON_ERROR) ?>;

  let currentUserId = null;

  function loadPanel(url) {
    bodyEl.innerHTML = '<div class="text-center text-muted py-4">Cargando…</div>';
    fetch(url, {
      credentials: 'same-origin',
      headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'text/html' },
    })
      .then(function (res) {
        if (!res.ok) throw new Error('HTTP ' + res.status);
        return res.text();
      })
      .then(function (html) { bodyEl.innerHTML = html; })
      .catch(function (err) {
        console.error('Total P fetch error:', err);
        bodyEl.innerHTML = '<div class="text-danger small">No se pudieron cargar los pronósticos.</div>';
      });
  }

  function loadPredictions(userId, userName) {
    currentUserId = String(userId);
    titleEl.textContent = 'Pronósticos — ' + userName;
    modal.show();

    const url = predictionsUrl
      + '?user_id=' + encodeURIComponent(currentUserId)
      + '&embed=modal&page=1&bet=all';

    loadPanel(url);
  }

  modalEl.addEventListener('submit', function (ev) {
    const form = ev.target.closest('form[data-modal-form]');
    if (!form || !bodyEl.contains(form)) return;
    ev.preventDefault();
    const params = new URLSearchParams(new FormData(form));
    if (currentUserId) {
      params.set('user_id', currentUserId);
    }
    params.set('embed', 'modal');
    loadPanel(predictionsUrl + '?' + params.toString());
  });

  modalEl.addEventListener('click', function (ev) {
    const link = ev.target.closest('a[data-modal-page], a[data-modal-reset]');
    if (!link || !bodyEl.contains(link)) return;
    ev.preventDefault();
    loadPanel(link.getAttribute('href') || predictionsUrl);
  });

  document.querySelectorAll('.total-p-view-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
      loadPredictions(btn.getAttribute('data-user-id'), btn.getAttribute('data-user-name') || 'Asesor');
    });
  });
});
</script>
<?php endif; ?>
