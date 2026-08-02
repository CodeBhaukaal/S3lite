<?php
declare(strict_types=1);

namespace App\Storage;

use App\Core\Config;
use RuntimeException;

/**
 * Shared plumbing for drivers that talk to another machine: path handling,
 * spooled temp streams and the "download, change, re-upload" fallbacks for
 * operations remote protocols do not implement natively.
 */
abstract class RemoteDriver implements StorageDriver
{
    /** Buffer this much of a read in RAM before spilling to a temp file. */
    protected const MEMORY_BUFFER = 2097152; // 2 MB

    public function __construct(
        protected string $slug,
        protected string $rootPath = '',
        protected array $options = []
    ) {
        $this->rootPath = trim(str_replace('\\', '/', $this->rootPath), '/');
    }

    public function name(): string
    {
        return $this->slug;
    }

    public function absolutePath(string $path): ?string
    {
        return null;
    }

    public function diskUsage(): array
    {
        // Remote protocols have no portable "df". Callers treat 0 as "unknown".
        return ['total' => 0, 'free' => 0, 'used' => 0];
    }

    /**
     * Turn a storage key into a path on the remote server, refusing anything
     * that would climb out of the configured root.
     */
    protected function remotePath(string $path): string
    {
        $path = ltrim(str_replace('\\', '/', $path), '/');

        if ($path === '' || str_contains($path, "\0")) {
            throw new RuntimeException('Invalid storage path.');
        }

        foreach (explode('/', $path) as $segment) {
            if ($segment === '..' || $segment === '.') {
                throw new RuntimeException('Invalid storage path.');
            }
        }

        return $this->rootPath === '' ? $path : $this->rootPath . '/' . $path;
    }

    /** Directory segments that must exist before writing $remotePath. */
    protected function directorySegments(string $remotePath): array
    {
        $dir = trim((string) preg_replace('#/[^/]*$#', '', $remotePath), '/');

        return $dir === '' ? [] : explode('/', $dir);
    }

    protected function option(string $key, mixed $default = null): mixed
    {
        return $this->options[$key] ?? $default;
    }

    protected function timeout(): int
    {
        $timeout = (int) $this->option('timeout', (int) Config::get('storage.remote.timeout', 30));

        return $timeout > 0 ? $timeout : 30;
    }

    /** @return resource */
    protected function temporaryStream()
    {
        $handle = fopen('php://temp/maxmemory:' . self::MEMORY_BUFFER, 'w+b');

        if ($handle === false) {
            throw new RuntimeException('Unable to open a temporary stream.');
        }

        return $handle;
    }

    /** A scratch file under storage/tmp, for protocols that need a real path. */
    protected function temporaryFile(string $prefix): string
    {
        $dir = rtrim((string) Config::get('storage.tmp_path'), '/\\');

        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException("Unable to create the temporary directory: {$dir}");
        }

        return $dir . '/' . $prefix . '-' . bin2hex(random_bytes(8));
    }

    public function get(string $path): ?string
    {
        $stream = $this->readStream($path);

        if ($stream === null) {
            return null;
        }

        $contents = stream_get_contents($stream);
        fclose($stream);

        return $contents === false ? null : $contents;
    }

    public function putContents(string $targetPath, string $contents): bool
    {
        $temp = $this->temporaryFile('put');

        if (file_put_contents($temp, $contents) === false) {
            return false;
        }

        try {
            return $this->put($temp, $targetPath, false);
        } finally {
            @unlink($temp);
        }
    }

    /** No remote protocol here has a server-side copy, so round-trip the bytes. */
    public function copy(string $from, string $to): bool
    {
        $stream = $this->readStream($from);

        if ($stream === null) {
            return false;
        }

        $temp = $this->temporaryFile('copy');
        $out = @fopen($temp, 'wb');

        if ($out === false) {
            fclose($stream);

            return false;
        }

        stream_copy_to_stream($stream, $out);
        fclose($stream);
        fclose($out);

        try {
            return $this->put($temp, $to, false);
        } finally {
            @unlink($temp);
        }
    }
}
