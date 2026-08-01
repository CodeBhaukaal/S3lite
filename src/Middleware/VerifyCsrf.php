<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Http\Exceptions\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Http\Session;

final class VerifyCsrf implements MiddlewareInterface
{
    private const READ_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    public function handle(Request $request, callable $next, array $params = []): Response
    {
        if (in_array($request->method, self::READ_METHODS, true)) {
            return $next($request);
        }

        $token = $request->post('_token');
        if (!is_string($token) || $token === '') {
            $token = $request->header('X-CSRF-Token', '');
        }

        if (!Session::verifyCsrf(is_string($token) ? $token : '')) {
            // 403 rather than the Laravel-style 419: some servers turn unknown
            // status codes into a 500 before the response leaves the process.
            throw new HttpException(403, 'CSRF token mismatch. Please refresh the page and try again.', 'csrf_mismatch');
        }

        return $next($request);
    }
}
