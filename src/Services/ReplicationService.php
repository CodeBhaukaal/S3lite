<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Logger;
use App\Http\Exceptions\HttpException;
use App\Models\StorageBackend;
use App\Storage\StorageDriver;
use App\Storage\StorageManager;
use Throwable;

/**
 * Backup layers. A blob is written to its primary backend as usual; this copies
 * it to every backend marked as a replica (priority 1, 2, 3...) from the queue,
 * and, when the primary cannot serve it, finds a copy that can.
 *
 * The `files`.`disk` column stays the source of truth for where a blob lives.
 * `file_replicas` only records extra copies, so turning a layer off never
 * strands anything.
 */
final class ReplicationService
{
    /** Copies that fail this many times stop being retried automatically. */
    public const MAX_ATTEMPTS = 5;

    /**
     * Active backup layers, in the order they are tried.
     *
     * @return list<array>
     */
    public static function layers(): array
    {
        try {
            return Database::select(
                'SELECT * FROM storage_backends
                 WHERE is_active = 1 AND replica_priority IS NOT NULL
                 ORDER BY replica_priority ASC, id ASC'
            );
        } catch (Throwable) {
            return []; // Migration 003 not applied yet.
        }
    }

    /**
     * Record that $path on $primarySlug should be copied to every layer, and
     * wake the worker. Never throws: a missing backup must not fail an upload.
     */
    public static function queue(string $primarySlug, string $path): void
    {
        try {
            $queued = 0;

            foreach (self::layers() as $layer) {
                if ((string) $layer['slug'] === $primarySlug) {
                    continue;
                }

                $queued += Database::statement(
                    "INSERT INTO file_replicas (storage_path, disk, status, attempts, created_at)
                     VALUES (?, ?, 'pending', 0, NOW())
                     ON DUPLICATE KEY UPDATE status = IF(status = 'synced', status, 'pending'), attempts = 0",
                    [$path, (string) $layer['slug']]
                );
            }

            if ($queued > 0) {
                JobService::dispatch('storage.replicate', []);
            }
        } catch (Throwable $e) {
            Logger::error('Could not queue replication: ' . $e->getMessage(), ['path' => $path]);
        }
    }

    /**
     * Copy a batch of pending objects to their layers.
     *
     * @return array{synced:int, failed:int, remaining:int}
     */
    public static function run(int $limit = 100): array
    {
        $limit = max(1, min(1000, $limit));
        $synced = 0;
        $failed = 0;

        $rows = Database::select(
            "SELECT * FROM file_replicas WHERE status = 'pending' AND attempts < ? ORDER BY id LIMIT " . $limit,
            [self::MAX_ATTEMPTS]
        );

        foreach ($rows as $row) {
            $path = (string) $row['storage_path'];
            $layerSlug = (string) $row['disk'];

            try {
                $primarySlug = self::primaryOf($path);

                if ($primarySlug === null) {
                    // The blob was deleted before we got to it.
                    Database::statement('DELETE FROM file_replicas WHERE id = ?', [(int) $row['id']]);
                    continue;
                }

                if ($primarySlug === $layerSlug) {
                    // A migration moved the primary onto this layer; the copy is the original now.
                    self::markSynced((int) $row['id']);
                    $synced++;
                    continue;
                }

                $source = self::readableDisk($primarySlug, $path, $layerSlug);
                StorageBackendService::transfer($source, StorageManager::disk($layerSlug), $path);

                self::markSynced((int) $row['id']);
                $synced++;
            } catch (Throwable $e) {
                $failed++;
                $attempts = (int) $row['attempts'] + 1;

                Database::statement(
                    'UPDATE file_replicas SET attempts = ?, status = ?, last_error = ? WHERE id = ?',
                    [
                        $attempts,
                        $attempts >= self::MAX_ATTEMPTS ? 'failed' : 'pending',
                        mb_substr($e->getMessage(), 0, 500),
                        (int) $row['id'],
                    ]
                );

                Logger::warning('Replication failed: ' . $e->getMessage(), ['path' => $path, 'layer' => $layerSlug]);
            }
        }

        $remaining = (int) Database::scalar(
            "SELECT COUNT(*) FROM file_replicas WHERE status = 'pending' AND attempts < ?",
            [self::MAX_ATTEMPTS]
        );

        return ['synced' => $synced, 'failed' => $failed, 'remaining' => $remaining];
    }

    /**
     * Queue every existing object that a layer does not have yet — used when a
     * backend is first made a layer, so older files get backed up too.
     */
    public static function backfill(string $layerSlug): int
    {
        $added = 0;

        foreach (['files', 'file_versions'] as $table) {
            $added += Database::statement(
                sprintf(
                    "INSERT IGNORE INTO file_replicas (storage_path, disk, status, attempts, created_at)
                     SELECT DISTINCT storage_path, ?, 'pending', 0, NOW() FROM %s WHERE disk != ?",
                    $table
                ),
                [$layerSlug, $layerSlug]
            );
        }

        if ($added > 0) {
            JobService::dispatch('storage.replicate', []);
        }

        return $added;
    }

