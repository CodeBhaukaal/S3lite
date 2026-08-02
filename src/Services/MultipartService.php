<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Database;
use App\Http\Exceptions\HttpException;
use App\Models\MultipartUpload;
use App\Support\Str;

/**
 * Chunked + resumable uploads. Parts land in storage/chunks and are assembled
 * on completion, so an interrupted transfer only replays its missing parts.
 */
final class MultipartService
{
    public static function init(int $userId, string $filename, int $totalSize, ?int $folderId = null, ?string $mime = null, ?int $partSize = null, ?string $disk = null): array
    {
        $filename = Str::sanitizeFilename($filename);

        if ($totalSize <= 0) {
            throw new HttpException(422, 'total_size must be greater than zero.', 'invalid_size');
        }

        $maxSize = (int) SettingService::get('max_upload_size', Config::get('storage.max_upload', 0));
        if ($maxSize > 0 && $totalSize > $maxSize) {
            throw new HttpException(413, 'File exceeds the maximum upload size of ' . Str::bytes($maxSize) . '.', 'file_too_large');
        }

        QuotaService::assert($userId, $totalSize);

        $inspection = MimeGuard::inspect('', $filename);
        if (!$inspection['ok']) {
            throw new HttpException(415, (string) $inspection['reason'], 'unsupported_file_type');
        }

        if ($folderId !== null) {
            $folder = \App\Models\Folder::find($folderId);
            if ($folder === null || (int) $folder['user_id'] !== $userId || $folder['deleted_at'] !== null) {
                throw new HttpException(404, 'Destination folder not found.', 'folder_not_found');
            }
        }

        $partSize = $partSize !== null && $partSize > 0
            ? $partSize
            : (int) SettingService::get('chunk_size', Config::get('storage.chunk_size', 8388608));

        $totalParts = (int) max(1, ceil($totalSize / $partSize));

        // Resolve the destination now so an unknown backend fails before the
        // client spends an hour uploading parts.
        $disk = StorageBackendService::resolveForUpload($disk);

        $uuid = Str::uuid();

        $id = MultipartUpload::create([
            'uuid'        => $uuid,
            'user_id'     => $userId,
            'folder_id'   => $folderId,
            'disk'        => $disk,
            'filename'    => $filename,
            'mime'        => $mime ?: 'application/octet-stream',
            'total_size'  => $totalSize,
            'total_parts' => $totalParts,
            'status'      => 'pending',
            'expires_at'  => date('Y-m-d H:i:s', time() + 86400),
        ]);

        @mkdir(self::chunkDir($uuid), 0775, true);

        AuditService::log('upload.multipart_init', 'upload', $id, "Started multipart upload of {$filename}", [
            'total_size'  => $totalSize,
            'total_parts' => $totalParts,
        ]);

        $upload = MultipartUpload::find($id) ?? [];

        return MultipartUpload::publicArray($upload) + ['part_size' => $partSize];
    }

