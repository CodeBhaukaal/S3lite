<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

final class MultipartUpload extends Model
{
    protected static string $table = 'multipart_uploads';

    public static function findByUuid(string $uuid): ?array
    {
        return self::findBy('uuid', $uuid);
    }

    public static function parts(int $uploadId): array
    {
        return Database::select(
            'SELECT * FROM multipart_parts WHERE upload_id = ? ORDER BY part_number ASC',
            [$uploadId]
        );
    }

    /** @return list<int> Part numbers already stored — lets clients resume. */
    public static function receivedParts(int $uploadId): array
    {
        return array_map(
            'intval',
            array_column(
                Database::select('SELECT part_number FROM multipart_parts WHERE upload_id = ? ORDER BY part_number', [$uploadId]),
                'part_number'
            )
        );
    }

    public static function recalculate(int $uploadId): void
    {
        $row = Database::selectOne(
            'SELECT COUNT(*) AS parts, COALESCE(SUM(size), 0) AS bytes FROM multipart_parts WHERE upload_id = ?',
            [$uploadId]
        ) ?? ['parts' => 0, 'bytes' => 0];

        Database::statement(
            'UPDATE multipart_uploads SET received_parts = ?, received_bytes = ?, updated_at = NOW() WHERE id = ?',
            [(int) $row['parts'], (int) $row['bytes'], $uploadId]
        );
    }

    public static function publicArray(array $upload): array
    {
        $received = self::receivedParts((int) $upload['id']);

        return [
            'upload_id'      => $upload['uuid'],
            'filename'       => $upload['filename'],
            'mime'           => $upload['mime'],
            'total_size'     => (int) $upload['total_size'],
            'total_parts'    => (int) $upload['total_parts'],
            'received_parts' => $received,
            'received_bytes' => (int) $upload['received_bytes'],
            'missing_parts'  => array_values(array_diff(range(1, max(1, (int) $upload['total_parts'])), $received)),
            'status'         => $upload['status'],
            'storage'        => $upload['disk'] ?? null,
            'progress'       => (int) $upload['total_parts'] > 0
                ? round(count($received) / (int) $upload['total_parts'] * 100, 2)
                : 0.0,
            'expires_at'     => $upload['expires_at'],
            'created_at'     => $upload['created_at'],
        ];
    }
}
