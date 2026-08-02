<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Config;
use App\Core\Database;

final class FileRecord extends Model
{
    protected static string $table = 'files';
    protected static array $jsonColumns = ['tags', 'meta'];
    protected static bool $softDeletes = true;

    public static function findByUuid(string $uuid): ?array
    {
        return self::findBy('uuid', $uuid);
    }

    /** Resolve by numeric id or uuid — API callers may use either. */
    public static function resolve(string $identifier): ?array
    {
        if (ctype_digit($identifier)) {
            return self::find((int) $identifier);
        }

        return self::findByUuid($identifier);
    }

    public static function ownedBy(string $identifier, int $userId, bool $includeTrashed = false): ?array
    {
        $file = self::resolve($identifier);

        if ($file === null || (int) $file['user_id'] !== $userId) {
            return null;
        }

        if (!$includeTrashed && $file['deleted_at'] !== null) {
            return null;
        }

        return $file;
    }

    public static function duplicateOf(int $userId, string $checksum): ?array
    {
        return self::findWhere([
            'user_id'    => $userId,
            'checksum'   => $checksum,
            'deleted_at' => null,
        ]);
    }

    public static function incrementDownloads(int $fileId): void
    {
        Database::statement('UPDATE files SET download_count = download_count + 1 WHERE id = ?', [$fileId]);
    }

    /**
     * Search/browse query used by both the panel and the API.
     *
     * @param array{
     *   user_id?:int, folder_id?:int|null, q?:string, mime?:string, extension?:string,
     *   tag?:string, trashed?:bool, shared?:bool, min_size?:int, max_size?:int,
     *   from?:string, to?:string
     * } $filters
     */
    public static function search(array $filters, int $page = 1, int $perPage = 24, string $sort = 'created_at', string $direction = 'desc'): array
    {
        $where = [];
        $bindings = [];

        if (isset($filters['user_id'])) {
            $where[] = 'f.user_id = ?';
            $bindings[] = (int) $filters['user_id'];
        }

        $where[] = ($filters['trashed'] ?? false) ? 'f.deleted_at IS NOT NULL' : 'f.deleted_at IS NULL';

        if (array_key_exists('folder_id', $filters) && ($filters['q'] ?? '') === '') {
            if ($filters['folder_id'] === null) {
                $where[] = 'f.folder_id IS NULL';
            } else {
                $where[] = 'f.folder_id = ?';
                $bindings[] = (int) $filters['folder_id'];
            }
        }

        if (($filters['q'] ?? '') !== '') {
            $where[] = '(f.name LIKE ? OR f.original_name LIKE ? OR f.tags LIKE ?)';
            $term = '%' . $filters['q'] . '%';
            $bindings[] = $term;
            $bindings[] = $term;
            $bindings[] = $term;
        }

        if (($filters['mime'] ?? '') !== '') {
            $where[] = 'f.mime LIKE ?';
            $bindings[] = rtrim((string) $filters['mime'], '*') . '%';
        }

        if (($filters['extension'] ?? '') !== '') {
            $where[] = 'f.extension = ?';
            $bindings[] = strtolower(ltrim((string) $filters['extension'], '.'));
        }

        if (($filters['tag'] ?? '') !== '') {
            $where[] = 'f.tags LIKE ?';
            $bindings[] = '%"' . $filters['tag'] . '"%';
        }

        if (!empty($filters['shared'])) {
            $where[] = 'f.is_public = 1';
        }

        if (!empty($filters['min_size'])) {
            $where[] = 'f.size >= ?';
            $bindings[] = (int) $filters['min_size'];
        }

        if (!empty($filters['max_size'])) {
            $where[] = 'f.size <= ?';
            $bindings[] = (int) $filters['max_size'];
        }

        if (!empty($filters['from'])) {
            $where[] = 'f.created_at >= ?';
            $bindings[] = date('Y-m-d 00:00:00', strtotime((string) $filters['from']) ?: time());
        }

        if (!empty($filters['to'])) {
            $where[] = 'f.created_at <= ?';
            $bindings[] = date('Y-m-d 23:59:59', strtotime((string) $filters['to']) ?: time());
        }

        $allowedSorts = ['created_at', 'updated_at', 'name', 'size', 'download_count'];
        $sort = in_array($sort, $allowedSorts, true) ? $sort : 'created_at';
        $direction = strtolower($direction) === 'asc' ? 'ASC' : 'DESC';

        $whereSql = implode(' AND ', $where);
        $total = (int) Database::scalar("SELECT COUNT(*) FROM files f WHERE {$whereSql}", $bindings);

        $perPage = max(1, min(200, $perPage));
        $page = max(1, $page);
        $offset = ($page - 1) * $perPage;

        $rows = Database::select(
            "SELECT f.*, fo.name AS folder_name,
                    (SELECT COUNT(*) FROM shares s WHERE s.file_id = f.id AND s.is_active = 1) AS share_count
             FROM files f
             LEFT JOIN folders fo ON fo.id = f.folder_id
             WHERE {$whereSql}
             ORDER BY f.{$sort} {$direction}
             LIMIT {$perPage} OFFSET {$offset}",
            $bindings
        );

        return [
            'data'      => array_map([self::class, 'hydrate'], $rows),
            'total'     => $total,
            'page'      => $page,
            'per_page'  => $perPage,
            'last_page' => (int) max(1, ceil($total / $perPage)),
        ];
    }

