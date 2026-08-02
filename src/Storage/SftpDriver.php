<?php
declare(strict_types=1);

namespace App\Storage;

use RuntimeException;

/**
 * SFTP storage over cURL's sftp:// protocol (libcurl + libssh2).
 *
 * ext-ssh2 is rarely available on shared hosting, but a curl built with SFTP
 * support usually is — check with `curl_version()['protocols']`.
 */
final class SftpDriver extends RemoteDriver
{
    /** CURLE_REMOTE_FILE_NOT_FOUND is not exposed as a PHP constant. */
    private const NOT_FOUND = [78, CURLE_FTP_COULDNT_RETR_FILE];

    private bool $absoluteRoot;
    private ?string $keyFile = null;
    private ?string $lastError = null;

    public function __construct(
        string $slug,
        private string $host,
        private int $port,
        private string $username,
        private string $password = '',
        private string $privateKey = '',
        private string $passphrase = '',
        string $rootPath = '',
        array $options = []
    ) {
        // A root that starts with "/" is absolute; otherwise it is relative to
        // the login user's home directory, which curl expresses as one slash.
        $this->absoluteRoot = str_starts_with(trim(str_replace('\\', '/', $rootPath)), '/');

        parent::__construct($slug, $rootPath, $options);

        if (!self::isSupported()) {
            throw new RuntimeException('This server\'s cURL build has no SFTP support, so SFTP backends cannot be used.');
        }

        if ($this->host === '') {
            throw new RuntimeException('This SFTP backend has no host configured.');
        }

        if ($this->password === '' && $this->privateKey === '') {
            throw new RuntimeException('This SFTP backend needs either a password or a private key.');
        }

        if ($this->port <= 0) {
            $this->port = 22;
        }
    }

    public static function isSupported(): bool
    {
        return function_exists('curl_version')
            && in_array('sftp', (array) (curl_version()['protocols'] ?? []), true);
    }

    public function driver(): string
    {
        return 'sftp';
    }

    public function put(string $sourcePath, string $targetPath, bool $moveSource = true): bool
    {
        $handle = @fopen($sourcePath, 'rb');

        if ($handle === false) {
            return false;
        }

        [$ok] = $this->execute($this->url($targetPath), [
            CURLOPT_UPLOAD     => true,
            CURLOPT_INFILE     => $handle,
            CURLOPT_INFILESIZE => (int) filesize($sourcePath),
            CURLOPT_FTP_CREATE_MISSING_DIRS => CURLFTP_CREATE_DIR_RETRY,
        ]);

        fclose($handle);

        if ($ok && $moveSource) {
            @unlink($sourcePath);
        }

        return $ok;
    }

    /** @return resource|null */
    public function readStream(string $path, int $offset = 0)
    {
        $stream = $this->temporaryStream();

        $extra = [CURLOPT_FILE => $stream];

        if ($offset > 0) {
            // A string range keeps 64-bit offsets intact on every platform.
            $extra[CURLOPT_RANGE] = $offset . '-';
        }

        [$ok] = $this->execute($this->url($path), $extra);

        if (!$ok) {
            fclose($stream);

            return null;
        }

        rewind($stream);

        return $stream;
    }

    public function exists(string $path): bool
    {
        [$ok] = $this->execute($this->url($path), [CURLOPT_NOBODY => true]);

        return $ok;
    }

    public function size(string $path): int
    {
        [$ok, , $info] = $this->execute($this->url($path), [CURLOPT_NOBODY => true]);

        if (!$ok) {
            return 0;
        }

        $size = (float) ($info['download_content_length'] ?? 0);

        return $size > 0 ? (int) $size : 0;
    }

    public function delete(string $path): bool
    {
        if (!$this->exists($path)) {
            return true;
        }

        $remote = $this->remotePath($path);

        [$ok] = $this->execute($this->directoryUrl(), [
            CURLOPT_NOBODY => true,
            CURLOPT_QUOTE  => array_merge(
                [$this->command('rm', [$this->absolute($remote)])],
                $this->pruneCommands($remote)
            ),
        ]);

        return $ok;
    }

    public function move(string $from, string $to): bool
    {
        $source = $this->remotePath($from);
        $target = $this->remotePath($to);

        [$ok] = $this->execute($this->directoryUrl(), [
            CURLOPT_NOBODY => true,
            CURLOPT_QUOTE  => array_merge(
                $this->mkdirCommands($target),
                [$this->command('rename', [$this->absolute($source), $this->absolute($target)])],
                $this->pruneCommands($source)
            ),
        ]);

        return $ok;
    }

    /** Absolute or home-relative, matching how the URL is built. */
    private function absolute(string $remotePath): string
    {
        return ($this->absoluteRoot ? '/' : '') . $remotePath;
    }

