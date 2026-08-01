<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Http\Request;
use App\Http\Response;
use App\Services\SettingService;

final class Cors implements MiddlewareInterface
{
    public function handle(Request $request, callable $next, array $params = []): Response
    {
        $origin = (string) $request->header('Origin', '');
        $allowed = SettingService::get('cors_origins', '*');

        $allowOrigin = '*';
        if ($allowed !== '*' && $allowed !== '') {
            $list = array_map('trim', explode(',', (string) $allowed));
            $allowOrigin = in_array($origin, $list, true) ? $origin : ($list[0] ?? '');
        }

        $headers = [
            'Access-Control-Allow-Origin'      => $allowOrigin,
            'Access-Control-Allow-Methods'     => 'GET, POST, PUT, PATCH, DELETE, OPTIONS',
            'Access-Control-Allow-Headers'     => 'Content-Type, Authorization, X-Api-Key, X-Requested-With, X-Idempotency-Key, X-Signature, X-Timestamp',
            'Access-Control-Expose-Headers'    => 'X-RateLimit-Limit, X-RateLimit-Remaining, X-RateLimit-Reset, Content-Disposition',
            'Access-Control-Max-Age'           => '86400',
        ];

        if ($allowOrigin !== '*') {
            $headers['Access-Control-Allow-Credentials'] = 'true';
            $headers['Vary'] = 'Origin';
        }

        if ($request->method === 'OPTIONS') {
            return Response::make('', 204)->withHeaders($headers);
        }

        return $next($request)->withHeaders($headers);
    }
}
