<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Logger;
use App\Http\Exceptions\HttpException;
use App\Models\StorageBackend;
use App\Storage\LocalDriver;
use App\Storage\StorageDriver;
use App\Storage\StorageManager;
use App\Support\Str;
use Throwable;

/**
 * Manages the places files can live: creating and testing backends, deciding
 * where an upload goes, and moving existing blobs between them.
 */
final class StorageBackendService
{
    /** @return list<array> */
    public static function list(): array
    {
        return StorageBackend::listAll();
    }

    public static function create(array $input): array
    {
        $data = self::normalise($input);

        $data['uuid'] = Str::uuid();
        $data['slug'] = StorageBackend::uniqueSlug((string) ($input['slug'] ?? $data['name']));
        $data['secret'] = StorageBackend::mergeCredentials([], self::secretsFrom($input));
        $data['status'] = 'unknown';

        $id = StorageBackend::create($data);

        if (!empty($input['is_default'])) {
            StorageBackend::makeDefault($id);
        }

        StorageManager::reset();

        $backend = StorageBackend::find($id) ?? [];

        AuditService::log('storage.backend_create', 'storage', $id, 'Added storage backend ' . $data['name'], [
            'driver' => $data['driver'],
            'slug'   => $data['slug'],
        ]);

        return $backend;
    }

    public static function update(int $id, array $input): array
    {
        $backend = self::findOrFail($id);

        // The driver and slug are baked into every file row that points here.
        $data = self::normalise($input, (string) $backend['driver']);
        unset($data['driver']);

        $data['secret'] = StorageBackend::mergeCredentials($backend, self::secretsFrom($input));

        StorageBackend::updateById($id, $data);

        if (!empty($input['is_default'])) {
            StorageBackend::makeDefault($id);
        }

        StorageManager::reset();

        AuditService::log('storage.backend_update', 'storage', $id, 'Updated storage backend ' . $backend['name']);

        return StorageBackend::find($id) ?? [];
    }

    public static function makeDefault(int $id): array
    {
        $backend = self::findOrFail($id);

        if (!(bool) $backend['is_active']) {
            throw new HttpException(422, 'Enable the backend before making it the default.', 'backend_inactive');
        }

        StorageBackend::makeDefault($id);
        StorageManager::reset();

        AuditService::log('storage.backend_default', 'storage', $id, $backend['name'] . ' is now the default storage backend');

        return StorageBackend::find($id) ?? [];
    }

    public static function delete(int $id): void
    {
        $backend = self::findOrFail($id);
        $slug = (string) $backend['slug'];

        if ((bool) $backend['is_default']) {
            throw new HttpException(422, 'Make another backend the default before deleting this one.', 'backend_is_default');
        }

        $usage = self::usage($slug);

        if ($usage['files'] > 0 || $usage['versions'] > 0) {
            throw new HttpException(
                422,
                sprintf('%d file(s) still live on this backend — migrate them first.', $usage['files'] + $usage['versions']),
                'backend_in_use'
            );
        }

        StorageBackend::deleteById($id);
        StorageManager::reset();

        AuditService::log('storage.backend_delete', 'storage', $id, 'Deleted storage backend ' . $backend['name']);
    }

    /**
     * Write, read back and remove a probe object. Never throws — the caller
     * wants a verdict, not an exception.
     *
     * @return array{ok:bool, message:string, latency_ms:float}
     */
    public static function test(int|array $backend): array
    {
        $row = is_array($backend) ? $backend : self::findOrFail($backend);
        $startedAt = microtime(true);

        $probe = '.connection-test-' . bin2hex(random_bytes(6));
        $payload = 'storage backend check ' . date('c');

        try {
            $driver = StorageManager::fromBackend($row);

            if (!$driver->putContents($probe, $payload)) {
                throw new \RuntimeException('The server refused the test upload.');
            }

            $readBack = $driver->get($probe);
            $driver->delete($probe);

            if ($readBack !== $payload) {
                throw new \RuntimeException('The test file was written but read back wrong.');
            }

            $result = [
                'ok'         => true,
                'message'    => 'Connected — the backend is readable and writable.',
                'latency_ms' => round((microtime(true) - $startedAt) * 1000, 1),
            ];
        } catch (Throwable $e) {
            $result = [
                'ok'         => false,
                'message'    => $e->getMessage(),
                'latency_ms' => round((microtime(true) - $startedAt) * 1000, 1),
            ];
        }

        if (isset($row['id'])) {
            StorageBackend::updateById((int) $row['id'], [
                'status'          => $result['ok'] ? 'ok' : 'error',
                'last_error'      => $result['ok'] ? null : mb_substr($result['message'], 0, 500),
                'last_checked_at' => date('Y-m-d H:i:s'),
            ]);
        }

        return $result;
    }