    private function url(string $path): string
    {
        $remote = $this->remotePath($path);
        $encoded = implode('/', array_map('rawurlencode', explode('/', $remote)));

        return sprintf('sftp://%s:%d/%s%s', $this->host, $this->port, $this->absoluteRoot ? '/' : '', $encoded);
    }

    /** A cheap URL to attach quote commands to. */
    private function directoryUrl(): string
    {
        return sprintf('sftp://%s:%d/%s', $this->host, $this->port, $this->absoluteRoot ? '/' : '');
    }

    /**
     * Quote commands take literal paths. A leading `*` tells curl to carry on
     * when the command fails, which is what we want for mkdir/rmdir.
     */
    private function command(string $verb, array $paths, bool $ignoreFailure = false): string
    {
        $quoted = array_map(static fn (string $p): string => '"' . str_replace('"', '\"', $p) . '"', $paths);

        return ($ignoreFailure ? '*' : '') . $verb . ' ' . implode(' ', $quoted);
    }

    /** @return list<string> */
    private function mkdirCommands(string $remotePath): array
    {
        $commands = [];
        $path = '';

        foreach ($this->directorySegments($remotePath) as $segment) {
            $path = $path === '' ? $segment : $path . '/' . $segment;
            $commands[] = $this->command('mkdir', [$this->absolute($path)], true);
        }

        return $commands;
    }

    /** Remove directories that just became empty, never climbing past the root. */
    private function pruneCommands(string $remotePath): array
    {
        $segments = $this->directorySegments($remotePath);
        $rootDepth = $this->rootPath === '' ? 0 : count(explode('/', $this->rootPath));

        $commands = [];

        while (count($segments) > $rootDepth && count($commands) < 8) {
            $commands[] = $this->command('rmdir', [$this->absolute(implode('/', $segments))], true);
            array_pop($segments);
        }

        return $commands;
    }

    /**
     * @param array<int, mixed> $extra
     * @return array{0:bool, 1:string, 2:array}
     */
    private function execute(string $url, array $extra): array
    {
        $ch = curl_init($url);

        if ($ch === false) {
            throw new RuntimeException('Unable to initialise cURL.');
        }

        curl_setopt_array($ch, array_replace([
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => min(30, $this->timeout()),
            CURLOPT_TIMEOUT        => max(60, $this->timeout() * 10),
            CURLOPT_USERNAME       => $this->username,
            CURLOPT_PORT           => $this->port,
        ], $extra));

        if ($this->privateKey !== '') {
            curl_setopt($ch, CURLOPT_SSH_PRIVATE_KEYFILE, $this->privateKeyFile());
            curl_setopt($ch, CURLOPT_SSH_PUBLIC_KEYFILE, '');

            if ($this->passphrase !== '') {
                curl_setopt($ch, CURLOPT_KEYPASSWD, $this->passphrase);
            }
        }

        if ($this->password !== '') {
            curl_setopt($ch, CURLOPT_PASSWORD, $this->password);
        }

        $this->pinHostKey($ch);

        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $info = curl_getinfo($ch);
        curl_close($ch);

        if ($errno !== 0) {
            if (!in_array($errno, self::NOT_FOUND, true)) {
                $this->lastError = $error;
            }

            return [false, '', $info];
        }

        $this->lastError = null;

        return [true, is_string($body) ? $body : '', $info];
    }

    /**
     * Optional host-key pinning. A 32-character hex string is an MD5
     * fingerprint; anything else is treated as base64 SHA-256.
     *
     * @param \CurlHandle $ch
     */
    private function pinHostKey($ch): void
    {
        $fingerprint = trim((string) $this->option('host_fingerprint', ''));

        if ($fingerprint === '') {
            return;
        }

        if (preg_match('/^[0-9a-f]{32}$/i', str_replace(':', '', $fingerprint)) === 1) {
            curl_setopt($ch, CURLOPT_SSH_HOST_PUBLIC_KEY_MD5, str_replace(':', '', $fingerprint));

            return;
        }

        if (defined('CURLOPT_SSH_HOST_PUBLIC_KEY_SHA256')) {
            curl_setopt($ch, constant('CURLOPT_SSH_HOST_PUBLIC_KEY_SHA256'), $fingerprint);
        }
    }

    /** The last transport error, for the connection tester. */
    public function lastError(): ?string
    {
        return $this->lastError;
    }

    /** libssh2 wants a real file, so the key is spilled to storage/tmp with 0600. */
    private function privateKeyFile(): string
    {
        if ($this->keyFile !== null && is_file($this->keyFile)) {
            return $this->keyFile;
        }

        $path = $this->temporaryFile('sshkey');

        if (file_put_contents($path, rtrim($this->privateKey, "\r\n") . "\n") === false) {
            throw new RuntimeException('Unable to stage the SSH private key.');
        }

        @chmod($path, 0600);

        return $this->keyFile = $path;
    }

    public function __destruct()
    {
        if ($this->keyFile !== null) {
            @unlink($this->keyFile);
            $this->keyFile = null;
        }
    }
}
