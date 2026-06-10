<?php
declare(strict_types=1);

use App\Core\Auth;
use App\Core\Url;

/** @var string $viewName */
$authUser = Auth::user();
$isAdmin = Auth::isAdmin();
$asset = static fn (string $p): string => Url::basePath() . '/' . ltrim($p, '/');

$viewFile = __DIR__ . '/../' . $viewName . '.php';
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Polla Mundial <?= (int)\App\Helpers\MatchView::season() ?></title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="<?= htmlspecialchars($asset('assets/css/app.css')) ?>" rel="stylesheet">
</head>
<body>
  <nav class="navbar navbar-expand-lg navbar-dark" style="background:#0b1020;">
    <div class="container-fluid">
      <a class="navbar-brand fw-semibold" href="<?= htmlspecialchars(Url::to('/')) ?>">Polla 2026</a>
      <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#nav" aria-controls="nav" aria-expanded="false" aria-label="Toggle navigation">
        <span class="navbar-toggler-icon"></span>
      </button>
      <div class="collapse navbar-collapse" id="nav">
        <ul class="navbar-nav me-auto mb-2 mb-lg-0">
          <li class="nav-item"><a class="nav-link" href="<?= htmlspecialchars(Url::to('/matches')) ?>">Partidos</a></li>
          <li class="nav-item"><a class="nav-link" href="<?= htmlspecialchars(Url::to('/leaderboard')) ?>">Posiciones</a></li>
          <li class="nav-item"><a class="nav-link" href="<?= htmlspecialchars(Url::to('/tournament-pick')) ?>">Campeón</a></li>
          <?php if ($isAdmin): ?>
            <li class="nav-item"><a class="nav-link" href="<?= htmlspecialchars(Url::to('/admin/users')) ?>">Admin</a></li>
            <li class="nav-item"><a class="nav-link" href="<?= htmlspecialchars(Url::to('/admin/predictions')) ?>">Auditoría</a></li>
          <?php endif; ?>
        </ul>
        <div class="d-flex gap-2">
          <?php if ($authUser): ?>
            <span class="text-muted small align-self-center">Hola, <?= htmlspecialchars((string)$authUser['name']) ?></span>
            <form method="post" action="<?= htmlspecialchars(Url::to('/logout')) ?>" class="m-0">
              <button class="btn btn-outline-light btn-sm">Salir</button>
            </form>
          <?php else: ?>
            <a class="btn btn-outline-light btn-sm" href="<?= htmlspecialchars(Url::to('/login')) ?>">Entrar</a>
            <a class="btn btn-light btn-sm" href="<?= htmlspecialchars(Url::to('/register')) ?>">Registro</a>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </nav>

  <main class="container py-3 py-lg-4">
    <?php require $viewFile; ?>
  </main>

  <nav class="navbar fixed-bottom bottom-nav">
    <div class="container-fluid justify-content-around">
      <a class="nav-link text-center" href="<?= htmlspecialchars(Url::to('/')) ?>"><div class="small">Inicio</div></a>
      <a class="nav-link text-center" href="<?= htmlspecialchars(Url::to('/matches')) ?>"><div class="small">Partidos</div></a>
      <a class="nav-link text-center" href="<?= htmlspecialchars(Url::to('/leaderboard')) ?>"><div class="small">Tabla</div></a>
      <a class="nav-link text-center" href="<?= htmlspecialchars(Url::to('/tournament-pick')) ?>"><div class="small">Campeón</div></a>
      <?php if ($authUser): ?>
        <form method="post" action="<?= htmlspecialchars(Url::to('/logout')) ?>" class="m-0">
          <button class="btn btn-link nav-link text-center p-0"><div class="small">Salir</div></button>
        </form>
      <?php else: ?>
        <a class="nav-link text-center" href="<?= htmlspecialchars(Url::to('/login')) ?>"><div class="small">Login</div></a>
      <?php endif; ?>
    </div>
  </nav>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
