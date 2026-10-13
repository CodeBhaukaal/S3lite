<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Logger;
use App\Support\Crypto;
use RuntimeException;
use Throwable;

/**
 * Let Cloudflare (or any CDN in front of the site) serve public downloads
 * from its cache, so popular files stop costing this server a transfer each.
 *
 * Only links that are safe to hand to a cache qualify: an active share with no
 * password and no download limit, that never expires or still has at least
 * the configured minimum lifetime left. Those get a stable /cdn/... URL and a
 * public Cache-Control header capped at the share's expiry.
 *
 * Everything works from headers alone. Adding a Cloudflare API token on top
 * lets the panel create the cache rule itself and purge cached copies the
 * moment a link is revoked or its file changes.
 */
final class CdnCacheService
{
    public const GROUP = 'cdn';
    public const RULE_REF = 's3lite_cdn_downloads';

    /** Overridden by the test suite to point at a fake API. */
    public static string $apiBase = 'https://api.cloudflare.com/client/v4';

    public static function enabled(): bool
    {
        return (bool) SettingService::get('cdn_cache_enabled', false);
    }

    /** How long Cloudflare may keep a copy, at most. */
    public static function days(): int
    {
        return max(1, min(365, (int) SettingService::get('cdn_cache_days', 30)));
    }

    /** Links expiring sooner than this are never cached. */
    public static function minLifetimeDays(): int
    {
        return max(0, min(365, (int) SettingService::get('cdn_min_lifetime_days', 7)));
    }

    /**
     * Seconds a CDN may cache this share's downloads; 0 = must not be cached.
     */
    public static function ttlForShare(array $share, ?int $now = null): int
    {
        $now ??= time();

        if (!self::enabled()
            || !(bool) $share['is_active']
            || ShareService::requiresPassword($share + ['password_hash' => null])
            || ($share['max_downloads'] ?? null) !== null) {
            return 0;
        }

        $ttl = self::days() * 86400;

        if (($share['expires_at'] ?? null) === null) {
            return $ttl;
        }

        $remaining = (int) strtotime((string) $share['expires_at']) - $now;

        if ($remaining <= 0 || $remaining < self::minLifetimeDays() * 86400) {
            return 0;
        }

        return min($ttl, $remaining);
    }

    /** The cacheable direct-download URL for a file in a share. */
    public static function url(array $share, array $file): string
    {
        // The filename is cosmetic, but gives Cloudflare an extension to
        // recognise and makes a rename a different cache entry.
        $url = url('cdn/' . $share['token'] . '/' . rawurlencode((string) $file['name']));

        // Folder shares need to say which file.
        return $share['file_id'] === null ? $url . '?file=' . rawurlencode((string) $file['uuid']) : $url;
    }

    /** Headers that make a response cacheable for $ttl seconds. */
    public static function headers(int $ttl): array
    {
        return [
            'Cache-Control'                => 'public, max-age=' . $ttl . ', s-maxage=' . $ttl,
            // Cloudflare reads this one for the edge and ignores it for browsers.
            'Cloudflare-CDN-Cache-Control' => 'max-age=' . $ttl,
            'Vary'                         => 'Accept-Encoding',
        ];
    }

    // --- Cloudflare API -------------------------------------------------

    public static function zoneId(): string
    {
        return trim((string) SettingService::get('cloudflare_zone_id', ''));
    }

    public static function apiToken(): string
    {
        $stored = (string) SettingService::get('cloudflare_api_token', '');

        return $stored === '' ? '' : (string) (Crypto::decrypt($stored) ?? '');
    }

    public static function apiConfigured(): bool
    {
        return self::zoneId() !== '' && self::apiToken() !== '';
    }

