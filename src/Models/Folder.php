<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

final class Folder extends Model
{
    protected static string $table = 'folders';
    protected static bool $softDeletes = true;

    public static function findByUuid(string $uuid): ?array
    {
        return self::findBy('uuid', $uuid);
    }

    public static function resolve(string $identifier): ?array
    {
        if ($identifier === '' || $identifier === 'root' || $identifier === '0') {
            return null;
        }

        return ctype_digit($identifier) ? self::find((int) $identifier) : self::findByUuid($identifier);
    }

    public static function ownedBy(string $identifier, int $userId): ?array
    {
        $folder = self::resolve($identifier);

        if ($folder === null || (int) $folder['user_id'] !== $userId || $folder['deleted_at'] !== null) {
            return null;
        }

        return $folder;
    }

    public static function children(int $userId, ?int $parentId): array
    {
        if ($parentId === null) {
            return Database::select(
                'SELECT f.*,
                        (SELECT COUNT(*) FROM files x WHERE x.folder_id = f.id AND x.deleted_at IS NULL) AS file_count,
                        (SELECT COUNT(*) FROM folders c WHERE c.parent_id = f.id AND c.deleted_at IS NULL) AS folder_count
                 FROM folders f
                 WHERE f.user_id = ? AND f.parent_id IS NULL AND f.deleted_at IS NULL
                 ORDER BY f.name',
                [$userId]
            );
        }

        return Database::select(
            'SELECT f.*,
                    (SELECT COUNT(*) FROM files x WHERE x.folder_id = f.id AND x.deleted_at IS NULL) AS file_count,
                    (SELECT COUNT(*) FROM folders c WHERE c.parent_id = f.id AND c.deleted_at IS NULL) AS folder_count
             FROM folders f
             WHERE f.user_id = ? AND f.parent_id = ? AND f.deleted_at IS NULL
             ORDER BY f.name',
            [$userId, $parentId]
        );
    }

    /** @return list<array{id:int,name:string,uuid:string}> Root-first breadcrumb chain. */
    public static function breadcrumbs(?int $folderId): array
    {
        $trail = [];
        $guard = 0;

        while ($folderId !== null && $guard++ < 64) {
            $folder = self::find($folderId);
            if ($folder === null) {
                break;
            }

            array_unshift($trail, [
                'id'   => (int) $folder['id'],
                'uuid' => $folder['uuid'],
                'name' => $folder['name'],
            ]);

            $folderId = $folder['parent_id'] === null ? null : (int) $folder['parent_id'];
        }

        return $trail;
    }

    public static function computePath(int $userId, ?int $parentId, string $name): string
    {
        if ($parentId === null) {
            return '/' . $name;
        }

        $parent = self::find($parentId);
        $base = $parent === null ? '' : rtrim((string) $parent['path'], '/');

        return $base . '/' . $name;
    }

    /** @return list<int> The folder and every descendant id. */
    public static function descendantIds(int $folderId): array
    {
        $ids = [$folderId];
        $queue = [$folderId];
        $guard = 0;

        while ($queue !== [] && $guard++ < 10000) {
            $current = array_shift($queue);
            $children = Database::select('SELECT id FROM folders WHERE parent_id = ?', [$current]);

            foreach ($children as $child) {
                $id = (int) $child['id'];
                if (!in_array($id, $ids, true)) {
                    $ids[] = $id;
                    $queue[] = $id;
                }
            }
        }

        return $ids;
    }

    /** Guard against moving a folder inside one of its own descendants. */
    public static function isDescendantOf(int $candidate, int $ancestor): bool
    {
        return in_array($candidate, self::descendantIds($ancestor), true);
    }

    public static function size(int $folderId): int
    {
        $ids = self::descendantIds($folderId);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        return (int) Database::scalar(
            "SELECT COALESCE(SUM(size), 0) FROM files WHERE folder_id IN ({$placeholders}) AND deleted_at IS NULL",
            $ids
        );
    }

    public static function refreshPaths(int $folderId): void
    {
        $folder = self::find($folderId);
        if ($folder === null) {
            return;
        }

        $path = self::computePath((int) $folder['user_id'], $folder['parent_id'] === null ? null : (int) $folder['parent_id'], (string) $folder['name']);
        self::updateById($folderId, ['path' => $path]);

        foreach (Database::select('SELECT id FROM folders WHERE parent_id = ?', [$folderId]) as $child) {
            self::refreshPaths((int) $child['id']);
        }
    }

    public static function publicArray(array $folder): array
    {
        return [
            'id'           => (int) $folder['id'],
            'uuid'         => $folder['uuid'],
            'name'         => $folder['name'],
            'path'         => $folder['path'],
            'parent_id'    => $folder['parent_id'] === null ? null : (int) $folder['parent_id'],
            'color'        => $folder['color'],
            'file_count'   => isset($folder['file_count']) ? (int) $folder['file_count'] : null,
            'folder_count' => isset($folder['folder_count']) ? (int) $folder['folder_count'] : null,
            'created_at'   => $folder['created_at'],
            'updated_at'   => $folder['updated_at'],
        ];
    }
}
