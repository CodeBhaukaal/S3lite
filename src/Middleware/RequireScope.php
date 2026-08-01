<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Http\Request;
use App\Http\Response;
use App\Services\Auth;

final class RequireScope implements MiddlewareInterface
{
    public function handle(Request $request, callable $next, array $params = []): Response
    {
        foreach ($params as $scope) {
            if (!Auth::hasScope($scope)) {
                return Response::apiError(
                    'insufficient_scope',
                    "This token is missing the required scope: {$scope}",
                    403,
                    ['required_scope' => $scope, 'granted' => Auth::scopes()]
                );
            }
        }

        return $next($request);
    }
}
