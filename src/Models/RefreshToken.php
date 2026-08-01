<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

final class RefreshToken extends Model
{
    protected static string $table = 'refresh_tokens';
    protected static bool $timestamps = false;

    public static function findUsable(string $hash): ?array
    {
        return Database::selectOne(
            'SELECT * FROM refresh_tokens
             WHERE token_hash = ? AND revoked_at IS NULL AND expires_at > NOW()
             LIMIT 1',
            [$hash]
        );
    }

    public static function revoke(int $id): void
    {
        Database::statement('UPDATE refresh_tokens SET revoked_at = NOW() WHERE id = ?', [$id]);
    }

    public static function revokeAllForUser(int $userId): int
    {
        return Database::statement(
            'UPDATE refresh_tokens SET revoked_at = NOW() WHERE user_id = ? AND revoked_at IS NULL',
            [$userId]
        );
    }

    public static function purgeExpired(): int
    {
        return Database::statement(
            'DELETE FROM refresh_tokens WHERE expires_at < DATE_SUB(NOW(), INTERVAL 7 DAY)'
        );
    }
}
