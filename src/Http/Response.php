<?php
declare(strict_types=1);

namespace App\Http;

final class Response
{
    private array $headers = [];
    private array $cookies = [];
    private ?\Closure $streamer = null;

    public function __construct(
        private string $content = '',
        private int $status = 200,
        array $headers = []
    ) {
        foreach ($headers as $name => $value) {
            $this->headers[strtolower($name)] = [$name, $value];
        }
    }

    public static function make(string $content = '', int $status = 200, array $headers = []): self
    {
        return new self($content, $status, $headers);
    }

    public static function html(string $html, int $status = 200): self
    {
        return new self($html, $status, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    public static function text(string $text, int $status = 200): self
    {
        return new self($text, $status, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }

    public static function json(mixed $data, int $status = 200, array $headers = []): self
    {
        $payload = json_encode(
            $data,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR
        );

        return new self(
            $payload === false ? '{"success":false,"error":{"code":"encoding_error"}}' : $payload,
            $status,
            array_merge(['Content-Type' => 'application/json; charset=UTF-8'], $headers)
        );
    }

    /** Standard success envelope used by every API endpoint. */
    public static function apiSuccess(mixed $data = null, int $status = 200, array $meta = []): self
    {
        $payload = ['success' => true, 'data' => $data];

        if ($meta !== []) {
            $payload['meta'] = $meta;
        }

        return self::json($payload, $status);
    }

    /** Standard error envelope used by every API endpoint. */
    public static function apiError(string $code, string $message, int $status = 400, array $details = []): self
    {
        $error = ['code' => $code, 'message' => $message];

        if ($details !== []) {
            $error['details'] = $details;
        }

        return self::json(['success' => false, 'error' => $error], $status);
    }

    public static function redirect(string $url, int $status = 302): self
    {
        return new self('', $status, ['Location' => $url]);
    }

    public static function noContent(): self
    {
        return new self('', 204);
    }

    /**
     * Stream a callback's output (used for downloads so large files never hit memory).
     */
    public static function stream(callable $callback, int $status = 200, array $headers = []): self
    {
        $response = new self('', $status, $headers);
        $response->streamer = \Closure::fromCallable($callback);

        return $response;
    }

    public function header(string $name, string $value): self
    {
        $this->headers[strtolower($name)] = [$name, $value];

        return $this;
    }

    public function withHeaders(array $headers): self
    {
        foreach ($headers as $name => $value) {
            $this->header($name, (string) $value);
        }

        return $this;
    }

    public function cookie(
        string $name,
        string $value,
        int $expires = 0,
        string $path = '/',
        bool $secure = false,
        bool $httpOnly = true,
        string $sameSite = 'Lax'
    ): self {
        $this->cookies[] = compact('name', 'value', 'expires', 'path', 'secure', 'httpOnly', 'sameSite');

        return $this;
    }

    public function status(): int
    {
        return $this->status;
    }

    public function setStatus(int $status): self
    {
        $this->status = $status;

        return $this;
    }

    public function content(): string
    {
        return $this->content;
    }

    public function headers(): array
    {
        $out = [];
        foreach ($this->headers as [$name, $value]) {
            $out[$name] = $value;
        }

        return $out;
    }

    public function send(): void
    {
        if (!headers_sent()) {
            http_response_code($this->status);

            foreach ($this->headers as [$name, $value]) {
                header($name . ': ' . $value, true);
            }

            foreach ($this->cookies as $cookie) {
                setcookie($cookie['name'], $cookie['value'], [
                    'expires'  => $cookie['expires'],
                    'path'     => $cookie['path'],
                    'secure'   => $cookie['secure'],
                    'httponly' => $cookie['httpOnly'],
                    'samesite' => $cookie['sameSite'],
                ]);
            }
        }

        if ($this->streamer !== null) {
            // Downloads must not be buffered or compressed.
            while (ob_get_level() > 0) {
                ob_end_flush();
            }
            ($this->streamer)();

            return;
        }

        echo $this->content;
    }
}