    /** Put failed copies back in the queue (after fixing a broken layer). */
    public static function retryFailed(?string $layerSlug = null): int
    {
        $sql = "UPDATE file_replicas SET status = 'pending', attempts = 0, last_error = NULL WHERE status = 'failed'";
        $bindings = [];

        if ($layerSlug !== null) {
            $sql .= ' AND disk = ?';
            $bindings[] = $layerSlug;
        }

        $count = Database::statement($sql, $bindings);

        if ($count > 0) {
            JobService::dispatch('storage.replicate', []);
        }

        return $count;
    }

    /**
     * The disk to read $path from: the primary when it has the object, else the
     * first synced layer that does. This is what keeps downloads working when
     * an FTP server goes down.
     *
     * @throws HttpException 404 when no backend can produce the object.
     */
    public static function readableDisk(string $primarySlug, string $path, ?string $exceptSlug = null): StorageDriver
    {
        try {
            $primary = StorageManager::disk($primarySlug);

            if ($primary->exists($path)) {
                return $primary;
            }
        } catch (Throwable $e) {
            Logger::warning('Primary backend unavailable, trying backup layers: ' . $e->getMessage(), [
                'disk' => $primarySlug,
                'path' => $path,
            ]);
        }

        foreach (self::syncedLayers($path) as $slug) {
            if ($slug === $primarySlug || $slug === $exceptSlug) {
                continue;
            }

            try {
                $layer = StorageManager::disk($slug);

                if ($layer->exists($path)) {
                    return $layer;
                }
            } catch (Throwable $e) {
                Logger::warning('Backup layer unavailable: ' . $e->getMessage(), ['disk' => $slug]);
            }
        }

        throw new HttpException(404, 'The stored file is missing from every storage backend.', 'file_missing');
    }

    /**
     * Delete $path from its primary and every layer holding a copy.
     */
    public static function deleteEverywhere(string $primarySlug, string $path): void
    {
        try {
            StorageManager::disk($primarySlug)->delete($path);
        } catch (Throwable $e) {
            Logger::warning('Could not delete from primary: ' . $e->getMessage(), ['disk' => $primarySlug, 'path' => $path]);
        }

        self::forget($path, $primarySlug);
    }

    /** Remove the layer copies of $path and their rows. */
    public static function forget(string $path, ?string $keepSlug = null): void
    {
        try {
            $rows = Database::select('SELECT id, disk FROM file_replicas WHERE storage_path = ?', [$path]);
        } catch (Throwable) {
            return;
        }

        foreach ($rows as $row) {
            $slug = (string) $row['disk'];

            // Not just synced rows: a copy interrupted before it was marked can
            // still have left bytes behind.
            if ($slug !== $keepSlug) {
                try {
                    StorageManager::disk($slug)->delete($path);
                } catch (Throwable $e) {
                    Logger::warning('Could not delete backup copy: ' . $e->getMessage(), ['disk' => $slug, 'path' => $path]);
                }
            }

            Database::statement('DELETE FROM file_replicas WHERE id = ?', [(int) $row['id']]);
        }
    }

    /**
     * Paths a backend holds as a backup copy, so the orphan sweeper leaves them.
     *
     * @return array<string, int> path => index (for isset lookups)
     */
    public static function pathsOn(string $slug): array
    {
        try {
            return array_flip(array_column(
                Database::select('SELECT storage_path FROM file_replicas WHERE disk = ?', [$slug]),
                'storage_path'
            ));
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Copy counts per layer, for the admin panel.
     *
     * @return array<string, array{synced:int, pending:int, failed:int}>
     */
    public static function statusBySlug(): array
    {
        $status = [];

        try {
            $rows = Database::select('SELECT disk, status, COUNT(*) AS n FROM file_replicas GROUP BY disk, status');
        } catch (Throwable) {
            return [];
        }

        foreach ($rows as $row) {
            $slug = (string) $row['disk'];
            $status[$slug] ??= ['synced' => 0, 'pending' => 0, 'failed' => 0];

            if (isset($status[$slug][$row['status']])) {
                $status[$slug][$row['status']] = (int) $row['n'];
            }
        }

        return $status;
    }

    /** Which backend holds $path as its primary copy, or null if nothing references it. */
    private static function primaryOf(string $path): ?string
    {
        foreach (['files', 'file_versions'] as $table) {
            $disk = Database::scalar(sprintf('SELECT disk FROM %s WHERE storage_path = ? LIMIT 1', $table), [$path]);

            if ($disk !== null && $disk !== false) {
                return (string) $disk;
            }
        }

        return null;
    }

    /** @return list<string> */
    private static function syncedLayers(string $path): array
    {
        try {
            return array_map('strval', array_column(
                Database::select(
                    "SELECT r.disk FROM file_replicas r
                     JOIN storage_backends b ON b.slug = r.disk
                     WHERE r.storage_path = ? AND r.status = 'synced' AND b.is_active = 1
                     ORDER BY COALESCE(b.replica_priority, 65535), b.id",
                    [$path]
                ),
                'disk'
            ));
        } catch (Throwable) {
            return [];
        }
    }

    private static function markSynced(int $id): void
    {
        Database::statement(
            "UPDATE file_replicas SET status = 'synced', last_error = NULL, synced_at = NOW() WHERE id = ?",
            [$id]
        );
    }
}
