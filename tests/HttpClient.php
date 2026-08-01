<?php
declare(strict_types=1);

namespace Tests;

/**
 * Thin cURL wrapper used by the integration tests. Keeps a cookie jar so the
 * web panel can be driven exactly like a browser would.
 *
 * Note: this is a fluent client, not a response object — `lastStatus`,
 * `lastBody` and `data()` always describe the most recent request. Capture
 * anything you need before issuing the next call.
 */
final class HttpClient
{
    private string $cookieJar;
    private array $defaultHeaders = [];

    public int $lastStatus = 0;
    public array $lastHeaders = [];
    public string $lastBody = '';

    public function __construct(private string $baseUrl)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->cookieJar = sys_get_temp_dir() . '/s3lite-test-' . bin2hex(random_bytes(6)) . '.cookies';
    }

    public function __destruct()
    {
        if (is_file($this->cookieJar)) {
            @unlink($this->cookieJar);
        }
    }

    public function setHeader(string $name, string $value): void
    {
        $this->defaultHeaders[$name] = $value;
    }

    public function removeHeader(string $name): void
    {
        unset($this->defaultHeaders[$name]);
    }

    public function get(string $path, array $query = [], array $headers = []): self
    {
        $url = $path . ($query === [] ? '' : (str_contains($path, '?') ? '&' : '?') . http_build_query($query));

        return $this->request('GET', $url, null, $headers);
    }

    public function post(string $path, array|string|null $body = null, array $headers = []): self
    {
        return $this->request('POST', $path, $body, $headers);
    }

    public function postJson(string $path, array $body, array $headers = []): self
    {
        return $this->request('POST', $path, json_encode($body), array_merge(['Content-Type' => 'application/json'], $headers));
    }

    public function patchJson(string $path, array $body, array $headers = []): self
    {
        return $this->request('PATCH', $path, json_encode($body), array_merge(['Content-Type' => 'application/json'], $headers));
    }

    public function putJson(string $path, array $body, array $headers = []): self
    {
        return $this->request('PUT', $path, json_encode($body), array_merge(['Content-Type' => 'application/json'], $headers));
    }

    public function delete(string $path, array $headers = []): self
    {
        return $this->request('DELETE', $path, null, $headers);
    }

    public function upload(string $path, string $field, string $filePath, array $fields = [], array $headers = []): self
    {
        $body = $fields;
        $mime = @mime_content_type($filePath);
        $body[$field] = new \CURLFile($filePath, is_string($mime) && $mime !== '' ? $mime : 'application/octet-stream', basename($filePath));

        return $this->request('POST', $path, $body, $headers);
    }

    public function request(string $method, string $path, array|string|null $body = null, array $headers = []): self
    {
        $url = str_starts_with($path, 'http') ? $path : $this->baseUrl . '/' . ltrim($path, '/');

        $ch = curl_init($url);

        $headerList = [];
        foreach (array_merge($this->defaultHeaders, $headers) as $name => $value) {
            $headerList[] = $name . ': ' . $value;
        }

        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_COOKIEJAR      => $this->cookieJar,
            CURLOPT_COOKIEFILE     => $this->cookieJar,
            CURLOPT_HTTPHEADER     => $headerList,
        ]);

        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $response = curl_exec($ch);
        $this->lastStatus = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new \RuntimeException('HTTP request failed: ' . $error . ' (' . $method . ' ' . $url . ')');
        }

        $rawHeaders = substr((string) $response, 0, $headerSize);
        $this->lastBody = substr((string) $response, $headerSize);

        $this->lastHeaders = [];
        foreach (explode("\r\n", $rawHeaders) as $line) {
            if (str_contains($line, ':')) {
                [$name, $value] = explode(':', $line, 2);
                $this->lastHeaders[strtolower(trim($name))] = trim($value);
            }
        }

        return $this;
    }

    public function json(): array
    {
        $decoded = json_decode($this->lastBody, true);

        return is_array($decoded) ? $decoded : [];
    }

    public function data(): mixed
    {
        return $this->json()['data'] ?? null;
    }

    public function errorCode(): ?string
    {
        return $this->json()['error']['code'] ?? null;
    }

    public function errorMessage(): ?string
    {
        return $this->json()['error']['message'] ?? null;
    }

    public function succeeded(): bool
    {
        return ($this->json()['success'] ?? false) === true;
    }

    public function header(string $name): ?string
    {
        return $this->lastHeaders[strtolower($name)] ?? null;
    }

    /** Scrape the CSRF token out of a rendered page. */
    public function csrfToken(): string
    {
        if (preg_match('/name="csrf-token" content="([^"]+)"/', $this->lastBody, $m) === 1) {
            return $m[1];
        }

        if (preg_match('/name="_token" value="([^"]+)"/', $this->lastBody, $m) === 1) {
            return $m[1];
        }

        return '';
    }

    public function location(): ?string
    {
        return $this->header('location');
    }
}
