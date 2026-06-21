<?php
declare(strict_types=1);

use App\Core\Auth;
use App\Core\Url;
use App\Services\PaymentReminderService;

/** @var string $viewName */
$authUser = Auth::user();
$isAdmin = Auth::isAdmin();
$showPaymentModal = Auth::shouldShowPaymentModal();
$paymentInCountdown = $showPaymentModal && PaymentReminderService::isInCountdownWindow();
$paymentAmountCop = number_format(PaymentReminderService::amountCop(), 0, ',', '.');
$paymentSecondsRemaining = PaymentReminderService::secondsRemaining();
$asset = static fn (string $p): string => Url::basePath() . '/' . ltrim($p, '/');

$viewFile = __DIR__ . '/../' . $viewName . '.php';
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Polla Mundial <?= (int)\App\Helpers\MatchView::season() ?></title>
  <link rel="icon" type="image/png" href="<?= htmlspecialchars($asset('img/logo-Photoroom.png')) ?>">
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
            <li class="nav-item"><a class="nav-link" href="<?= htmlspecialchars(Url::to('/admin/matches/manual')) ?>">Manual</a></li>
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

  <?php if ($showPaymentModal): ?>
  <div class="modal fade" id="paymentReminderModal" tabindex="-1" aria-labelledby="paymentReminderModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content text-dark">
        <div class="modal-header border-bottom">
          <h5 class="modal-title text-dark" id="paymentReminderModalLabel">Pago de la polla</h5>
        </div>
        <div class="modal-body text-center text-dark">
          <p class="mb-3 text-dark">
            Para participar debes pagar <strong>$<?= htmlspecialchars($paymentAmountCop) ?> </strong>
            antes del <strong>26 de junio de 2026</strong>.
          </p>
          <img
            src="<?= htmlspecialchars($asset(PaymentReminderService::qrImagePath())) ?>"
            alt="Código QR de pago"
            class="img-fluid rounded border mb-3"
            style="max-width: 280px;"
          >
          <p class="small mb-0 text-dark">
            Escanea el código QR y realiza el pago. Después, envía la captura del comprobante por WhatsApp al
            <strong>320 874 8605</strong> para que un administrador confirme tu participación.
          </p>
          <div id="paymentCountdown" class="alert alert-warning mt-3 mb-0<?= $paymentInCountdown ? '' : ' d-none' ?>">
            <span id="paymentCountdownText"></span>
          </div>
        </div>
        <div class="modal-footer justify-content-center">
          <button type="button" class="btn btn-primary" id="paymentModalDismissBtn">Entendido</button>
        </div>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <?php if ($showPaymentModal): ?>
  <script>
  (function () {
    var modalEl = document.getElementById('paymentReminderModal');
    if (!modalEl) return;

    var dismissUrl = <?= json_encode(Url::to('/payment-modal/dismiss'), JSON_THROW_ON_ERROR) ?>;
    var inCountdown = <?= $paymentInCountdown ? 'true' : 'false' ?>;
    var secondsRemaining = <?= (int)$paymentSecondsRemaining ?>;
    var amountFormatted = <?= json_encode('$' . $paymentAmountCop, JSON_THROW_ON_ERROR) ?>;
    var countdownEl = document.getElementById('paymentCountdown');
    var countdownTextEl = document.getElementById('paymentCountdownText');
    var modal = new bootstrap.Modal(modalEl);

    function formatCountdown(totalSeconds) {
      var hours = Math.floor(totalSeconds / 3600);
      var minutes = Math.floor((totalSeconds % 3600) / 60);
      if (hours > 0 && minutes > 0) {
        return 'Faltan ' + hours + ' hora' + (hours === 1 ? '' : 's') +
          ' y ' + minutes + ' minuto' + (minutes === 1 ? '' : 's') +
          ' para pagar los ' + amountFormatted + ' de la polla o su cuenta será inhabilitada.';
      }
      if (hours > 0) {
        return 'Faltan ' + hours + ' hora' + (hours === 1 ? '' : 's') +
          ' para pagar los ' + amountFormatted + ' de la polla o su cuenta será inhabilitada.';
      }
      return 'Faltan ' + minutes + ' minuto' + (minutes === 1 ? '' : 's') +
        ' para pagar los ' + amountFormatted + ' de la polla o su cuenta será inhabilitada.';
    }

    function updateCountdown() {
      if (!inCountdown || !countdownTextEl) return;
      if (secondsRemaining <= 0) {
        countdownTextEl.textContent = 'El plazo de pago ha vencido. Su cuenta puede ser inhabilitada en cualquier momento.';
        return;
      }
      countdownTextEl.textContent = formatCountdown(secondsRemaining);
      secondsRemaining = Math.max(0, secondsRemaining - 60);
    }

    updateCountdown();
    if (inCountdown) {
      window.setInterval(updateCountdown, 60000);
    }

    modal.show();

    document.getElementById('paymentModalDismissBtn').addEventListener('click', function () {
      fetch(dismissUrl, { method: 'POST', credentials: 'same-origin' })
        .finally(function () { modal.hide(); });
    });
  })();
  </script>
  <?php endif; ?>
</body>
</html>
