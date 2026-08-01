<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Core\Cache;
use App\Core\Config;
use App\Http\Exceptions\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Services\Auth;

/**
 * Fixed-window limiter backed by Redis (or the file cache when Redis is down).
 * Usage: `throttle:api` / `throttle:login` / `throttle:60,60`
 */
final class RateLimit implements MiddlewareInterface
{
    public function handle(Request $request, callable $next, array $params = []): Response
    {
        if (!Config::get('app.rate_limit.enabled', true)) {
            return $next($request);
        }

        [$limit, $window, $bucket] = $this->resolveLimits($params);

        $identity = Auth::id() !== null
            ? 'u' . Auth::id()
            : 'ip' . $request->ip();

        $key = sprintf('ratelimit:%s:%s:%d', $bucket, $identity, (int) floor(time() / $window));
        $reset = ($window * (int) floor(time() / $window)) + $window;

        // The login bucket counts failures only: a legitimate user signing in
        // repeatedly (or a test suite) must never be locked out, while credential
        // stuffing still hits the wall. Per-account lockout is enforced separately.
        $deferred = $bucket === 'login';

        $hits = $deferred
            ? (int) Cache::get($key, 0) + 1
            : Cache::increment($key, 1, $window);

        $remaining = max(0, $limit - $hits);

        $headers = [
            'X-RateLimit-Limit'     => (string) $limit,
            'X-RateLimit-Remaining' => (string) $remaining,
            'X-RateLimit-Reset'     => (string) $reset,
        ];

        if ($hits > $limit) {
            $retryAfter = max(1, $reset - time());

            if ($request->wantsJson()) {
                return Response::apiError(
                    'rate_limit_exceeded',
                    'Too many requests. Please slow down.',
                    429,
                    ['retry_after' => $retryAfter]
                )->withHeaders($headers + ['Retry-After' => (string) $retryAfter]);
            }

            throw new HttpException(429, 'Too many requests. Please try again in ' . $retryAfter . ' seconds.', 'rate_limit_exceeded');
        }

        $response = $next($request);

        if ($deferred && $request->attribute('auth.succeeded') !== true) {
            Cache::increment($key, 1, $window);
        }

        return $response->withHeaders($headers);
    }

    /**
     * @return array{0:int, 1:int, 2:string}
     */
    private function resolveLimits(array $params): array
    {
        $window = (int) Config::get('app.rate_limit.window', 60);

        if ($params === []) {
            return [(int) Config::get('app.rate_limit.api', 120), $window, 'api'];
        }

        $first = $params[0];

        if (is_numeric($first)) {
            return [(int) $first, (int) ($params[1] ?? $window), 'custom'];
        }

        $limit = (int) Config::get('app.rate_limit.' . $first, Config::get('app.rate_limit.api', 120));

        return [$limit, $window, (string) $first];
    }
}
