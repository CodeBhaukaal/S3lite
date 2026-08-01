<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

final class User extends Model
{
    protected static string $table = 'users';
    protected static array $jsonColumns = ['settings', 'recovery_codes'];

    public static function findByEmail(string $email): ?array
    {
        return self::findWhere(['email' => $email, 'deleted_at' => null]);
    }

    public static function findByUuid(string $uuid): ?array
    {
        return self::findWhere(['uuid' => $uuid, 'deleted_at' => null]);
    }

    /** User row joined with its role name and permission list. */
    public static function withRole(int $id): ?array
    {
        $row = Database::selectOne(
            'SELECT u.*, r.name AS role_name, r.label AS role_label
             FROM users u
             INNER JOIN roles r ON r.id = u.role_id
             WHERE u.id = ? AND u.deleted_at IS NULL
             LIMIT 1',
            [$id]
        );

        if ($row === null) {
            return null;
        }

        $row = self::hydrate($row);
        $row['permissions'] = self::permissions($id);

        return $row;
    }

    /** @return list<string> */
    public static function permissions(int $userId): array
    {
        $rows = Database::select(
            'SELECT p.name
             FROM users u
             INNER JOIN role_permissions rp ON rp.role_id = u.role_id
             INNER JOIN permissions p ON p.id = rp.permission_id
             WHERE u.id = ?',
            [$userId]
        );

        return array_column($rows, 'name');
    }

    public static function isAdmin(array $user): bool
    {
        return ($user['role_name'] ?? '') === 'admin';
    }

    public static function can(array $user, string $permission): bool
    {
        if (self::isAdmin($user)) {
            return true;
        }

        return in_array($permission, $user['permissions'] ?? [], true);
    }

    public static function recalculateUsage(int $userId): int
    {
        $used = (int) Database::scalar(
            'SELECT COALESCE(SUM(size), 0) FROM files WHERE user_id = ? AND deleted_at IS NULL',
            [$userId]
        );

        // Trashed files still occupy disk, so they still count against quota.
        $trashed = (int) Database::scalar(
            'SELECT COALESCE(SUM(size), 0) FROM files WHERE user_id = ? AND deleted_at IS NOT NULL',
            [$userId]
        );

        $versions = (int) Database::scalar(
            'SELECT COALESCE(SUM(v.size), 0)
             FROM file_versions v
             INNER JOIN files f ON f.id = v.file_id
             WHERE f.user_id = ?',
            [$userId]
        );

        $total = $used + $trashed + $versions;

        Database::statement('UPDATE users SET used_bytes = ? WHERE id = ?', [$total, $userId]);

        return $total;
    }

    public static function addUsage(int $userId, int $delta): void
    {
        Database::statement(
            'UPDATE users SET used_bytes = GREATEST(0, CAST(used_bytes AS SIGNED) + ?) WHERE id = ?',
            [$delta, $userId]
        );
    }

    public static function listWithRoles(array $conditions = [], int $page = 1, int $perPage = 20, string $order = 'u.id DESC'): array
    {
        [$where, $bindings] = self::buildWhere($conditions, 'u');

        $total = (int) Database::scalar("SELECT COUNT(*) FROM users u WHERE {$where}", $bindings);

        $offset = max(0, ($page - 1) * $perPage);
        $rows = Database::select(
            "SELECT u.*, r.name AS role_name, r.label AS role_label
             FROM users u INNER JOIN roles r ON r.id = u.role_id
             WHERE {$where}
             ORDER BY {$order}
             LIMIT {$perPage} OFFSET {$offset}",
            $bindings
        );

        return [
            'data'      => array_map([self::class, 'hydrate'], $rows),
            'total'     => $total,
            'page'      => $page,
            'per_page'  => $perPage,
            'last_page' => (int) max(1, ceil($total / max(1, $perPage))),
        ];
    }

    public static function publicArray(array $user): array
    {
        return [
            'id'         => (int) $user['id'],
            'uuid'       => $user['uuid'],
            'name'       => $user['name'],
            'email'      => $user['email'],
            'role'       => $user['role_name'] ?? null,
            'status'     => $user['status'],
            'quota'      => [
                'limit'   => (int) $user['quota_bytes'],
                'used'    => (int) $user['used_bytes'],
                'percent' => (int) $user['quota_bytes'] > 0
                    ? round(((int) $user['used_bytes'] / (int) $user['quota_bytes']) * 100, 2)
                    : 0.0,
            ],
            'two_factor_enabled' => (bool) $user['two_factor_enabled'],
            'last_login_at'      => $user['last_login_at'],
            'created_at'         => $user['created_at'],
        ];
    }
}
