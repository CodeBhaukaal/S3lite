<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Database;
use App\Core\Logger;
use App\Http\Exceptions\HttpException;
use App\Models\FileRecord;
use App\Models\FileVersion;
use App\Models\Folder;
use App\Storage\StorageManager;
use App\Support\Str;

/**
 * All file mutations live here so the API, the web panel and the SFTP sync
 * share exactly one code path (and one audit trail).
 */
final class FileService
{
    /**
     * Store an uploaded/temporary file.
     *
     * @param array{path:string, name:string, size?:int} $upload
     * @param array{folder_id?:?int, tags?:list<string>, source?:string, overwrite_file_id?:int, note?:string} $options
     */
    public static function store(int $userId, array $upload, array $options = []): array
    {
        $startedAt = microtime(true);

        $sourcePath = $upload['path'];
        $originalName = Str::sanitizeFilename($upload['name']);

        if (!is_file($sourcePath)) {
            throw new HttpException(400, 'Upload source file is missing.', 'upload_failed');
        }

        $size = (int) ($upload['size'] ?? filesize($sourcePath));
        $maxSize = (int) SettingService::get('max_upload_size', Config::get('storage.max_upload', 0));

        if ($maxSize > 0 && $size > $maxSize) {
            @unlink($sourcePath);
            throw new HttpException(413, 'File exceeds the maximum upload size of ' . Str::bytes($maxSize) . '.', 'file_too_large');
        }

        $inspection = MimeGuard::inspect($sourcePath, $originalName);

        if (!$inspection['ok']) {
            @unlink($sourcePath);
            AuditService::failure('file.upload_rejected', (string) $inspection['reason'], ['filename' => $originalName]);
            throw new HttpException(415, (string) $inspection['reason'], 'unsupported_file_type');
        }

        $scan = VirusScanner::scan($sourcePath);

        if ($scan['result'] === VirusScanner::INFECTED) {
            @unlink($sourcePath);
            AuditService::failure('file.infected', 'Upload blocked by virus scan', [
                'filename' => $originalName,
                'detail'   => $scan['detail'],
            ]);
            throw new HttpException(422, 'The file was rejected by the virus scanner.', 'malware_detected', ['detail' => $scan['detail']]);
        }

        QuotaService::assert($userId, $size);

        $folderId = $options['folder_id'] ?? null;
        if ($folderId !== null) {
            $folder = Folder::find($folderId);
            if ($folder === null || (int) $folder['user_id'] !== $userId || $folder['deleted_at'] !== null) {
                @unlink($sourcePath);
                throw new HttpException(404, 'Destination folder not found.', 'folder_not_found');
            }
        }

        $checksum = hash_file('sha256', $sourcePath);
        if ($checksum === false) {
            throw new HttpException(500, 'Unable to checksum the uploaded file.', 'checksum_failed');
        }

        // New version of an existing file?
        if (!empty($options['overwrite_file_id'])) {
            return self::storeNewVersion(
                $userId,
                (int) $options['overwrite_file_id'],
                $sourcePath,
                $originalName,
                $size,
                $checksum,
                (string) $inspection['mime'],
                (string) ($options['note'] ?? ''),
                $scan
            );
        }

        // Duplicate detection: identical content already owned by this user.
        $allowDuplicates = (bool) SettingService::get('allow_duplicates', false);
        if (!$allowDuplicates) {
            $existing = FileRecord::duplicateOf($userId, $checksum);

            if ($existing !== null && (int) ($existing['folder_id'] ?? 0) === (int) ($folderId ?? 0)) {
                @unlink($sourcePath);

                AuditService::log('file.duplicate', 'file', (int) $existing['id'], 'Duplicate upload discarded: ' . $originalName, [
                    'checksum' => $checksum,
                ]);

                return [
                    'file'      => $existing,
                    'duplicate' => true,
                    'message'   => 'An identical file already exists; the existing copy was returned.',
                ];
            }
        }

        $disk = StorageManager::disk();
        $storagePath = StorageManager::buildPath($userId, $checksum, (string) $inspection['extension']);

        if (!$disk->put($sourcePath, $storagePath, true)) {
            throw new HttpException(500, 'Failed to write the file to storage.', 'storage_write_failed');
        }

        $name = self::uniqueName($userId, $folderId, $originalName);

        $fileId = FileRecord::create([
            'uuid'          => Str::uuid(),
            'user_id'       => $userId,
            'folder_id'     => $folderId,
            'name'          => $name,
            'original_name' => $originalName,
            'extension'     => (string) $inspection['extension'],
            'mime'          => (string) $inspection['mime'],
            'size'          => $size,
            'checksum'      => $checksum,
            'disk'          => $disk->name(),
            'storage_path'  => $storagePath,
            'version'       => 1,
            'tags'          => array_values(array_filter((array) ($options['tags'] ?? []))),
            'meta'          => self::extractMeta($disk->absolutePath($storagePath), (string) $inspection['mime']),
            'source'        => (string) ($options['source'] ?? 'web'),
            'scanned_at'    => $scan['result'] === VirusScanner::SKIPPED ? null : date('Y-m-d H:i:s'),
            'scan_result'   => $scan['result'],
        ]);

        QuotaService::consume($userId, $size);

        Database::insert('uploads', [
            'user_id'     => $userId,
            'file_id'     => $fileId,
            'filename'    => $name,
            'size'        => $size,
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
            'source'      => (string) ($options['source'] ?? 'web'),
            'ip'          => \App\Core\App::instance()->request()?->ip(),
            'user_agent'  => \App\Core\App::instance()->request()?->userAgent(),
            'created_at'  => date('Y-m-d H:i:s'),
        ]);

        $file = FileRecord::find($fileId) ?? [];

        AuditService::log('file.upload', 'file', $fileId, 'Uploaded ' . $name, [
            'size'      => $size,
            'mime'      => $inspection['mime'],
            'folder_id' => $folderId,
        ]);

        WebhookService::dispatch('file.uploaded', [
            'file_id' => $fileId,
            'uuid'    => $file['uuid'] ?? null,
            'name'    => $name,
            'size'    => $size,
            'mime'    => $inspection['mime'],
        ], $userId);

        return ['file' => $file, 'duplicate' => false, 'message' => 'File uploaded.'];
    }

