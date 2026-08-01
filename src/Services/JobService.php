<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Logger;
use App\Models\Job;
use App\Models\RefreshToken;
use App\Models\SftpSession;

/**
 * Background job dispatcher. `php bin/console queue:work` drains the queue;
 * `queue:run-once` is cron-friendly.
 */
final class JobService
{
    public const TYPES = [
        'cleanup'          => 'Purge expired trash, shares, tokens and stale uploads',
        'rescan'           => 'Re-index storage and recalculate quotas',
        'backup'           => 'Create a database backup',
        'sftp.sync'        => 'Import files uploaded over SFTP',
        'metrics.sample'   => 'Record a system metrics sample',
        'integrity.check'  => 'Verify stored files against the index',
        'orphans.prune'    => 'Delete stored blobs with no database row',
        'webhook.deliver'  => 'Deliver a webhook payload',
        'audit.purge'      => 'Delete audit entries past the retention window',
    ];

    public static function dispatch(string $type, array $payload = [], int $delaySeconds = 0): int
    {
        $queue = $type === 'webhook.deliver' ? 'webhooks' : 'default';

        $id = Job::push($type, $payload, $queue, $delaySeconds);

        AuditService::log('job.dispatch', 'job', $id, "Queued job: {$type}", $payload);

        return $id;
    }

    /**
     * Run a single job synchronously.
     *
     * @return array{ok:bool, output:string}
     */
    public static function execute(string $type, array $payload = []): array
    {
        try {
            $output = match ($type) {
                'cleanup'         => self::cleanup(),
                'rescan'          => self::rescan(),
                'backup'          => json_encode(BackupService::create('job'), JSON_UNESCAPED_SLASHES) ?: '',
                'sftp.sync'       => json_encode(SftpService::syncAll(), JSON_UNESCAPED_SLASHES) ?: '',
                'metrics.sample'  => self::sampleMetrics(),
                'integrity.check' => self::integrity(),
                'orphans.prune'   => 'Removed ' . BackupService::pruneOrphans() . ' orphaned blobs',
                'webhook.deliver' => self::webhook($payload),
                'audit.purge'     => 'Deleted ' . AuditService::purge((int) ($payload['days'] ?? 180)) . ' audit entries',
                default           => throw new \RuntimeException("Unknown job type: {$type}"),
            };

            return ['ok' => true, 'output' => (string) $output];
        } catch (\Throwable $e) {
            Logger::exception($e, ['job_type' => $type]);

            return ['ok' => false, 'output' => $e->getMessage()];
        }
    }

    /**
     * Drain the queue.
     *
     * @return array{processed:int, failed:int}
     */
    public static function work(string $queue = 'default', int $maxJobs = 100): array
    {
        $processed = 0;
        $failed = 0;

        while ($processed + $failed < $maxJobs) {
            $job = Job::reserve($queue);

            if ($job === null) {
                break;
            }

            $result = self::execute((string) $job['type'], (array) ($job['payload'] ?: []));

            if ($result['ok']) {
                Job::complete((int) $job['id'], $result['output']);
                $processed++;
            } else {
                Job::fail((int) $job['id'], $result['output']);
                $failed++;
            }
        }

        return ['processed' => $processed, 'failed' => $failed];
    }

    private static function cleanup(): string
    {
        $results = [
            'trash_purged'      => FileService::purgeExpiredTrash(),
            'shares_purged'     => ShareService::purgeExpired(),
            'uploads_purged'    => MultipartService::purgeExpired(),
            'tokens_purged'     => RefreshToken::purgeExpired(),
            'sessions_reaped'   => SftpSession::reapStale(),
            'idempotency_purged' => Database::statement('DELETE FROM idempotency_keys WHERE expires_at < NOW()'),
            'attempts_purged'   => Database::statement('DELETE FROM login_attempts WHERE created_at < DATE_SUB(NOW(), INTERVAL 90 DAY)'),
            'jobs_purged'       => Database::statement("DELETE FROM jobs WHERE status = 'completed' AND completed_at < DATE_SUB(NOW(), INTERVAL 7 DAY)"),
            'tmp_cleaned'       => self::cleanTempFiles(),
        ];

        AuditService::system('job.cleanup', 'Cleanup job finished', $results);

        return json_encode($results, JSON_UNESCAPED_SLASHES) ?: '';
    }

    private static function rescan(): string
    {
        $users = QuotaService::recalculateAll();
        $integrity = BackupService::verifyIntegrity();

        $results = [
            'users_recalculated' => $users,
            'files_checked'      => $integrity['checked'],
            'missing_files'      => count($integrity['missing']),
            'orphaned_blobs'     => $integrity['orphans'],
        ];

        AuditService::system('job.rescan', 'Rescan job finished', $results);

        return json_encode($results, JSON_UNESCAPED_SLASHES) ?: '';
    }

    private static function sampleMetrics(): string
    {
        MetricsService::sample();

        return 'Metrics sample recorded';
    }

    private static function integrity(): string
    {
        $result = BackupService::verifyIntegrity();

        if ($result['missing'] !== []) {
            AuditService::system('integrity.missing', count($result['missing']) . ' indexed files are missing from disk', [
                'files' => array_slice($result['missing'], 0, 50),
            ]);
        }

        return json_encode([
            'checked' => $result['checked'],
            'missing' => count($result['missing']),
            'orphans' => $result['orphans'],
        ], JSON_UNESCAPED_SLASHES) ?: '';
    }

    private static function webhook(array $payload): string
    {
        $result = WebhookService::deliver(
            (int) ($payload['webhook_id'] ?? 0),
            (string) ($payload['event'] ?? ''),
            (array) ($payload['payload'] ?? [])
        );

        if (!$result['ok']) {
            throw new \RuntimeException('Webhook delivery failed with status ' . $result['status']);
        }

        return 'Delivered with status ' . $result['status'];
    }

    private static function cleanTempFiles(): int
    {
        $removed = 0;
        $tmp = rtrim((string) \App\Core\Config::get('storage.tmp_path'), '/\\');

        foreach (glob($tmp . '/*') ?: [] as $file) {
            if (is_file($file) && filemtime($file) < time() - 86400) {
                @unlink($file);
                $removed++;
            }
        }

        return $removed;
    }

    public static function recent(int $limit = 50): array
    {
        return Database::select(
            'SELECT * FROM jobs ORDER BY id DESC LIMIT ' . max(1, min(200, $limit))
        );
    }

    public static function retry(int $jobId): void
    {
        Database::statement(
            "UPDATE jobs SET status = 'pending', attempts = 0, available_at = NOW(), error = NULL WHERE id = ?",
            [$jobId]
        );

        AuditService::log('job.retry', 'job', $jobId, 'Job re-queued');
    }
}
