<?php
declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/**
 * Pure-PHP RESP client. The php_redis extension is not required, so the same
 * code path works on stock XAMPP builds and inside Docker.
 */
final class RedisClient
{
    /** @var resource|null */
    private $socket = null;
    private bool $failed = false;

    public function __construct(
        private string $host = '127.0.0.1',
        private int $port = 6379,
        private string $password = '',
        private int $database = 0,
        private float $timeout = 2.0,
        private string $prefix = ''
    ) {
    }

    public function connect(): bool
    {
        if (is_resource($this->socket)) {
            return true;
        }
        if ($this->failed) {
            return false;
        }

        $errno = 0;
        $errstr = '';
        $socket = @stream_socket_client(
            sprintf('tcp://%s:%d', $this->host, $this->port),
            $errno,
            $errstr,
            $this->timeout
        );

        if ($socket === false) {
            $this->failed = true;

            return false;
        }

        stream_set_timeout($socket, (int) $this->timeout);
        $this->socket = $socket;

        try {
            if ($this->password !== '') {
                $this->command(['AUTH', $this->password]);
            }
            if ($this->database > 0) {
                $this->command(['SELECT', (string) $this->database]);
            }
        } catch (\Throwable) {
            $this->failed = true;
            $this->close();

            return false;
        }

        return true;
    }

    public function isAvailable(): bool
    {
        return $this->connect();
    }

    public function close(): void
    {
        if (is_resource($this->socket)) {
            @fclose($this->socket);
        }
        $this->socket = null;
    }

    private function key(string $key): string
    {
        return $this->prefix . $key;
    }

    /**
     * @param list<string> $args
     */
    public function command(array $args): mixed
    {
        if (!is_resource($this->socket) && !$this->connect()) {
            throw new RuntimeException('Redis unavailable');
        }

        $payload = '*' . count($args) . "\r\n";
        foreach ($args as $arg) {
            $arg = (string) $arg;
            $payload .= '$' . strlen($arg) . "\r\n" . $arg . "\r\n";
        }

        if (@fwrite($this->socket, $payload) === false) {
            $this->close();
            throw new RuntimeException('Redis write failed');
        }

        return $this->readReply();
    }

    private function readReply(): mixed
    {
        $line = fgets($this->socket);

        if ($line === false) {
            $this->close();
            throw new RuntimeException('Redis read failed');
        }

        $type = $line[0];
        $value = substr($line, 1, -2);

        return match ($type) {
            '+'     => $value,
            '-'     => throw new RuntimeException('Redis error: ' . $value),
            ':'     => (int) $value,
            '$'     => $this->readBulk((int) $value),
            '*'     => $this->readArray((int) $value),
            default => throw new RuntimeException('Unknown Redis reply: ' . $type),
        };
    }

    private function readBulk(int $length): ?string
    {
        if ($length === -1) {
            return null;
        }

        $data = '';
        $remaining = $length + 2;

        while ($remaining > 0) {
            $chunk = fread($this->socket, $remaining);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $data .= $chunk;
            $remaining -= strlen($chunk);
        }

        return substr($data, 0, $length);
    }

    private function readArray(int $count): ?array
    {
        if ($count === -1) {
            return null;
        }

        $items = [];
        for ($i = 0; $i < $count; $i++) {
            $items[] = $this->readReply();
        }

        return $items;
    }

    public function get(string $key): ?string
    {
        $value = $this->command(['GET', $this->key($key)]);

        return is_string($value) ? $value : null;
    }

    public function set(string $key, string $value, ?int $ttl = null): bool
    {
        $args = ['SET', $this->key($key), $value];
        if ($ttl !== null && $ttl > 0) {
            $args[] = 'EX';
            $args[] = (string) $ttl;
        }

        return $this->command($args) === 'OK';
    }

    public function del(string ...$keys): int
    {
        $args = ['DEL'];
        foreach ($keys as $key) {
            $args[] = $this->key($key);
        }

        return (int) $this->command($args);
    }

    public function exists(string $key): bool
    {
        return (int) $this->command(['EXISTS', $this->key($key)]) > 0;
    }

    public function incr(string $key): int
    {
        return (int) $this->command(['INCR', $this->key($key)]);
    }

    public function incrBy(string $key, int $by): int
    {
        return (int) $this->command(['INCRBY', $this->key($key), (string) $by]);
    }

    public function expire(string $key, int $ttl): bool
    {
        return (int) $this->command(['EXPIRE', $this->key($key), (string) $ttl]) === 1;
    }

    public function ttl(string $key): int
    {
        return (int) $this->command(['TTL', $this->key($key)]);
    }

    public function keys(string $pattern): array
    {
        $result = $this->command(['KEYS', $this->key($pattern)]);

        return is_array($result) ? $result : [];
    }

    public function flushPrefix(): int
    {
        $keys = $this->keys('*');
        if ($keys === []) {
            return 0;
        }

        return (int) $this->command(array_merge(['DEL'], $keys));
    }

    public function ping(): bool
    {
        try {
            return $this->command(['PING']) === 'PONG';
        } catch (\Throwable) {
            return false;
        }
    }

    public function info(): array
    {
        try {
            $raw = (string) $this->command(['INFO']);
        } catch (\Throwable) {
            return [];
        }

        $info = [];
        foreach (explode("\n", $raw) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, ':')) {
                continue;
            }
            [$k, $v] = explode(':', $line, 2);
            $info[$k] = $v;
        }

        return $info;
    }

    public function lpush(string $key, string $value): int
    {
        return (int) $this->command(['LPUSH', $this->key($key), $value]);
    }

    public function rpop(string $key): ?string
    {
        $value = $this->command(['RPOP', $this->key($key)]);

        return is_string($value) ? $value : null;
    }

    public function zadd(string $key, float $score, string $member): int
    {
        return (int) $this->command(['ZADD', $this->key($key), (string) $score, $member]);
    }

    public function zcount(string $key, string $min, string $max): int
    {
        return (int) $this->command(['ZCOUNT', $this->key($key), $min, $max]);
    }

    public function zremrangebyscore(string $key, string $min, string $max): int
    {
        return (int) $this->command(['ZREMRANGEBYSCORE', $this->key($key), $min, $max]);
    }
}
