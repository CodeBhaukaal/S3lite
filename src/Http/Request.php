<?php
declare(strict_types=1);

namespace App\Http;

final class Request
{
    private array $attributes = [];
    private ?array $jsonCache = null;

    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query,
        public readonly array $body,
        public readonly array $files,
        public readonly array $server,
        public readonly array $cookies,
        public readonly string $rawBody,
        public readonly string $basePath
    ) {
    }

    public static function capture(): self
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

        // Allow method override for clients limited to GET/POST.
        $override = $_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE'] ?? ($_POST['_method'] ?? null);
        if ($method === 'POST' && is_string($override)) {
            $candidate = strtoupper($override);
            if (in_array($candidate, ['PUT', 'PATCH', 'DELETE'], true)) {
                $method = $candidate;
            }
        }

        $basePath = self::detectBasePath();
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';
        $path = urldecode($path);

        if ($basePath !== '' && str_starts_with($path, $basePath)) {
            $path = substr($path, strlen($basePath));
        }

        // Support /index.php/foo style routing when mod_rewrite is unavailable.
        if (str_starts_with($path, '/index.php')) {
            $path = substr($path, strlen('/index.php'));
        }

        $path = '/' . trim($path, '/');

        $raw = '';
        if (!in_array($method, ['GET', 'HEAD'], true)) {
            $contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
            // Streamed binary bodies are read by the controller, not buffered here.
            if (!str_contains($contentType, 'multipart/form-data') && !str_contains($contentType, 'application/octet-stream')) {
                $raw = (string) file_get_contents('php://input');
            }
        }

        $body = $_POST;
        if ($raw !== '' && $body === []) {
            $contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
            if (str_contains($contentType, 'application/json')) {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $body = $decoded;
                }
            } elseif (str_contains($contentType, 'application/x-www-form-urlencoded')) {
                parse_str($raw, $parsed);
                $body = $parsed;
            }
        }

        return new self(
            $method,
            $path,
            $_GET,
            $body,
            $_FILES,
            $_SERVER,
            $_COOKIE,
            $raw,
            $basePath
        );
    }

    private static function detectBasePath(): string
    {
        $script = (string) ($_SERVER['SCRIPT_NAME'] ?? '');
        $dir = rtrim(str_replace('\\', '/', dirname($script)), '/');

        return $dir === '/' ? '' : $dir;
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->body[$key] ?? $this->query[$key] ?? $default;
    }

    public function query(string $key, mixed $default = null): mixed
    {
        return $this->query[$key] ?? $default;
    }

    public function post(string $key, mixed $default = null): mixed
    {
        return $this->body[$key] ?? $default;
    }

    public function string(string $key, string $default = ''): string
    {
        $value = $this->input($key, $default);

        return is_scalar($value) ? trim((string) $value) : $default;
    }

    public function int(string $key, int $default = 0): int
    {
        $value = $this->input($key, $default);

        return is_numeric($value) ? (int) $value : $default;
    }

    public function bool(string $key, bool $default = false): bool
    {
        $value = $this->input($key, $default);

        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower((string) $value), ['1', 'true', 'yes', 'on'], true);
    }

    public function array(string $key, array $default = []): array
    {
        $value = $this->input($key, $default);

        return is_array($value) ? $value : $default;
    }

    public function all(): array
    {
        return array_merge($this->query, $this->body);
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->body) || array_key_exists($key, $this->query);
    }

    public function filled(string $key): bool
    {
        $value = $this->input($key);

        return $value !== null && $value !== '' && $value !== [];
    }

    public function json(): array
    {
        if ($this->jsonCache !== null) {
            return $this->jsonCache;
        }

        $decoded = json_decode($this->rawBody, true);

        return $this->jsonCache = is_array($decoded) ? $decoded : [];
    }

    public function file(string $key): ?array
    {
        $file = $this->files[$key] ?? null;

        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return null;
        }

        return $file;
    }

    /** Normalise both `files[]` and `file` shapes into a flat list. */
    public function fileList(string $key): array
    {
        $file = $this->files[$key] ?? null;
        if (!is_array($file)) {
            return [];
        }

        if (!is_array($file['name'] ?? null)) {
            return ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE ? [] : [$file];
        }

        $list = [];
        foreach (array_keys($file['name']) as $i) {
            if (($file['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            $list[] = [
                'name'     => $file['name'][$i],
                'type'     => $file['type'][$i] ?? '',
                'tmp_name' => $file['tmp_name'][$i],
                'error'    => $file['error'][$i],
                'size'     => $file['size'][$i] ?? 0,
            ];
        }

        return $list;
    }

    public function header(string $name, ?string $default = null): ?string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));

        if (isset($this->server[$key])) {
            return (string) $this->server[$key];
        }

        $direct = strtoupper(str_replace('-', '_', $name));
        if (in_array($direct, ['CONTENT_TYPE', 'CONTENT_LENGTH'], true) && isset($this->server[$direct])) {
            return (string) $this->server[$direct];
        }

        return $default;
    }

    public function bearerToken(): ?string
    {
        $header = $this->header('Authorization', '');

        if ($header !== null && preg_match('/^Bearer\s+(.+)$/i', $header, $m)) {
            return trim($m[1]);
        }

        return null;
    }

    public function apiKey(): ?string
    {
        $key = $this->header('X-Api-Key');

        return $key !== null && $key !== '' ? trim($key) : null;
    }

    public function ip(): string
    {
        foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'] as $key) {
            $value = $this->server[$key] ?? null;
            if (!is_string($value) || $value === '') {
                continue;
            }
            $ip = trim(explode(',', $value)[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP) !== false) {
                return $ip;
            }
        }

        return '0.0.0.0';
    }

    public function userAgent(): string
    {
        return substr((string) ($this->server['HTTP_USER_AGENT'] ?? ''), 0, 500);
    }

    public function isSecure(): bool
    {
        return ($this->server['HTTPS'] ?? '') === 'on'
            || (int) ($this->server['SERVER_PORT'] ?? 80) === 443
            || strtolower((string) ($this->server['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    }

    public function isApi(): bool
    {
        return str_starts_with($this->path, '/api/');
    }

    public function wantsJson(): bool
    {
        if ($this->isApi()) {
            return true;
        }

        $accept = (string) $this->header('Accept', '');
        if (str_contains($accept, 'application/json')) {
            return true;
        }

        return strtolower((string) $this->header('X-Requested-With', '')) === 'xmlhttprequest';
    }

    public function url(): string
    {
        return $this->basePath . $this->path;
    }

    public function fullUrl(): string
    {
        $query = $this->query === [] ? '' : '?' . http_build_query($this->query);

        return $this->url() . $query;
    }

    public function setAttribute(string $key, mixed $value): void
    {
        $this->attributes[$key] = $value;
    }

    public function attribute(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }

    public function attributes(): array
    {
        return $this->attributes;
    }
}
