<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Core\Cache;
use App\Http\Exceptions\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Models\IpRule;

final class IpFilter implements MiddlewareInterface
{
    public function handle(Request $request, callable $next, array $params = []): Response
    {
        if (!EnsureInstalled::isInstalled()) {
            return $next($request);
        }

        $ip = $request->ip();
        $scope = $params[0] ?? 'global';

        $verdict = Cache::remember('ipfilter:' . $scope . ':' . $ip, 60, static function () use ($ip, $scope): array {
            try {
                return IpRule::evaluate($ip, $scope);
            } catch (\Throwable) {
                return ['allowed' => true, 'rule' => null];
            }
        });

        if (!($verdict['allowed'] ?? true)) {
            if ($request->wantsJson()) {
                return Response::apiError('ip_blocked', 'Your IP address is not permitted to access this resource.', 403);
            }

            throw new HttpException(403, 'Your IP address is not permitted to access this resource.', 'ip_blocked');
        }

        return $next($request);
    }
}
