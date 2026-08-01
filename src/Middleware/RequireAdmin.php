<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Http\Exceptions\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Services\Auth;

final class RequireAdmin implements MiddlewareInterface
{
    public function handle(Request $request, callable $next, array $params = []): Response
    {
        if (!Auth::check()) {
            return (new Authenticate())->handle($request, $next);
        }

        // A named permission may be passed as `admin:users.manage`.
        $permission = $params[0] ?? null;

        if ($permission !== null) {
            if (!Auth::can($permission)) {
                throw new HttpException(403, 'You do not have permission to perform this action.', 'forbidden');
            }

            return $next($request);
        }

        if (!Auth::isAdmin()) {
            throw new HttpException(403, 'Administrator access is required.', 'forbidden');
        }

        return $next($request);
    }
}
