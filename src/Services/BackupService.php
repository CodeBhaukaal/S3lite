<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Database;
use App\Core\Logger;
use App\Http\Exceptions\HttpException;

/**
 * SQL dump backups written in pure PHP — no mysqldump binary required.
 */
final class BackupService
{
    public static function path(): string
    {
        $path = rtrim((string) Config::get('storage.backup_path'), '/\\');

        if (!is_dir($path)) {
            @mkdir($path, 0775, true);
        }

        return $path;
    }

    /**
     * @return array{file:string, size:int, tables:int, rows:int, duration_ms:int}
     */
    public static function create(?string $label = null): array
    {
        $startedAt = microtime(true);

        $name = sprintf(
            'backup-%s%s.sql',
            date('Ymd-His'),
            $label === null || $label === '' ? '' : '-' . preg_replace('/[^a-z0-9_-]/i', '', $label)
        );

        $file = self::path() . '/' . $name;
        $handle = @fopen($file, 'wb');

        if ($handle === false) {
            throw new HttpException(500, 'Unable to create the backup file.', 'backup_failed');
        }

        $database = (string) Config::get('database.database');

        fwrite($handle, "-- S3 Lite backup\n");
        fwrite($handle, '-- Database: ' . $database . "\n");
        fwrite($handle, '-- Generated: ' . date('c') . "\n\n");
        fwrite($handle, "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\n\n");

        $tables = array_column(
            Database::select('SELECT table_name AS t FROM information_schema.tables WHERE table_schema = ? AND table_type = "BASE TABLE" ORDER BY table_name', [$database]),
            't'
        );

        $totalRows = 0;

        foreach ($tables as $table) {
            $create = Database::selectOne('SHOW CREATE TABLE `' . str_replace('`', '', $table) . '`');
            $ddl = $create['Create Table'] ?? null;

            fwrite($handle, "\n-- Table: {$table}\n");
            fwrite($handle, "DROP TABLE IF EXISTS `{$table}`;\n");

            if (is_string($ddl)) {
                fwrite($handle, $ddl . ";\n\n");
            }

            $offset = 0;
            $chunk = 500;

            while (true) {
                $rows = Database::select(sprintf('SELECT * FROM `%s` LIMIT %d OFFSET %d', $table, $chunk, $offset));

                if ($rows === []) {
                    break;
                }

                foreach ($rows as $row) {
                    $columns = '`' . implode('`, `', array_keys($row)) . '`';
                    $values = [];

                    foreach ($row as $value) {
                        if ($value === null) {
                            $values[] = 'NULL';
                        } elseif (is_int($value) || is_float($value)) {
                            $values[] = (string) $value;
                        } else {
                            $values[] = Database::pdo()->quote((string) $value);
                        }
                    }

                    fwrite($handle, sprintf("INSERT INTO `%s` (%s) VALUES (%s);\n", $table, $columns, implode(', ', $values)));
                    $totalRows++;
                }

                $offset += $chunk;
            }
        }

        fwrite($handle, "\nSET FOREIGN_KEY_CHECKS = 1;\n");
        fclose($handle);

        $size = (int) filesize($file);

        AuditService::log('backup.create', 'backup', $name, "Created database backup {$name}", [
            'size'   => $size,
            'tables' => count($tables),
            'rows'   => $totalRows,
        ]);

        self::rotate();

        return [
            'file'        => $name,
            'size'        => $size,
            'tables'      => count($tables),
            'rows'        => $totalRows,
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ];
    }

    /** @return list<array{name:string, size:int, created_at:string}> */
    public static function list(): array
    {
        $backups = [];

        foreach (glob(self::path() . '/backup-*.sql') ?: [] as $file) {
            $backups[] = [
                'name'       => basename($file),
                'size'       => (int) filesize($file),
                'created_at' => date('Y-m-d H:i:s', (int) filemtime($file)),
            ];
        }

        usort($backups, static fn (array $a, array $b): int => strcmp($b['created_at'], $a['created_at']));

        return $backups;
    }

    public static function resolve(string $name): string
    {
        $name = basename($name);

        if (preg_match('/^backup-[A-Za-z0-9_-]+\.sql$/', $name) !== 1) {
            throw new HttpException(422, 'Invalid backup name.', 'invalid_backup');
        }

        $file = self::path() . '/' . $name;

        if (!is_file($file)) {
            throw new HttpException(404, 'Backup not found.', 'backup_not_found');
        }

        return $file;
    }

    public static function delete(string $name): void
    {
        $file = self::resolve($name);
        @unlink($file);

        AuditService::log('backup.delete', 'backup', $name, "Deleted backup {$name}");
    }

