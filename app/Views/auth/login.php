<?php
declare(strict_types=1);

/** @var string|null $error */
?>

<div class="row justify-content-center">
  <div class="col-12 col-md-6 col-lg-4">
    <div class="card p-4">
      <h5 class="mb-3">Iniciar sesión</h5>
      <?php if ($error): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
      <?php endif; ?>
      <form method="post" action="<?= htmlspecialchars(\App\Core\Url::to('/login')) ?>" class="vstack gap-2">
        <div>
          <label class="form-label small text-muted">Usuario</label>
          <input class="form-control" type="text" name="username" required autocomplete="username">
        </div>
        <div>
          <label class="form-label small text-muted">Contraseña</label>
          <input class="form-control" type="password" name="password" required autocomplete="current-password">
        </div>
        <button class="btn btn-light mt-2">Entrar</button>
        <a class="btn btn-outline-light" href="<?= htmlspecialchars(\App\Core\Url::to('/register')) ?>">Registrarse como asesor</a>
      </form>
    </div>
  </div>
</div>
