<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Logger;
use App\Models\Webhook;

final class WebhookService
{
    /**
     * Queue a webhook delivery for every subscriber of an event.
     * Delivery happens in the job worker so requests are never blocked.
     */
    public static function dispatch(string $event, array $payload, ?int $userId = null): int
    {
        try {
            $hooks = Webhook::listeningFor($event, $userId);
        } catch (\Throwable) {
            return 0;
        }

        foreach ($hooks as $hook) {
            \App\Models\Job::push('webhook.deliver', [
                'webhook_id' => (int) $hook['id'],
                'event'      => $event,
                'payload'    => $payload,
            ], 'webhooks');
        }

        return count($hooks);
    }

    /**
     * @return array{ok:bool, status:int, body:string}
     */
    public static function deliver(int $webhookId, string $event, array $payload): array
    {
        $hook = Webhook::find($webhookId);

        if ($hook === null || !(int) $hook['is_active']) {
            return ['ok' => false, 'status' => 0, 'body' => 'webhook inactive'];
        }

        $body = json_encode([
            'event'     => $event,
            'timestamp' => time(),
            'data'      => $payload,
        ], JSON_UNESCAPED_SLASHES);

        $body = $body === false ? '{}' : $body;
        $signature = hash_hmac('sha256', $body, (string) $hook['secret']);

        $ch = curl_init((string) $hook['url']);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'User-Agent: S3Lite-Webhook/1.0',
                'X-S3Lite-Event: ' . $event,
                'X-S3Lite-Signature: sha256=' . $signature,
                'X-S3Lite-Delivery: ' . \App\Support\Str::uuid(),
            ],
        ]);

        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        $ok = $status >= 200 && $status < 300;
        $responseBody = $response === false ? $error : substr((string) $response, 0, 2000);

        Database::insert('webhook_deliveries', [
            'webhook_id'  => $webhookId,
            'event'       => $event,
            'payload'     => substr($body, 0, 60000),
            'status_code' => $status,
            'response'    => $responseBody,
            'attempts'    => 1,
            'created_at'  => date('Y-m-d H:i:s'),
        ]);

        if ($ok) {
            Database::statement('UPDATE webhooks SET last_fired_at = NOW(), failures = 0 WHERE id = ?', [$webhookId]);
        } else {
            Database::statement('UPDATE webhooks SET failures = failures + 1 WHERE id = ?', [$webhookId]);
            Logger::warning('Webhook delivery failed', ['webhook' => $webhookId, 'status' => $status]);

            // Disable a hook that keeps failing rather than retrying forever.
            $failures = (int) Database::scalar('SELECT failures FROM webhooks WHERE id = ?', [$webhookId]);
            if ($failures >= 20) {
                Database::statement('UPDATE webhooks SET is_active = 0 WHERE id = ?', [$webhookId]);
                AuditService::system('webhook.disabled', 'Webhook disabled after repeated failures', ['webhook_id' => $webhookId]);
            }
        }

        return ['ok' => $ok, 'status' => $status, 'body' => $responseBody];
    }

    public static function deliveries(int $webhookId, int $limit = 50): array
    {
        return Database::select(
            'SELECT * FROM webhook_deliveries WHERE webhook_id = ? ORDER BY id DESC LIMIT ' . max(1, min(200, $limit)),
            [$webhookId]
        );
    }
}
