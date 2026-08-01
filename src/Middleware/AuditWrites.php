<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Http\Request;
use App\Http\Response;
use App\Services\AuditService;

/**
 * Blanket audit record for every API write, in addition to the semantic
 * entries the services themselves write.
 */
final class AuditWrites implements MiddlewareInterface
{
    public function handle(Request $request, callable $next, array $params = []): Response
    {
        AuditService::bind($request);

        if (in_array($request->method, ['GET', 'HEAD', 'OPTIONS'], true)) {
            return $next($request);
        }

        $response = $next($request);

        AuditService::log(
            'api.' . strtolower($request->method),
            'request',
            null,
            sprintf('%s %s -> %d', $request->method, $request->path, $response->status()),
            ['guard' => $request->attribute('auth.guard', 'unknown')],
            $response->status() < 400 ? 'success' : 'failed'
        );

        return $response;
    }
}
