<?php

declare(strict_types=1);



use App\Core\Url;



/** @var list<array<string, mixed>> $users */

/** @var int $totalUsers */

/** @var int|null $currentUserId */

/** @var string|null $flash */

/** @var string|null $error */

/** @var int $season */

/** @var array<string, mixed> $prizes */



$prizeCount = (int)($prizes['prize_count'] ?? 1);

$prizeLabels = ['1.er lugar', '2.º lugar', '3.er lugar', '4.º lugar', '5.º lugar'];

?>



<div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2">

  <div>

    <h5 class="mb-0">Administración de usuarios</h5>

    <div class="small text-muted">Usuarios registrados: <?= (int)$totalUsers ?></div>

  </div>

  <div class="d-flex gap-2 flex-wrap">
    <a class="btn btn-outline-light btn-sm" href="<?= htmlspecialchars(Url::to('/admin/predictions')) ?>">Ver pronósticos por partido</a>
    <a class="btn btn-outline-light btn-sm" href="<?= htmlspecialchars(Url::to('/')) ?>">Inicio</a>
  </div>

</div>



<?php if (!empty($flash)): ?>

  <div class="alert alert-success py-2 small"><?= htmlspecialchars($flash) ?></div>

<?php endif; ?>

<?php if (!empty($error)): ?>

  <div class="alert alert-danger py-2 small"><?= htmlspecialchars($error) ?></div>

<?php endif; ?>



<div class="row g-3">

  <div class="col-lg-8">

    <div class="card p-0 overflow-hidden">

      <div class="table-responsive">

        <table class="table table-dark table-striped mb-0 align-middle">

          <thead>

            <tr>

              <th>Nombre completo</th>

              <th>Usuario</th>

              <th>Rol</th>

              <th>Estado</th>

              <th>Pago</th>

              <th class="text-end">Acciones</th>

            </tr>

          </thead>

          <tbody>

            <?php if (!$users): ?>

              <tr><td colspan="6" class="text-muted">No hay usuarios registrados.</td></tr>

            <?php endif; ?>

            <?php foreach ($users as $u): ?>

              <?php

                $isActive = ($u['status'] ?? '') === 'active';

                $isSelf = $currentUserId !== null && (int)$u['id'] === $currentUserId;

                $hasPaid = (int)($u['has_paid'] ?? 0) === 1;

              ?>

              <tr>

                <td><?= htmlspecialchars((string)$u['name']) ?></td>

                <td><?= htmlspecialchars((string)$u['username']) ?></td>

                <td><?= htmlspecialchars((string)$u['role_name']) ?></td>

                <td>

                  <?php if ($isActive): ?>

                    <span class="badge badge-ft">Activo</span>

                  <?php else: ?>

                    <span class="badge badge-live">Inhabilitado</span>

                  <?php endif; ?>

                </td>

                <td>

                  <div class="d-flex align-items-center gap-2">

                    <?php if ($hasPaid): ?>

                      <span class="badge badge-ft">Sí</span>

                    <?php else: ?>

                      <span class="badge badge-live">No</span>

                    <?php endif; ?>

                    <form method="post"

                          action="<?= htmlspecialchars(Url::to('/admin/users/toggle-paid')) ?>"

                          class="d-inline">

                      <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">

                      <button type="submit" class="btn btn-outline-light btn-sm py-0 px-2" title="Cambiar estado de pago">

                        <?= $hasPaid ? 'Marcar No' : 'Marcar Sí' ?>

                      </button>

                    </form>

                  </div>

                </td>

                <td class="text-end">

                  <div class="d-inline-flex gap-1 flex-wrap justify-content-end">

                    <a class="btn btn-outline-light btn-sm"

                       href="<?= htmlspecialchars(Url::to('/admin/users/edit') . '?id=' . (int)$u['id']) ?>">

                      Editar usuario

                    </a>

                    <?php if ($isActive && !$isSelf): ?>

                      <form method="post"

                            action="<?= htmlspecialchars(Url::to('/admin/users/disable')) ?>"

                            class="d-inline"

                            onsubmit="return confirm('¿Inhabilitar a este usuario? No podrá iniciar sesión.');">

                        <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">

                        <button type="submit" class="btn btn-outline-danger btn-sm">Inhabilitar</button>

                      </form>

                    <?php elseif (!$isActive): ?>

                      <form method="post"

                            action="<?= htmlspecialchars(Url::to('/admin/users/enable')) ?>"

                            class="d-inline"

                            onsubmit="return confirm('¿Habilitar a este usuario? Podrá iniciar sesión de nuevo.');">

                        <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">

                        <button type="submit" class="btn btn-outline-success btn-sm">Habilitar</button>

                      </form>

                    <?php endif; ?>

                  </div>

                </td>

              </tr>

            <?php endforeach; ?>

          </tbody>

        </table>

      </div>

    </div>

  </div>



  <div class="col-lg-4">

    <div class="card p-3">

      <h6 class="mb-1">Premios (COP)</h6>

      <p class="small text-muted mb-3">

        Montos en pesos colombianos para el Mundial <?= (int)$season ?>.

        Se asignan según la tabla de posiciones al cierre del torneo.

      </p>



      <form method="post" action="<?= htmlspecialchars(Url::to('/admin/users/prizes')) ?>" class="vstack gap-3" id="prizes-form">

        <div>

          <label class="form-label small mb-1" for="prize_count">Cantidad de premios</label>

          <select class="form-select form-select-sm" name="prize_count" id="prize_count">

            <?php for ($n = 1; $n <= 5; $n++): ?>

              <option value="<?= $n ?>" <?= $prizeCount === $n ? 'selected' : '' ?>><?= $n ?> premios</option>

            <?php endfor; ?>

          </select>

        </div>



        <?php for ($i = 1; $i <= 5; $i++): ?>

          <?php

            $fieldKey = "prize_{$i}_cop";

            $value = $prizes[$fieldKey] ?? null;

            $hidden = $i > $prizeCount;

          ?>

          <div class="prize-field<?= $hidden ? ' d-none' : '' ?>" data-prize-index="<?= $i ?>">

            <label class="form-label small mb-1" for="<?= $fieldKey ?>"><?= $prizeLabels[$i - 1] ?></label>

            <div class="input-group input-group-sm">

              <span class="input-group-text">$</span>

              <input type="text"

                     class="form-control prize-amount-input"

                     id="<?= $fieldKey ?>"

                     name="<?= $fieldKey ?>"

                     inputmode="numeric"

                     placeholder="0"

                     value="<?= $value !== null ? htmlspecialchars(number_format((int)$value, 0, ',', '.')) : '' ?>">

            </div>

          </div>

        <?php endfor; ?>



        <div class="small text-muted" id="prizes-preview"></div>



        <button type="submit" class="btn btn-primary btn-sm">Guardar premios</button>

      </form>

    </div>

  </div>

