<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

final class LoginAttempt extends Model
{
    protected static string $table = 'login_attempts';
    protected static bool $timestamps = false;

    public static function record(?string $email, ?int $userId, string $ip, string $userAgent, bool $success, ?string $reason = null): void
    {
        self::create([
            'email'      => $email,
            'user_id'    => $userId,
            'ip'         => $ip,
            'user_agent' => $userAgent,
            'success'    => $success ? 1 : 0,
            'reason'     => $reason,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Failures for one account. Deliberately not combined with the IP count:
     * several users behind one NAT must not lock each other out.
     */
    public static function recentFailures(string $email, int $minutes = 15): int
    {
        return (int) Database::scalar(
            'SELECT COUNT(*) FROM login_attempts
             WHERE success = 0 AND email = ?
               AND created_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)',
            [$email, $minutes]
        );
    }

    /** Failures from one address, across every account — catches credential stuffing. */
    public static function recentFailuresForIp(string $ip, int $minutes = 15): int
    {
        return (int) Database::scalar(
            'SELECT COUNT(*) FROM login_attempts
             WHERE success = 0 AND ip = ?
               AND created_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)',
            [$ip, $minutes]
        );
    }

    public static function recent(int $limit = 50, ?bool $success = null): array
    {
        $sql = 'SELECT * FROM login_attempts';
        $bindings = [];

        if ($success !== null) {
            $sql .= ' WHERE success = ?';
            $bindings[] = $success ? 1 : 0;
        }

        $sql .= ' ORDER BY id DESC LIMIT ' . max(1, min(500, $limit));

        return Database::select($sql, $bindings);
    }
}
