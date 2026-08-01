<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Http\Exceptions\HttpException;
use App\Models\FileRecord;
use App\Models\Folder;
use App\Support\Str;

final class FolderService
{
    public static function create(int $userId, string $name, ?int $parentId = null, ?string $color = null): array
    {
        $name = self::sanitizeName($name);

        if ($parentId !== null) {
            $parent = Folder::find($parentId);
            if ($parent === null || (int) $parent['user_id'] !== $userId || $parent['deleted_at'] !== null) {
                throw new HttpException(404, 'Parent folder not found.', 'folder_not_found');
            }
        }

        if (self::nameTaken($userId, $parentId, $name)) {
            throw new HttpException(409, 'A folder with that name already exists here.', 'folder_exists');
        }

        $id = Folder::create([
            'uuid'      => Str::uuid(),
            'user_id'   => $userId,
            'parent_id' => $parentId,
            'name'      => $name,
            'path'      => Folder::computePath($userId, $parentId, $name),
            'color'     => $color,
        ]);

        AuditService::log('folder.create', 'folder', $id, 'Created folder ' . $name, ['parent_id' => $parentId]);

        return Folder::find($id) ?? [];
    }

    public static function rename(int $userId, int $folderId, string $name): array
    {
        $folder = self::ownedOrFail($userId, $folderId);
        $name = self::sanitizeName($name);

        $parentId = $folder['parent_id'] === null ? null : (int) $folder['parent_id'];

        if (self::nameTaken($userId, $parentId, $name, $folderId)) {
            throw new HttpException(409, 'A folder with that name already exists here.', 'folder_exists');
        }

        Folder::updateById($folderId, [
            'name' => $name,
            'path' => Folder::computePath($userId, $parentId, $name),
        ]);

        Folder::refreshPaths($folderId);

        AuditService::log('folder.rename', 'folder', $folderId, "Renamed folder {$folder['name']} to {$name}");

        return Folder::find($folderId) ?? [];
    }

    public static function move(int $userId, int $folderId, ?int $newParentId): array
    {
        $folder = self::ownedOrFail($userId, $folderId);

        if ($newParentId !== null) {
            if ($newParentId === $folderId) {
                throw new HttpException(422, 'A folder cannot be moved into itself.', 'invalid_move');
            }

            $parent = Folder::find($newParentId);
            if ($parent === null || (int) $parent['user_id'] !== $userId || $parent['deleted_at'] !== null) {
                throw new HttpException(404, 'Destination folder not found.', 'folder_not_found');
            }

            if (Folder::isDescendantOf($newParentId, $folderId)) {
                throw new HttpException(422, 'A folder cannot be moved inside one of its own subfolders.', 'invalid_move');
            }
        }

        if (self::nameTaken($userId, $newParentId, (string) $folder['name'], $folderId)) {
            throw new HttpException(409, 'A folder with that name already exists in the destination.', 'folder_exists');
        }

        Folder::updateById($folderId, [
            'parent_id' => $newParentId,
            'path'      => Folder::computePath($userId, $newParentId, (string) $folder['name']),
        ]);

        Folder::refreshPaths($folderId);

        AuditService::log('folder.move', 'folder', $folderId, "Moved folder {$folder['name']}", ['parent_id' => $newParentId]);

        return Folder::find($folderId) ?? [];
    }

    /** Trash a folder together with everything inside it. */
    public static function trash(int $userId, int $folderId): array
    {
        $folder = self::ownedOrFail($userId, $folderId);
        $ids = Folder::descendantIds($folderId);

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $now = date('Y-m-d H:i:s');

        $fileCount = Database::statement(
            "UPDATE files SET deleted_at = ? WHERE folder_id IN ({$placeholders}) AND deleted_at IS NULL",
            array_merge([$now], $ids)
        );

        Database::statement(
            "UPDATE folders SET deleted_at = ? WHERE id IN ({$placeholders}) AND deleted_at IS NULL",
            array_merge([$now], $ids)
        );

        Database::statement(
            "UPDATE shares SET is_active = 0 WHERE folder_id IN ({$placeholders})",
            $ids
        );

        AuditService::log('folder.trash', 'folder', $folderId, "Moved folder {$folder['name']} to trash", [
            'folders' => count($ids),
            'files'   => $fileCount,
        ]);

        return ['folders' => count($ids), 'files' => $fileCount];
    }