    private static function storeNewVersion(
        int $userId,
        int $fileId,
        string $sourcePath,
        string $originalName,
        int $size,
        string $checksum,
        string $mime,
        string $note,
        array $scan
    ): array {
        $file = FileRecord::find($fileId);

        if ($file === null || (int) $file['user_id'] !== $userId || $file['deleted_at'] !== null) {
            @unlink($sourcePath);
            throw new HttpException(404, 'File not found.', 'file_not_found');
        }

        $disk = StorageManager::disk();
        $currentVersion = (int) $file['version'];

        // Archive the current content as a version before overwriting.
        $archivePath = StorageManager::versionPath((string) $file['storage_path'], $currentVersion);

        if ($disk->exists((string) $file['storage_path'])) {
            $disk->copy((string) $file['storage_path'], $archivePath);

            FileVersion::create([
                'file_id'      => $fileId,
                'version'      => $currentVersion,
                'size'         => (int) $file['size'],
                'checksum'     => (string) $file['checksum'],
                'storage_path' => $archivePath,
                'mime'         => (string) $file['mime'],
                'created_by'   => $userId,
                'note'         => $note !== '' ? $note : null,
                'created_at'   => date('Y-m-d H:i:s'),
            ]);
        }

        $newPath = StorageManager::buildPath($userId, $checksum, strtolower(pathinfo($originalName, PATHINFO_EXTENSION)));

        if (!$disk->put($sourcePath, $newPath, true)) {
            throw new HttpException(500, 'Failed to write the new version to storage.', 'storage_write_failed');
        }

        $oldPath = (string) $file['storage_path'];

        FileRecord::updateById($fileId, [
            'storage_path' => $newPath,
            'size'         => $size,
            'checksum'     => $checksum,
            'mime'         => $mime,
            'version'      => $currentVersion + 1,
            'scanned_at'   => $scan['result'] === VirusScanner::SKIPPED ? null : date('Y-m-d H:i:s'),
            'scan_result'  => $scan['result'],
        ]);

        if ($oldPath !== $newPath && $oldPath !== $archivePath) {
            $disk->delete($oldPath);
        }

        QuotaService::consume($userId, $size);

        // Retention: drop the oldest versions past the configured limit.
        $keep = (int) SettingService::get('versions_kept', Config::get('storage.versions_kept', 10));
        foreach (FileVersion::prune($fileId, $keep) as $pruned) {
            $disk->delete((string) $pruned['storage_path']);
            QuotaService::release($userId, (int) $pruned['size']);
        }

        AuditService::log('file.version', 'file', $fileId, 'New version of ' . $file['name'], [
            'version' => $currentVersion + 1,
            'size'    => $size,
        ]);

        return [
            'file'      => FileRecord::find($fileId) ?? [],
            'duplicate' => false,
            'message'   => 'New version stored.',
        ];
    }

