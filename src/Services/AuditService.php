<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Logger;
use App\Http\Request;
use App\Models\AuditLog;

/**
 * Every write path funnels through here so the audit trail stays complete.
 */
final class AuditService
{
    private static ?Request $request = null;

    public static function bind(Request $request): void
    {
        self::$request = $request;
    }

    public static function log(
        string $action,
        ?string $entityType = null,
        int|string|null $entityId = null,
        string $description = '',
        array $meta = [],
        string $status = 'success',
        ?int $userId = null,
        string $actorType = 'user',
        ?string $actorLabel = null
    ): void {
        $request = self::$request;

        $userId ??= Auth::id();

        if ($actorLabel === null) {
            $user = Auth::user();
            $actorLabel = $user['email'] ?? ($userId === null ? 'system' : null);
        }

        try {
            AuditLog::create([
                'user_id'     => $userId,
                'actor_type'  => $actorType,
                'actor_label' => $actorLabel,
                'action'      => $action,
                'entity_type' => $entityType,
                'entity_id'   => $entityId === null ? null : (string) $entityId,
                'description' => mb_substr($description, 0, 500),
                'ip'          => $request?->ip(),
                'user_agent'  => $request?->userAgent(),
                'method'      => $request?->method,
                'path'        => $request === null ? null : mb_substr($request->path, 0, 255),
                'status'      => $status,
                'meta'        => $meta,
                'created_at'  => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            // Auditing must never break the request it is recording.
            Logger::error('Audit write failed: ' . $e->getMessage(), ['action' => $action]);
        }
    }

    public static function failure(string $action, string $description, array $meta = []): void
    {
        self::log($action, null, null, $description, $meta, 'failed');
    }

    public static function system(string $action, string $description, array $meta = []): void
    {
        self::log($action, null, null, $description, $meta, 'success', null, 'system', 'system');
    }

    public static function purge(int $olderThanDays): int
    {
        return \App\Core\Database::statement(
            'DELETE FROM audit_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)',
            [$olderThanDays]
        );
    }
}
