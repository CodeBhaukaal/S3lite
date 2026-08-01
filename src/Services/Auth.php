<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Http\Session;
use App\Models\LoginAttempt;
use App\Models\User;
use App\Support\Crypto;
use App\Support\Totp;

/**
 * Authentication context for the current request. Backed by the PHP session for
 * the web panel, or set directly by the API middleware for token requests.
 */
final class Auth
{
    private static ?array $user = null;
    private static bool $resolved = false;
    private static string $guard = 'session';
    private static ?array $apiKey = null;
    private static array $scopes = [];

    public static function user(): ?array
    {
        if (self::$resolved) {
            return self::$user;
        }

        self::$resolved = true;

        $id = Session::get('user_id');
        if (!is_int($id) && !is_numeric($id)) {
            return null;
        }

        $user = User::withRole((int) $id);

        if ($user === null || $user['status'] !== 'active') {
            Session::forget('user_id');

            return null;
        }

        return self::$user = $user;
    }

    public static function id(): ?int
    {
        $user = self::user();

        return $user === null ? null : (int) $user['id'];
    }

    public static function check(): bool
    {
        return self::user() !== null;
    }

    public static function isAdmin(): bool
    {
        $user = self::user();

        return $user !== null && User::isAdmin($user);
    }

    public static function can(string $permission): bool
    {
        $user = self::user();

        return $user !== null && User::can($user, $permission);
    }

    public static function guard(): string
    {
        return self::$guard;
    }

    public static function apiKey(): ?array
    {
        return self::$apiKey;
    }

    /** @return list<string> */
    public static function scopes(): array
    {
        return self::$scopes;
    }

    public static function hasScope(string $scope): bool
    {
        if (self::$guard === 'session' || self::$guard === 'jwt') {
            return true;
        }

        if (in_array('*', self::$scopes, true)) {
            return true;
        }

        if (in_array($scope, self::$scopes, true)) {
            return true;
        }

        // 'files:*' covers 'files:read'
        $prefix = explode(':', $scope)[0] . ':*';

        return in_array($prefix, self::$scopes, true);
    }

    /** Used by API middleware once a token has been verified. */
    public static function setUser(array $user, string $guard = 'jwt', ?array $apiKey = null, array $scopes = []): void
    {
        self::$user = $user;
        self::$resolved = true;
        self::$guard = $guard;
        self::$apiKey = $apiKey;
        self::$scopes = $scopes;
    }

    public static function login(array $user, bool $remember = false): void
    {
        Session::regenerate();
        Session::put('user_id', (int) $user['id']);
        Session::put('login_at', time());

        self::$user = null;
        self::$resolved = false;
        self::$guard = 'session';

        \App\Core\Database::statement(
            'UPDATE users SET last_login_at = NOW(), last_login_ip = ?, failed_attempts = 0, locked_until = NULL WHERE id = ?',
            [self::currentIp(), (int) $user['id']]
        );

        if ($remember) {
            Session::put('remember', true);
        }
    }

    public static function logout(): void
    {
        Session::flushAll();
        self::$user = null;
        self::$resolved = true;
        self::$guard = 'session';
    }

    /**
     * @return array{ok:bool, user:?array, reason:?string}
     */
    public static function attempt(string $email, string $password, ?string $otp = null): array
    {
        $ip = self::currentIp();
        $userAgent = self::currentUserAgent();

        $maxAttempts = (int) SettingService::get('max_login_attempts', Config::get('app.security.max_login_attempts', 5));
        $lockMinutes = (int) SettingService::get('lockout_minutes', Config::get('app.security.lockout_minutes', 15));

        // Per-account lockout, plus a looser per-address ceiling so that one
        // attacker cannot lock out everyone sharing an outbound IP.
        if (LoginAttempt::recentFailures($email, $lockMinutes) >= $maxAttempts
            || LoginAttempt::recentFailuresForIp($ip, $lockMinutes) >= $maxAttempts * 10) {
            LoginAttempt::record($email, null, $ip, $userAgent, false, 'rate_limited');

            return ['ok' => false, 'user' => null, 'reason' => 'too_many_attempts'];
        }

        $user = User::findByEmail($email);

        if ($user === null) {
            LoginAttempt::record($email, null, $ip, $userAgent, false, 'unknown_user');

            return ['ok' => false, 'user' => null, 'reason' => 'invalid_credentials'];
        }

        if ($user['locked_until'] !== null && strtotime((string) $user['locked_until']) > time()) {
            LoginAttempt::record($email, (int) $user['id'], $ip, $userAgent, false, 'locked');

            return ['ok' => false, 'user' => null, 'reason' => 'account_locked'];
        }

        if (!Crypto::verifyPassword($password, (string) $user['password_hash'])) {
            $attempts = (int) $user['failed_attempts'] + 1;
            $update = ['failed_attempts' => $attempts];

            if ($attempts >= $maxAttempts) {
                $update['locked_until'] = date('Y-m-d H:i:s', time() + ($lockMinutes * 60));
            }

            User::updateById((int) $user['id'], $update);
            LoginAttempt::record($email, (int) $user['id'], $ip, $userAgent, false, 'bad_password');
            WebhookService::dispatch('user.login_failed', ['email' => $email, 'ip' => $ip], (int) $user['id']);

            return ['ok' => false, 'user' => null, 'reason' => 'invalid_credentials'];
        }

        if ($user['status'] !== 'active') {
            LoginAttempt::record($email, (int) $user['id'], $ip, $userAgent, false, 'inactive');

            return ['ok' => false, 'user' => null, 'reason' => 'account_' . $user['status']];
        }

        if ((int) $user['two_factor_enabled'] === 1) {
            if ($otp === null || $otp === '') {
                return ['ok' => false, 'user' => $user, 'reason' => 'two_factor_required'];
            }

            $secret = Crypto::decrypt((string) $user['two_factor_secret']) ?? '';

            if (!Totp::verify($secret, $otp) && !self::consumeRecoveryCode($user, $otp)) {
                LoginAttempt::record($email, (int) $user['id'], $ip, $userAgent, false, 'bad_otp');

                return ['ok' => false, 'user' => $user, 'reason' => 'invalid_two_factor'];
            }
        }

        if (Crypto::needsRehash((string) $user['password_hash'])) {
            User::updateById((int) $user['id'], ['password_hash' => Crypto::hashPassword($password)]);
        }

        LoginAttempt::record($email, (int) $user['id'], $ip, $userAgent, true, null);
        WebhookService::dispatch('user.login', ['user_id' => (int) $user['id'], 'ip' => $ip], (int) $user['id']);

        return ['ok' => true, 'user' => User::withRole((int) $user['id']), 'reason' => null];
    }

    private static function consumeRecoveryCode(array $user, string $code): bool
    {
        $codes = is_array($user['recovery_codes'] ?? null) ? $user['recovery_codes'] : [];

        foreach ($codes as $index => $stored) {
            if (hash_equals((string) $stored, strtoupper(trim($code)))) {
                unset($codes[$index]);
                User::updateById((int) $user['id'], ['recovery_codes' => array_values($codes)]);

                return true;
            }
        }

        return false;
    }

    public static function reset(): void
    {
        self::$user = null;
        self::$resolved = false;
        self::$guard = 'session';
        self::$apiKey = null;
        self::$scopes = [];
    }

    private static function currentIp(): string
    {
        $request = \App\Core\App::instance()->request();

        return $request?->ip() ?? '0.0.0.0';
    }

    private static function currentUserAgent(): string
    {
        $request = \App\Core\App::instance()->request();

        return $request?->userAgent() ?? '';
    }
}
