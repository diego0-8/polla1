<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\View;
use App\Models\User;

final class AuthController extends Controller
{
    public function showLogin(): void
    {
        View::render('auth/login', ['error' => null]);
    }

    public function login(): void
    {
        $username = User::normalizeUsername((string)($_POST['username'] ?? ''));
        $password = (string)($_POST['password'] ?? '');

        $user = User::findByUsername($username);
        if (!$user || !password_verify($password, $user['password_hash'])) {
            View::render('auth/login', ['error' => 'Credenciales inválidas.']);
            return;
        }
        if (($user['status'] ?? 'active') !== 'active') {
            View::render('auth/login', ['error' => 'Usuario suspendido.']);
            return;
        }

        Auth::login((int)$user['id']);
        $dest = User::hasRole((int)$user['id'], 'asesor') ? '/cruces' : '/';
        $this->redirect($this->url($dest));
    }

    public function showRegister(): void
    {
        View::render('auth/register', ['error' => null]);
    }

    public function register(): void
    {
        $name = trim((string)($_POST['name'] ?? ''));
        $username = User::normalizeUsername((string)($_POST['username'] ?? ''));
        $password = (string)($_POST['password'] ?? '');

        if ($name === '' || $username === '' || $password === '') {
            View::render('auth/register', ['error' => 'Completa todos los campos.']);
            return;
        }

        try {
            User::assertValidUsername($username);
        } catch (\RuntimeException $e) {
            View::render('auth/register', ['error' => $e->getMessage()]);
            return;
        }

        if (User::findByUsername($username)) {
            View::render('auth/register', ['error' => 'Ese usuario ya existe.']);
            return;
        }

        try {
            $id = User::createAsesor($name, $username, password_hash($password, PASSWORD_DEFAULT));
        } catch (\Throwable $e) {
            View::render('auth/register', ['error' => 'No se pudo crear la cuenta. Verifica la base de datos.']);
            return;
        }

        Auth::login($id);
        $this->redirect($this->url('/cruces'));
    }

    public function logout(): void
    {
        Auth::logout();
        $this->redirect($this->url('/'));
    }

    public function dismissPaymentModal(): void
    {
        Auth::dismissPaymentModal();
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => true], JSON_THROW_ON_ERROR);
        exit;
    }
}
