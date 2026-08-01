<?php
declare(strict_types=1);

namespace App\Support;

use App\Core\Config;
use RuntimeException;

/**
 * AES-256-GCM encryption + password hashing helpers.
 */
final class Crypto
{
    private const CIPHER = 'aes-256-gcm';

    public static function generateKey(): string
    {
        return 'base64:' . base64_encode(random_bytes(32));
    }

    private static function key(): string
    {
        $key = (string) Config::get('app.key', '');

        if ($key === '') {
            throw new RuntimeException('APP_KEY is not set. Run: php bin/console key:generate');
        }

        if (str_starts_with($key, 'base64:')) {
            $decoded = base64_decode(substr($key, 7), true);
            if ($decoded === false) {
                throw new RuntimeException('APP_KEY is not valid base64.');
            }

            return $decoded;
        }

        return hash('sha256', $key, true);
    }

    public static function encrypt(string $plaintext): string
    {
        $iv = random_bytes(12);
        $tag = '';

        $cipher = openssl_encrypt($plaintext, self::CIPHER, self::key(), OPENSSL_RAW_DATA, $iv, $tag);
        if ($cipher === false) {
            throw new RuntimeException('Encryption failed.');
        }

        return base64_encode($iv . $tag . $cipher);
    }

    public static function decrypt(string $payload): ?string
    {
        $raw = base64_decode($payload, true);
        if ($raw === false || strlen($raw) < 29) {
            return null;
        }

        $iv = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $cipher = substr($raw, 28);

        $plain = openssl_decrypt($cipher, self::CIPHER, self::key(), OPENSSL_RAW_DATA, $iv, $tag);

        return $plain === false ? null : $plain;
    }

    public static function hashPassword(string $password): string
    {
        return password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
    }

    public static function verifyPassword(string $password, string $hash): bool
    {
        return $hash !== '' && password_verify($password, $hash);
    }

    public static function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, PASSWORD_BCRYPT, ['cost' => 12]);
    }

    /** Constant-time token hashing (tokens are already high entropy). */
    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    public static function equals(string $known, string $given): bool
    {
        return hash_equals($known, $given);
    }

    public static function hmac(string $data, ?string $secret = null): string
    {
        return hash_hmac('sha256', $data, $secret ?? (string) Config::get('app.key', 'fallback'));
    }
}
