<?php
declare(strict_types=1);

namespace App\Storage;

use App\Core\Config;
use App\Http\Exceptions\HttpException;
use App\Models\StorageBackend;
use RuntimeException;
use Throwable;

/**
 * Resolves backend slugs to drivers.
 *
 * Slugs come from the `storage_backends` table, but anything defined in
 * config/storage.php still resolves too — that keeps installs that only ever
 * used STORAGE_DRIVER in .env working without a data migration.
 */
final class StorageManager
{
    /** @var array<string, StorageDriver> */
    private static array $drivers = [];

    private static ?string $defaultSlug = null;

    public static function disk(?string $slug = null): StorageDriver
    {
        $slug = $slug === null || $slug === '' ? self::defaultSlug() : $slug;

        if (isset(self::$drivers[$slug])) {
            return self::$drivers[$slug];
        }

        try {
            return self::$drivers[$slug] = self::build($slug);
        } catch (HttpException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new HttpException(
                503,
                sprintf('Storage backend "%s" is unavailable: %s', $slug, $e->getMessage()),
                'storage_backend_unavailable'
            );
        }
    }

    /** Where new uploads go when nothing more specific is asked for. */
    public static function defaultSlug(): string
    {
        if (self::$defaultSlug !== null) {
            return self::$defaultSlug;
        }

        $row = null;

        try {
            $row = StorageBackend::defaultRow();
        } catch (Throwable) {
            // The table does not exist yet (fresh install, mid-migration).
        }

        return self::$defaultSlug = $row === null
            ? (string) Config::get('storage.driver', 'local')
            : (string) $row['slug'];
    }

    public static function reset(): void
    {
        self::$drivers = [];
        self::$defaultSlug = null;
    }

    private static function build(string $slug): StorageDriver
    {
        $backend = null;

        try {
            $backend = StorageBackend::findBySlug($slug);
        } catch (Throwable) {
            // No table yet — fall through to the config-defined drivers.
        }

        if ($backend !== null) {
            return self::fromBackend($backend);
        }

        return self::fromConfig($slug);
    }

    /** Build a driver straight from a backend row (also used by the tester). */
    public static function fromBackend(array $backend): StorageDriver
    {
        $slug = (string) $backend['slug'];
        $credentials = StorageBackend::credentials($backend);
        $options = is_array($backend['options'] ?? null) ? $backend['options'] : [];
        $root = (string) ($backend['root_path'] ?? '');

        return match ((string) $backend['driver']) {
            'local' => new LocalDriver($root !== '' ? $root : self::localRoot(), $slug),
            'ftp', 'ftps' => new FtpDriver(
                $slug,
                (string) $backend['host'],
                (int) ($backend['port'] ?: 21),
                (string) $backend['username'],
                (string) ($credentials['password'] ?? ''),
                $backend['driver'] === 'ftps',
                $root,
                $options
            ),
            'sftp' => new SftpDriver(
                $slug,
                (string) $backend['host'],
                (int) ($backend['port'] ?: 22),
                (string) $backend['username'],
                (string) ($credentials['password'] ?? ''),
                (string) ($credentials['private_key'] ?? ''),
                (string) ($credentials['passphrase'] ?? ''),
                $root,
                $options
            ),
            's3' => new S3Driver(
                (string) ($options['endpoint'] ?? ''),
                (string) ($options['region'] ?? 'us-east-1'),
                (string) ($options['bucket'] ?? ''),
                (string) ($options['access_key'] ?? ''),
                (string) ($credentials['secret_key'] ?? ''),
                $slug
            ),
            default => throw new RuntimeException("Unknown storage driver: {$backend['driver']}"),
        };
    }

    private static function fromConfig(string $slug): StorageDriver
    {
        $config = (array) Config::get('storage.drivers.' . $slug, []);

        if ($config === []) {
            throw new RuntimeException("There is no storage backend called \"{$slug}\".");
        }

        return match ($slug) {
            'local' => new LocalDriver((string) ($config['root'] ?? self::localRoot()), 'local'),
            's3'    => new S3Driver(
                (string) ($config['endpoint'] ?? ''),
                (string) ($config['region'] ?? 'us-east-1'),
                (string) ($config['bucket'] ?? ''),
                (string) ($config['access_key'] ?? ''),
                (string) ($config['secret_key'] ?? ''),
                's3'
            ),
            default => throw new RuntimeException("Unknown storage driver: {$slug}"),
        };
    }

    private static function localRoot(): string
    {
        return (string) Config::get('storage.drivers.local.root', '');
    }

    /**
     * Copy an object between two backends (or within one). Cross-backend
     * copies are spooled through a temporary file so neither side has to hold
     * the whole object in memory.
     */
    public static function copyAcross(StorageDriver $from, string $fromPath, StorageDriver $to, string $toPath): bool
    {
        if ($from === $to) {
            return $from->copy($fromPath, $toPath);
        }

        $stream = $from->readStream($fromPath);

        if ($stream === null) {
            return false;
        }

        $temp = rtrim((string) Config::get('storage.tmp_path'), '/\\') . '/transfer-' . bin2hex(random_bytes(8));
        $out = @fopen($temp, 'wb');

        if ($out === false) {
            fclose($stream);

            return false;
        }

        $copied = stream_copy_to_stream($stream, $out);
        fclose($stream);
        fclose($out);

        try {
            return $copied !== false && $to->put($temp, $toPath, false);
        } finally {
            @unlink($temp);
        }
    }

    /**
     * Content-addressed layout: {user}/{ab}/{cd}/{hash}-{random}.{ext}
     * Sharding keeps directory sizes sane on large installs.
     */
    public static function buildPath(int $userId, string $checksum, string $extension): string
    {
        $extension = strtolower(preg_replace('/[^a-z0-9]/i', '', $extension) ?? '');
        $suffix = $extension === '' ? '' : '.' . substr($extension, 0, 12);

        return sprintf(
            '%d/%s/%s/%s-%s%s',
            $userId,
            substr($checksum, 0, 2),
            substr($checksum, 2, 2),
            substr($checksum, 0, 32),
            bin2hex(random_bytes(4)),
            $suffix
        );
    }

    public static function versionPath(string $originalPath, int $version): string
    {
        $dir = dirname($originalPath);
        $base = pathinfo($originalPath, PATHINFO_FILENAME);
        $ext = pathinfo($originalPath, PATHINFO_EXTENSION);

        return sprintf('%s/versions/%s-v%d%s', $dir, $base, $version, $ext === '' ? '' : '.' . $ext);
    }
}
