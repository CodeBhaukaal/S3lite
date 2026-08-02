<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Cache;
use App\Core\Config;
use App\Core\Database;
use App\Storage\LocalDriver;
use App\Storage\StorageManager;

/**
 * Host and service metrics. Works on both Windows (XAMPP) and Linux/Docker.
 */
final class MetricsService
{
    public static function system(): array
    {
        return [
            'cpu'       => self::cpu(),
            'memory'    => self::memory(),
            'disk'      => self::disk(),
            'php'       => self::php(),
            'uptime'    => self::uptime(),
            'platform'  => PHP_OS_FAMILY,
            'hostname'  => gethostname() ?: 'unknown',
            'timestamp' => time(),
        ];
    }

    /** @return array{percent:float, cores:int, load:list<float>} */
    public static function cpu(): array
    {
        $cores = self::cpuCores();

        if (PHP_OS_FAMILY === 'Windows') {
            $percent = Cache::remember('metrics:cpu', 5, static function (): float {
                $output = [];
                @exec('wmic cpu get loadpercentage /value 2>NUL', $output);

                foreach ($output as $line) {
                    if (preg_match('/LoadPercentage=(\d+)/i', $line, $m) === 1) {
                        return (float) $m[1];
                    }
                }

                return -1.0;
            });

            return ['percent' => max(0.0, (float) $percent), 'cores' => $cores, 'load' => []];
        }

        $load = function_exists('sys_getloadavg') ? (sys_getloadavg() ?: [0, 0, 0]) : [0, 0, 0];
        $percent = $cores > 0 ? round(min(100, ($load[0] / $cores) * 100), 1) : 0.0;

        return [
            'percent' => $percent,
            'cores'   => $cores,
            'load'    => array_map(static fn ($v): float => round((float) $v, 2), $load),
        ];
    }

    public static function cpuCores(): int
    {
        return (int) Cache::remember('metrics:cores', 3600, static function (): int {
            if (PHP_OS_FAMILY === 'Windows') {
                $env = getenv('NUMBER_OF_PROCESSORS');

                return $env === false ? 1 : max(1, (int) $env);
            }

            $contents = self::readSystemFile('/proc/cpuinfo');
            if ($contents !== null) {

                return max(1, substr_count($contents, 'processor'));
            }

            return 1;
        });
    }

    /** @return array{total:int, used:int, free:int, percent:float, php_usage:int, php_peak:int} */
    public static function memory(): array
    {
        $total = 0;
        $free = 0;

        if (PHP_OS_FAMILY === 'Windows') {
            $values = Cache::remember('metrics:mem', 5, static function (): array {
                $output = [];
                @exec('wmic OS get FreePhysicalMemory,TotalVisibleMemorySize /value 2>NUL', $output);

                $result = ['total' => 0, 'free' => 0];
                foreach ($output as $line) {
                    if (preg_match('/TotalVisibleMemorySize=(\d+)/i', $line, $m) === 1) {
                        $result['total'] = (int) $m[1] * 1024;
                    }
                    if (preg_match('/FreePhysicalMemory=(\d+)/i', $line, $m) === 1) {
                        $result['free'] = (int) $m[1] * 1024;
                    }
                }

                return $result;
            });

            $total = (int) ($values['total'] ?? 0);
            $free = (int) ($values['free'] ?? 0);
        } else {
            $meminfo = self::readSystemFile('/proc/meminfo');

            if ($meminfo !== null) {
                if (preg_match('/MemTotal:\s+(\d+)/', $meminfo, $m) === 1) {
                    $total = (int) $m[1] * 1024;
                }
                if (preg_match('/MemAvailable:\s+(\d+)/', $meminfo, $m) === 1) {
                    $free = (int) $m[1] * 1024;
                }
            }
        }

        $used = max(0, $total - $free);

        return [
            'total'     => $total,
            'used'      => $used,
            'free'      => $free,
            'percent'   => $total > 0 ? round(($used / $total) * 100, 1) : 0.0,
            'php_usage' => memory_get_usage(true),
            'php_peak'  => memory_get_peak_usage(true),
        ];
    }