    /**
     * @param array{path:string, size?:int} $part
     */
    public static function uploadPart(int $userId, string $uploadUuid, int $partNumber, array $part): array
    {
        $upload = self::ownedOrFail($userId, $uploadUuid);

        if ($upload['status'] !== 'pending') {
            throw new HttpException(409, 'This upload is no longer accepting parts.', 'upload_not_pending');
        }

        if ($partNumber < 1 || $partNumber > (int) $upload['total_parts']) {
            throw new HttpException(422, 'part_number is out of range.', 'invalid_part_number');
        }

        if (!is_file($part['path'])) {
            throw new HttpException(400, 'No part data was received.', 'missing_part_data');
        }

        $size = (int) ($part['size'] ?? filesize($part['path']));
        $target = self::chunkDir((string) $upload['uuid']) . '/part-' . str_pad((string) $partNumber, 6, '0', STR_PAD_LEFT);

        if (!@rename($part['path'], $target) && !@copy($part['path'], $target)) {
            throw new HttpException(500, 'Unable to persist the uploaded part.', 'part_write_failed');
        }

        @unlink($part['path']);

        $checksum = hash_file('sha256', $target) ?: null;

        Database::statement(
            'INSERT INTO multipart_parts (upload_id, part_number, size, checksum, path, created_at)
             VALUES (?, ?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE size = VALUES(size), checksum = VALUES(checksum), path = VALUES(path)',
            [(int) $upload['id'], $partNumber, $size, $checksum, $target]
        );

        MultipartUpload::recalculate((int) $upload['id']);

        return MultipartUpload::publicArray(MultipartUpload::find((int) $upload['id']) ?? []);
    }

    public static function status(int $userId, string $uploadUuid): array
    {
        return MultipartUpload::publicArray(self::ownedOrFail($userId, $uploadUuid));
    }

    public static function complete(int $userId, string $uploadUuid, array $options = []): array
    {
        $upload = self::ownedOrFail($userId, $uploadUuid);

        if ($upload['status'] === 'completed' && $upload['file_id'] !== null) {
            return [
                'file'      => \App\Models\FileRecord::find((int) $upload['file_id']) ?? [],
                'duplicate' => false,
                'message'   => 'Upload already completed.',
            ];
        }

        $received = MultipartUpload::receivedParts((int) $upload['id']);
        $expected = range(1, (int) $upload['total_parts']);
        $missing = array_values(array_diff($expected, $received));

        if ($missing !== []) {
            throw new HttpException(409, 'Some parts are still missing.', 'incomplete_upload', ['missing_parts' => $missing]);
        }

        $assembled = Config::get('storage.tmp_path') . '/assembled-' . $upload['uuid'] . '.tmp';
        $out = @fopen($assembled, 'wb');

        if ($out === false) {
            throw new HttpException(500, 'Unable to assemble the upload.', 'assembly_failed');
        }

        foreach (MultipartUpload::parts((int) $upload['id']) as $part) {
            $in = @fopen((string) $part['path'], 'rb');

            if ($in === false) {
                fclose($out);
                @unlink($assembled);
                throw new HttpException(500, 'A part is missing from temporary storage.', 'assembly_failed');
            }

            stream_copy_to_stream($in, $out);
            fclose($in);
        }

        fclose($out);

        $actualSize = (int) filesize($assembled);
        $expectedChecksum = $options['checksum'] ?? null;

        if (is_string($expectedChecksum) && $expectedChecksum !== '') {
            $actual = hash_file('sha256', $assembled);
            if ($actual !== $expectedChecksum) {
                @unlink($assembled);
                MultipartUpload::updateById((int) $upload['id'], ['status' => 'failed']);
                throw new HttpException(422, 'Checksum mismatch — the assembled file does not match the expected hash.', 'checksum_mismatch', [
                    'expected' => $expectedChecksum,
                    'actual'   => $actual,
                ]);
            }
        }

        try {
            $result = FileService::store($userId, [
                'path' => $assembled,
                'name' => (string) $upload['filename'],
                'size' => $actualSize,
            ], [
                'folder_id' => $upload['folder_id'] === null ? null : (int) $upload['folder_id'],
                'tags'      => (array) ($options['tags'] ?? []),
                'source'    => 'api',
                'disk'      => $upload['disk'] ?: null,
            ]);
        } catch (\Throwable $e) {
            @unlink($assembled);
            MultipartUpload::updateById((int) $upload['id'], ['status' => 'failed']);
            throw $e;
        }

        MultipartUpload::updateById((int) $upload['id'], [
            'status'  => 'completed',
            'file_id' => (int) ($result['file']['id'] ?? 0),
        ]);

        self::cleanupChunks((string) $upload['uuid']);

        AuditService::log('upload.multipart_complete', 'file', (int) ($result['file']['id'] ?? 0), "Completed multipart upload of {$upload['filename']}");

        return $result;
    }

    public static function abort(int $userId, string $uploadUuid): void
    {
        $upload = self::ownedOrFail($userId, $uploadUuid);

        MultipartUpload::updateById((int) $upload['id'], ['status' => 'aborted']);
        self::cleanupChunks((string) $upload['uuid']);

        AuditService::log('upload.multipart_abort', 'upload', (int) $upload['id'], "Aborted multipart upload of {$upload['filename']}");
    }

    public static function listPending(int $userId): array
    {
        $rows = MultipartUpload::where(['user_id' => $userId, 'status' => 'pending'], 'id DESC', 50);

        return array_map([MultipartUpload::class, 'publicArray'], $rows);
    }

    /** Drop abandoned uploads and their chunks. */
    public static function purgeExpired(): int
    {
        $rows = Database::select(
            "SELECT * FROM multipart_uploads WHERE status = 'pending' AND expires_at < NOW() LIMIT 500"
        );

        foreach ($rows as $row) {
            MultipartUpload::updateById((int) $row['id'], ['status' => 'aborted']);
            self::cleanupChunks((string) $row['uuid']);
        }

        Database::statement(
            "DELETE FROM multipart_uploads WHERE status IN ('aborted','failed','completed') AND updated_at < DATE_SUB(NOW(), INTERVAL 7 DAY)"
        );

        return count($rows);
    }

    private static function chunkDir(string $uuid): string
    {
        return rtrim((string) Config::get('storage.chunk_path'), '/\\') . '/' . $uuid;
    }

    private static function cleanupChunks(string $uuid): void
    {
        $dir = self::chunkDir($uuid);

        foreach (glob($dir . '/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($dir);
    }

    private static function ownedOrFail(int $userId, string $uuid): array
    {
        $upload = MultipartUpload::findByUuid($uuid);

        if ($upload === null || (int) $upload['user_id'] !== $userId) {
            throw new HttpException(404, 'Upload session not found.', 'upload_not_found');
        }

        return $upload;
    }
}
