<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Cache facade backed by Redis when reachable, transparently falling back to
 * the filesystem so nothing breaks when the container is down.
 */
final class Cache
{
    private static ?RedisClient $redis = null;
    private static bool $resolved = false;
    private static string $driver = 'file';

    public static function redis(): ?RedisClient
    {
        if (self::$resolved) {
            return self::$redis;
        }

        self::$resolved = true;
        $config = Config::get('cache.redis', []);

        if (!($config['enabled'] ?? false)) {
            self::$driver = 'file';

            return null;
        }

        $client = new RedisClient(
            (string) ($config['host'] ?? '127.0.0.1'),
            (int) ($config['port'] ?? 6379),
            (string) ($config['password'] ?? ''),
            (int) ($config['database'] ?? 0),
            (float) ($config['timeout'] ?? 2.0),
            (string) ($config['prefix'] ?? '')
        );

        if ($client->isAvailable()) {
            self::$redis = $client;
            self::$driver = 'redis';
        } else {
            self::$driver = 'file';
        }

        return self::$redis;
    }

    public static function driver(): string
    {
        self::redis();

        return self::$driver;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $raw = self::raw($key);

        if ($raw === null) {
            return $default;
        }

        $decoded = json_decode($raw, true);

        return $decoded === null && json_last_error() !== JSON_ERROR_NONE ? $default : $decoded;
    }

    public static function put(string $key, mixed $value, int $ttl = 3600): bool
    {
        $payload = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($payload === false) {
            return false;
        }

        $redis = self::redis();
        if ($redis !== null) {
            try {
                return $redis->set($key, $payload, $ttl);
            } catch (\Throwable) {
                self::$driver = 'file';
            }
        }

        return self::filePut($key, $payload, $ttl);
    }

    public static function forget(string $key): void
    {
        $redis = self::redis();
        if ($redis !== null) {
            try {
                $redis->del($key);

                return;
            } catch (\Throwable) {
                // fall through to file driver
            }
        }

        $path = self::filePath($key);
        if (is_file($path)) {
            @unlink($path);
        }
    }

    public static function has(string $key): bool
    {
        return self::raw($key) !== null;
    }

    public static function remember(string $key, int $ttl, callable $callback): mixed
    {
        $cached = self::get($key, '__miss__');

        if ($cached !== '__miss__') {
            return $cached;
        }

        $value = $callback();
        self::put($key, $value, $ttl);

        return $value;
    }

    public static function increment(string $key, int $by = 1, int $ttl = 60): int
    {
        $redis = self::redis();
        if ($redis !== null) {
            try {
                $value = $redis->incrBy($key, $by);
                if ($value === $by) {
                    $redis->expire($key, $ttl);
                }

                return $value;
            } catch (\Throwable) {
                // fall through
            }
        }

        $current = (int) (self::get($key, 0));
        $current += $by;
        self::put($key, $current, $ttl);

        return $current;
    }

    public static function ttl(string $key): int
    {
        $redis = self::redis();
        if ($redis !== null) {
            try {
                return $redis->ttl($key);
            } catch (\Throwable) {
                // fall through
            }
        }

        $path = self::filePath($key);
        if (!is_file($path)) {
            return -2;
        }

        $payload = json_decode((string) file_get_contents($path), true);
        $expires = (int) ($payload['expires'] ?? 0);

        return $expires === 0 ? -1 : max(0, $expires - time());
    }

    public static function flush(): void
    {
        $redis = self::redis();
        if ($redis !== null) {
            try {
                $redis->flushPrefix();
            } catch (\Throwable) {
                // ignore
            }
        }

        foreach (glob(self::dir() . '/*.cache') ?: [] as $file) {
            @unlink($file);
        }
    }

    private static function raw(string $key): ?string
    {
        $redis = self::redis();
        if ($redis !== null) {
            try {
                return $redis->get($key);
            } catch (\Throwable) {
                self::$driver = 'file';
            }
        }

        $path = self::filePath($key);
        if (!is_file($path)) {
            return null;
        }

        $payload = json_decode((string) file_get_contents($path), true);
        if (!is_array($payload)) {
            return null;
        }

        $expires = (int) ($payload['expires'] ?? 0);
        if ($expires !== 0 && $expires < time()) {
            @unlink($path);

            return null;
        }

        return (string) ($payload['value'] ?? '');
    }

    private static function filePut(string $key, string $payload, int $ttl): bool
    {
        $dir = self::dir();
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $data = json_encode([
            'expires' => $ttl > 0 ? time() + $ttl : 0,
            'value'   => $payload,
        ]);

        return file_put_contents(self::filePath($key), (string) $data, LOCK_EX) !== false;
    }

    private static function dir(): string
    {
        return (string) Config::get('cache.file.path', sys_get_temp_dir());
    }

    private static function filePath(string $key): string
    {
        return self::dir() . '/' . sha1($key) . '.cache';
    }
}