</div>



<script>

(function () {

  const countSelect = document.getElementById('prize_count');

  const fields = document.querySelectorAll('.prize-field');

  const preview = document.getElementById('prizes-preview');



  function formatCop(value) {

    const digits = String(value).replace(/\D/g, '');

    if (!digits) return '';

    return Number(digits).toLocaleString('es-CO');

  }



  function updateFields() {

    const count = parseInt(countSelect.value, 10);

    fields.forEach(function (el) {

      const idx = parseInt(el.dataset.prizeIndex, 10);

      el.classList.toggle('d-none', idx > count);

    });

    updatePreview();

  }



  function updatePreview() {

    const count = parseInt(countSelect.value, 10);

    const parts = [];

    for (let i = 1; i <= count; i++) {

      const input = document.getElementById('prize_' + i + '_cop');

      const raw = input ? input.value.replace(/\D/g, '') : '';

      if (raw) {

        parts.push(i + 'º: $' + Number(raw).toLocaleString('es-CO'));

      }

    }

    preview.textContent = parts.length ? 'Vista previa: ' + parts.join(' · ') : '';

  }



  countSelect.addEventListener('change', updateFields);

  document.querySelectorAll('.prize-amount-input').forEach(function (input) {

    input.addEventListener('input', function () {

      const pos = input.selectionStart;

      const before = input.value.length;

      input.value = formatCop(input.value);

      const after = input.value.length;

      input.setSelectionRange(pos + (after - before), pos + (after - before));

      updatePreview();

    });

  });



  updateFields();

})();

</script>


