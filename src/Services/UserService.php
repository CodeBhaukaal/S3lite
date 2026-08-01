<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Database;
use App\Http\Exceptions\HttpException;
use App\Models\RefreshToken;
use App\Models\Role;
use App\Models\User;
use App\Support\Crypto;
use App\Support\Str;

final class UserService
{
    private const AVATAR_COLORS = ['#4f7cff', '#7c5cff', '#f97316', '#10b981', '#ef4444', '#06b6d4', '#ec4899', '#eab308'];

    public static function create(array $data): array
    {
        $email = strtolower(trim((string) ($data['email'] ?? '')));
        $name = trim((string) ($data['name'] ?? ''));
        $password = (string) ($data['password'] ?? '');

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new HttpException(422, 'A valid email address is required.', 'invalid_email');
        }

        if ($name === '') {
            throw new HttpException(422, 'A name is required.', 'invalid_name');
        }

        if (mb_strlen($password) < (int) Config::get('app.security.password_min', 8)) {
            throw new HttpException(422, 'The password must be at least 8 characters.', 'weak_password');
        }

        if (User::findByEmail($email) !== null) {
            throw new HttpException(409, 'An account with that email already exists.', 'email_taken');
        }

        $roleName = (string) ($data['role'] ?? 'user');
        $role = Role::findByName($roleName);

        if ($role === null) {
            throw new HttpException(422, "Unknown role: {$roleName}", 'invalid_role');
        }

        $id = User::create([
            'uuid'          => Str::uuid(),
            'name'          => mb_substr($name, 0, 120),
            'email'         => $email,
            'password_hash' => Crypto::hashPassword($password),
            'role_id'       => (int) $role['id'],
            'status'        => in_array($data['status'] ?? 'active', ['active', 'suspended', 'pending'], true)
                ? $data['status']
                : 'active',
            'quota_bytes'   => (int) ($data['quota_bytes'] ?? SettingService::get('default_quota', Config::get('storage.default_quota', 10737418240))),
            'avatar_color'  => self::AVATAR_COLORS[array_rand(self::AVATAR_COLORS)],
            'email_verified_at' => empty($data['require_verification']) ? date('Y-m-d H:i:s') : null,
        ]);

        AuditService::log('user.create', 'user', $id, "Created user {$email}", ['role' => $roleName]);

