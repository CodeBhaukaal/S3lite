<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

final class Notification extends Model
{
    protected static string $table = 'notifications';
    protected static bool $timestamps = false;

    public static function push(int $userId, string $title, string $body = '', string $type = 'info', ?string $link = null): int
    {
        return self::create([
            'user_id'    => $userId,
            'type'       => $type,
            'title'      => $title,
            'body'       => $body,
            'link'       => $link,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public static function unread(int $userId, int $limit = 10): array
    {
        return Database::select(
            'SELECT * FROM notifications WHERE user_id = ? AND read_at IS NULL ORDER BY id DESC LIMIT ' . $limit,
            [$userId]
        );
    }

    public static function unreadCount(int $userId): int
    {
        return (int) Database::scalar(
            'SELECT COUNT(*) FROM notifications WHERE user_id = ? AND read_at IS NULL',
            [$userId]
        );
    }

    public static function markAllRead(int $userId): int
    {
        return Database::statement(
            'UPDATE notifications SET read_at = NOW() WHERE user_id = ? AND read_at IS NULL',
            [$userId]
        );
    }
}
