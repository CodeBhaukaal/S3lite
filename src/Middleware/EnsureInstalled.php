<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Core\App;
use App\Http\Request;
use App\Http\Response;

/**
 * Sends first-run traffic to the install wizard until storage/installed.lock exists.
 */
final class EnsureInstalled implements MiddlewareInterface
{
    public function handle(Request $request, callable $next, array $params = []): Response
    {
        // Deliberately the cheap file check: this runs on every request, and a
        // deeper verification belongs to the installer alone.
        if (self::isInstalled()) {
            return $next($request);
        }

        if (str_starts_with($request->path, '/install')) {
            return $next($request);
        }

        if ($request->wantsJson()) {
            return Response::apiError('not_installed', 'The platform has not been installed yet.', 503);
        }

        return Response::redirect(url('/install'));
    }

    public static function isInstalled(): bool
    {
        return is_file(App::instance()->basePath('storage/installed.lock'));
    }
}