    /**
     * Save the admin's settings. A blank token keeps the stored one.
     *
     * @param array<string, mixed> $input
     */
    public static function saveSettings(array $input): void
    {
        $values = [
            'cdn_cache_enabled'     => in_array((string) ($input['cdn_cache_enabled'] ?? ''), ['1', 'true', 'on'], true),
            'cdn_cache_days'        => max(1, min(365, (int) ($input['cdn_cache_days'] ?? 30))),
            'cdn_min_lifetime_days' => max(0, min(365, (int) ($input['cdn_min_lifetime_days'] ?? 7))),
            'cloudflare_zone_id'    => preg_replace('/[^a-f0-9]/i', '', (string) ($input['cloudflare_zone_id'] ?? '')) ?? '',
        ];

        $token = trim((string) ($input['cloudflare_api_token'] ?? ''));

        if ($token !== '') {
            $values['cloudflare_api_token'] = Crypto::encrypt($token);
        }

        if (!empty($input['forget_api_token'])) {
            $values['cloudflare_api_token'] = '';
        }

        SettingService::setMany($values, self::GROUP);

        AuditService::log('cdn.settings', 'settings', null, 'Updated CDN caching settings', [
            'enabled' => $values['cdn_cache_enabled'],
            'days'    => $values['cdn_cache_days'],
        ]);
    }

    /** The path the cache rule matches, including any sub-directory install. */
    public static function pathPrefix(): string
    {
        $path = (string) parse_url(str_replace('\\', '/', url('cdn/')), PHP_URL_PATH);

        return '/' . trim($path, '/') . '/';
    }

    /**
     * Create (or update) the Cloudflare cache rule for /cdn/ URLs, leaving any
     * other cache rules on the zone untouched.
     *
     * @return string What happened, for the admin.
     */
    public static function ensureCacheRule(): string
    {
        $rule = [
            'ref'               => self::RULE_REF,
            'description'       => 'S3 Lite: cache public file downloads',
            'expression'        => sprintf('(starts_with(http.request.uri.path, "%s"))', self::pathPrefix()),
            'action'            => 'set_cache_settings',
            'action_parameters' => [
                'cache'       => true,
                // The origin decides per link (and caps at its expiry); a
                // response without a Cache-Control header - an error page,
                // say - is not cached at all rather than held for
                // Cloudflare's default TTL.
                'edge_ttl'    => ['mode' => 'bypass_by_default'],
                'browser_ttl' => ['mode' => 'respect_origin'],
            ],
            'enabled'           => true,
        ];

        $entry = sprintf('/zones/%s/rulesets/phases/http_request_cache_settings/entrypoint', self::zoneId());
        [$status, $ruleset] = self::api('GET', $entry);

        if ($status === 404) {
            self::call('PUT', $entry, ['rules' => [$rule]]);

            return 'Created the Cloudflare cache rule.';
        }

        if ($status !== 200) {
            throw new RuntimeException(self::errorFrom($ruleset, $status));
        }

        $rulesetId = (string) ($ruleset['result']['id'] ?? '');

        foreach ((array) ($ruleset['result']['rules'] ?? []) as $existing) {
            if (($existing['ref'] ?? '') === self::RULE_REF) {
                self::call('PATCH', sprintf('/zones/%s/rulesets/%s/rules/%s', self::zoneId(), $rulesetId, $existing['id']), $rule);

                return 'Updated the existing Cloudflare cache rule.';
            }
        }

        self::call('POST', sprintf('/zones/%s/rulesets/%s/rules', self::zoneId(), $rulesetId), $rule);

        return 'Added the cache rule alongside your existing Cloudflare rules.';
    }

