<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Models\ApiKey;
use App\Models\RefreshToken;
use App\Models\User;
use App\Support\Crypto;
use App\Support\Jwt;
use App\Support\Str;

final class TokenService
{
    public const SCOPES = [
        'files:read'    => 'List, read and download files',
        'files:write'   => 'Upload, rename, move and delete files',
        'folders:read'  => 'List folders',
        'folders:write' => 'Create, rename, move and delete folders',
        'shares:read'   => 'List share links',
        'shares:write'  => 'Create and revoke share links',
        'users:read'    => 'Read user accounts',
        'users:write'   => 'Create, update and delete user accounts',
        'sftp:read'     => 'Read FTP/SFTP accounts and sessions',
        'sftp:write'    => 'Manage FTP/SFTP accounts, keys and sessions',
        'metrics:read'  => 'Read metrics, statistics and health',
        'logs:read'     => 'Read audit logs',
        'jobs:write'    => 'Trigger maintenance jobs',
        'admin'         => 'Full administrative access',
        '*'             => 'Unrestricted access',
    ];

    /**
     * @return array{access_token:string, refresh_token:string, token_type:string, expires_in:int, user:array}
     */
    public static function issue(array $user, string $ip = '', string $userAgent = ''): array
    {
        $accessTtl = (int) Config::get('app.jwt.access_ttl', 3600);
        $refreshTtl = (int) Config::get('app.jwt.refresh_ttl', 1209600);

        $access = Jwt::encode([
            'sub'   => (int) $user['id'],
            'uuid'  => $user['uuid'],
            'email' => $user['email'],
            'role'  => $user['role_name'] ?? null,
            'typ'   => 'access',
        ], $accessTtl);

        $refresh = Str::random(64);

        RefreshToken::create([
            'user_id'    => (int) $user['id'],
            'token_hash' => Crypto::hashToken($refresh),
            'user_agent' => mb_substr($userAgent, 0, 255),
            'ip'         => $ip,
            'expires_at' => date('Y-m-d H:i:s', time() + $refreshTtl),
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        return [
            'access_token'  => $access,
            'refresh_token' => $refresh,
            'token_type'    => 'Bearer',
            'expires_in'    => $accessTtl,
            'user'          => User::publicArray($user),
        ];
    }

    /**
     * Refresh tokens are single-use: the old one is revoked as the new one is issued.
     *
     * @return array{ok:bool, data:?array, error:?string}
     */
    public static function refresh(string $refreshToken, string $ip = '', string $userAgent = ''): array
    {
        $record = RefreshToken::findUsable(Crypto::hashToken($refreshToken));

        if ($record === null) {
            return ['ok' => false, 'data' => null, 'error' => 'invalid_refresh_token'];
        }

        $user = User::withRole((int) $record['user_id']);

        if ($user === null || $user['status'] !== 'active') {
            return ['ok' => false, 'data' => null, 'error' => 'account_unavailable'];
        }

        RefreshToken::revoke((int) $record['id']);

        return ['ok' => true, 'data' => self::issue($user, $ip, $userAgent), 'error' => null];
    }

    public static function revokeRefreshToken(string $refreshToken): bool
    {
        $record = RefreshToken::findUsable(Crypto::hashToken($refreshToken));

        if ($record === null) {
            return false;
        }

        RefreshToken::revoke((int) $record['id']);

        return true;
    }

    /**
     * @return array{ok:bool, user:?array, payload:array, error:?string}
     */
    public static function verifyAccessToken(string $token): array
    {
        $result = Jwt::verify($token);

        if (!$result['valid']) {
            return ['ok' => false, 'user' => null, 'payload' => $result['payload'], 'error' => $result['error']];
        }

        $payload = $result['payload'];

        if (($payload['typ'] ?? '') !== 'access') {
            return ['ok' => false, 'user' => null, 'payload' => $payload, 'error' => 'wrong_token_type'];
        }

        $user = User::withRole((int) ($payload['sub'] ?? 0));

        if ($user === null || $user['status'] !== 'active') {
            return ['ok' => false, 'user' => null, 'payload' => $payload, 'error' => 'account_unavailable'];
        }

        return ['ok' => true, 'user' => $user, 'payload' => $payload, 'error' => null];
    }

    /**
     * API keys are shown once, at creation time, in the form `s3k_<prefix>_<secret>`.
     *
     * @return array{key:string, record:array}
     */
    public static function createApiKey(
        int $userId,
        string $name,
        array $scopes = ['files:read'],
        array $ipAllowlist = [],
        ?string $expiresAt = null
    ): array {
        $prefix = strtolower(Str::random(8));
        $secret = Str::random(48);
        $plain = 's3k_' . $prefix . '_' . $secret;

        $id = ApiKey::create([
            'uuid'         => Str::uuid(),
            'user_id'      => $userId,
            'name'         => $name,
            'prefix'       => $prefix,
            'key_hash'     => Crypto::hashToken($plain),
            'scopes'       => array_values($scopes),
            'ip_allowlist' => array_values($ipAllowlist),
            'expires_at'   => $expiresAt,
        ]);

        return ['key' => $plain, 'record' => ApiKey::find($id) ?? []];
    }

    /**
     * @return array{ok:bool, user:?array, key:?array, error:?string}
     */
    public static function verifyApiKey(string $plain, string $ip = ''): array
    {
        if (!str_starts_with($plain, 's3k_')) {
            return ['ok' => false, 'user' => null, 'key' => null, 'error' => 'invalid_api_key'];
        }

        $record = ApiKey::findByHash(Crypto::hashToken($plain));

        if ($record === null) {
            return ['ok' => false, 'user' => null, 'key' => null, 'error' => 'invalid_api_key'];
        }

        if (!ApiKey::isUsable($record)) {
            return ['ok' => false, 'user' => null, 'key' => $record, 'error' => 'api_key_revoked_or_expired'];
        }

        $allowlist = $record['ip_allowlist'] ?: [];
        if ($allowlist !== [] && $ip !== '') {
            $matched = false;
            foreach ($allowlist as $cidr) {
                if (\App\Models\IpRule::matches($ip, (string) $cidr)) {
                    $matched = true;
                    break;
                }
            }
            if (!$matched) {
                return ['ok' => false, 'user' => null, 'key' => $record, 'error' => 'ip_not_allowed'];
            }
        }

        $user = User::withRole((int) $record['user_id']);

        if ($user === null || $user['status'] !== 'active') {
            return ['ok' => false, 'user' => null, 'key' => $record, 'error' => 'account_unavailable'];
        }

        ApiKey::touch((int) $record['id'], $ip);

        return ['ok' => true, 'user' => $user, 'key' => $record, 'error' => null];
    }

    /**
     * Optional HMAC request signing for sensitive endpoints.
     * Signature = HMAC-SHA256(method + "\n" + path + "\n" + timestamp + "\n" + sha256(body), apiKeySecretHash)
     */
    public static function verifySignature(string $method, string $path, string $body, string $timestamp, string $signature, string $keyHash): bool
    {
        if (abs(time() - (int) $timestamp) > 300) {
            return false;
        }

        $expected = hash_hmac(
            'sha256',
            strtoupper($method) . "\n" . $path . "\n" . $timestamp . "\n" . hash('sha256', $body),
            $keyHash
        );

        return hash_equals($expected, $signature);
    }
}