        return User::withRole($id) ?? [];
    }

    public static function update(int $userId, array $data, bool $isAdminAction = false): array
    {
        $user = User::find($userId);

        if ($user === null) {
            throw new HttpException(404, 'User not found.', 'user_not_found');
        }

        $update = [];

        if (!empty($data['name'])) {
            $update['name'] = mb_substr(trim((string) $data['name']), 0, 120);
        }

        if (!empty($data['email'])) {
            $email = strtolower(trim((string) $data['email']));

            if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                throw new HttpException(422, 'A valid email address is required.', 'invalid_email');
            }

            $existing = User::findByEmail($email);
            if ($existing !== null && (int) $existing['id'] !== $userId) {
                throw new HttpException(409, 'That email is already in use.', 'email_taken');
            }

            $update['email'] = $email;
        }

        if (!empty($data['password'])) {
            if (mb_strlen((string) $data['password']) < (int) Config::get('app.security.password_min', 8)) {
                throw new HttpException(422, 'The password must be at least 8 characters.', 'weak_password');
            }
            $update['password_hash'] = Crypto::hashPassword((string) $data['password']);
            RefreshToken::revokeAllForUser($userId);
        }

        if ($isAdminAction) {
            if (!empty($data['role'])) {
                $role = Role::findByName((string) $data['role']);
                if ($role === null) {
                    throw new HttpException(422, 'Unknown role: ' . $data['role'], 'invalid_role');
                }

                // The last administrator must keep their role.
                if ($user['role_id'] !== $role['id'] && self::isLastAdmin($userId)) {
                    throw new HttpException(422, 'You cannot demote the last administrator.', 'last_admin');
                }

                $update['role_id'] = (int) $role['id'];
            }

            if (!empty($data['status'])) {
                if ($data['status'] !== 'active' && self::isLastAdmin($userId)) {
                    throw new HttpException(422, 'You cannot suspend the last administrator.', 'last_admin');
                }

                $update['status'] = in_array($data['status'], ['active', 'suspended', 'pending'], true)
                    ? $data['status']
                    : 'active';

                if ($update['status'] !== 'active') {
                    RefreshToken::revokeAllForUser($userId);
                }
            }

            if (array_key_exists('quota_bytes', $data) && $data['quota_bytes'] !== null && $data['quota_bytes'] !== '') {
                $update['quota_bytes'] = max(0, (int) $data['quota_bytes']);
            }

            if (array_key_exists('unlock', $data) && $data['unlock']) {
                $update['failed_attempts'] = 0;
                $update['locked_until'] = null;
            }
        }

        if ($update !== []) {
            User::updateById($userId, $update);
        }

        AuditService::log('user.update', 'user', $userId, "Updated user {$user['email']}", array_keys($update));

        return User::withRole($userId) ?? [];
    }

    public static function suspend(int $userId): array
    {
        return self::update($userId, ['status' => 'suspended'], true);
    }

    public static function activate(int $userId): array
    {
        return self::update($userId, ['status' => 'active'], true);
    }

    /** Soft delete, keeping files recoverable. */
    public static function delete(int $userId, bool $purgeFiles = false): void
    {
        $user = User::find($userId);

        if ($user === null) {
            throw new HttpException(404, 'User not found.', 'user_not_found');
        }

        if (self::isLastAdmin($userId)) {
            throw new HttpException(422, 'You cannot delete the last administrator.', 'last_admin');
        }

        RefreshToken::revokeAllForUser($userId);

        if ($purgeFiles) {
            $files = Database::select('SELECT id FROM files WHERE user_id = ?', [$userId]);

            foreach ($files as $file) {
                try {
                    FileService::purge($userId, (int) $file['id']);
                } catch (\Throwable) {
                    // Continue purging the rest.
                }
            }

            foreach (\App\Models\SftpAccount::forUser($userId) as $account) {
                SftpService::deleteAccount((int) $account['id'], true);
            }

            User::deleteById($userId);
        } else {
            User::updateById($userId, [
                'deleted_at' => date('Y-m-d H:i:s'),
                'status'     => 'suspended',
                'email'      => 'deleted+' . $userId . '@' . parse_url((string) Config::get('app.url', 'http://localhost'), PHP_URL_HOST),
            ]);
        }

        AuditService::log('user.delete', 'user', $userId, "Deleted user {$user['email']}", ['purged' => $purgeFiles]);
    }

    public static function isLastAdmin(int $userId): bool
    {
        $adminRole = Role::findByName('admin');

        if ($adminRole === null) {
            return false;
        }

        $user = User::find($userId);

        if ($user === null || (int) $user['role_id'] !== (int) $adminRole['id']) {
            return false;
        }

        $count = (int) Database::scalar(
            'SELECT COUNT(*) FROM users WHERE role_id = ? AND status = "active" AND deleted_at IS NULL',
            [(int) $adminRole['id']]
        );

        return $count <= 1;
    }

    public static function changePassword(int $userId, string $current, string $new): void
    {
        $user = User::find($userId);

        if ($user === null) {
            throw new HttpException(404, 'User not found.', 'user_not_found');
        }

        if (!Crypto::verifyPassword($current, (string) $user['password_hash'])) {
            throw new HttpException(422, 'Your current password is not correct.', 'invalid_password');
        }

        if (mb_strlen($new) < (int) Config::get('app.security.password_min', 8)) {
            throw new HttpException(422, 'The new password must be at least 8 characters.', 'weak_password');
        }

        User::updateById($userId, ['password_hash' => Crypto::hashPassword($new)]);
        RefreshToken::revokeAllForUser($userId);

        AuditService::log('user.password_change', 'user', $userId, 'Password changed');
    }

    // --- Two-factor -----------------------------------------------------

    /** @return array{secret:string, uri:string} */
    public static function beginTwoFactor(int $userId): array
    {
        $user = User::find($userId);

        if ($user === null) {
            throw new HttpException(404, 'User not found.', 'user_not_found');
        }

        $secret = \App\Support\Totp::generateSecret();

        User::updateById($userId, [
            'two_factor_secret'  => Crypto::encrypt($secret),
            'two_factor_enabled' => 0,
        ]);

        return [
            'secret' => $secret,
            'uri'    => \App\Support\Totp::provisioningUri(
                $secret,
                (string) $user['email'],
                (string) Config::get('app.name', 'S3 Lite')
            ),
        ];
    }

    /** @return list<string> Recovery codes, shown once. */
    public static function confirmTwoFactor(int $userId, string $code): array
    {
        $user = User::find($userId);

        if ($user === null || empty($user['two_factor_secret'])) {
            throw new HttpException(422, 'Two-factor setup has not been started.', 'two_factor_not_started');
        }

        $secret = Crypto::decrypt((string) $user['two_factor_secret']) ?? '';

        if (!\App\Support\Totp::verify($secret, $code)) {
            throw new HttpException(422, 'That code is not correct. Please try again.', 'invalid_two_factor');
        }

        $codes = \App\Support\Totp::recoveryCodes();

        User::updateById($userId, [
            'two_factor_enabled' => 1,
            'recovery_codes'     => $codes,
        ]);

        AuditService::log('user.2fa_enabled', 'user', $userId, 'Two-factor authentication enabled');

        return $codes;
    }

    public static function disableTwoFactor(int $userId, string $password): void
    {
        $user = User::find($userId);

        if ($user === null || !Crypto::verifyPassword($password, (string) $user['password_hash'])) {
            throw new HttpException(422, 'Your password is not correct.', 'invalid_password');
        }

        User::updateById($userId, [
            'two_factor_enabled' => 0,
            'two_factor_secret'  => null,
            'recovery_codes'     => null,
        ]);

        AuditService::log('user.2fa_disabled', 'user', $userId, 'Two-factor authentication disabled');
    }
}