    /**
     * Which backend an upload should go to. An unknown or disabled slug falls
     * back to the default rather than failing the upload.
     */
    public static function resolveForUpload(?string $requested): string
    {
        $requested = trim((string) $requested);

        if ($requested === '') {
            return StorageManager::defaultSlug();
        }

        $backend = StorageBackend::findBySlug($requested);

        if ($backend === null || !(bool) $backend['is_active']) {
            throw new HttpException(
                422,
                sprintf('There is no active storage backend called "%s".', $requested),
                'unknown_storage_backend'
            );
        }

        return (string) $backend['slug'];
    }

    /** @return array{files:int, versions:int, bytes:int} */
    public static function usage(string $slug): array
    {
        return [
            'files'    => (int) Database::scalar('SELECT COUNT(*) FROM files WHERE disk = ?', [$slug]),
            'versions' => (int) Database::scalar('SELECT COUNT(*) FROM file_versions WHERE disk = ?', [$slug]),
            'bytes'    => (int) Database::scalar('SELECT COALESCE(SUM(size), 0) FROM files WHERE disk = ?', [$slug]),
        ];
    }

    /** @return array<string, array{files:int, versions:int, bytes:int}> */
    public static function usageBySlug(): array
    {
        $usage = [];

        foreach (Database::select('SELECT disk, COUNT(*) AS files, COALESCE(SUM(size), 0) AS bytes FROM files GROUP BY disk') as $row) {
            $usage[(string) $row['disk']] = [
                'files'    => (int) $row['files'],
                'versions' => 0,
                'bytes'    => (int) $row['bytes'],
            ];
        }

        foreach (Database::select('SELECT disk, COUNT(*) AS versions FROM file_versions GROUP BY disk') as $row) {
            $slug = (string) $row['disk'];
            $usage[$slug] ??= ['files' => 0, 'versions' => 0, 'bytes' => 0];
            $usage[$slug]['versions'] = (int) $row['versions'];
        }

        return $usage;
    }

    /**
     * Move blobs from one backend to another, in batches.
     *
     * Driven entirely off the `disk` column, so it is safe to re-run: whatever
     * is left on the source is simply picked up on the next pass.
     *
     * @return array{moved:int, failed:int, bytes:int, remaining:int, errors:list<string>}
     */
    public static function migrate(string $fromSlug, string $toSlug, int $limit = 200): array
    {
        if ($fromSlug === $toSlug) {
            throw new HttpException(422, 'Pick two different backends.', 'same_backend');
        }

        $source = StorageManager::disk($fromSlug);
        $target = StorageManager::disk($toSlug);

        $limit = max(1, min(1000, $limit));
        $moved = 0;
        $failed = 0;
        $bytes = 0;
        $errors = [];

        $rows = array_merge(
            array_map(
                static fn (array $r): array => $r + ['table' => 'files'],
                Database::select('SELECT id, storage_path, size FROM files WHERE disk = ? ORDER BY id LIMIT ' . $limit, [$fromSlug])
            ),
            array_map(
                static fn (array $r): array => $r + ['table' => 'file_versions'],
                Database::select('SELECT id, storage_path, size FROM file_versions WHERE disk = ? ORDER BY id LIMIT ' . $limit, [$fromSlug])
            )
        );

        foreach ($rows as $row) {
            $path = (string) $row['storage_path'];

            try {
                if (!self::transfer($source, $target, $path)) {
                    throw new \RuntimeException('copy failed');
                }

                Database::statement(
                    sprintf('UPDATE %s SET disk = ? WHERE id = ?', $row['table']),
                    [$toSlug, (int) $row['id']]
                );

                // Only unlink once nothing left on the source still points here.
                if (!self::stillReferenced($fromSlug, $path)) {
                    $source->delete($path);
                }

                $moved++;
                $bytes += (int) $row['size'];
            } catch (Throwable $e) {
                $failed++;
                $message = $row['table'] . '#' . $row['id'] . ': ' . $e->getMessage();
                $errors[] = $message;
                Logger::error('Storage migration failed: ' . $message, ['from' => $fromSlug, 'to' => $toSlug]);
            }
        }

        $usage = self::usage($fromSlug);
        $remaining = $usage['files'] + $usage['versions'];

        AuditService::log('storage.migrate', 'storage', null, sprintf('Moved %d object(s) from %s to %s', $moved, $fromSlug, $toSlug), [
            'moved'     => $moved,
            'failed'    => $failed,
            'remaining' => $remaining,
        ]);

        return [
            'moved'     => $moved,
            'failed'    => $failed,
            'bytes'     => $bytes,
            'remaining' => $remaining,
            'errors'    => array_slice($errors, 0, 10),
        ];
    }

