<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

final class FileVersion extends Model
{
    protected static string $table = 'file_versions';
    protected static bool $timestamps = false;

    public static function forFile(int $fileId): array
    {
        return Database::select(
            'SELECT v.*, u.name AS author_name
             FROM file_versions v
             LEFT JOIN users u ON u.id = v.created_by
             WHERE v.file_id = ?
             ORDER BY v.version DESC',
            [$fileId]
        );
    }

    public static function findVersion(int $fileId, int $version): ?array
    {
        return self::findWhere(['file_id' => $fileId, 'version' => $version]);
    }

    /** Drop the oldest versions beyond the retention limit; returns removed rows. */
    public static function prune(int $fileId, int $keep): array
    {
        $versions = Database::select(
            'SELECT * FROM file_versions WHERE file_id = ? ORDER BY version DESC',
            [$fileId]
        );

        if (count($versions) <= $keep) {
            return [];
        }

        $removed = array_slice($versions, $keep);

        foreach ($removed as $version) {
            self::deleteById((int) $version['id']);
        }

        return $removed;
    }

    public static function publicArray(array $version): array
    {
        return [
            'id'         => (int) $version['id'],
            'version'    => (int) $version['version'],
            'size'       => (int) $version['size'],
            'checksum'   => $version['checksum'],
            'mime'       => $version['mime'],
            'note'       => $version['note'],
            'author'     => $version['author_name'] ?? null,
            'created_at' => $version['created_at'],
        ];
    }
}
