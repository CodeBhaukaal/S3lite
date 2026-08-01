<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Http\Request;
use App\Http\Response;
use App\Http\Session;
use App\Services\Auth;

final class Authenticate implements MiddlewareInterface
{
    public function handle(Request $request, callable $next, array $params = []): Response
    {
        if (!Auth::check()) {
            if ($request->wantsJson()) {
                return Response::apiError('unauthenticated', 'Authentication required.', 401);
            }

            Session::put('intended_url', $request->fullUrl());
            Session::flash('error', 'Please sign in to continue.');

            return Response::redirect(url('/login'));
        }

        return $next($request);
    }
}