    /** @return array{ok:bool, message:string} */
    public static function testConnection(): array
    {
        try {
            if (!self::apiConfigured()) {
                throw new RuntimeException('Enter a zone ID and an API token first.');
            }

            // Checked against what the token is actually for, so it needs no
            // Zone:Read on top of Cache Rules + Cache Purge. 404 just means
            // the zone has no cache rules yet.
            [$status, $body] = self::api('GET', sprintf('/zones/%s/rulesets/phases/http_request_cache_settings/entrypoint', self::zoneId()));

            if ($status !== 200 && $status !== 404) {
                throw new RuntimeException(self::errorFrom($body, $status));
            }

            $hasRule = false;
            foreach ((array) ($body['result']['rules'] ?? []) as $rule) {
                $hasRule = $hasRule || ($rule['ref'] ?? '') === self::RULE_REF;
            }

            return ['ok' => true, 'message' => 'Connected to Cloudflare. ' . ($hasRule
                ? 'The download cache rule is in place.'
                : 'Now press "Create cache rule in Cloudflare".')];
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    /** Drop every cached download URL of one share. Never throws. */
    public static function purgeShare(array $share): void
    {
        try {
            $files = $share['file_id'] !== null
                ? Database::select('SELECT id, uuid, name FROM files WHERE id = ?', [(int) $share['file_id']])
                : Database::select('SELECT id, uuid, name FROM files WHERE folder_id = ?', [(int) $share['folder_id']]);

            self::purgeUrls(array_map(static fn (array $file): string => self::url($share, $file), $files));
        } catch (Throwable $e) {
            Logger::warning('CDN purge for a share failed: ' . $e->getMessage());
        }
    }

    /**
     * Drop every cached URL a file is reachable at, before it changes. Call
     * with the file as it was: a rename or move changes the URL itself.
     */
    public static function purgeFile(array $file): void
    {
        if (!self::apiConfigured()) {
            return;
        }

        try {
            $shares = Database::select(
                'SELECT * FROM shares WHERE file_id = ? OR (folder_id IS NOT NULL AND folder_id = ?)',
                [(int) $file['id'], (int) ($file['folder_id'] ?? 0)]
            );

            self::purgeUrls(array_map(static fn (array $share): string => self::url($share, $file), $shares));
        } catch (Throwable $e) {
            Logger::warning('CDN purge for a file failed: ' . $e->getMessage(), ['file_id' => $file['id'] ?? null]);
        }
    }

    /** @param list<string> $urls */
    public static function purgeUrls(array $urls): void
    {
        $urls = array_values(array_unique(array_filter($urls)));

        if ($urls === [] || !self::apiConfigured()) {
            return;
        }

        // Cloudflare accepts 30 URLs per purge call on every plan.
        foreach (array_chunk($urls, 30) as $chunk) {
            try {
                self::call('POST', '/zones/' . self::zoneId() . '/purge_cache', ['files' => $chunk]);
            } catch (Throwable $e) {
                Logger::warning('Cloudflare purge failed: ' . $e->getMessage(), ['urls' => $chunk]);
            }
        }
    }

    /** A call that must succeed; returns the decoded body. */
    private static function call(string $method, string $path, ?array $payload = null): array
    {
        [$status, $body] = self::api($method, $path, $payload);

        if ($status < 200 || $status >= 300 || ($body['success'] ?? false) !== true) {
            throw new RuntimeException(self::errorFrom($body, $status));
        }

        return $body;
    }

    /** @return array{0:int, 1:array} */
    private static function api(string $method, string $path, ?array $payload = null): array
    {
        $ch = curl_init(rtrim(self::$apiBase, '/') . $path);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            // Purges run inside user requests; never hold one up for long.
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . self::apiToken(),
                'Content-Type: application/json',
            ],
        ]);

        if ($payload !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, (string) json_encode($payload));
        }

        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            throw new RuntimeException('Could not reach Cloudflare: ' . $error);
        }

        $decoded = json_decode((string) $raw, true);

        return [$status, is_array($decoded) ? $decoded : []];
    }

    private static function errorFrom(array $body, int $status): string
    {
        $message = (string) ($body['errors'][0]['message'] ?? '');

        return 'Cloudflare refused the request (HTTP ' . $status . ')' . ($message !== '' ? ': ' . $message : '');
    }
}