    public static function isPreviewable(array $file): bool
    {
        return in_array($file['mime'], (array) Config::get('storage.previewable', []), true);
    }

    public static function kind(array $file): string
    {
        $mime = (string) $file['mime'];

        return match (true) {
            str_starts_with($mime, 'image/')  => 'image',
            str_starts_with($mime, 'video/')  => 'video',
            str_starts_with($mime, 'audio/')  => 'audio',
            $mime === 'application/pdf'       => 'pdf',
            str_starts_with($mime, 'text/'),
            in_array($mime, ['application/json', 'application/xml'], true) => 'text',
            in_array($mime, [
                'application/zip', 'application/x-tar', 'application/gzip',
                'application/x-7z-compressed', 'application/vnd.rar',
            ], true) => 'archive',
            default => 'file',
        };
    }

    public static function publicArray(array $file, ?string $downloadUrl = null): array
    {
        return [
            'id'             => (int) $file['id'],
            'uuid'           => $file['uuid'],
            'name'           => $file['name'],
            'original_name'  => $file['original_name'],
            'extension'      => $file['extension'],
            'mime'           => $file['mime'],
            'kind'           => self::kind($file),
            'size'           => (int) $file['size'],
            'size_human'     => \App\Support\Str::bytes((int) $file['size']),
            'checksum'       => $file['checksum'],
            'folder_id'      => $file['folder_id'] === null ? null : (int) $file['folder_id'],
            'version'        => (int) $file['version'],
            'is_public'      => (bool) $file['is_public'],
            'download_count' => (int) $file['download_count'],
            'tags'           => $file['tags'] ?: [],
            'meta'           => $file['meta'] ?: [],
            'source'         => $file['source'],
            'storage'        => $file['disk'] ?? null,
            'previewable'    => self::isPreviewable($file),
            'download_url'   => $downloadUrl,
            'trashed'        => $file['deleted_at'] !== null,
            'created_at'     => $file['created_at'],
            'updated_at'     => $file['updated_at'],
            'deleted_at'     => $file['deleted_at'],
        ];
    }

    /** @return list<string> Distinct tags used by a given user. */
    public static function allTags(int $userId): array
    {
        $rows = Database::select(
            'SELECT tags FROM files WHERE user_id = ? AND deleted_at IS NULL AND tags IS NOT NULL AND tags != "[]"',
            [$userId]
        );

        $tags = [];
        foreach ($rows as $row) {
            foreach ((array) json_decode((string) $row['tags'], true) as $tag) {
                $tag = trim((string) $tag);
                if ($tag !== '') {
                    $tags[$tag] = ($tags[$tag] ?? 0) + 1;
                }
            }
        }

        arsort($tags);

        return $tags;
    }
}