    /** Stream one object across, verifying the byte count on arrival. */
    private static function transfer(StorageDriver $source, StorageDriver $target, string $path): bool
    {
        if ($target->exists($path)) {
            return true; // A previous pass already copied it.
        }

        $stream = $source->readStream($path);

        if ($stream === null) {
            throw new \RuntimeException('the source object is missing');
        }

        $temp = rtrim((string) \App\Core\Config::get('storage.tmp_path'), '/\\') . '/migrate-' . bin2hex(random_bytes(8));
        $out = @fopen($temp, 'wb');

        if ($out === false) {
            fclose($stream);

            throw new \RuntimeException('could not open a temporary file');
        }

        $copied = stream_copy_to_stream($stream, $out);
        fclose($stream);
        fclose($out);

        try {
            if ($copied === false) {
                throw new \RuntimeException('the transfer was interrupted');
            }

            if (!$target->put($temp, $path, false)) {
                throw new \RuntimeException('the destination refused the write');
            }

            $written = $target->size($path);

            // Some backends do not report sizes; only compare when they do.
            if ($written > 0 && $written !== $copied) {
                $target->delete($path);

                throw new \RuntimeException(sprintf('size mismatch (%d sent, %d stored)', $copied, $written));
            }

            return true;
        } finally {
            @unlink($temp);
        }
    }

    private static function stillReferenced(string $slug, string $path): bool
    {
        $files = (int) Database::scalar('SELECT COUNT(*) FROM files WHERE disk = ? AND storage_path = ?', [$slug, $path]);
        $versions = (int) Database::scalar('SELECT COUNT(*) FROM file_versions WHERE disk = ? AND storage_path = ?', [$slug, $path]);

        return $files + $versions > 0;
    }

    /**
     * Adopt a driver that was only ever configured in .env.
     *
     * Installs that ran with STORAGE_DRIVER=s3 have no backend row, and the
     * seeded local disk would otherwise quietly become their default. Runs on
     * migrate and on install; does nothing once a row exists.
     */
    public static function adoptConfiguredDriver(): ?array
    {
        $slug = (string) \App\Core\Config::get('storage.driver', 'local');

        if ($slug === 'local' || StorageBackend::findBySlug($slug) !== null) {
            return null;
        }

        $config = (array) \App\Core\Config::get('storage.drivers.' . $slug, []);

        if ($config === []) {
            return null;
        }

        $id = StorageBackend::create([
            'uuid'      => Str::uuid(),
            'name'      => strtoupper($slug) . ' (from .env)',
            'slug'      => $slug,
            'driver'    => $slug,
            'root_path' => '',
            'secret'    => StorageBackend::mergeCredentials([], [
                'secret_key' => (string) ($config['secret_key'] ?? ''),
            ]),
            'options'   => [
                'timeout'    => 30,
                'endpoint'   => (string) ($config['endpoint'] ?? ''),
                'region'     => (string) ($config['region'] ?? 'us-east-1'),
                'bucket'     => (string) ($config['bucket'] ?? ''),
                'access_key' => (string) ($config['access_key'] ?? ''),
            ],
            'is_active' => true,
            'status'    => 'unknown',
        ]);

        StorageBackend::makeDefault($id);
        StorageManager::reset();

        return StorageBackend::find($id);
    }

    /** Backends that can appear in an upload destination picker. */
    public static function selectable(): array
    {
        return array_map(
            static fn (array $b): array => [
                'slug'       => (string) $b['slug'],
                'name'       => (string) $b['name'],
                'driver'     => (string) $b['driver'],
                'is_default' => (bool) $b['is_default'],
            ],
            StorageBackend::activeList()
        );
    }

