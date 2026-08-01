<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Core\Config;
use App\Http\Request;
use App\Http\Response;

final class ForceHttps implements MiddlewareInterface
{
    public function handle(Request $request, callable $next, array $params = []): Response
    {
        if (Config::get('app.force_https', false) && !$request->isSecure()) {
            $host = (string) ($request->server['HTTP_HOST'] ?? 'localhost');

            return Response::redirect('https://' . $host . $request->fullUrl(), 301);
        }

        return $next($request);
    }
}
