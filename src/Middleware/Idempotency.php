<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Core\Database;
use App\Http\Request;
use App\Http\Response;
use App\Services\Auth;

/**
 * Replays the stored response when the same X-Idempotency-Key is retried,
 * so a dropped connection never causes a duplicate upload or delete.
 */
final class Idempotency implements MiddlewareInterface
{
    private const TTL_HOURS = 24;

    public function handle(Request $request, callable $next, array $params = []): Response
    {
        $key = $request->header('X-Idempotency-Key');

        if ($key === null || $key === '' || in_array($request->method, ['GET', 'HEAD', 'OPTIONS'], true)) {
            return $next($request);
        }

        $key = substr($key, 0, 128);
        $userId = Auth::id();

        $existing = Database::selectOne(
            'SELECT * FROM idempotency_keys WHERE key_value = ? AND (user_id <=> ?) AND expires_at > NOW() LIMIT 1',
            [$key, $userId]
        );

        if ($existing !== null) {
            return Response::make(
                (string) $existing['response'],
                (int) $existing['status_code'],
                ['Content-Type' => 'application/json; charset=UTF-8', 'X-Idempotent-Replay' => 'true']
            );
        }

        $response = $next($request);

        if ($response->status() < 500) {
            try {
                Database::statement(
                    'INSERT INTO idempotency_keys (key_value, user_id, method, path, status_code, response, expires_at, created_at)
                     VALUES (?, ?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL ? HOUR), NOW())
                     ON DUPLICATE KEY UPDATE status_code = VALUES(status_code), response = VALUES(response)',
                    [
                        $key,
                        $userId,
                        $request->method,
                        substr($request->path, 0, 255),
                        $response->status(),
                        substr($response->content(), 0, 4000000),
                        self::TTL_HOURS,
                    ]
                );
            } catch (\Throwable) {
                // A failed idempotency write must not fail the request itself.
            }
        }

        return $response;
    }
}
