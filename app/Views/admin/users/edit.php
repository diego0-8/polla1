<?php
declare(strict_types=1);

use App\Core\Url;

/** @var array $user */
/** @var string $role */
/** @var string|null $error */

$roles = ['admin' => 'Administrador', 'helper' => 'Helper', 'asesor' => 'Asesor'];
?>

<div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-2">
  <h5 class="mb-0">Editar usuario</h5>
  <a class="btn btn-outline-light btn-sm" href="<?= htmlspecialchars(Url::to('/admin/users')) ?>">← Volver al listado</a>
</div>

<?php if (!empty($error)): ?>
  <div class="alert alert-danger py-2 small"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<div class="card p-3">
  <form method="post" action="<?= htmlspecialchars(Url::to('/admin/users/update')) ?>" class="vstack gap-3" id="edit-user-form">
    <input type="hidden" name="user_id" value="<?= (int)$user['id'] ?>">

    <div>
      <label class="form-label small text-muted">Nombre completo</label>
      <input class="form-control" type="text" name="name" required
             value="<?= htmlspecialchars((string)$user['name']) ?>">
    </div>

    <div>
      <label class="form-label small text-muted">Usuario</label>
      <input class="form-control" type="text" name="username" required
             pattern="[a-zA-Z0-9._]{3,30}"
             title="3-30 caracteres: letras, números, punto (.) y guion bajo (_)"
             value="<?= htmlspecialchars((string)$user['username']) ?>">
      <div class="form-text text-muted">Se guardará en minúsculas (ej. AlejoAdmin1 → alejoadmin1).</div>
    </div>

    <div>
      <label class="form-label small text-muted">Rol</label>
      <select class="form-select" name="role" required>
        <option value="" disabled <?= $role === '' ? 'selected' : '' ?>>Selecciona un rol</option>
        <?php foreach ($roles as $value => $label): ?>
          <option value="<?= htmlspecialchars($value) ?>" <?= $role === $value ? 'selected' : '' ?>>
            <?= htmlspecialchars($label) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div>
      <label class="form-label small text-muted" for="edit-password">Contraseña nueva (opcional)</label>
      <div class="input-group">
        <input class="form-control" type="password" name="password" id="edit-password" autocomplete="new-password"
               placeholder="Dejar vacío para no cambiar">
        <button type="button" class="btn btn-outline-secondary password-toggle-btn" data-target="edit-password"
                aria-label="Mostrar contraseña" title="Mostrar contraseña">
          <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="currentColor" viewBox="0 0 16 16" aria-hidden="true">
            <path d="M16 8s-3-5.5-8-5.5S0 8 0 8s3 5.5 8 5.5S16 8 16 8M1.173 8a13 13 0 0 1 1.66-2.043C4.12 4.668 5.88 3.5 8 3.5s3.879 1.168 5.168 2.457A13 13 0 0 1 14.828 8q-.086.13-.195.288c-.335.48-.83 1.12-1.465 1.755C11.879 11.332 10.119 12.5 8 12.5s-3.879-1.168-5.168-2.457A13 13 0 0 1 1.172 8z"/>
            <path d="M8 5.5a2.5 2.5 0 1 0 0 5 2.5 2.5 0 0 0 0-5M4.5 8a3.5 3.5 0 1 1 7 0 3.5 3.5 0 0 1-7 0"/>
          </svg>
        </button>
      </div>
    </div>

    <div>
      <label class="form-label small text-muted" for="edit-password-confirm">Confirmar contraseña</label>
      <div class="input-group">
        <input class="form-control" type="password" name="password_confirm" id="edit-password-confirm" autocomplete="new-password"
               placeholder="Repite la contraseña nueva">
        <button type="button" class="btn btn-outline-secondary password-toggle-btn" data-target="edit-password-confirm"
                aria-label="Mostrar contraseña" title="Mostrar contraseña">
          <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" fill="currentColor" viewBox="0 0 16 16" aria-hidden="true">
            <path d="M16 8s-3-5.5-8-5.5S0 8 0 8s3 5.5 8 5.5S16 8 16 8M1.173 8a13 13 0 0 1 1.66-2.043C4.12 4.668 5.88 3.5 8 3.5s3.879 1.168 5.168 2.457A13 13 0 0 1 14.828 8q-.086.13-.195.288c-.335.48-.83 1.12-1.465 1.755C11.879 11.332 10.119 12.5 8 12.5s-3.879-1.168-5.168-2.457A13 13 0 0 1 1.172 8z"/>
            <path d="M8 5.5a2.5 2.5 0 1 0 0 5 2.5 2.5 0 0 0 0-5M4.5 8a3.5 3.5 0 1 1 7 0 3.5 3.5 0 0 1-7 0"/>
          </svg>
        </button>
      </div>
    </div>

    <div class="d-flex gap-2">
      <button type="submit" class="btn btn-light">Guardar cambios</button>
      <a class="btn btn-outline-light" href="<?= htmlspecialchars(Url::to('/admin/users')) ?>">Cancelar</a>
    </div>
  </form>
</div>

<script>
(function () {
  document.querySelectorAll('.password-toggle-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var input = document.getElementById(btn.dataset.target);
      if (!input) return;
      var show = input.type === 'password';
      input.type = show ? 'text' : 'password';
      btn.setAttribute('aria-label', show ? 'Ocultar contraseña' : 'Mostrar contraseña');
      btn.setAttribute('title', show ? 'Ocultar contraseña' : 'Mostrar contraseña');
    });
  });

  var form = document.getElementById('edit-user-form');
  if (form) {
    form.addEventListener('submit', function (e) {
      var pwd = document.getElementById('edit-password');
      var confirm = document.getElementById('edit-password-confirm');
      if (!pwd || !confirm) return;
      if (pwd.value !== '' && pwd.value !== confirm.value) {
        e.preventDefault();
        alert('Las contraseñas no coinciden.');
        confirm.focus();
      }
    });
  }
})();
</script>
