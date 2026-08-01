<?php
declare(strict_types=1);

namespace App\Storage;

use App\Core\Config;
use RuntimeException;

final class StorageManager
{
    private static ?StorageDriver $driver = null;

    public static function disk(?string $name = null): StorageDriver
    {
        if ($name === null && self::$driver !== null) {
            return self::$driver;
        }

        $name ??= (string) Config::get('storage.driver', 'local');
        $config = (array) Config::get('storage.drivers.' . $name, []);

        $driver = match ($name) {
            'local' => new LocalDriver((string) ($config['root'] ?? '')),
            's3'    => new S3Driver(
                (string) ($config['endpoint'] ?? ''),
                (string) ($config['region'] ?? 'us-east-1'),
                (string) ($config['bucket'] ?? ''),
                (string) ($config['access_key'] ?? ''),
                (string) ($config['secret_key'] ?? '')
            ),
            default => throw new RuntimeException("Unknown storage driver: {$name}"),
        };

        if ($name === (string) Config::get('storage.driver', 'local')) {
            self::$driver = $driver;
        }

        return $driver;
    }

    public static function reset(): void
    {
        self::$driver = null;
    }

    /**
     * Content-addressed layout: {user}/{ab}/{cd}/{hash}-{random}.{ext}
     * Sharding keeps directory sizes sane on large installs.
     */
    public static function buildPath(int $userId, string $checksum, string $extension): string
    {
        $extension = strtolower(preg_replace('/[^a-z0-9]/i', '', $extension) ?? '');
        $suffix = $extension === '' ? '' : '.' . substr($extension, 0, 12);

        return sprintf(
            '%d/%s/%s/%s-%s%s',
            $userId,
            substr($checksum, 0, 2),
            substr($checksum, 2, 2),
            substr($checksum, 0, 32),
            bin2hex(random_bytes(4)),
            $suffix
        );
    }

    public static function versionPath(string $originalPath, int $version): string
    {
        $dir = dirname($originalPath);
        $base = pathinfo($originalPath, PATHINFO_FILENAME);
        $ext = pathinfo($originalPath, PATHINFO_EXTENSION);

        return sprintf('%s/versions/%s-v%d%s', $dir, $base, $version, $ext === '' ? '' : '.' . $ext);
    }
}
