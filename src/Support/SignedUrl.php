<?php
declare(strict_types=1);

namespace App\Support;

use App\Core\Config;

/**
 * HMAC-signed, optionally expiring URLs for temporary download access.
 */
final class SignedUrl
{
    public static function sign(string $path, array $params = [], ?int $ttl = null): string
    {
        if ($ttl !== null) {
            $params['expires'] = time() + $ttl;
        }

        ksort($params);
        $query = http_build_query($params);
        $signature = self::signature($path, $query);

        $base = rtrim((string) Config::get('app.url', ''), '/');

        return $base . $path . ($query !== '' ? '?' . $query : '?') . '&signature=' . $signature;
    }

    public static function verify(string $path, array $params): bool
    {
        $signature = (string) ($params['signature'] ?? '');
        unset($params['signature']);

        if ($signature === '') {
            return false;
        }

        if (isset($params['expires']) && (int) $params['expires'] < time()) {
            return false;
        }

        ksort($params);
        $expected = self::signature($path, http_build_query($params));

        return hash_equals($expected, $signature);
    }

    private static function signature(string $path, string $query): string
    {
        return hash_hmac('sha256', $path . '|' . $query, (string) Config::get('app.key', 'fallback'));
    }
}
