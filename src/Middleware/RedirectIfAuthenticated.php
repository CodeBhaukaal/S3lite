<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Http\Request;
use App\Http\Response;
use App\Services\Auth;

final class RedirectIfAuthenticated implements MiddlewareInterface
{
    public function handle(Request $request, callable $next, array $params = []): Response
    {
        if (Auth::check()) {
            return Response::redirect(url('/dashboard'));
        }

        return $next($request);
    }
}