    /** Whether the default backend keeps bytes on this machine. */
    public static function defaultIsLocal(): bool
    {
        try {
            return StorageManager::disk() instanceof LocalDriver;
        } catch (Throwable) {
            return false;
        }
    }

    private static function findOrFail(int $id): array
    {
        $backend = StorageBackend::find($id);

        if ($backend === null) {
            throw new HttpException(404, 'Storage backend not found.', 'backend_not_found');
        }

        return $backend;
    }

    /**
     * @return array<string, string|null>
     */
    private static function secretsFrom(array $input): array
    {
        $secrets = [];

        foreach (StorageBackend::SECRET_FIELDS as $field) {
            if (array_key_exists($field, $input)) {
                $secrets[$field] = $input[$field] === null ? null : (string) $input[$field];
            }
        }

        return $secrets;
    }

    /** Validate and shape the writable columns. */
    private static function normalise(array $input, ?string $currentDriver = null): array
    {
        $driver = strtolower(trim((string) ($input['driver'] ?? $currentDriver ?? '')));

        if (!array_key_exists($driver, StorageBackend::DRIVERS)) {
            throw new HttpException(422, 'Choose a valid storage driver.', 'invalid_driver', [
                'available' => array_keys(StorageBackend::DRIVERS),
            ]);
        }

        $name = trim((string) ($input['name'] ?? ''));

        if ($name === '') {
            throw new HttpException(422, 'Give the backend a name.', 'invalid_name');
        }

        $host = trim((string) ($input['host'] ?? ''));
        $username = trim((string) ($input['username'] ?? ''));
        $port = (int) ($input['port'] ?? 0);

        if (in_array($driver, ['ftp', 'ftps', 'sftp'], true)) {
            if ($host === '') {
                throw new HttpException(422, 'A host name or IP address is required.', 'invalid_host');
            }

            if ($username === '') {
                throw new HttpException(422, 'A username is required.', 'invalid_username');
            }

            if ($port <= 0) {
                $port = $driver === 'sftp' ? 22 : 21;
            }
        }

        $options = self::options($driver, (array) ($input['options'] ?? $input));

        if ($driver === 's3' && (($options['endpoint'] ?? '') === '' || ($options['bucket'] ?? '') === '')) {
            throw new HttpException(422, 'S3 backends need an endpoint and a bucket.', 'invalid_s3_config');
        }

        return [
            'name'      => mb_substr($name, 0, 100),
            'driver'    => $driver,
            'host'      => $host === '' ? null : mb_substr($host, 0, 190),
            'port'      => $port > 0 ? $port : null,
            'username'  => $username === '' ? null : mb_substr($username, 0, 190),
            // A leading slash is meaningful for SFTP (absolute vs home-relative).
            'root_path' => rtrim(trim(str_replace('\\', '/', (string) ($input['root_path'] ?? ''))), '/'),
            'options'   => $options,
            'is_active' => !array_key_exists('is_active', $input) || (bool) $input['is_active'],
        ];
    }

    /** @return array<string, mixed> */
    private static function options(string $driver, array $input): array
    {
        $bool = static fn (string $key, bool $default): bool => array_key_exists($key, $input)
            ? in_array((string) $input[$key], ['1', 'true', 'on', 'yes'], true)
            : $default;

        $timeout = (int) ($input['timeout'] ?? 0);

        $options = ['timeout' => $timeout > 0 ? min(600, $timeout) : 30];

        if ($driver === 'ftp' || $driver === 'ftps') {
            $options['passive'] = $bool('passive', true);
            $options['use_pasv_address'] = $bool('use_pasv_address', true);
        }

        if ($driver === 'sftp') {
            $options['host_fingerprint'] = trim((string) ($input['host_fingerprint'] ?? ''));
        }

        if ($driver === 's3') {
            $options['endpoint'] = rtrim(trim((string) ($input['endpoint'] ?? '')), '/');
            $options['region'] = trim((string) ($input['region'] ?? '')) ?: 'us-east-1';
            $options['bucket'] = trim((string) ($input['bucket'] ?? ''));
            $options['access_key'] = trim((string) ($input['access_key'] ?? ''));
        }

        return $options;
    }
}
