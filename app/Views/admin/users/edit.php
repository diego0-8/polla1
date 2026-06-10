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
  <form method="post" action="<?= htmlspecialchars(Url::to('/admin/users/update')) ?>" class="vstack gap-3">
    <input type="hidden" name="user_id" value="<?= (int)$user['id'] ?>">

    <div>
      <label class="form-label small text-muted">Nombre completo</label>
      <input class="form-control" type="text" name="name" required
             value="<?= htmlspecialchars((string)$user['name']) ?>">
    </div>

    <div>
      <label class="form-label small text-muted">Usuario</label>
      <input class="form-control" type="text" name="username" required
             pattern="[a-z0-9_]{3,30}" title="3-30 caracteres: letras minúsculas, números y _"
             value="<?= htmlspecialchars((string)$user['username']) ?>">
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
      <label class="form-label small text-muted">Contraseña nueva (opcional)</label>
      <input class="form-control" type="password" name="password" autocomplete="new-password"
             placeholder="Dejar vacío para no cambiar">
    </div>

    <div class="d-flex gap-2">
      <button type="submit" class="btn btn-light">Guardar cambios</button>
      <a class="btn btn-outline-light" href="<?= htmlspecialchars(Url::to('/admin/users')) ?>">Cancelar</a>
    </div>
  </form>
</div>
