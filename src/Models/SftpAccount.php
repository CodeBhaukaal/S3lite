<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

final class SftpAccount extends Model
{
    protected static string $table = 'sftp_accounts';
    protected static array $jsonColumns = ['ip_allowlist'];

    public static function findByUsername(string $username): ?array
    {
        return self::findBy('username', $username);
    }

    public static function resolve(string $identifier): ?array
    {
        return ctype_digit($identifier) ? self::find((int) $identifier) : self::findBy('uuid', $identifier);
    }

    public static function forUser(int $userId): array
    {
        return self::where(['user_id' => $userId], 'id DESC', 100);
    }

    public static function withOwners(array $conditions = [], int $page = 1, int $perPage = 20): array
    {
        [$where, $bindings] = self::buildWhere($conditions, 'a');

        $total = (int) Database::scalar("SELECT COUNT(*) FROM sftp_accounts a WHERE {$where}", $bindings);
        $offset = max(0, ($page - 1) * $perPage);

        $rows = Database::select(
            "SELECT a.*, u.name AS owner_name, u.email AS owner_email,
                    (SELECT COUNT(*) FROM sftp_keys k WHERE k.account_id = a.id) AS key_count,
                    (SELECT COUNT(*) FROM sftp_sessions s WHERE s.account_id = a.id AND s.status = 'active') AS active_sessions
             FROM sftp_accounts a
             INNER JOIN users u ON u.id = a.user_id
             WHERE {$where}
             ORDER BY a.id DESC
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

    public static function publicArray(array $account): array
    {
        return [
            'id'           => (int) $account['id'],
            'uuid'         => $account['uuid'],
            'user_id'      => (int) $account['user_id'],
            'username'     => $account['username'],
            'home_dir'     => $account['home_dir'],
            'protocol'     => $account['protocol'],
            'permission'   => $account['permission'],
            'quota_bytes'  => (int) $account['quota_bytes'],
            'used_bytes'   => (int) $account['used_bytes'],
            'status'       => $account['status'],
            'ip_allowlist' => $account['ip_allowlist'] ?: [],
            'has_password' => $account['password_hash'] !== null && $account['password_hash'] !== '',
            'key_count'    => isset($account['key_count']) ? (int) $account['key_count'] : null,
            'last_login_at' => $account['last_login_at'],
            'created_at'   => $account['created_at'],
        ];
    }
}