    /**
     * Headroom on the default backend. Remote backends report zeros, which
     * callers read as "unknown" rather than "full".
     *
     * @return array{total:int, used:int, free:int, percent:float, stored:int, path:string, backend:string, driver:string}
     */
    public static function disk(): array
    {
        try {
            $driver = StorageManager::disk();
            $usage = $driver->diskUsage();
            $path = $driver instanceof LocalDriver ? $driver->root() : $driver->name();
            $backend = $driver->name();
            $type = $driver->driver();
        } catch (\Throwable $e) {
            $usage = ['total' => 0, 'used' => 0, 'free' => 0];
            $path = $e->getMessage();
            $backend = StorageManager::defaultSlug();
            $type = 'unavailable';
        }

        $stored = (int) Database::scalar('SELECT COALESCE(SUM(size), 0) FROM files') ?: 0;

        return [
            'total'   => $usage['total'],
            'used'    => $usage['used'],
            'free'    => $usage['free'],
            'percent' => $usage['total'] > 0 ? round(($usage['used'] / $usage['total']) * 100, 1) : 0.0,
            'stored'  => $stored,
            'path'    => $path,
            'backend' => $backend,
            'driver'  => $type,
        ];
    }

    public static function php(): array
    {
        return [
            'version'          => PHP_VERSION,
            'sapi'             => PHP_SAPI,
            'memory_limit'     => ini_get('memory_limit'),
            'max_execution'    => (int) ini_get('max_execution_time'),
            'upload_max'       => ini_get('upload_max_filesize'),
            'post_max'         => ini_get('post_max_size'),
            'extensions'       => [
                'pdo_mysql' => extension_loaded('pdo_mysql'),
                'openssl'   => extension_loaded('openssl'),
                'mbstring'  => extension_loaded('mbstring'),
                'fileinfo'  => extension_loaded('fileinfo'),
                'curl'      => extension_loaded('curl'),
                'gd'        => extension_loaded('gd'),
                'zip'       => extension_loaded('zip'),
                'redis'     => extension_loaded('redis'),
            ],
        ];
    }

    public static function uptime(): array
    {
        $seconds = 0;

        $contents = self::readSystemFile('/proc/uptime');
        if ($contents !== null) {
            $seconds = (int) (float) explode(' ', $contents)[0];
        } elseif (PHP_OS_FAMILY === 'Windows') {
            $seconds = (int) Cache::remember('metrics:uptime', 60, static function (): int {
                $output = [];
                @exec('wmic os get lastbootuptime /value 2>NUL', $output);

                foreach ($output as $line) {
                    if (preg_match('/LastBootUpTime=(\d{14})/', $line, $m) === 1) {
                        $boot = \DateTime::createFromFormat('YmdHis', $m[1]);

                        return $boot === false ? 0 : max(0, time() - $boot->getTimestamp());
                    }
                }

                return 0;
            });
        }

        return [
            'seconds' => $seconds,
            'human'   => self::humanDuration($seconds),
        ];
    }

    /**
     * Aggregated health check used by /api/v1/health and the monitoring page.
     *
     * @return array{status:string, checks:array<string,array{status:string, message:string, latency_ms?:float}>}
     */
    public static function health(): array
    {
        $checks = [];

        // Database
        $start = microtime(true);
        try {
            Database::scalar('SELECT 1');
            $checks['database'] = [
                'status'     => 'ok',
                'message'    => 'Connected',
                'latency_ms' => round((microtime(true) - $start) * 1000, 2),
            ];
        } catch (\Throwable $e) {
            $checks['database'] = ['status' => 'fail', 'message' => $e->getMessage()];
        }

        // Cache / Redis
        $start = microtime(true);
        $redis = Cache::redis();
        if ($redis !== null && $redis->ping()) {
            $info = $redis->info();
            $checks['redis'] = [
                'status'     => 'ok',
                'message'    => 'Connected (v' . ($info['redis_version'] ?? '?') . ')',
                'latency_ms' => round((microtime(true) - $start) * 1000, 2),
            ];
        } else {
            $checks['redis'] = [
                'status'  => Config::get('cache.redis.enabled') ? 'degraded' : 'skipped',
                'message' => Config::get('cache.redis.enabled')
                    ? 'Unreachable — using the file cache instead'
                    : 'Disabled in configuration',
            ];
        }

        // Storage writability
        try {
            $driver = StorageManager::disk();
            $probe = '.healthcheck-' . bin2hex(random_bytes(4));
            $written = $driver->putContents($probe, 'ok');
            $readBack = $written ? $driver->get($probe) : null;
            $driver->delete($probe);

            $checks['storage'] = $readBack === 'ok'
                ? ['status' => 'ok', 'message' => 'Readable and writable (' . $driver->driver() . ': ' . $driver->name() . ')']
                : ['status' => 'fail', 'message' => 'Storage is not writable (' . $driver->name() . ')'];
        } catch (\Throwable $e) {
            $checks['storage'] = ['status' => 'fail', 'message' => $e->getMessage()];
        }

        // Disk headroom
        $disk = self::disk();
        $checks['disk_space'] = match (true) {
            $disk['total'] === 0    => ['status' => 'skipped', 'message' => 'Not reported by this driver'],
            $disk['percent'] >= 95  => ['status' => 'fail', 'message' => sprintf('Only %s free', \App\Support\Str::bytes($disk['free']))],
            $disk['percent'] >= 85  => ['status' => 'degraded', 'message' => sprintf('%s free (%.1f%% used)', \App\Support\Str::bytes($disk['free']), $disk['percent'])],
            default                 => ['status' => 'ok', 'message' => sprintf('%s free', \App\Support\Str::bytes($disk['free']))],
        };

        // Queue backlog
        try {
            $jobs = \App\Models\Job::stats();
            $checks['queue'] = $jobs['failed'] > 20
                ? ['status' => 'degraded', 'message' => $jobs['failed'] . ' failed jobs need attention']
                : ['status' => 'ok', 'message' => $jobs['pending'] . ' pending, ' . $jobs['failed'] . ' failed'];
        } catch (\Throwable $e) {
            $checks['queue'] = ['status' => 'fail', 'message' => $e->getMessage()];
        }

        $status = 'ok';
        foreach ($checks as $check) {
            if ($check['status'] === 'fail') {
                $status = 'fail';
                break;
            }
            if ($check['status'] === 'degraded') {
                $status = 'degraded';
            }
        }

        return ['status' => $status, 'checks' => $checks];
    }

