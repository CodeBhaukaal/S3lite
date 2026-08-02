<?php
declare(strict_types=1);

namespace App\Storage;

use RuntimeException;

final class LocalDriver implements StorageDriver
{
    private string $root;

    public function __construct(string $root, private string $slug = 'local')
    {
        $this->root = rtrim(str_replace('\\', '/', $root), '/');

        if (!is_dir($this->root) && !@mkdir($this->root, 0775, true) && !is_dir($this->root)) {
            throw new RuntimeException("Unable to create storage root: {$this->root}");
        }
    }

    public function name(): string
    {
        return $this->slug;
    }

    public function driver(): string
    {
        return 'local';
    }

    public function root(): string
    {
        return $this->root;
    }

    /**
     * Resolve a relative key to an absolute path, refusing anything that would
     * escape the storage root.
     */
    private function resolve(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        $path = ltrim($path, '/');

        if ($path === '' || str_contains($path, '..') || str_contains($path, "\0")) {
            throw new RuntimeException('Invalid storage path.');
        }

        return $this->root . '/' . $path;
    }

    private function ensureDirectory(string $absolute): void
    {
        $dir = dirname($absolute);

        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException("Unable to create directory: {$dir}");
        }
    }

    public function put(string $sourcePath, string $targetPath, bool $moveSource = true): bool
    {
        $absolute = $this->resolve($targetPath);
        $this->ensureDirectory($absolute);

        if ($moveSource) {
            if (is_uploaded_file($sourcePath)) {
                return move_uploaded_file($sourcePath, $absolute);
            }

            if (@rename($sourcePath, $absolute)) {
                return true;
            }
        }

        return @copy($sourcePath, $absolute);
    }

    public function putContents(string $targetPath, string $contents): bool
    {
        $absolute = $this->resolve($targetPath);
        $this->ensureDirectory($absolute);

        return file_put_contents($absolute, $contents, LOCK_EX) !== false;
    }

    public function get(string $path): ?string
    {
        $absolute = $this->resolve($path);

        if (!is_file($absolute)) {
            return null;
        }

        $contents = file_get_contents($absolute);

        return $contents === false ? null : $contents;
    }

    /** @return resource|null */
    public function readStream(string $path, int $offset = 0)
    {
        $absolute = $this->resolve($path);

        if (!is_file($absolute)) {
            return null;
        }

        $handle = @fopen($absolute, 'rb');

        if ($handle === false) {
            return null;
        }

        if ($offset > 0) {
            fseek($handle, $offset);
        }

        return $handle;
    }

    public function exists(string $path): bool
    {
        return is_file($this->resolve($path));
    }

    public function delete(string $path): bool
    {
        $absolute = $this->resolve($path);

        if (!is_file($absolute)) {
            return true;
        }

        $result = @unlink($absolute);
        $this->pruneEmptyDirectories(dirname($absolute));

        return $result;
    }

    public function copy(string $from, string $to): bool
    {
        $target = $this->resolve($to);
        $this->ensureDirectory($target);

        return @copy($this->resolve($from), $target);
    }

    public function move(string $from, string $to): bool
    {
        $target = $this->resolve($to);
        $this->ensureDirectory($target);

        return @rename($this->resolve($from), $target);
    }

    public function size(string $path): int
    {
        $absolute = $this->resolve($path);

        return is_file($absolute) ? (int) filesize($absolute) : 0;
    }

    public function absolutePath(string $path): ?string
    {
        return $this->resolve($path);
    }

    public function diskUsage(): array
    {
        $total = @disk_total_space($this->root);
        $free = @disk_free_space($this->root);

        $total = $total === false ? 0 : (int) $total;
        $free = $free === false ? 0 : (int) $free;

        return ['total' => $total, 'free' => $free, 'used' => max(0, $total - $free)];
    }

    /** Keep the tree tidy after deletions, but never climb above the root. */
    private function pruneEmptyDirectories(string $dir): void
    {
        $dir = str_replace('\\', '/', $dir);
        $guard = 0;

        while ($guard++ < 8 && $dir !== $this->root && str_starts_with($dir, $this->root)) {
            $entries = @scandir($dir);
            if ($entries === false || count(array_diff($entries, ['.', '..'])) > 0) {
                return;
            }

            @rmdir($dir);
            $dir = dirname($dir);
        }
    }

    /** Total bytes stored under the root (used for storage reporting). */
    public function usedBytes(): int
    {
        $total = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile()) {
                $total += $file->getSize();
            }
        }

        return $total;
    }
}
