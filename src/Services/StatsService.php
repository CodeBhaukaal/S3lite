<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Cache;
use App\Core\Database;

final class StatsService
{
    /** Headline counters for a user's dashboard. */
    public static function forUser(int $userId): array
    {
        $row = Database::selectOne(
            'SELECT
                (SELECT COUNT(*) FROM files WHERE user_id = ? AND deleted_at IS NULL) AS files,
                (SELECT COALESCE(SUM(size), 0) FROM files WHERE user_id = ? AND deleted_at IS NULL) AS bytes,
                (SELECT COUNT(*) FROM files WHERE user_id = ? AND deleted_at IS NOT NULL) AS trashed,
                (SELECT COUNT(*) FROM folders WHERE user_id = ? AND deleted_at IS NULL) AS folders,
                (SELECT COUNT(*) FROM shares WHERE user_id = ? AND is_active = 1) AS shares,
                (SELECT COALESCE(SUM(download_count), 0) FROM files WHERE user_id = ?) AS downloads,
                (SELECT COUNT(*) FROM api_keys WHERE user_id = ? AND revoked_at IS NULL) AS api_keys,
                (SELECT COUNT(*) FROM sftp_accounts WHERE user_id = ?) AS sftp_accounts',
            array_fill(0, 8, $userId)
        ) ?? [];

        $quota = QuotaService::check($userId);

        return [
            'files'         => (int) ($row['files'] ?? 0),
            'bytes'         => (int) ($row['bytes'] ?? 0),
            'trashed'       => (int) ($row['trashed'] ?? 0),
            'folders'       => (int) ($row['folders'] ?? 0),
            'shares'        => (int) ($row['shares'] ?? 0),
            'downloads'     => (int) ($row['downloads'] ?? 0),
            'api_keys'      => (int) ($row['api_keys'] ?? 0),
            'sftp_accounts' => (int) ($row['sftp_accounts'] ?? 0),
            'quota'         => $quota,
        ];
    }

