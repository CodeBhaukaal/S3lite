<?php
declare(strict_types=1);

namespace App\Storage;

interface StorageDriver
{
    public function name(): string;

    /** Move an uploaded/temporary file into permanent storage. */
    public function put(string $sourcePath, string $targetPath, bool $moveSource = true): bool;

    public function putContents(string $targetPath, string $contents): bool;

    public function get(string $path): ?string;

    /** @return resource|null */
    public function readStream(string $path);

    public function exists(string $path): bool;

    public function delete(string $path): bool;

    public function copy(string $from, string $to): bool;

    public function move(string $from, string $to): bool;

    public function size(string $path): int;

    /** Absolute filesystem path, or null for remote drivers. */
    public function absolutePath(string $path): ?string;

    /** @return array{total:int, free:int, used:int} */
    public function diskUsage(): array;
}