    public static function restore(int $userId, int $folderId): array
    {
        $folder = Folder::find($folderId);

        if ($folder === null || (int) $folder['user_id'] !== $userId) {
            throw new HttpException(404, 'Folder not found.', 'folder_not_found');
        }

        $ids = Folder::descendantIds($folderId);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        Database::statement("UPDATE folders SET deleted_at = NULL WHERE id IN ({$placeholders})", $ids);
        $files = Database::statement("UPDATE files SET deleted_at = NULL WHERE folder_id IN ({$placeholders})", $ids);

        // Restoring a child requires its ancestors to exist too.
        $parentId = $folder['parent_id'] === null ? null : (int) $folder['parent_id'];
        while ($parentId !== null) {
            $parent = Folder::find($parentId);
            if ($parent === null) {
                break;
            }
            if ($parent['deleted_at'] !== null) {
                Folder::updateById($parentId, ['deleted_at' => null]);
            }
            $parentId = $parent['parent_id'] === null ? null : (int) $parent['parent_id'];
        }

        AuditService::log('folder.restore', 'folder', $folderId, "Restored folder {$folder['name']}");

        return ['folders' => count($ids), 'files' => $files];
    }

    /** Permanently delete a folder tree and all bytes below it. */
    public static function purge(int $userId, int $folderId): array
    {
        $folder = Folder::find($folderId);

        if ($folder === null || (int) $folder['user_id'] !== $userId) {
            throw new HttpException(404, 'Folder not found.', 'folder_not_found');
        }

        $ids = Folder::descendantIds($folderId);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        $files = Database::select(
            "SELECT id FROM files WHERE folder_id IN ({$placeholders})",
            $ids
        );

        foreach ($files as $file) {
            FileService::purge($userId, (int) $file['id']);
        }

        // Deepest first so foreign keys stay satisfied.
        foreach (array_reverse($ids) as $id) {
            Folder::deleteById($id);
        }

        AuditService::log('folder.purge', 'folder', $folderId, "Permanently deleted folder {$folder['name']}", [
            'folders' => count($ids),
            'files'   => count($files),
        ]);

        return ['folders' => count($ids), 'files' => count($files)];
    }

    /**
     * @return array{folders:list<array>, files:array, breadcrumbs:list<array>, folder:?array}
     */
    public static function browse(int $userId, ?int $folderId, array $filters = [], int $page = 1, int $perPage = 24, string $sort = 'created_at', string $direction = 'desc'): array
    {
        $folder = null;

        if ($folderId !== null) {
            $folder = Folder::find($folderId);
            if ($folder === null || (int) $folder['user_id'] !== $userId || $folder['deleted_at'] !== null) {
                throw new HttpException(404, 'Folder not found.', 'folder_not_found');
            }
        }

        $searching = ($filters['q'] ?? '') !== '' || !empty($filters['tag']) || !empty($filters['mime']);

        $files = FileRecord::search(
            array_merge($filters, ['user_id' => $userId, 'folder_id' => $folderId]),
            $page,
            $perPage,
            $sort,
            $direction
        );

        return [
            'folder'      => $folder,
            'folders'     => $searching ? [] : Folder::children($userId, $folderId),
            'files'       => $files,
            'breadcrumbs' => Folder::breadcrumbs($folderId),
        ];
    }

    /** @return list<array{id:int,name:string,depth:int}> Flattened tree for pickers. */
    public static function tree(int $userId, ?int $parentId = null, int $depth = 0): array
    {
        if ($depth > 12) {
            return [];
        }

        $out = [];

        foreach (Folder::children($userId, $parentId) as $folder) {
            $out[] = [
                'id'    => (int) $folder['id'],
                'uuid'  => $folder['uuid'],
                'name'  => $folder['name'],
                'depth' => $depth,
            ];

            foreach (self::tree($userId, (int) $folder['id'], $depth + 1) as $child) {
                $out[] = $child;
            }
        }

        return $out;
    }

    private static function sanitizeName(string $name): string
    {
        $name = trim(str_replace(['/', '\\', "\0"], '', $name));
        $name = preg_replace('/[<>:"|?*\x00-\x1F]/', '', $name) ?? '';
        $name = trim($name, '. ');

        if ($name === '') {
            throw new HttpException(422, 'A folder name is required.', 'invalid_name');
        }

        return mb_substr($name, 0, 120);
    }

    private static function nameTaken(int $userId, ?int $parentId, string $name, ?int $ignoreId = null): bool
    {
        $sql = 'SELECT COUNT(*) FROM folders WHERE user_id = ? AND name = ? AND deleted_at IS NULL AND ';
        $sql .= $parentId === null ? 'parent_id IS NULL' : 'parent_id = ?';

        $bindings = [$userId, $name];
        if ($parentId !== null) {
            $bindings[] = $parentId;
        }

        if ($ignoreId !== null) {
            $sql .= ' AND id != ?';
            $bindings[] = $ignoreId;
        }

        return (int) Database::scalar($sql, $bindings) > 0;
    }

    private static function ownedOrFail(int $userId, int $folderId): array
    {
        $folder = Folder::find($folderId);

        if ($folder === null || (int) $folder['user_id'] !== $userId || $folder['deleted_at'] !== null) {
            throw new HttpException(404, 'Folder not found.', 'folder_not_found');
        }

        return $folder;
    }
}