    /** Platform-wide counters for the admin dashboard. */
    public static function global(): array
    {
        return Cache::remember('stats:global', 30, static function (): array {
            $row = Database::selectOne(
                'SELECT
                    (SELECT COUNT(*) FROM users WHERE deleted_at IS NULL) AS users,
                    (SELECT COUNT(*) FROM users WHERE status = "active" AND deleted_at IS NULL) AS active_users,
                    (SELECT COUNT(*) FROM files WHERE deleted_at IS NULL) AS files,
                    (SELECT COALESCE(SUM(size), 0) FROM files WHERE deleted_at IS NULL) AS bytes,
                    (SELECT COUNT(*) FROM files WHERE deleted_at IS NOT NULL) AS trashed,
                    (SELECT COUNT(*) FROM folders WHERE deleted_at IS NULL) AS folders,
                    (SELECT COUNT(*) FROM shares WHERE is_active = 1) AS shares,
                    (SELECT COUNT(*) FROM api_keys WHERE revoked_at IS NULL) AS api_keys,
                    (SELECT COUNT(*) FROM sftp_accounts) AS sftp_accounts,
                    (SELECT COUNT(*) FROM sftp_sessions WHERE status = "active") AS active_sessions,
                    (SELECT COUNT(*) FROM downloads WHERE created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)) AS downloads_24h,
                    (SELECT COUNT(*) FROM uploads WHERE created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)) AS uploads_24h,
                    (SELECT COALESCE(SUM(bytes), 0) FROM downloads WHERE created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)) AS download_bytes_24h,
                    (SELECT COALESCE(SUM(size), 0) FROM uploads WHERE created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)) AS upload_bytes_24h,
                    (SELECT COUNT(*) FROM login_attempts WHERE success = 0 AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)) AS failed_logins_24h,
                    (SELECT COUNT(*) FROM audit_logs WHERE created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)) AS events_24h'
            ) ?? [];

            return array_map('intval', $row);
        });
    }

    /**
     * Daily upload/download series for charts.
     *
     * @return array{labels:list<string>, uploads:list<int>, downloads:list<int>, upload_bytes:list<int>, download_bytes:list<int>}
     */
    public static function timeline(int $days = 14, ?int $userId = null): array
    {
        $days = max(1, min(90, $days));

        $labels = [];
        $uploads = [];
        $downloads = [];
        $uploadBytes = [];
        $downloadBytes = [];

        $userClause = $userId === null ? '' : ' AND user_id = ?';
        $bindings = $userId === null ? [] : [$userId];

        $uploadRows = Database::select(
            "SELECT DATE(created_at) AS day, COUNT(*) AS total, COALESCE(SUM(size), 0) AS bytes
             FROM uploads
             WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL {$days} DAY){$userClause}
             GROUP BY DATE(created_at)",
            $bindings
        );

        $downloadRows = Database::select(
            "SELECT DATE(created_at) AS day, COUNT(*) AS total, COALESCE(SUM(bytes), 0) AS bytes
             FROM downloads
             WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL {$days} DAY){$userClause}
             GROUP BY DATE(created_at)",
            $bindings
        );

        $uploadMap = [];
        foreach ($uploadRows as $row) {
            $uploadMap[$row['day']] = $row;
        }

        $downloadMap = [];
        foreach ($downloadRows as $row) {
            $downloadMap[$row['day']] = $row;
        }

        for ($i = $days - 1; $i >= 0; $i--) {
            $day = date('Y-m-d', strtotime("-{$i} days"));
            $labels[] = date('d M', strtotime($day));
            $uploads[] = (int) ($uploadMap[$day]['total'] ?? 0);
            $downloads[] = (int) ($downloadMap[$day]['total'] ?? 0);
            $uploadBytes[] = (int) ($uploadMap[$day]['bytes'] ?? 0);
            $downloadBytes[] = (int) ($downloadMap[$day]['bytes'] ?? 0);
        }

        return [
            'labels'         => $labels,
            'uploads'        => $uploads,
            'downloads'      => $downloads,
            'upload_bytes'   => $uploadBytes,
            'download_bytes' => $downloadBytes,
        ];
    }

    /** @return list<array{mime:string, kind:string, files:int, bytes:int}> */
    public static function storageByType(?int $userId = null): array
    {
        $where = $userId === null ? 'deleted_at IS NULL' : 'deleted_at IS NULL AND user_id = ?';
        $bindings = $userId === null ? [] : [$userId];

        $rows = Database::select(
            "SELECT mime, COUNT(*) AS files, COALESCE(SUM(size), 0) AS bytes
             FROM files WHERE {$where}
             GROUP BY mime ORDER BY bytes DESC LIMIT 200",
            $bindings
        );

        $grouped = [];
        foreach ($rows as $row) {
            $kind = \App\Models\FileRecord::kind(['mime' => $row['mime']]);
            $grouped[$kind]['kind'] = $kind;
            $grouped[$kind]['files'] = ($grouped[$kind]['files'] ?? 0) + (int) $row['files'];
            $grouped[$kind]['bytes'] = ($grouped[$kind]['bytes'] ?? 0) + (int) $row['bytes'];
        }

        uasort($grouped, static fn (array $a, array $b): int => $b['bytes'] <=> $a['bytes']);

        return array_values($grouped);
    }

    /** @return list<array> Most downloaded files. */
    public static function topFiles(int $limit = 10, ?int $userId = null): array
    {
        $where = $userId === null ? 'f.deleted_at IS NULL' : 'f.deleted_at IS NULL AND f.user_id = ?';
        $bindings = $userId === null ? [] : [$userId];
        $limit = max(1, min(100, $limit));

        return Database::select(
            "SELECT f.id, f.uuid, f.name, f.size, f.mime, f.download_count, u.name AS owner
             FROM files f INNER JOIN users u ON u.id = f.user_id
             WHERE {$where}
             ORDER BY f.download_count DESC, f.size DESC
             LIMIT {$limit}",
            $bindings
        );
    }

    /** @return list<array> Users ordered by storage consumption. */
    public static function topUsers(int $limit = 10): array
    {
        $limit = max(1, min(100, $limit));

        return Database::select(
            "SELECT u.id, u.name, u.email, u.used_bytes, u.quota_bytes,
                    (SELECT COUNT(*) FROM files f WHERE f.user_id = u.id AND f.deleted_at IS NULL) AS files
             FROM users u
             WHERE u.deleted_at IS NULL
             ORDER BY u.used_bytes DESC
             LIMIT {$limit}"
        );
    }

    public static function recentActivity(int $limit = 15, ?int $userId = null): array
    {
        $where = $userId === null ? '1 = 1' : 'a.user_id = ?';
        $bindings = $userId === null ? [] : [$userId];
        $limit = max(1, min(100, $limit));

        return Database::select(
            "SELECT a.*, u.name AS user_name
             FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id
             WHERE {$where}
             ORDER BY a.id DESC LIMIT {$limit}",
            $bindings
        );
    }

    /** Average transfer throughput over a window, in bytes/second. */
    public static function throughput(int $minutes = 60): array
    {
        $row = Database::selectOne(
            'SELECT
                (SELECT COALESCE(SUM(bytes), 0) FROM downloads WHERE created_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)) AS down_bytes,
                (SELECT COALESCE(SUM(duration_ms), 0) FROM downloads WHERE created_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)) AS down_ms,
                (SELECT COALESCE(SUM(size), 0) FROM uploads WHERE created_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)) AS up_bytes,
                (SELECT COALESCE(SUM(duration_ms), 0) FROM uploads WHERE created_at > DATE_SUB(NOW(), INTERVAL ? MINUTE)) AS up_ms',
            [$minutes, $minutes, $minutes, $minutes]
        ) ?? [];

        $downMs = (int) ($row['down_ms'] ?? 0);
        $upMs = (int) ($row['up_ms'] ?? 0);

        return [
            'window_minutes'   => $minutes,
            'download_bytes'   => (int) ($row['down_bytes'] ?? 0),
            'upload_bytes'     => (int) ($row['up_bytes'] ?? 0),
            'download_bps'     => $downMs > 0 ? (int) round(((int) $row['down_bytes'] / $downMs) * 1000) : 0,
            'upload_bps'       => $upMs > 0 ? (int) round(((int) $row['up_bytes'] / $upMs) * 1000) : 0,
        ];
    }
}
