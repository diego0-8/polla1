<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\View;
use App\Models\MatchModel;
use App\Models\SeasonPrize;
use App\Models\User;

final class AdminUserController extends Controller
{
    public function index(): void
    {
        $flash = $_SESSION['admin_flash'] ?? null;
        $error = $_SESSION['admin_error'] ?? null;
        unset($_SESSION['admin_flash'], $_SESSION['admin_error']);

        $season = MatchModel::seasonYear();

        View::render('admin/users/index', [
            'users' => User::allForAdmin(),
            'totalUsers' => User::countAll(),
            'currentUserId' => Auth::id(),
            'flash' => $flash,
            'error' => $error,
            'season' => $season,
            'prizes' => SeasonPrize::getForSeason($season),
        ]);
    }

    public function edit(): void
    {
        $id = (int)($_GET['id'] ?? 0);
        $user = $id ? User::findById($id) : null;
        if (!$user) {
            $_SESSION['admin_error'] = 'Usuario no encontrado.';
            $this->redirect($this->url('/admin/users'));
        }

        $role = User::primaryRole($id);
        if ($role === '—' || !in_array($role, ['admin', 'helper', 'asesor'], true)) {
            $role = '';
        }

        View::render('admin/users/edit', [
            'user' => $user,
            'role' => $role,
            'error' => $_SESSION['admin_edit_error'] ?? null,
        ]);
        unset($_SESSION['admin_edit_error']);
    }

    public function update(): void
    {
        $id = (int)($_POST['user_id'] ?? 0);
        $redirectEdit = $this->url('/admin/users/edit') . '?id=' . $id;
        $redirectList = $this->url('/admin/users');

        $user = $id ? User::findById($id) : null;
        if (!$user) {
            $_SESSION['admin_error'] = 'Usuario no encontrado.';
            $this->redirect($redirectList);
        }

        $name = trim((string)($_POST['name'] ?? ''));
        $username = strtolower(trim((string)($_POST['username'] ?? '')));
        $role = (string)($_POST['role'] ?? '');
        $password = (string)($_POST['password'] ?? '');

        try {
            $hash = $password !== '' ? password_hash($password, PASSWORD_DEFAULT) : null;
            User::updateProfile($id, $name, $username, $role, $hash);
            $_SESSION['admin_flash'] = 'Usuario actualizado correctamente.';
            $this->redirect($redirectList);
        } catch (\RuntimeException $e) {
            $_SESSION['admin_edit_error'] = $e->getMessage();
            $this->redirect($redirectEdit);
        }
    }

    public function disable(): void
    {
        $id = (int)($_POST['user_id'] ?? 0);
        $redirect = $this->url('/admin/users');

        if ($id <= 0) {
            $_SESSION['admin_error'] = 'Usuario inválido.';
            $this->redirect($redirect);
        }

        $currentId = Auth::id();
        if ($currentId !== null && $id === $currentId) {
            $_SESSION['admin_error'] = 'No puedes inhabilitar tu propia cuenta.';
            $this->redirect($redirect);
        }

        try {
            User::setStatus($id, 'suspended');
            $_SESSION['admin_flash'] = 'Usuario inhabilitado. Ya no podrá iniciar sesión.';
        } catch (\RuntimeException $e) {
            $_SESSION['admin_error'] = $e->getMessage();
        }

        $this->redirect($redirect);
    }

    public function enable(): void
    {
        $id = (int)($_POST['user_id'] ?? 0);
        $redirect = $this->url('/admin/users');

        if ($id <= 0) {
            $_SESSION['admin_error'] = 'Usuario inválido.';
            $this->redirect($redirect);
        }

        try {
            User::setStatus($id, 'active');
            $_SESSION['admin_flash'] = 'Usuario habilitado. Ya puede iniciar sesión.';
        } catch (\RuntimeException $e) {
            $_SESSION['admin_error'] = $e->getMessage();
        }

        $this->redirect($redirect);
    }

    public function togglePaid(): void
    {
        $id = (int)($_POST['user_id'] ?? 0);
        $redirect = $this->url('/admin/users');

        if ($id <= 0) {
            $_SESSION['admin_error'] = 'Usuario inválido.';
            $this->redirect($redirect);
        }

        $user = User::findById($id);
        if (!$user) {
            $_SESSION['admin_error'] = 'Usuario no encontrado.';
            $this->redirect($redirect);
        }

        try {
            $currentlyPaid = (int)($user['has_paid'] ?? 0) === 1;
            User::setPaid($id, !$currentlyPaid);
            $_SESSION['admin_flash'] = $currentlyPaid
                ? 'Pago marcado como No.'
                : 'Pago marcado como Sí.';
        } catch (\RuntimeException $e) {
            $_SESSION['admin_error'] = $e->getMessage();
        }

        $this->redirect($redirect);
    }

    public function savePrizes(): void
    {
        $redirect = $this->url('/admin/users');
        $season = MatchModel::seasonYear();
        $prizeCount = (int)($_POST['prize_count'] ?? 1);

        $amounts = [];
        for ($i = 1; $i <= 5; $i++) {
            $raw = trim((string)($_POST["prize_{$i}_cop"] ?? ''));
            $amounts[] = $raw === '' ? null : (int)preg_replace('/\D/', '', $raw);
        }

        try {
            SeasonPrize::save($season, $prizeCount, $amounts);
            $_SESSION['admin_flash'] = 'Premios guardados correctamente.';
        } catch (\RuntimeException $e) {
            $_SESSION['admin_error'] = $e->getMessage();
        }

        $this->redirect($redirect);
    }
}
