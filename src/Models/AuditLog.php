<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

final class AuditLog extends Model
{
    protected static string $table = 'audit_logs';
    protected static array $jsonColumns = ['meta'];
    protected static bool $timestamps = false;

    public static function search(array $filters, int $page = 1, int $perPage = 30): array
    {
        $where = ['1 = 1'];
        $bindings = [];

        if (!empty($filters['user_id'])) {
            $where[] = 'a.user_id = ?';
            $bindings[] = (int) $filters['user_id'];
        }
        if (!empty($filters['action'])) {
            $where[] = 'a.action = ?';
            $bindings[] = $filters['action'];
        }
        if (!empty($filters['status'])) {
            $where[] = 'a.status = ?';
            $bindings[] = $filters['status'];
        }
        if (!empty($filters['entity_type'])) {
            $where[] = 'a.entity_type = ?';
            $bindings[] = $filters['entity_type'];
        }
        if (!empty($filters['q'])) {
            $where[] = '(a.description LIKE ? OR a.action LIKE ? OR a.ip LIKE ?)';
            $term = '%' . $filters['q'] . '%';
            $bindings[] = $term;
            $bindings[] = $term;
            $bindings[] = $term;
        }
        if (!empty($filters['from'])) {
            $where[] = 'a.created_at >= ?';
            $bindings[] = date('Y-m-d 00:00:00', strtotime((string) $filters['from']) ?: time());
        }
        if (!empty($filters['to'])) {
            $where[] = 'a.created_at <= ?';
            $bindings[] = date('Y-m-d 23:59:59', strtotime((string) $filters['to']) ?: time());
        }

        $whereSql = implode(' AND ', $where);
        $total = (int) Database::scalar("SELECT COUNT(*) FROM audit_logs a WHERE {$whereSql}", $bindings);

        $perPage = max(1, min(200, $perPage));
        $offset = max(0, ($page - 1) * $perPage);

        $rows = Database::select(
            "SELECT a.*, u.name AS user_name, u.email AS user_email
             FROM audit_logs a
             LEFT JOIN users u ON u.id = a.user_id
             WHERE {$whereSql}
             ORDER BY a.id DESC
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

    public static function distinctActions(): array
    {
        return array_column(
            Database::select('SELECT DISTINCT action FROM audit_logs ORDER BY action LIMIT 200'),
            'action'
        );
    }
}
