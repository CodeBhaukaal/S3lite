<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

final class Role extends Model
{
    protected static string $table = 'roles';

    public static function findByName(string $name): ?array
    {
        return self::findBy('name', $name);
    }

    /** @return list<string> */
    public static function permissionNames(int $roleId): array
    {
        return array_column(
            Database::select(
                'SELECT p.name FROM role_permissions rp
                 INNER JOIN permissions p ON p.id = rp.permission_id
                 WHERE rp.role_id = ?',
                [$roleId]
            ),
            'name'
        );
    }

    public static function syncPermissions(int $roleId, array $permissionNames): void
    {
        Database::transaction(static function () use ($roleId, $permissionNames): void {
            Database::delete('role_permissions', 'role_id = ?', [$roleId]);

            if ($permissionNames === []) {
                return;
            }

            $placeholders = implode(',', array_fill(0, count($permissionNames), '?'));
            $ids = array_column(
                Database::select("SELECT id FROM permissions WHERE name IN ({$placeholders})", $permissionNames),
                'id'
            );

            foreach ($ids as $permissionId) {
                Database::statement(
                    'INSERT IGNORE INTO role_permissions (role_id, permission_id) VALUES (?, ?)',
                    [$roleId, (int) $permissionId]
                );
            }
        });
    }

    public static function withCounts(): array
    {
        return Database::select(
            'SELECT r.*, COUNT(u.id) AS user_count
             FROM roles r LEFT JOIN users u ON u.role_id = r.id AND u.deleted_at IS NULL
             GROUP BY r.id ORDER BY r.id'
        );
    }
}