    /** Keep only the newest N backups. */
    public static function rotate(int $keep = 10): int
    {
        $backups = self::list();

        if (count($backups) <= $keep) {
            return 0;
        }

        $removed = 0;
        foreach (array_slice($backups, $keep) as $backup) {
            @unlink(self::path() . '/' . $backup['name']);
            $removed++;
        }

        return $removed;
    }

    /**
     * Verify that every indexed file still exists on disk.
     *
     * @return array{checked:int, missing:list<array{id:int, name:string, path:string}>, orphans:int}
     */
    public static function verifyIntegrity(int $limit = 5000): array
    {
        $rows = Database::select(
            'SELECT id, name, disk, storage_path, size, checksum FROM files ORDER BY id DESC LIMIT ' . max(1, $limit)
        );

        $missing = [];

        foreach ($rows as $row) {
            try {
                $disk = \App\Storage\StorageManager::disk((string) $row['disk']);
                $present = $disk->exists((string) $row['storage_path']);
            } catch (\Throwable $e) {
                // An unreachable backend is not proof the file is gone.
                Logger::error('Integrity check skipped a backend: ' . $e->getMessage(), ['disk' => $row['disk']]);
                continue;
            }

            if (!$present) {
                $missing[] = [
                    'id'   => (int) $row['id'],
                    'name' => (string) $row['name'],
                    'path' => (string) $row['storage_path'],
                    'disk' => (string) $row['disk'],
                ];
            }
        }

        $orphans = 0;
        foreach (self::localDisks() as $disk) {
            $slug = $disk->name();
            $known = array_flip(array_column(
                Database::select('SELECT storage_path FROM files WHERE disk = ?', [$slug]),
                'storage_path'
            ));
            $versionPaths = array_flip(array_column(
                Database::select('SELECT storage_path FROM file_versions WHERE disk = ?', [$slug]),
                'storage_path'
            ));
            $root = str_replace('\\', '/', $disk->root());

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::LEAVES_ONLY
            );

            foreach ($iterator as $file) {
                // Dot-files (.gitkeep, editor droppings) are not stored blobs.
                if (!$file instanceof \SplFileInfo || !$file->isFile() || str_starts_with($file->getFilename(), '.')) {
                    continue;
                }

                $relative = ltrim(substr(str_replace('\\', '/', $file->getPathname()), strlen($root)), '/');

                if (!isset($known[$relative]) && !isset($versionPaths[$relative])) {
                    $orphans++;
                }
            }
        }

        Logger::info('Integrity check complete', ['checked' => count($rows), 'missing' => count($missing), 'orphans' => $orphans]);

        return ['checked' => count($rows), 'missing' => $missing, 'orphans' => $orphans];
    }

    /** Delete stored blobs that no database row references. */
    public static function pruneOrphans(): int
    {
        $removed = 0;

        // Only local backends can be walked; remote listings are neither
        // portable nor cheap enough to sweep on a schedule.
        foreach (self::localDisks() as $disk) {
            $slug = $disk->name();
            $known = array_flip(array_column(
                Database::select('SELECT storage_path FROM files WHERE disk = ?', [$slug]),
                'storage_path'
            ));
            $versions = array_flip(array_column(
                Database::select('SELECT storage_path FROM file_versions WHERE disk = ?', [$slug]),
                'storage_path'
            ));
            $root = str_replace('\\', '/', $disk->root());

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::LEAVES_ONLY
            );

            foreach ($iterator as $file) {
                if (!$file instanceof \SplFileInfo || !$file->isFile() || str_starts_with($file->getFilename(), '.')) {
                    continue;
                }

                $relative = ltrim(substr(str_replace('\\', '/', $file->getPathname()), strlen($root)), '/');

                // Files created in the last hour may belong to an in-flight upload.
                if (isset($known[$relative]) || isset($versions[$relative]) || $file->getMTime() > time() - 3600) {
                    continue;
                }

                if (@unlink($file->getPathname())) {
                    $removed++;
                }
            }
        }

        AuditService::system('storage.prune_orphans', "Removed {$removed} orphaned blobs");

        return $removed;
    }

    /**
     * Every configured backend whose bytes live on this machine.
     *
     * @return list<\App\Storage\LocalDriver>
     */
    private static function localDisks(): array
    {
        $slugs = array_column(Database::select('SELECT DISTINCT disk FROM files'), 'disk');
        $slugs[] = \App\Storage\StorageManager::defaultSlug();

        $disks = [];

        foreach (array_unique($slugs) as $slug) {
            try {
                $disk = \App\Storage\StorageManager::disk((string) $slug);
            } catch (\Throwable) {
                continue;
            }

            if ($disk instanceof \App\Storage\LocalDriver && !isset($disks[$disk->root()])) {
                $disks[$disk->root()] = $disk;
            }
        }

        return array_values($disks);
    }
}
