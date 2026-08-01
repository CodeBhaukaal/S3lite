<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Minimal .env parser with type coercion.
 */
final class Env
{
    private static array $vars = [];
    private static bool $loaded = false;

    public static function load(string $path): void
    {
        self::$loaded = true;

        if (!is_file($path)) {
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (!str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);

            $len = strlen($value);
            if ($len >= 2) {
                $first = $value[0];
                if (($first === '"' || $first === "'") && $value[$len - 1] === $first) {
                    $value = substr($value, 1, -1);
                }
            }

            self::$vars[$key] = $value;
        }
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $value = self::$vars[$key] ?? $_ENV[$key] ?? getenv($key);

        if ($value === false || $value === null || $value === '') {
            return $default;
        }

        return match (strtolower((string) $value)) {
            'true', '(true)'   => true,
            'false', '(false)' => false,
            'null', '(null)'   => null,
            'empty'            => '',
            default            => $value,
        };
    }

    public static function set(string $key, string $value): void
    {
        self::$vars[$key] = $value;
    }

    public static function all(): array
    {
        return self::$vars;
    }

    public static function isLoaded(): bool
    {
        return self::$loaded;
    }

    /**
     * Rewrite (or append) keys inside an .env file, preserving comments/order.
     */
    public static function write(string $path, array $values): void
    {
        $lines = is_file($path) ? (file($path, FILE_IGNORE_NEW_LINES) ?: []) : [];
        $seen = [];

        foreach ($lines as $i => $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '#') || !str_contains($trimmed, '=')) {
                continue;
            }
            $key = trim(explode('=', $trimmed, 2)[0]);
            if (array_key_exists($key, $values)) {
                $lines[$i] = $key . '=' . self::quote((string) $values[$key]);
                $seen[$key] = true;
            }
        }

        foreach ($values as $key => $value) {
            if (!isset($seen[$key])) {
                $lines[] = $key . '=' . self::quote((string) $value);
            }
            self::$vars[$key] = (string) $value;
        }

        if (file_put_contents($path, implode(PHP_EOL, $lines) . PHP_EOL) === false) {
            throw new \RuntimeException(
                'Could not write ' . $path . '. Make the file (or its directory) writable and try again.'
            );
        }
    }

    private static function quote(string $value): string
    {
        return preg_match('/\s|"|#/', $value) ? '"' . str_replace('"', '\"', $value) . '"' : $value;
    }
}
