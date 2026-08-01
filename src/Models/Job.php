<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

final class Job extends Model
{
    protected static string $table = 'jobs';
    protected static array $jsonColumns = ['payload'];
    protected static bool $timestamps = false;

    public static function push(string $type, array $payload = [], string $queue = 'default', int $delaySeconds = 0): int
    {
        return self::create([
            'queue'        => $queue,
            'type'         => $type,
            'payload'      => $payload,
            'status'       => 'pending',
            'available_at' => date('Y-m-d H:i:s', time() + $delaySeconds),
            'created_at'   => date('Y-m-d H:i:s'),
        ]);
    }

    /** Claim the next runnable job (single-worker friendly). */
    public static function reserve(string $queue = 'default'): ?array
    {
        return Database::transaction(static function () use ($queue): ?array {
            $row = Database::selectOne(
                "SELECT * FROM jobs
                 WHERE queue = ? AND status = 'pending' AND available_at <= NOW()
                 ORDER BY id ASC LIMIT 1 FOR UPDATE",
                [$queue]
            );

            if ($row === null) {
                return null;
            }

            Database::statement(
                "UPDATE jobs SET status = 'running', reserved_at = NOW(), attempts = attempts + 1 WHERE id = ?",
                [(int) $row['id']]
            );

            return self::hydrate($row);
        });
    }

    public static function complete(int $id, string $output = ''): void
    {
        Database::statement(
            "UPDATE jobs SET status = 'completed', completed_at = NOW(), output = ? WHERE id = ?",
            [substr($output, 0, 60000), $id]
        );
    }

    public static function fail(int $id, string $error): void
    {
        $job = self::find($id);
        $attempts = (int) ($job['attempts'] ?? 1);
        $max = (int) ($job['max_attempts'] ?? 3);

        if ($attempts < $max) {
            Database::statement(
                "UPDATE jobs SET status = 'pending', available_at = DATE_ADD(NOW(), INTERVAL ? SECOND), error = ? WHERE id = ?",
                [min(600, 30 * $attempts), substr($error, 0, 60000), $id]
            );

            return;
        }

        Database::statement(
            "UPDATE jobs SET status = 'failed', completed_at = NOW(), error = ? WHERE id = ?",
            [substr($error, 0, 60000), $id]
        );
    }

    public static function stats(): array
    {
        $rows = Database::select('SELECT status, COUNT(*) AS total FROM jobs GROUP BY status');

        $stats = ['pending' => 0, 'running' => 0, 'completed' => 0, 'failed' => 0];
        foreach ($rows as $row) {
            $stats[$row['status']] = (int) $row['total'];
        }

        return $stats;
    }
}
