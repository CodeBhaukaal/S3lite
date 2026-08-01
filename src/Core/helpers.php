<?php
declare(strict_types=1);

use App\Core\Config;
use App\Core\View;
use App\Http\Session;
use App\Support\Str;

if (!function_exists('e')) {
    /** HTML-escape any value. */
    function e(mixed $value): string
    {
        return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('config')) {
    function config(string $key, mixed $default = null): mixed
    {
        return Config::get($key, $default);
    }
}

if (!function_exists('base_path')) {
    function base_path(string $append = ''): string
    {
        return dirname(__DIR__, 2) . ($append !== '' ? '/' . ltrim($append, '/') : '');
    }
}

if (!function_exists('storage_path')) {
    function storage_path(string $append = ''): string
    {
        return base_path('storage') . ($append !== '' ? '/' . ltrim($append, '/') : '');
    }
}

if (!function_exists('url')) {
    /** Build a URL relative to the public web root. */
    function url(string $path = ''): string
    {
        $base = rtrim((string) Config::get('app.url', ''), '/');

        // Before installation APP_URL is empty; fall back to the directory the
        // front controller is served from so the installer links correctly.
        if ($base === '') {
            $base = \App\Core\App::instance()->request()?->basePath ?? '';
            $base = rtrim($base, '/');
        }

        return $base . '/' . ltrim($path, '/');
    }
}

if (!function_exists('asset')) {
    /**
     * Asset URL with a content-derived cache buster, so a stylesheet and the
     * script that depends on it can never be served from cache out of step.
     */
    function asset(string $path): string
    {
        $path = ltrim($path, '/');
        $file = base_path('public/assets/' . $path);
        $version = is_file($file) ? (string) filemtime($file) : (string) Config::get('app.version', '1');

        return url('assets/' . $path) . '?v=' . $version;
    }
}

if (!function_exists('csrf_token')) {
    function csrf_token(): string
    {
        return Session::csrfToken();
    }
}

if (!function_exists('csrf_field')) {
    function csrf_field(): string
    {
        return '<input type="hidden" name="_token" value="' . e(Session::csrfToken()) . '">';
    }
}

if (!function_exists('method_field')) {
    function method_field(string $method): string
    {
        return '<input type="hidden" name="_method" value="' . e(strtoupper($method)) . '">';
    }
}

if (!function_exists('old')) {
    function old(string $key, mixed $default = ''): mixed
    {
        return Session::old($key, $default);
    }
}

if (!function_exists('bytes')) {
    function bytes(int|float|null $value, int $precision = 2): string
    {
        return Str::bytes($value, $precision);
    }
}

if (!function_exists('time_ago')) {
    function time_ago(?string $datetime): string
    {
        return Str::timeAgo($datetime);
    }
}

if (!function_exists('view')) {
    function view(string $template, array $data = []): string
    {
        return View::render($template, $data);
    }
}

if (!function_exists('icon')) {
    /**
     * Inline SVG icon from the sprite. The UI uses icons only, never emoji.
     */
    function icon(string $name, string $class = 'icon'): string
    {
        return '<svg class="' . e($class) . '" aria-hidden="true" focusable="false"><use href="#i-' . e($name) . '"></use></svg>';
    }
}

if (!function_exists('active_class')) {
    function active_class(string $pattern, string $class = 'is-active'): string
    {
        $current = (string) (View::shared()['currentPath'] ?? '');

        if ($pattern === '/') {
            return $current === '/' ? $class : '';
        }

        return str_starts_with($current, $pattern) ? $class : '';
    }
}

if (!function_exists('json_pretty')) {
    function json_pretty(mixed $data): string
    {
        return (string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}

if (!function_exists('percent')) {
    function percent(int|float $used, int|float $total): float
    {
        if ($total <= 0) {
            return 0.0;
        }

        return round(min(100, ($used / $total) * 100), 1);
    }
}
