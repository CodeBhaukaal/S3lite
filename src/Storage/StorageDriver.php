<?php
declare(strict_types=1);

namespace App\Storage;

interface StorageDriver
{
    /** The backend slug this instance serves — what gets stored in `files`.`disk`. */
    public function name(): string;

    /** The driver type: local, ftp, ftps, sftp or s3. */
    public function driver(): string;

    /** Move an uploaded/temporary file into permanent storage. */
    public function put(string $sourcePath, string $targetPath, bool $moveSource = true): bool;

    public function putContents(string $targetPath, string $contents): bool;

    public function get(string $path): ?string;

    /**
     * Read from $offset onwards. Remote drivers resume server-side instead of
     * transferring the whole object just to seek past it.
     *
     * @return resource|null
     */
    public function readStream(string $path, int $offset = 0);

    public function exists(string $path): bool;

    public function delete(string $path): bool;

    public function copy(string $from, string $to): bool;

    public function move(string $from, string $to): bool;

    public function size(string $path): int;

    /** Absolute filesystem path, or null for remote drivers. */
    public function absolutePath(string $path): ?string;

    /** @return array{total:int, free:int, used:int} Zeros when the driver cannot report. */
    public function diskUsage(): array;
}
