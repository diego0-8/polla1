<?php
declare(strict_types=1);

/** @var string|null $error */
?>

<div class="row justify-content-center">
  <div class="col-12 col-md-6 col-lg-4">
    <div class="card p-4">
      <h5 class="mb-3">Registro de asesor</h5>
      <p class="small text-muted">Solo se registran usuarios con rol asesor.</p>
      <?php if ($error): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
      <?php endif; ?>
      <form method="post" action="<?= htmlspecialchars(\App\Core\Url::to('/register')) ?>" class="vstack gap-2">
        <div>
          <label class="form-label small text-muted">Nombre completo</label>
          <input class="form-control" type="text" name="name" required autocomplete="name">
        </div>
        <div>
          <label class="form-label small text-muted">Usuario</label>
          <input class="form-control" type="text" name="username" required autocomplete="username" pattern="[a-zA-Z0-9._]{3,30}" title="3-30 caracteres: letras, números, punto (.) y guion bajo (_)">
          <div class="form-text text-muted">Único. Con este usuario iniciarás sesión.</div>
        </div>
        <div>
          <label class="form-label small text-muted">Contraseña</label>
          <input class="form-control" type="password" name="password" required autocomplete="new-password">
        </div>
        <button class="btn btn-light mt-2">Crear cuenta</button>
        <a class="btn btn-outline-light" href="<?= htmlspecialchars(\App\Core\Url::to('/login')) ?>">Ya tengo cuenta</a>
      </form>
    </div>
  </div>
</div>
