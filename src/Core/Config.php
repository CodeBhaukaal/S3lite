<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Dot-notation configuration store loaded from config/*.php.
 */
final class Config
{
    private static array $items = [];

    public static function loadPath(string $dir): void
    {
        foreach (glob(rtrim($dir, '/\\') . '/*.php') ?: [] as $file) {
            $name = pathinfo($file, PATHINFO_FILENAME);
            self::$items[$name] = require $file;
        }
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $segments = explode('.', $key);
        $value = self::$items;

        foreach ($segments as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    public static function set(string $key, mixed $value): void
    {
        $segments = explode('.', $key);
        $ref = &self::$items;

        foreach ($segments as $segment) {
            if (!isset($ref[$segment]) || !is_array($ref[$segment])) {
                $ref[$segment] = [];
            }
            $ref = &$ref[$segment];
        }

        $ref = $value;
    }

    public static function all(): array
    {
        return self::$items;
    }
}
