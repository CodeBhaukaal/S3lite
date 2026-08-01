<?php
declare(strict_types=1);

namespace App\Http;

use App\Core\Config;
use App\Support\Str;

final class Session
{
    private static bool $started = false;

    public static function start(): void
    {
        if (self::$started || session_status() === PHP_SESSION_ACTIVE) {
            self::$started = true;

            return;
        }

        // Console commands have no session; $_SESSION acts as a scratch array so
        // shared services (auditing, flash helpers) work unchanged.
        if (PHP_SAPI === 'cli' || headers_sent()) {
            self::$started = true;
            $_SESSION ??= [];

            return;
        }

        $secure = (bool) Config::get('app.force_https', false)
            || (($_SERVER['HTTPS'] ?? '') === 'on');

        session_set_cookie_params([
            'lifetime' => (int) Config::get('app.security.session_lifetime', 7200),
            'path'     => '/',
            'domain'   => '',
            'secure'   => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        session_name('s3lite_session');
        session_start();

        self::$started = true;

        // Rotate the id periodically to blunt session fixation.
        $now = time();
        $last = (int) ($_SESSION['_regenerated_at'] ?? 0);
        if ($last === 0) {
            $_SESSION['_regenerated_at'] = $now;
        } elseif ($now - $last > 1800) {
            session_regenerate_id(true);
            $_SESSION['_regenerated_at'] = $now;
        }

        // Absolute idle timeout.
        $lifetime = (int) Config::get('app.security.session_lifetime', 7200);
        $lastActivity = (int) ($_SESSION['_last_activity'] ?? $now);
        if ($now - $lastActivity > $lifetime) {
            self::flushAll();
        }
        $_SESSION['_last_activity'] = $now;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        self::start();

        return $_SESSION[$key] ?? $default;
    }

    public static function put(string $key, mixed $value): void
    {
        self::start();
        $_SESSION[$key] = $value;
    }

    public static function has(string $key): bool
    {
        self::start();

        return isset($_SESSION[$key]);
    }

    public static function forget(string $key): void
    {
        self::start();
        unset($_SESSION[$key]);
    }

    public static function all(): array
    {
        self::start();

        return $_SESSION;
    }

    public static function flushAll(): void
    {
        self::start();
        $_SESSION = [];
        session_regenerate_id(true);
    }

    public static function regenerate(): void
    {
        self::start();
        session_regenerate_id(true);
        $_SESSION['_regenerated_at'] = time();
    }

    public static function id(): string
    {
        self::start();

        return session_id() ?: '';
    }

    // --- Flash messages -------------------------------------------------

    public static function flash(string $type, string $message): void
    {
        self::start();
        $_SESSION['_flash'][] = ['type' => $type, 'message' => $message];
    }

    public static function pullFlash(): array
    {
        self::start();
        $flash = $_SESSION['_flash'] ?? [];
        unset($_SESSION['_flash']);

        return $flash;
    }

    public static function flashInput(array $input): void
    {
        self::start();
        unset($input['password'], $input['password_confirmation'], $input['_token']);
        $_SESSION['_old'] = $input;
    }

    public static function old(string $key, mixed $default = null): mixed
    {
        self::start();

        return $_SESSION['_old'][$key] ?? $default;
    }

    public static function clearOld(): void
    {
        self::start();
        unset($_SESSION['_old']);
    }

    public static function flashErrors(array $errors): void
    {
        self::start();
        $_SESSION['_errors'] = $errors;
    }

    public static function pullErrors(): array
    {
        self::start();
        $errors = $_SESSION['_errors'] ?? [];
        unset($_SESSION['_errors']);

        return $errors;
    }

    // --- CSRF -----------------------------------------------------------

    public static function csrfToken(): string
    {
        self::start();

        if (!isset($_SESSION['_csrf_token'])) {
            $_SESSION['_csrf_token'] = Str::random(64);
        }

        return $_SESSION['_csrf_token'];
    }

    public static function verifyCsrf(?string $token): bool
    {
        if ($token === null || $token === '') {
            return false;
        }

        return hash_equals(self::csrfToken(), $token);
    }
}
