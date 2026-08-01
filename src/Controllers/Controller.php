<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\View;
use App\Http\Exceptions\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Http\Session;
use App\Http\Validator;
use App\Models\Notification;
use App\Services\Auth;
use App\Services\SettingService;

abstract class Controller
{
    protected function view(string $template, array $data = [], int $status = 200): Response
    {
        View::reset();

        $user = Auth::user();

        View::share('authUser', $user);
        View::share('flash', Session::pullFlash());
        View::share('errors', Session::pullErrors());
        View::share('currentPath', \App\Core\App::instance()->request()?->path ?? '/');
        View::share('siteName', SettingService::get('site_name', config('app.name', 'S3 Lite')));
        View::share('accentColor', SettingService::get('accent_color', '#4f7cff'));
        View::share('appVersion', config('app.version', '1.0.0'));
        View::share('notifications', $user === null ? [] : Notification::unread((int) $user['id'], 8));
        View::share('notificationCount', $user === null ? 0 : Notification::unreadCount((int) $user['id']));

        $html = View::render($template, $data);

        Session::clearOld();

        return Response::html($html, $status);
    }

    protected function json(mixed $data, int $status = 200, array $meta = []): Response
    {
        return Response::apiSuccess($data, $status, $meta);
    }

    protected function error(string $code, string $message, int $status = 400, array $details = []): Response
    {
        return Response::apiError($code, $message, $status, $details);
    }

    protected function redirect(string $path, ?string $flashType = null, ?string $flashMessage = null): Response
    {
        if ($flashType !== null && $flashMessage !== null) {
            Session::flash($flashType, $flashMessage);
        }

        return Response::redirect(str_starts_with($path, 'http') ? $path : url($path));
    }

    protected function back(Request $request, ?string $flashType = null, ?string $flashMessage = null): Response
    {
        if ($flashType !== null && $flashMessage !== null) {
            Session::flash($flashType, $flashMessage);
        }

        $referer = $request->header('Referer');

        return Response::redirect($referer ?: url('/dashboard'));
    }

    /**
     * @throws \App\Http\Exceptions\ValidationException
     */
    protected function validate(Request $request, array $rules, array $messages = []): array
    {
        return Validator::validate($request->all(), $rules, $messages);
    }

    protected function user(): array
    {
        $user = Auth::user();

        if ($user === null) {
            throw new HttpException(401, 'Authentication required.', 'unauthenticated');
        }

        return $user;
    }

    protected function userId(): int
    {
        return (int) $this->user()['id'];
    }

    protected function requireAdmin(): void
    {
        if (!Auth::isAdmin()) {
            throw new HttpException(403, 'Administrator access is required.', 'forbidden');
        }
    }

    protected function pageNumber(Request $request): int
    {
        return max(1, $request->int('page', 1));
    }

    protected function perPage(Request $request, int $default = 24, int $max = 200): int
    {
        return max(1, min($max, $request->int('per_page', $default)));
    }

    /** Pagination metadata in the shape every API list response uses. */
    protected function paginationMeta(array $result): array
    {
        return [
            'pagination' => [
                'total'     => (int) ($result['total'] ?? 0),
                'page'      => (int) ($result['page'] ?? 1),
                'per_page'  => (int) ($result['per_page'] ?? 20),
                'last_page' => (int) ($result['last_page'] ?? 1),
            ],
        ];
    }
}
