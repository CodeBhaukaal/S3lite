<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Models\User;

final class QuotaService
{
    /**
     * @return array{allowed:bool, limit:int, used:int, remaining:int, percent:float}
     */
    public static function check(int $userId, int $incomingBytes = 0): array
    {
        $row = Database::selectOne('SELECT quota_bytes, used_bytes FROM users WHERE id = ?', [$userId]);

        $limit = (int) ($row['quota_bytes'] ?? 0);
        $used = (int) ($row['used_bytes'] ?? 0);

        // A zero quota means unlimited.
        $allowed = $limit === 0 || ($used + $incomingBytes) <= $limit;
        $remaining = $limit === 0 ? PHP_INT_MAX : max(0, $limit - $used);

        return [
            'allowed'   => $allowed,
            'limit'     => $limit,
            'used'      => $used,
            'remaining' => $remaining,
            'percent'   => $limit > 0 ? round(($used / $limit) * 100, 2) : 0.0,
        ];
    }

    public static function assert(int $userId, int $incomingBytes): void
    {
        $quota = self::check($userId, $incomingBytes);

        if (!$quota['allowed']) {
            WebhookService::dispatch('quota.exceeded', [
                'user_id'   => $userId,
                'limit'     => $quota['limit'],
                'used'      => $quota['used'],
                'requested' => $incomingBytes,
            ], $userId);

            throw new \App\Http\Exceptions\HttpException(
                413,
                sprintf(
                    'Storage quota exceeded. %s free of %s.',
                    \App\Support\Str::bytes($quota['remaining']),
                    \App\Support\Str::bytes($quota['limit'])
                ),
                'quota_exceeded',
                $quota
            );
        }
    }

    public static function consume(int $userId, int $bytes): void
    {
        User::addUsage($userId, $bytes);
        self::notifyIfNearLimit($userId);
    }

    public static function release(int $userId, int $bytes): void
    {
        User::addUsage($userId, -abs($bytes));
    }

    public static function recalculate(int $userId): int
    {
        return User::recalculateUsage($userId);
    }

    public static function recalculateAll(): int
    {
        $ids = array_column(Database::select('SELECT id FROM users WHERE deleted_at IS NULL'), 'id');

        foreach ($ids as $id) {
            User::recalculateUsage((int) $id);
        }

        return count($ids);
    }

    private static function notifyIfNearLimit(int $userId): void
    {
        $quota = self::check($userId);

        if ($quota['limit'] === 0 || $quota['percent'] < 90) {
            return;
        }

        $alreadyWarned = \App\Core\Cache::get('quota_warned:' . $userId);

        if ($alreadyWarned !== null) {
            return;
        }

        \App\Models\Notification::push(
            $userId,
            'Storage almost full',
            sprintf('You have used %.1f%% of your %s quota.', $quota['percent'], \App\Support\Str::bytes($quota['limit'])),
            'warning',
            url('/files')
        );

        \App\Core\Cache::put('quota_warned:' . $userId, true, 86400);
    }
}
