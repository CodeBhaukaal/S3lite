<?php
declare(strict_types=1);

namespace App\Core;

/**
 * Daily-rotating JSON-lines logger.
 */
final class Logger
{
    private const LEVELS = [
        'debug'     => 100,
        'info'      => 200,
        'notice'    => 250,
        'warning'   => 300,
        'error'     => 400,
        'critical'  => 500,
    ];

    private static ?string $path = null;

    public static function setPath(string $path): void
    {
        self::$path = $path;
    }

    public static function path(): string
    {
        return self::$path ?? dirname(__DIR__, 2) . '/storage/logs';
    }

    public static function log(string $level, string $message, array $context = []): void
    {
        $threshold = self::LEVELS[strtolower((string) Config::get('app.log_level', 'debug'))] ?? 100;
        $current = self::LEVELS[$level] ?? 100;

        if ($current < $threshold) {
            return;
        }

        $dir = self::path();
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $entry = json_encode([
            'time'    => date('c'),
            'level'   => $level,
            'message' => $message,
            'context' => $context,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        @file_put_contents(
            $dir . '/app-' . date('Y-m-d') . '.log',
            $entry . PHP_EOL,
            FILE_APPEND | LOCK_EX
        );
    }

    public static function debug(string $m, array $c = []): void { self::log('debug', $m, $c); }
    public static function info(string $m, array $c = []): void { self::log('info', $m, $c); }
    public static function notice(string $m, array $c = []): void { self::log('notice', $m, $c); }
    public static function warning(string $m, array $c = []): void { self::log('warning', $m, $c); }
    public static function error(string $m, array $c = []): void { self::log('error', $m, $c); }
    public static function critical(string $m, array $c = []): void { self::log('critical', $m, $c); }

    public static function exception(\Throwable $e, array $context = []): void
    {
        self::error($e->getMessage(), array_merge($context, [
            'exception' => $e::class,
            'file'      => $e->getFile(),
            'line'      => $e->getLine(),
            'trace'     => array_slice(explode("\n", $e->getTraceAsString()), 0, 12),
        ]));
    }

    /**
     * Read recent log entries, newest first.
     */
    public static function tail(int $limit = 200, ?string $level = null, ?string $date = null): array
    {
        $file = self::path() . '/app-' . ($date ?: date('Y-m-d')) . '.log';
        if (!is_file($file)) {
            return [];
        }

        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        $lines = array_reverse($lines);

        $entries = [];
        foreach ($lines as $line) {
            $entry = json_decode($line, true);
            if (!is_array($entry)) {
                continue;
            }
            if ($level !== null && $level !== '' && ($entry['level'] ?? '') !== $level) {
                continue;
            }
            $entries[] = $entry;
            if (count($entries) >= $limit) {
                break;
            }
        }

        return $entries;
    }

    public static function availableDates(): array
    {
        $dates = [];
        foreach (glob(self::path() . '/app-*.log') ?: [] as $file) {
            if (preg_match('/app-(\d{4}-\d{2}-\d{2})\.log$/', $file, $m)) {
                $dates[] = $m[1];
            }
        }
        rsort($dates);

        return $dates;
    }
}
