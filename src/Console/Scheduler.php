<?php
declare(strict_types=1);

namespace App\Console;

use App\Core\Config;
use App\Core\Logger;
use App\Services\JobService;
use App\Services\SettingService;

/**
 * Self-scheduling maintenance runner.
 *
 * Shared hosting control panels usually let you point cron at a single PHP file
 * and nothing more. So instead of one cron entry per task, this runs on a
 * frequent tick and decides for itself which tasks are due, remembering the
 * last run of each in the settings table.
 */
final class Scheduler
{
    /**
     * Task => how often it should run, in seconds. `0` means every tick.
     *
     * @var array<string, int>
     */
    public const SCHEDULE = [
        'queue'           => 0,       // deliver webhooks and queued work
        'metrics.sample'  => 300,     // 5 minutes  — builds the monitoring chart
        'sftp.sync'       => 600,     // 10 minutes — index SFTP uploads
        'cleanup'         => 86400,   // daily      — trash, tokens, temp files
        'backup'          => 86400,   // daily      — database dump
        'integrity.check' => 604800,  // weekly     — index vs disk
    ];

    private const LOCK_FILE = 'cron.lock';
    private const LOCK_STALE_SECONDS = 900;
    private const SETTING_GROUP = 'cron';

    /**
     * @return array{
     *   ok:bool, skipped:bool, reason:?string, duration_ms:int,
     *   ran:array<string, string>, due:list<string>
     * }
     */
    public static function run(?int $budgetSeconds = null, bool $force = false): array
    {
        $started = microtime(true);
        $budgetSeconds ??= 50;

        $lock = self::acquireLock();

        if ($lock === null) {
            return [
                'ok'          => true,
                'skipped'     => true,
                'reason'      => 'another run is still in progress',
                'duration_ms' => 0,
                'ran'         => [],
                'due'         => [],
            ];
        }

        $ran = [];
        $due = [];

        try {
            foreach (self::SCHEDULE as $task => $interval) {
                if (!$force && !self::isDue($task, $interval)) {
                    continue;
                }

                $due[] = $task;

                // Stop starting new tasks once the budget is spent; whatever is
                // left stays due and runs on the next tick.
                if (microtime(true) - $started > $budgetSeconds) {
                    break;
                }

                $ran[$task] = self::execute($task);
                self::markRun($task);
            }
        } finally {
            self::releaseLock($lock);
        }

        return [
            'ok'          => true,
            'skipped'     => false,
            'reason'      => null,
            'duration_ms' => (int) round((microtime(true) - $started) * 1000),
            'ran'         => $ran,
            'due'         => $due,
        ];
    }

    private static function execute(string $task): string
    {
        if ($task === 'queue') {
            $result = JobService::workAll(50);

            return sprintf('%d processed, %d failed', $result['processed'], $result['failed']);
        }

        $result = JobService::execute($task);

        if (!$result['ok']) {
            Logger::error('Scheduled task failed', ['task' => $task, 'error' => $result['output']]);

            return 'FAILED: ' . $result['output'];
        }

        return $result['output'] === '' ? 'done' : $result['output'];
    }

    public static function isDue(string $task, int $interval): bool
    {
        if ($interval === 0) {
            return true;
        }

        return (time() - self::lastRun($task)) >= $interval;
    }

    public static function lastRun(string $task): int
    {
        return (int) SettingService::get(self::settingKey($task), 0);
    }

    private static function markRun(string $task): void
    {
        SettingService::set(self::settingKey($task), time(), self::SETTING_GROUP);
    }

    private static function settingKey(string $task): string
    {
        return 'cron_last_' . str_replace('.', '_', $task);
    }

    /** A report of every task, for the admin panel. */
    public static function status(): array
    {
        $rows = [];

        foreach (self::SCHEDULE as $task => $interval) {
            $last = self::lastRun($task);

            $rows[] = [
                'task'     => $task,
                'interval' => $interval,
                'last_run' => $last === 0 ? null : date('Y-m-d H:i:s', $last),
                'due'      => self::isDue($task, $interval),
                'next_run' => $interval === 0 || $last === 0 ? null : date('Y-m-d H:i:s', $last + $interval),
            ];
        }

        return $rows;
    }

    /** True when cron has never reported in — the panel warns about this. */
    public static function hasNeverRun(): bool
    {
        foreach (array_keys(self::SCHEDULE) as $task) {
            if (self::lastRun($task) > 0) {
                return false;
            }
        }

        return true;
    }

    public static function lastActivity(): ?string
    {
        $latest = 0;

        foreach (array_keys(self::SCHEDULE) as $task) {
            $latest = max($latest, self::lastRun($task));
        }

        return $latest === 0 ? null : date('Y-m-d H:i:s', $latest);
    }

    // --- Locking ---------------------------------------------------------

    /**
     * A slow task must not have a second run pile up behind it.
     *
     * @return resource|null
     */
    private static function acquireLock()
    {
        $path = self::lockPath();

        // A lock left behind by a crashed run must not block cron forever.
        if (is_file($path) && (time() - (int) filemtime($path)) > self::LOCK_STALE_SECONDS) {
            @unlink($path);
        }

        $handle = @fopen($path, 'c');

        if ($handle === false) {
            return null;
        }

        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);

            return null;
        }

        ftruncate($handle, 0);
        fwrite($handle, (string) time());

        return $handle;
    }

    /** @param resource $handle */
    private static function releaseLock($handle): void
    {
        flock($handle, LOCK_UN);
        fclose($handle);
        @unlink(self::lockPath());
    }

    private static function lockPath(): string
    {
        return rtrim((string) Config::get('storage.tmp_path'), '/\\') . '/' . self::LOCK_FILE;
    }

    // --- HTTP access -----------------------------------------------------

    /**
     * Path to the PHP command-line binary.
     *
     * Under mod_php/FPM, PHP_BINARY is the web server executable, which would
     * be useless in a cron entry — so only trust it on the CLI and otherwise
     * look for a real CLI binary next to it.
     */
    public static function phpBinary(): ?string
    {
        if (PHP_SAPI === 'cli') {
            return PHP_BINARY;
        }

        $candidates = [
            PHP_BINDIR . '/php',
            PHP_BINDIR . '/php.exe',
            '/usr/local/bin/php',
            '/usr/bin/php',
        ];

        foreach ($candidates as $candidate) {
            if (@is_file($candidate)) {
                return str_replace('\\', '/', $candidate);
            }
        }

        return null;
    }

    public static function token(): string
    {
        return (string) Config::get('app.cron_token', '');
    }

    /**
     * Constant-time token check. An unset token means HTTP triggering is off,
     * which is the safe default rather than an open endpoint.
     */
    public static function tokenMatches(string $given): bool
    {
        $expected = self::token();

        if ($expected === '' || $given === '') {
            return false;
        }

        return hash_equals($expected, $given);
    }
}
