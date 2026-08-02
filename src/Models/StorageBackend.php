<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;
use App\Support\Crypto;
use App\Support\Str;

/**
 * A place files can live: the local disk, a remote FTP/FTPS/SFTP server or an
 * S3 bucket. The `slug` is what gets written into `files`.`disk`, so a file
 * always knows which backend holds its bytes.
 */
final class StorageBackend extends Model
{
    protected static string $table = 'storage_backends';
    protected static array $jsonColumns = ['options'];

    public const DRIVERS = [
        'local' => 'Local disk (this server)',
        'ftp'   => 'FTP',
        'ftps'  => 'FTP over TLS (FTPS)',
        'sftp'  => 'SFTP (SSH)',
        's3'    => 'S3-compatible',
    ];

    /** Credential fields kept inside the encrypted `secret` blob. */
    public const SECRET_FIELDS = ['password', 'private_key', 'passphrase', 'secret_key'];

    public static function findBySlug(string $slug): ?array
    {
        return self::findBy('slug', $slug);
    }

    public static function resolve(string $identifier): ?array
    {
        return ctype_digit($identifier) ? self::find((int) $identifier) : self::findBy('uuid', $identifier);
    }

    /** The backend new uploads go to, or null when none is configured. */
    public static function defaultRow(): ?array
    {
        return self::findWhere(['is_default' => 1, 'is_active' => 1], 'id ASC');
    }

    /** @return list<array> */
    public static function listAll(): array
    {
        return self::where([], 'is_default DESC, id ASC', 200);
    }

    /** @return list<array> */
    public static function activeList(): array
    {
        return self::where(['is_active' => 1], 'is_default DESC, id ASC', 200);
    }

    /** Exactly one backend is the default at any time. */
    public static function makeDefault(int $id): void
    {
        Database::statement('UPDATE storage_backends SET is_default = 0 WHERE id != ?', [$id]);
        Database::statement('UPDATE storage_backends SET is_default = 1, is_active = 1, updated_at = NOW() WHERE id = ?', [$id]);
    }

    /**
     * Decrypt the credential blob.
     *
     * @return array<string, string>
     */
    public static function credentials(array $backend): array
    {
        $secret = (string) ($backend['secret'] ?? '');

        if ($secret === '') {
            return [];
        }

        $plain = Crypto::decrypt($secret);
        $decoded = $plain === null ? null : json_decode($plain, true);

        return is_array($decoded) ? array_map('strval', $decoded) : [];
    }

    /**
     * Merge new credential values over the stored ones and re-encrypt.
     *
     * A blank string keeps whatever is stored — that is what an edit form
     * posts when the admin does not retype the password. An explicit null
     * removes the credential.
     *
     * @param array<string, string|null> $values
     */
    public static function mergeCredentials(array $existing, array $values): ?string
    {
        $current = self::credentials($existing);

        foreach (self::SECRET_FIELDS as $field) {
            if (!array_key_exists($field, $values)) {
                continue;
            }

            if ($values[$field] === null) {
                unset($current[$field]);
                continue;
            }

            $value = (string) $values[$field];

            if ($value !== '') {
                $current[$field] = $value;
            }
        }

        return $current === [] ? null : Crypto::encrypt((string) json_encode($current));
    }

    public static function uniqueSlug(string $desired, ?int $ignoreId = null): string
    {
        $base = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($desired)) ?? '', '-');

        if ($base === '') {
            $base = 'backend';
        }

        $base = substr($base, 0, 56);
        $candidate = $base;
        $counter = 2;

        while (self::slugTaken($candidate, $ignoreId)) {
            $candidate = $base . '-' . $counter;
            $counter++;

            if ($counter > 99) {
                $candidate = $base . '-' . Str::random(6);
                break;
            }
        }

        return $candidate;
    }

    private static function slugTaken(string $slug, ?int $ignoreId): bool
    {
        $sql = 'SELECT COUNT(*) FROM storage_backends WHERE slug = ?';
        $bindings = [$slug];

        if ($ignoreId !== null) {
            $sql .= ' AND id != ?';
            $bindings[] = $ignoreId;
        }

        return (int) Database::scalar($sql, $bindings) > 0;
    }

    /** Never leaks credentials — only whether they are set. */
    public static function publicArray(array $backend): array
    {
        $credentials = self::credentials($backend);
        $options = is_array($backend['options'] ?? null) ? $backend['options'] : [];

        return [
            'id'              => (int) $backend['id'],
            'uuid'            => $backend['uuid'],
            'name'            => $backend['name'],
            'slug'            => $backend['slug'],
            'driver'          => $backend['driver'],
            'driver_label'    => self::DRIVERS[$backend['driver']] ?? $backend['driver'],
            'host'            => $backend['host'],
            'port'            => $backend['port'] === null ? null : (int) $backend['port'],
            'username'        => $backend['username'],
            'root_path'       => $backend['root_path'],
            'options'         => $options,
            'is_default'      => (bool) $backend['is_default'],
            'is_active'       => (bool) $backend['is_active'],
            'status'          => $backend['status'],
            'last_error'      => $backend['last_error'],
            'last_checked_at' => $backend['last_checked_at'],
            'has_password'    => ($credentials['password'] ?? '') !== '',
            'has_private_key' => ($credentials['private_key'] ?? '') !== '',
            'has_secret_key'  => ($credentials['secret_key'] ?? '') !== '',
            'created_at'      => $backend['created_at'],
        ];
    }

    /** Human-readable target, e.g. "ftp.example.com:21/backups". */
    public static function endpointLabel(array $backend): string
    {
        if ($backend['driver'] === 'local') {
            return 'this server';
        }

        if ($backend['driver'] === 's3') {
            $options = is_array($backend['options'] ?? null) ? $backend['options'] : [];

            return trim((string) ($options['endpoint'] ?? '')) . '/' . (string) ($options['bucket'] ?? '');
        }

        $label = (string) $backend['host'];

        if ($backend['port'] !== null) {
            $label .= ':' . (int) $backend['port'];
        }

        $root = trim((string) $backend['root_path'], '/');

        return $root === '' ? $label : $label . '/' . $root;
    }
}
