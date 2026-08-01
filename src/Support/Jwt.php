<?php
declare(strict_types=1);

namespace App\Support;

use App\Core\Config;
use RuntimeException;

/**
 * HS256 JSON Web Tokens. No external dependency, constant-time verification.
 */
final class Jwt
{
    public static function encode(array $claims, ?int $ttl = null, ?string $secret = null): string
    {
        $now = time();
        $ttl ??= (int) Config::get('app.jwt.access_ttl', 3600);

        $payload = array_merge([
            'iss' => Config::get('app.jwt.issuer', 's3lite'),
            'iat' => $now,
            'nbf' => $now,
            'exp' => $now + $ttl,
            'jti' => Str::random(16),
        ], $claims);

        $header = ['typ' => 'JWT', 'alg' => 'HS256'];

        $segments = [
            self::b64encode(self::json($header)),
            self::b64encode(self::json($payload)),
        ];

        $signing = implode('.', $segments);
        $signature = hash_hmac('sha256', $signing, self::secret($secret), true);
        $segments[] = self::b64encode($signature);

        return implode('.', $segments);
    }

    /**
     * @return array{valid:bool, payload:array, error:?string}
     */
    public static function verify(string $token, ?string $secret = null): array
    {
        $parts = explode('.', $token);

        if (count($parts) !== 3) {
            return ['valid' => false, 'payload' => [], 'error' => 'malformed_token'];
        }

        [$header64, $payload64, $signature64] = $parts;

        $header = json_decode((string) self::b64decode($header64), true);
        if (!is_array($header) || ($header['alg'] ?? '') !== 'HS256') {
            return ['valid' => false, 'payload' => [], 'error' => 'unsupported_algorithm'];
        }

        $expected = hash_hmac('sha256', $header64 . '.' . $payload64, self::secret($secret), true);
        $provided = self::b64decode($signature64);

        if ($provided === false || !hash_equals($expected, $provided)) {
            return ['valid' => false, 'payload' => [], 'error' => 'invalid_signature'];
        }

        $payload = json_decode((string) self::b64decode($payload64), true);
        if (!is_array($payload)) {
            return ['valid' => false, 'payload' => [], 'error' => 'invalid_payload'];
        }

        $now = time();
        if (isset($payload['nbf']) && $now < (int) $payload['nbf'] - 5) {
            return ['valid' => false, 'payload' => $payload, 'error' => 'token_not_yet_valid'];
        }
        if (isset($payload['exp']) && $now >= (int) $payload['exp']) {
            return ['valid' => false, 'payload' => $payload, 'error' => 'token_expired'];
        }

        return ['valid' => true, 'payload' => $payload, 'error' => null];
    }

    public static function decodeUnsafe(string $token): array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return [];
        }

        $payload = json_decode((string) self::b64decode($parts[1]), true);

        return is_array($payload) ? $payload : [];
    }

    private static function secret(?string $secret): string
    {
        $secret ??= (string) Config::get('app.jwt.secret', '');

        if ($secret === '') {
            throw new RuntimeException('JWT_SECRET is not configured.');
        }

        return $secret;
    }

    private static function json(array $data): string
    {
        $encoded = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($encoded === false) {
            throw new RuntimeException('Unable to encode JWT segment.');
        }

        return $encoded;
    }

    private static function b64encode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private static function b64decode(string $data): string|false
    {
        $remainder = strlen($data) % 4;
        if ($remainder > 0) {
            $data .= str_repeat('=', 4 - $remainder);
        }

        return base64_decode(strtr($data, '-_', '+/'), true);
    }
}