    public static function restoreVersion(int $userId, int $fileId, int $version): array
    {
        $file = FileRecord::find($fileId);

        if ($file === null || (int) $file['user_id'] !== $userId) {
            throw new HttpException(404, 'File not found.', 'file_not_found');
        }

        $target = FileVersion::findVersion($fileId, $version);

        if ($target === null) {
            throw new HttpException(404, 'Version not found.', 'version_not_found');
        }

        $disk = StorageManager::disk();
        $currentVersion = (int) $file['version'];
        $archivePath = StorageManager::versionPath((string) $file['storage_path'], $currentVersion);

        if ($disk->exists((string) $file['storage_path']) && FileVersion::findVersion($fileId, $currentVersion) === null) {
            $disk->copy((string) $file['storage_path'], $archivePath);

            FileVersion::create([
                'file_id'      => $fileId,
                'version'      => $currentVersion,
                'size'         => (int) $file['size'],
                'checksum'     => (string) $file['checksum'],
                'storage_path' => $archivePath,
                'mime'         => (string) $file['mime'],
                'created_by'   => $userId,
                'note'         => 'Auto-saved before restore',
                'created_at'   => date('Y-m-d H:i:s'),
            ]);
        }

        $restoredPath = StorageManager::buildPath($userId, (string) $target['checksum'], (string) $file['extension']);

        if (!$disk->copy((string) $target['storage_path'], $restoredPath)) {
            throw new HttpException(500, 'Unable to restore that version.', 'restore_failed');
        }

        FileRecord::updateById($fileId, [
            'storage_path' => $restoredPath,
            'size'         => (int) $target['size'],
            'checksum'     => (string) $target['checksum'],
            'mime'         => (string) $target['mime'],
            'version'      => $currentVersion + 1,
        ]);

        QuotaService::recalculate($userId);

        AuditService::log('file.version_restore', 'file', $fileId, "Restored version {$version} of {$file['name']}");

        return FileRecord::find($fileId) ?? [];
    }

    public static function rename(int $userId, int $fileId, string $newName): array
    {
        $file = self::ownedOrFail($userId, $fileId);
        $name = Str::sanitizeFilename($newName);

        if ($name === '') {
            throw new HttpException(422, 'A file name is required.', 'invalid_name');
        }

        $inspection = MimeGuard::inspect(
            (string) (StorageManager::disk()->absolutePath((string) $file['storage_path']) ?? ''),
            $name
        );

        if (!$inspection['ok']) {
            throw new HttpException(415, (string) $inspection['reason'], 'unsupported_file_type');
        }

        $unique = self::uniqueName($userId, $file['folder_id'] === null ? null : (int) $file['folder_id'], $name, $fileId);

        FileRecord::updateById($fileId, ['name' => $unique]);

        AuditService::log('file.rename', 'file', $fileId, "Renamed {$file['name']} to {$unique}");

        return FileRecord::find($fileId) ?? [];
    }

    public static function move(int $userId, int $fileId, ?int $folderId): array
    {
        $file = self::ownedOrFail($userId, $fileId);

        if ($folderId !== null) {
            $folder = Folder::find($folderId);
            if ($folder === null || (int) $folder['user_id'] !== $userId || $folder['deleted_at'] !== null) {
                throw new HttpException(404, 'Destination folder not found.', 'folder_not_found');
            }
        }

        $name = self::uniqueName($userId, $folderId, (string) $file['name'], $fileId);

        FileRecord::updateById($fileId, ['folder_id' => $folderId, 'name' => $name]);

        AuditService::log('file.move', 'file', $fileId, "Moved {$file['name']}", ['folder_id' => $folderId]);

        return FileRecord::find($fileId) ?? [];
    }

    public static function copy(int $userId, int $fileId, ?int $folderId = null): array
    {
        $file = self::ownedOrFail($userId, $fileId);

        QuotaService::assert($userId, (int) $file['size']);

        $disk = StorageManager::disk();
        $newPath = StorageManager::buildPath($userId, (string) $file['checksum'], (string) $file['extension']);

        if (!$disk->copy((string) $file['storage_path'], $newPath)) {
            throw new HttpException(500, 'Unable to copy the file.', 'copy_failed');
        }

        $targetFolder = $folderId ?? ($file['folder_id'] === null ? null : (int) $file['folder_id']);
        $name = self::uniqueName($userId, $targetFolder, self::copySuffix((string) $file['name']));

        $newId = FileRecord::create([
            'uuid'          => Str::uuid(),
            'user_id'       => $userId,
            'folder_id'     => $targetFolder,
            'name'          => $name,
            'original_name' => $file['original_name'],
            'extension'     => $file['extension'],
            'mime'          => $file['mime'],
            'size'          => (int) $file['size'],
            'checksum'      => $file['checksum'],
            'disk'          => $disk->name(),
            'storage_path'  => $newPath,
            'version'       => 1,
            'tags'          => $file['tags'] ?: [],
            'meta'          => $file['meta'] ?: [],
            'source'        => 'copy',
        ]);

        QuotaService::consume($userId, (int) $file['size']);

        AuditService::log('file.copy', 'file', $newId, "Copied {$file['name']} to {$name}");

        return FileRecord::find($newId) ?? [];
    }

