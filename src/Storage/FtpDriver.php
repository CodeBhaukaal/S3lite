<?php
declare(strict_types=1);

namespace App\Storage;

use RuntimeException;

/**
 * FTP and FTPS (explicit TLS) storage, built on ext-ftp.
 *
 * The connection is opened lazily on the first operation, so constructing a
 * driver for a backend that is never touched costs nothing.
 */
final class FtpDriver extends RemoteDriver
{
    /** @var \FTP\Connection|null */
    private $connection = null;

    public function __construct(
        string $slug,
        private string $host,
        private int $port,
        private string $username,
        private string $password,
        private bool $secure = false,
        string $rootPath = '',
        array $options = []
    ) {
        parent::__construct($slug, $rootPath, $options);

        if (!extension_loaded('ftp')) {
            throw new RuntimeException('The PHP ftp extension is not installed on this server.');
        }

        if ($this->host === '') {
            throw new RuntimeException('This FTP backend has no host configured.');
        }

        if ($this->port <= 0) {
            $this->port = 21;
        }
    }

    public function driver(): string
    {
        return $this->secure ? 'ftps' : 'ftp';
    }

    /** @return \FTP\Connection */
    private function connection()
    {
        if ($this->connection !== null) {
            return $this->connection;
        }

        $timeout = $this->timeout();

        if ($this->secure && !function_exists('ftp_ssl_connect')) {
            throw new RuntimeException('This PHP build cannot do FTPS (ftp_ssl_connect is unavailable).');
        }

        // Busy servers reject the odd login for no lasting reason, so give the
        // handshake one more go on a fresh socket before calling it a failure.
        $connection = false;
        $rejected = false;

        for ($attempt = 0; $attempt < 2; $attempt++) {
            if ($attempt > 0) {
                usleep(400000);
            }

            $socket = $this->secure
                ? @ftp_ssl_connect($this->host, $this->port, $timeout)
                : @ftp_connect($this->host, $this->port, $timeout);

            if ($socket === false) {
                continue;
            }

            if (@ftp_login($socket, $this->username, $this->password)) {
                $connection = $socket;
                break;
            }

            $rejected = true;
            @ftp_close($socket);
        }

        if ($connection === false) {
            throw new RuntimeException($rejected
                ? 'The FTP server rejected the username or password.'
                : "Could not reach {$this->host}:{$this->port}.");
        }

        @ftp_set_option($connection, FTP_TIMEOUT_SEC, $timeout);

        // Servers behind NAT often advertise an unroutable address in PASV
        // replies; ignoring it and reusing the control host fixes the hang.
        if ($this->option('use_pasv_address', true) === false) {
            @ftp_set_option($connection, FTP_USEPASVADDRESS, false);
        }

        if ($this->option('passive', true) !== false && !@ftp_pasv($connection, true)) {
            throw new RuntimeException('The FTP server refused passive mode.');
        }

        return $this->connection = $connection;
    }

    /**
     * Run a data transfer, retrying once on a freshly opened connection.
     *
     * Two things make that necessary. Servers quietly drop control connections
     * that have gone idle, and PHP's ext-ftp cannot perform more than one TLS
     * data transfer per connection against most servers — so on FTPS the
     * connection is deliberately thrown away after every transfer.
     */
    private function dataTransfer(callable $operation): bool
    {
        $ok = (bool) $operation($this->connection());

        if (!$ok) {
            $this->close();
            $ok = (bool) $operation($this->connection());
        }

        if ($this->secure) {
            $this->close();
        }

        return $ok;
    }

    public function put(string $sourcePath, string $targetPath, bool $moveSource = true): bool
    {
        $remote = $this->remotePath($targetPath);

        $ok = $this->dataTransfer(function ($connection) use ($remote, $sourcePath): bool {
            $this->ensureDirectory($remote);

            return @ftp_put($connection, $remote, $sourcePath, FTP_BINARY);
        });

        if ($ok && $moveSource) {
            @unlink($sourcePath);
        }

        return $ok;
    }

    /** @return resource|null */
    public function readStream(string $path, int $offset = 0)
    {
        $remote = $this->remotePath($path);
        $handle = null;

        $ok = $this->dataTransfer(function ($connection) use (&$handle, $remote, $offset): bool {
            // A retry needs an empty buffer, not the debris of the failed try.
            if ($handle !== null) {
                fclose($handle);
            }

            $handle = $this->temporaryStream();

            return @ftp_fget($connection, $handle, $remote, FTP_BINARY, max(0, $offset));
        });

        if (!$ok) {
            if ($handle !== null) {
                fclose($handle);
            }

            return null;
        }

        rewind($handle);

        return $handle;
    }

    public function exists(string $path): bool
    {
        $remote = $this->remotePath($path);
        $connection = $this->connection();

        if (@ftp_size($connection, $remote) >= 0) {
            return true;
        }

        // Some servers disable SIZE; MDTM is the usual fallback.
        return @ftp_mdtm($connection, $remote) > 0;
    }

    public function delete(string $path): bool
    {
        $remote = $this->remotePath($path);

        if (!$this->exists($path)) {
            return true;
        }

        $deleted = @ftp_delete($this->connection(), $remote);
        $this->pruneEmptyDirectories($remote);

        return $deleted;
    }

    public function move(string $from, string $to): bool
    {
        $target = $this->remotePath($to);
        $this->ensureDirectory($target);

        $source = $this->remotePath($from);

        if (!@ftp_rename($this->connection(), $source, $target)) {
            return false;
        }

        $this->pruneEmptyDirectories($source);

        return true;
    }

    public function size(string $path): int
    {
        $size = @ftp_size($this->connection(), $this->remotePath($path));

        return $size < 0 ? 0 : $size;
    }

    /** Create the target directory one segment at a time; FTP has no mkdir -p. */
    private function ensureDirectory(string $remotePath): void
    {
        $segments = $this->directorySegments($remotePath);

        if ($segments === []) {
            return;
        }

        $connection = $this->connection();
        $path = '';

        foreach ($segments as $segment) {
            $path = $path === '' ? $segment : $path . '/' . $segment;

            // mkdir fails when the directory already exists, which is fine.
            @ftp_mkdir($connection, $path);
        }
    }

    /** Walk up removing directories that just became empty, never past the root. */
    private function pruneEmptyDirectories(string $remotePath): void
    {
        $segments = $this->directorySegments($remotePath);
        $rootDepth = $this->rootPath === '' ? 0 : count(explode('/', $this->rootPath));
        $connection = $this->connection();
        $guard = 0;

        while ($guard++ < 8 && count($segments) > $rootDepth) {
            if (!@ftp_rmdir($connection, implode('/', $segments))) {
                return; // Not empty (or not permitted) — stop climbing.
            }

            array_pop($segments);
        }
    }

    public function close(): void
    {
        if ($this->connection !== null) {
            @ftp_close($this->connection);
            $this->connection = null;
        }
    }

    public function __destruct()
    {
        $this->close();
    }
}
