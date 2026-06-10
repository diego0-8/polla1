<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\DB;

final class User
{
    private const ALLOWED_ROLES = ['admin', 'helper', 'asesor'];

    public static function findById(int $id): ?array
    {
        $st = DB::pdo()->prepare('SELECT * FROM users WHERE id = :id');
        $st->execute(['id' => $id]);
        $row = $st->fetch();
        return $row ?: null;
    }

    public static function findByUsername(string $username): ?array
    {
        $st = DB::pdo()->prepare('SELECT * FROM users WHERE username = :username');
        $st->execute(['username' => $username]);
        $row = $st->fetch();
        return $row ?: null;
    }

    /** @return list<string> */
    public static function rolesFor(int $userId): array
    {
        $st = DB::pdo()->prepare(
            'SELECT r.name FROM user_roles ur
             INNER JOIN roles r ON r.id = ur.role_id
             WHERE ur.user_id = :user_id
             ORDER BY r.name ASC'
        );
        $st->execute(['user_id' => $userId]);
        return array_column($st->fetchAll() ?: [], 'name');
    }

    public static function hasRole(int $userId, string $role): bool
    {
        return in_array($role, self::rolesFor($userId), true);
    }

    public static function primaryRole(int $userId): string
    {
        $roles = self::rolesFor($userId);
        return $roles[0] ?? '—';
    }

    public static function countAll(): int
    {
        return (int)DB::pdo()->query('SELECT COUNT(*) FROM users')->fetchColumn();
    }

    /** @return list<array<string, mixed>> */
    public static function activeByRole(string $role): array
    {
        $st = DB::pdo()->prepare(
            'SELECT u.id, u.name, u.username
             FROM users u
             INNER JOIN user_roles ur ON ur.user_id = u.id
             INNER JOIN roles r ON r.id = ur.role_id
             WHERE r.name = :role AND u.status = \'active\'
             ORDER BY u.name ASC'
        );
        $st->execute(['role' => $role]);
        return $st->fetchAll() ?: [];
    }

    /** @return list<array<string, mixed>> */
    public static function allForAdmin(): array
    {
        $st = DB::pdo()->query(
            'SELECT u.id, u.name, u.username, u.status, COALESCE(u.has_paid, 0) AS has_paid,
                    COALESCE(
                        (SELECT r.name FROM user_roles ur
                         INNER JOIN roles r ON r.id = ur.role_id
                         WHERE ur.user_id = u.id
                         ORDER BY r.name ASC LIMIT 1),
                        \'—\'
                    ) AS role_name
             FROM users u
             ORDER BY u.name ASC'
        );
        return $st->fetchAll() ?: [];
    }

    public static function createAsesor(string $name, string $username, string $hash): int
    {
        $pdo = DB::pdo();
        $pdo->beginTransaction();
        try {
            $st = $pdo->prepare(
                'INSERT INTO users (name, username, password_hash, status, created_at, updated_at)
                 VALUES (:name, :username, :hash, :status, NOW(), NOW())'
            );
            $st->execute([
                'name' => $name,
                'username' => $username,
                'hash' => $hash,
                'status' => 'active',
            ]);
            $userId = (int)$pdo->lastInsertId();

            $stRole = $pdo->prepare(
                'INSERT INTO user_roles (user_id, role_id)
                 SELECT :user_id, r.id FROM roles r WHERE r.name = :role LIMIT 1'
            );
            $stRole->execute(['user_id' => $userId, 'role' => 'asesor']);

            $pdo->prepare('INSERT INTO user_points (user_id, points_total) VALUES (:user_id, 0)')
                ->execute(['user_id' => $userId]);

            $pdo->commit();
            return $userId;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    public static function updateProfile(
        int $id,
        string $name,
        string $username,
        string $role,
        ?string $passwordHash = null,
    ): void {
        if (!in_array($role, self::ALLOWED_ROLES, true)) {
            throw new \RuntimeException('Rol inválido.');
        }

        $user = self::findById($id);
        if (!$user) {
            throw new \RuntimeException('Usuario no encontrado.');
        }

        $username = strtolower(trim($username));
        if ($name === '' || $username === '') {
            throw new \RuntimeException('Nombre y usuario son obligatorios.');
        }

        if (!preg_match('/^[a-z0-9_]{3,30}$/', $username)) {
            throw new \RuntimeException('Usuario: 3-30 caracteres, solo letras, números y _.');
        }

        $existing = self::findByUsername($username);
        if ($existing && (int)$existing['id'] !== $id) {
            throw new \RuntimeException('Ese nombre de usuario ya está en uso.');
        }

        $currentRole = self::primaryRole($id);
        if ($currentRole === 'admin' && $role !== 'admin' && self::countActiveAdmins() <= 1 && self::hasRole($id, 'admin')) {
            throw new \RuntimeException('No puedes quitar el rol admin al último administrador activo.');
        }

        $pdo = DB::pdo();
        $pdo->beginTransaction();
        try {
            if ($passwordHash !== null) {
                $st = $pdo->prepare(
                    'UPDATE users SET name = :name, username = :username, password_hash = :hash, updated_at = NOW()
                     WHERE id = :id'
                );
                $st->execute(['name' => $name, 'username' => $username, 'hash' => $passwordHash, 'id' => $id]);
            } else {
                $st = $pdo->prepare(
                    'UPDATE users SET name = :name, username = :username, updated_at = NOW() WHERE id = :id'
                );
                $st->execute(['name' => $name, 'username' => $username, 'id' => $id]);
            }

            $pdo->prepare('DELETE FROM user_roles WHERE user_id = :user_id')->execute(['user_id' => $id]);
            $stRole = $pdo->prepare(
                'INSERT INTO user_roles (user_id, role_id)
                 SELECT :user_id, r.id FROM roles r WHERE r.name = :role LIMIT 1'
            );
            $stRole->execute(['user_id' => $id, 'role' => $role]);
            if ($stRole->rowCount() === 0) {
                throw new \RuntimeException('No se pudo asignar el rol seleccionado.');
            }

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    public static function setStatus(int $id, string $status): void
    {
        if (!in_array($status, ['active', 'suspended'], true)) {
            throw new \RuntimeException('Estado inválido.');
        }

        $user = self::findById($id);
        if (!$user) {
            throw new \RuntimeException('Usuario no encontrado.');
        }

        if ($status === 'suspended' && self::hasRole($id, 'admin') && self::countActiveAdmins() <= 1) {
            throw new \RuntimeException('No puedes inhabilitar al último administrador activo.');
        }

        $st = DB::pdo()->prepare('UPDATE users SET status = :status, updated_at = NOW() WHERE id = :id');
        $st->execute(['status' => $status, 'id' => $id]);
    }

    public static function setPaid(int $id, bool $paid): void
    {
        $user = self::findById($id);
        if (!$user) {
            throw new \RuntimeException('Usuario no encontrado.');
        }

        $st = DB::pdo()->prepare('UPDATE users SET has_paid = :paid, updated_at = NOW() WHERE id = :id');
        $st->execute(['paid' => $paid ? 1 : 0, 'id' => $id]);
    }

    public static function countActiveAdmins(): int
    {
        $st = DB::pdo()->query(
            'SELECT COUNT(DISTINCT u.id)
             FROM users u
             INNER JOIN user_roles ur ON ur.user_id = u.id
             INNER JOIN roles r ON r.id = ur.role_id
             WHERE r.name = \'admin\' AND u.status = \'active\''
        );
        return (int)$st->fetchColumn();
    }
}