    /** Soft delete (moves to trash). */
    public static function trash(int $userId, int $fileId): void
    {
        $file = self::ownedOrFail($userId, $fileId);

        FileRecord::softDelete($fileId);
        Database::statement('UPDATE shares SET is_active = 0 WHERE file_id = ?', [$fileId]);

        AuditService::log('file.trash', 'file', $fileId, "Moved {$file['name']} to trash", ['size' => (int) $file['size']]);

        WebhookService::dispatch('file.deleted', [
            'file_id'   => $fileId,
            'name'      => $file['name'],
            'permanent' => false,
        ], $userId);
    }

    public static function restore(int $userId, int $fileId): array
    {
        $file = FileRecord::find($fileId);

        if ($file === null || (int) $file['user_id'] !== $userId) {
            throw new HttpException(404, 'File not found.', 'file_not_found');
        }

        if ($file['deleted_at'] === null) {
            return $file;
        }

        // The original folder may itself have been trashed; fall back to root.
        $folderId = $file['folder_id'] === null ? null : (int) $file['folder_id'];
        if ($folderId !== null) {
            $folder = Folder::find($folderId);
            if ($folder === null || $folder['deleted_at'] !== null) {
                $folderId = null;
            }
        }

        FileRecord::updateById($fileId, [
            'deleted_at' => null,
            'folder_id'  => $folderId,
            'name'       => self::uniqueName($userId, $folderId, (string) $file['name'], $fileId),
        ]);

        AuditService::log('file.restore', 'file', $fileId, "Restored {$file['name']} from trash");
        WebhookService::dispatch('file.restored', ['file_id' => $fileId, 'name' => $file['name']], $userId);

        return FileRecord::find($fileId) ?? [];
    }

    /** Permanently remove the record, its versions and its bytes. */
    public static function purge(int $userId, int $fileId): void
    {
        $file = FileRecord::find($fileId);

        if ($file === null || (int) $file['user_id'] !== $userId) {
            throw new HttpException(404, 'File not found.', 'file_not_found');
        }

        $disk = StorageManager::disk();
        $freed = (int) $file['size'];

        foreach (FileVersion::forFile($fileId) as $version) {
            $disk->delete((string) $version['storage_path']);
            $freed += (int) $version['size'];
        }

        // Other records may share the same bytes; only unlink when unreferenced.
        $shared = (int) Database::scalar(
            'SELECT COUNT(*) FROM files WHERE storage_path = ? AND id != ?',
            [$file['storage_path'], $fileId]
        );

        if ($shared === 0) {
            $disk->delete((string) $file['storage_path']);
        }

        FileRecord::deleteById($fileId);
        QuotaService::release($userId, $freed);

        AuditService::log('file.purge', 'file', $fileId, "Permanently deleted {$file['name']}", ['freed' => $freed]);

        WebhookService::dispatch('file.deleted', [
            'file_id'   => $fileId,
            'name'      => $file['name'],
            'permanent' => true,
        ], $userId);
    }

    public static function emptyTrash(int $userId): int
    {
        $files = FileRecord::where(['user_id' => $userId, 'deleted_at' => ['not null', null]], 'id DESC', 5000);
        $count = 0;

        foreach ($files as $file) {
            try {
                self::purge($userId, (int) $file['id']);
                $count++;
            } catch (\Throwable $e) {
                Logger::error('Failed to purge file: ' . $e->getMessage(), ['file_id' => $file['id']]);
            }
        }

        AuditService::log('trash.empty', 'user', $userId, "Emptied trash ({$count} files)");

        return $count;
    }

