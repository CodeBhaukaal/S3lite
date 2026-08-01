<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

final class ApiKey extends Model
{
    protected static string $table = 'api_keys';
    protected static array $jsonColumns = ['scopes', 'ip_allowlist'];

    public static function findByHash(string $hash): ?array
    {
        return self::findBy('key_hash', $hash);
    }

    public static function forUser(int $userId): array
    {
        return self::where(['user_id' => $userId], 'id DESC', 200);
    }

    public static function touch(int $id, string $ip): void
    {
        Database::statement(
            'UPDATE api_keys SET last_used_at = NOW(), last_used_ip = ?, usage_count = usage_count + 1 WHERE id = ?',
            [$ip, $id]
        );
    }

    public static function isUsable(array $key): bool
    {
        if ($key['revoked_at'] !== null) {
            return false;
        }

        if ($key['expires_at'] !== null && strtotime((string) $key['expires_at']) < time()) {
            return false;
        }

        return true;
    }

    public static function publicArray(array $key): array
    {
        return [
            'id'           => (int) $key['id'],
            'uuid'         => $key['uuid'],
            'name'         => $key['name'],
            'prefix'       => $key['prefix'],
            'scopes'       => $key['scopes'] ?: [],
            'ip_allowlist' => $key['ip_allowlist'] ?: [],
            'last_used_at' => $key['last_used_at'],
            'usage_count'  => (int) $key['usage_count'],
            'expires_at'   => $key['expires_at'],
            'revoked_at'   => $key['revoked_at'],
            'created_at'   => $key['created_at'],
            'active'       => self::isUsable($key),
        ];
    }
}
