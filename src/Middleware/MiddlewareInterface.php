<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Http\Request;
use App\Http\Response;

interface MiddlewareInterface
{
    /**
     * @param callable(Request):Response $next
     * @param list<string> $params Arguments from the `alias:a,b` route syntax.
     */
    public function handle(Request $request, callable $next, array $params = []): Response;
}
