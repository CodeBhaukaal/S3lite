<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

final class SftpSession extends Model
{
    protected static string $table = 'sftp_sessions';
    protected static bool $timestamps = false;

    public static function active(): array
    {
        return Database::select(
            "SELECT s.*, a.username, a.protocol AS account_protocol, u.name AS owner_name
             FROM sftp_sessions s
             INNER JOIN sftp_accounts a ON a.id = s.account_id
             INNER JOIN users u ON u.id = a.user_id
             WHERE s.status = 'active'
             ORDER BY s.last_activity_at DESC
             LIMIT 200"
        );
    }

    public static function forUser(int $userId): array
    {
        return Database::select(
            "SELECT s.*, a.username
             FROM sftp_sessions s
             INNER JOIN sftp_accounts a ON a.id = s.account_id
             WHERE a.user_id = ? AND s.status = 'active'
             ORDER BY s.last_activity_at DESC
             LIMIT 100",
            [$userId]
        );
    }

    public static function close(int $id): int
    {
        return Database::statement(
            "UPDATE sftp_sessions SET status = 'closed', ended_at = NOW() WHERE id = ? AND status = 'active'",
            [$id]
        );
    }

    /** Sessions idle beyond the timeout are considered dead. */
    public static function reapStale(int $idleSeconds = 900): int
    {
        return Database::statement(
            "UPDATE sftp_sessions SET status = 'closed', ended_at = NOW()
             WHERE status = 'active' AND last_activity_at < DATE_SUB(NOW(), INTERVAL ? SECOND)",
            [$idleSeconds]
        );
    }

    public static function publicArray(array $session): array
    {
        return [
            'id'          => (int) $session['id'],
            'account_id'  => (int) $session['account_id'],
            'username'    => $session['username'] ?? null,
            'session_key' => $session['session_key'],
            'protocol'    => $session['protocol'],
            'ip'          => $session['ip'],
            'client'      => $session['client'],
            'bytes_in'    => (int) $session['bytes_in'],
            'bytes_out'   => (int) $session['bytes_out'],
            'status'      => $session['status'],
            'started_at'  => $session['started_at'],
            'last_activity_at' => $session['last_activity_at'],
            'ended_at'    => $session['ended_at'],
        ];
    }
}
