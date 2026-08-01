<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Cache;
use App\Core\Database;

/**
 * Runtime settings stored in the `settings` table, cached for 5 minutes.
 */
final class SettingService
{
    private const CACHE_KEY = 'settings:all';
    private static ?array $memo = null;

    public static function all(): array
    {
        if (self::$memo !== null) {
            return self::$memo;
        }

        $cached = Cache::get(self::CACHE_KEY);

        if (is_array($cached)) {
            return self::$memo = $cached;
        }

        $settings = [];

        try {
            foreach (Database::select('SELECT key_name, value, type FROM settings') as $row) {
                $settings[$row['key_name']] = self::cast($row['value'], (string) $row['type']);
            }
        } catch (\Throwable) {
            // Settings table may not exist before installation completes.
            return self::$memo = [];
        }

        Cache::put(self::CACHE_KEY, $settings, 300);

        return self::$memo = $settings;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $all = self::all();

        return array_key_exists($key, $all) ? $all[$key] : $default;
    }

    public static function set(string $key, mixed $value, string $group = 'general'): void
    {
        $type = match (true) {
            is_bool($value)  => 'bool',
            is_int($value)   => 'int',
            is_float($value) => 'float',
            is_array($value) => 'json',
            default          => 'string',
        };

        $stored = match ($type) {
            'bool'  => $value ? '1' : '0',
            'json'  => json_encode($value, JSON_UNESCAPED_SLASHES),
            default => (string) $value,
        };

        Database::statement(
            'INSERT INTO settings (key_name, value, type, group_name, updated_at)
             VALUES (?, ?, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE value = VALUES(value), type = VALUES(type), group_name = VALUES(group_name), updated_at = NOW()',
            [$key, $stored, $type, $group]
        );

        self::flush();
    }

    public static function setMany(array $values, string $group = 'general'): void
    {
        foreach ($values as $key => $value) {
            self::set($key, $value, $group);
        }
    }

    public static function forget(string $key): void
    {
        Database::delete('settings', 'key_name = ?', [$key]);
        self::flush();
    }

    public static function grouped(): array
    {
        $rows = Database::select('SELECT * FROM settings ORDER BY group_name, key_name');

        $grouped = [];
        foreach ($rows as $row) {
            $grouped[$row['group_name']][$row['key_name']] = self::cast($row['value'], (string) $row['type']);
        }

        return $grouped;
    }

    public static function flush(): void
    {
        self::$memo = null;
        Cache::forget(self::CACHE_KEY);
    }

    private static function cast(?string $value, string $type): mixed
    {
        return match ($type) {
            'bool'  => $value === '1' || $value === 'true',
            'int'   => (int) $value,
            'float' => (float) $value,
            'json'  => json_decode((string) $value, true) ?? [],
            default => $value,
        };
    }

    /** Defaults written during installation. */
    public static function defaults(): array
    {
        return [
            'branding' => [
                'site_name'        => 'S3 Lite',
                'site_tagline'     => 'Self-hosted object storage',
                'accent_color'     => '#4f7cff',
            ],
            'general' => [
                'allow_registration'  => false,
                'require_email_verify' => false,
                'cors_origins'        => '*',
                'default_quota'       => 10737418240,
                'trash_retention_days' => 30,
                'versions_kept'       => 10,
            ],
            'security' => [
                'force_2fa_admins'    => false,
                'max_login_attempts'  => 5,
                'lockout_minutes'     => 15,
                'signed_url_ttl'      => 3600,
                'require_request_signing' => false,
            ],
            'uploads' => [
                'max_upload_size'    => 5368709120,
                'chunk_size'         => 8388608,
                'allow_duplicates'   => false,
                'virus_scan_enabled' => false,
            ],
            'services' => [
                'sftp_enabled' => true,
                'ftp_enabled'  => false,
                'ftps_enabled' => false,
            ],
        ];
    }
}
