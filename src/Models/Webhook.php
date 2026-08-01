<?php
declare(strict_types=1);

namespace App\Models;

final class Webhook extends Model
{
    protected static string $table = 'webhooks';
    protected static array $jsonColumns = ['events'];

    public const EVENTS = [
        'file.uploaded',
        'file.downloaded',
        'file.deleted',
        'file.restored',
        'share.created',
        'share.accessed',
        'quota.exceeded',
        'user.login',
        'user.login_failed',
        'sftp.upload',
        'sftp.download',
    ];

    public static function forUser(int $userId): array
    {
        return self::where(['user_id' => $userId], 'id DESC', 100);
    }

    /** @return list<array> Active hooks subscribed to a given event. */
    public static function listeningFor(string $event, ?int $userId = null): array
    {
        $conditions = ['is_active' => 1, 'events' => ['like', '%"' . $event . '"%']];

        if ($userId !== null) {
            $conditions['user_id'] = $userId;
        }

        return self::where($conditions, 'id ASC', 200);
    }

    public static function publicArray(array $hook): array
    {
        return [
            'id'        => (int) $hook['id'],
            'uuid'      => $hook['uuid'],
            'name'      => $hook['name'],
            'url'       => $hook['url'],
            'events'    => $hook['events'] ?: [],
            'is_active' => (bool) $hook['is_active'],
            'failures'  => (int) $hook['failures'],
            'last_fired_at' => $hook['last_fired_at'],
            'created_at' => $hook['created_at'],
        ];
    }
}