    public static function setTags(int $userId, int $fileId, array $tags): array
    {
        self::ownedOrFail($userId, $fileId);

        $clean = [];
        foreach ($tags as $tag) {
            $tag = trim((string) $tag);
            if ($tag !== '' && mb_strlen($tag) <= 40) {
                $clean[] = $tag;
            }
        }

        FileRecord::updateById($fileId, ['tags' => array_values(array_unique($clean))]);

        AuditService::log('file.tags', 'file', $fileId, 'Updated tags', ['tags' => $clean]);

        return FileRecord::find($fileId) ?? [];
    }

    /** A name that does not collide within the destination folder. */
    public static function uniqueName(int $userId, ?int $folderId, string $name, ?int $ignoreId = null): string
    {
        $base = pathinfo($name, PATHINFO_FILENAME);
        $ext = pathinfo($name, PATHINFO_EXTENSION);
        $suffix = $ext === '' ? '' : '.' . $ext;

        $candidate = $name;
        $counter = 1;

        while (self::nameTaken($userId, $folderId, $candidate, $ignoreId)) {
            $candidate = $base . ' (' . $counter . ')' . $suffix;
            $counter++;

            if ($counter > 999) {
                $candidate = $base . '-' . Str::random(6) . $suffix;
                break;
            }
        }

        return $candidate;
    }

    private static function nameTaken(int $userId, ?int $folderId, string $name, ?int $ignoreId): bool
    {
        $sql = 'SELECT COUNT(*) FROM files WHERE user_id = ? AND name = ? AND deleted_at IS NULL AND ';
        $sql .= $folderId === null ? 'folder_id IS NULL' : 'folder_id = ?';

        $bindings = [$userId, $name];
        if ($folderId !== null) {
            $bindings[] = $folderId;
        }

        if ($ignoreId !== null) {
            $sql .= ' AND id != ?';
            $bindings[] = $ignoreId;
        }

        return (int) Database::scalar($sql, $bindings) > 0;
    }

    private static function copySuffix(string $name): string
    {
        $base = pathinfo($name, PATHINFO_FILENAME);
        $ext = pathinfo($name, PATHINFO_EXTENSION);

        return $base . ' (copy)' . ($ext === '' ? '' : '.' . $ext);
    }

    private static function ownedOrFail(int $userId, int $fileId): array
    {
        $file = FileRecord::find($fileId);

        if ($file === null || (int) $file['user_id'] !== $userId || $file['deleted_at'] !== null) {
            throw new HttpException(404, 'File not found.', 'file_not_found');
        }

        return $file;
    }

    /** Lightweight metadata extraction (no GD required). */
    private static function extractMeta(?string $absolutePath, string $mime): array
    {
        $meta = [];

        if ($absolutePath === null || !is_file($absolutePath)) {
            return $meta;
        }

        if (str_starts_with($mime, 'image/')) {
            $info = @getimagesize($absolutePath);
            if (is_array($info)) {
                $meta['width'] = (int) $info[0];
                $meta['height'] = (int) $info[1];
            }

            if (in_array($mime, ['image/jpeg', 'image/tiff'], true) && function_exists('exif_read_data')) {
                $exif = @exif_read_data($absolutePath);
                if (is_array($exif)) {
                    $meta['camera'] = $exif['Model'] ?? null;
                    $meta['taken_at'] = $exif['DateTimeOriginal'] ?? null;
                    $meta = array_filter($meta, static fn ($v) => $v !== null);
                }
            }
        }

        if ($mime === 'application/pdf') {
            $head = (string) @file_get_contents($absolutePath, false, null, 0, 4096);
            if (preg_match('/\/Count\s+(\d+)/', $head, $m) === 1) {
                $meta['pages'] = (int) $m[1];
            }
        }

        if (str_starts_with($mime, 'text/') || $mime === 'application/json') {
            $meta['lines'] = @count(file($absolutePath) ?: []);
        }

        return $meta;
    }

    /** Trash items older than the retention window. */
    public static function purgeExpiredTrash(?int $days = null): int
    {
        $days ??= (int) SettingService::get('trash_retention_days', Config::get('storage.trash_retention_days', 30));

        if ($days <= 0) {
            return 0;
        }

        $rows = Database::select(
            'SELECT id, user_id FROM files WHERE deleted_at IS NOT NULL AND deleted_at < DATE_SUB(NOW(), INTERVAL ? DAY) LIMIT 1000',
            [$days]
        );

        $count = 0;
        foreach ($rows as $row) {
            try {
                self::purge((int) $row['user_id'], (int) $row['id']);
                $count++;
            } catch (\Throwable $e) {
                Logger::error('Trash purge failed: ' . $e->getMessage(), ['file_id' => $row['id']]);
            }
        }

        return $count;
    }
}
