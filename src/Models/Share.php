<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

final class Share extends Model
{
    protected static string $table = 'shares';

    public static function findByToken(string $token): ?array
    {
        return self::findBy('token', $token);
    }

    public static function forFile(int $fileId): array
    {
        return self::where(['file_id' => $fileId], 'id DESC', 50);
    }

    public static function isUsable(array $share): bool
    {
        if (!(int) $share['is_active']) {
            return false;
        }

        if ($share['expires_at'] !== null && strtotime((string) $share['expires_at']) < time()) {
            return false;
        }

        if ($share['max_downloads'] !== null && (int) $share['download_count'] >= (int) $share['max_downloads']) {
            return false;
        }

        return true;
    }

    public static function reason(array $share): string
    {
        if (!(int) $share['is_active']) {
            return 'This link has been disabled by its owner.';
        }
        if ($share['expires_at'] !== null && strtotime((string) $share['expires_at']) < time()) {
            return 'This link has expired.';
        }
        if ($share['max_downloads'] !== null && (int) $share['download_count'] >= (int) $share['max_downloads']) {
            return 'This link has reached its download limit.';
        }

        return 'This link is no longer available.';
    }

    public static function registerHit(int $shareId): void
    {
        Database::statement(
            'UPDATE shares SET download_count = download_count + 1, last_accessed_at = NOW() WHERE id = ?',
            [$shareId]
        );
    }

    public static function publicArray(array $share): array
    {
        return [
            'id'             => (int) $share['id'],
            'uuid'           => $share['uuid'],
            'token'          => $share['token'],
            'url'            => url('s/' . $share['token']),
            'direct_url'     => url('s/' . $share['token'] . '/download'),
            'file_id'        => $share['file_id'] === null ? null : (int) $share['file_id'],
            'folder_id'      => $share['folder_id'] === null ? null : (int) $share['folder_id'],
            'type'           => $share['type'],
            'password_protected' => $share['password_hash'] !== null && $share['password_hash'] !== '',
            'expires_at'     => $share['expires_at'],
            'max_downloads'  => $share['max_downloads'] === null ? null : (int) $share['max_downloads'],
            'download_count' => (int) $share['download_count'],
            'allow_preview'  => (bool) $share['allow_preview'],
            'is_active'      => (bool) $share['is_active'],
            'usable'         => self::isUsable($share),
            'last_accessed_at' => $share['last_accessed_at'],
            'created_at'     => $share['created_at'],
        ];
    }

    public static function listForUser(int $userId, int $page = 1, int $perPage = 20): array
    {
        $offset = max(0, ($page - 1) * $perPage);

        $total = (int) Database::scalar('SELECT COUNT(*) FROM shares WHERE user_id = ?', [$userId]);

        $rows = Database::select(
            "SELECT s.*, f.name AS file_name, f.size AS file_size, f.mime AS file_mime, fo.name AS folder_name
             FROM shares s
             LEFT JOIN files f ON f.id = s.file_id
             LEFT JOIN folders fo ON fo.id = s.folder_id
             WHERE s.user_id = ?
             ORDER BY s.id DESC
             LIMIT {$perPage} OFFSET {$offset}",
            [$userId]
        );

        return [
            'data'      => $rows,
            'total'     => $total,
            'page'      => $page,
            'per_page'  => $perPage,
            'last_page' => (int) max(1, ceil($total / max(1, $perPage))),
        ];
    }
}