    /** Persist a sample so the monitoring page can chart trends. */
    public static function sample(): void
    {
        $cpu = self::cpu();
        $memory = self::memory();
        $disk = self::disk();

        foreach ([
            'cpu_percent'    => $cpu['percent'],
            'memory_percent' => $memory['percent'],
            'disk_percent'   => $disk['percent'],
            'stored_bytes'   => $disk['stored'],
        ] as $metric => $value) {
            Database::insert('metrics_samples', [
                'metric'     => $metric,
                'value'      => (float) $value,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        }

        Database::statement('DELETE FROM metrics_samples WHERE created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)');
    }

    /** @return array{labels:list<string>, values:list<float>} */
    public static function series(string $metric, int $hours = 24): array
    {
        $rows = Database::select(
            'SELECT DATE_FORMAT(created_at, "%Y-%m-%d %H:00") AS bucket, AVG(value) AS value
             FROM metrics_samples
             WHERE metric = ? AND created_at > DATE_SUB(NOW(), INTERVAL ? HOUR)
             GROUP BY bucket ORDER BY bucket',
            [$metric, $hours]
        );

        return [
            'labels' => array_map(static fn (array $r): string => date('H:i', strtotime($r['bucket'])), $rows),
            'values' => array_map(static fn (array $r): float => round((float) $r['value'], 2), $rows),
        ];
    }

    public static function humanDuration(int $seconds): string
    {
        if ($seconds <= 0) {
            return 'unknown';
        }

        $days = intdiv($seconds, 86400);
        $hours = intdiv($seconds % 86400, 3600);
        $minutes = intdiv($seconds % 3600, 60);

        if ($days > 0) {
            return "{$days}d {$hours}h";
        }
        if ($hours > 0) {
            return "{$hours}h {$minutes}m";
        }

        return "{$minutes}m";
    }

    private static function readSystemFile(string $path): ?string
    {
        if (!self::isAllowedByOpenBaseDir($path) || !is_file($path)) {
            return null;
        }

        $contents = file_get_contents($path);

        return $contents === false ? null : $contents;
    }

    private static function isAllowedByOpenBaseDir(string $path): bool
    {
        $openBaseDir = (string) ini_get('open_basedir');
        if ($openBaseDir === '') {
            return true;
        }

        $path = str_replace('\\', '/', $path);

        foreach (explode(PATH_SEPARATOR, $openBaseDir) as $baseDir) {
            $baseDir = str_replace('\\', '/', trim($baseDir));
            if ($baseDir === '') {
                continue;
            }

            $baseDir = rtrim($baseDir, '/');
            if ($path === $baseDir || str_starts_with($path, $baseDir . '/')) {
                return true;
            }
        }

        return false;
    }
}
