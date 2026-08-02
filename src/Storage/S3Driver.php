<?php
declare(strict_types=1);

namespace App\Storage;

use RuntimeException;

/**
 * S3-compatible driver using SigV4 over cURL. No SDK dependency.
 *
 * Enable by setting STORAGE_DRIVER=s3 plus the S3_* variables in .env.
 */
final class S3Driver implements StorageDriver
{
    public function __construct(
        private string $endpoint,
        private string $region,
        private string $bucket,
        private string $accessKey,
        private string $secretKey,
        private string $slug = 's3'
    ) {
        if ($this->endpoint === '' || $this->bucket === '' || $this->accessKey === '') {
            throw new RuntimeException('S3 driver is not configured.');
        }

        $this->endpoint = rtrim($this->endpoint, '/');
    }

    public function name(): string
    {
        return $this->slug;
    }

    public function driver(): string
    {
        return 's3';
    }

    public function put(string $sourcePath, string $targetPath, bool $moveSource = true): bool
    {
        $contents = file_get_contents($sourcePath);

        if ($contents === false) {
            return false;
        }

        $ok = $this->putContents($targetPath, $contents);

        if ($ok && $moveSource) {
            @unlink($sourcePath);
        }

        return $ok;
    }

    public function putContents(string $targetPath, string $contents): bool
    {
        [$status] = $this->request('PUT', $targetPath, $contents);

        return $status >= 200 && $status < 300;
    }

    public function get(string $path): ?string
    {
        [$status, $body] = $this->request('GET', $path);

        return $status === 200 ? $body : null;
    }

    /** @return resource|null */
    public function readStream(string $path, int $offset = 0)
    {
        // Let S3 do the seeking instead of pulling bytes we would discard.
        $extraHeaders = $offset > 0 ? ['Range: bytes=' . $offset . '-'] : [];
        [$status, $body] = $this->request('GET', $path, '', $extraHeaders);

        $contents = $status === 200 || $status === 206 ? $body : null;

        if ($contents === null) {
            return null;
        }

        $handle = fopen('php://temp', 'r+b');
        if ($handle === false) {
            return null;
        }

        fwrite($handle, $contents);
        rewind($handle);

        return $handle;
    }

    public function exists(string $path): bool
    {
        [$status] = $this->request('HEAD', $path);

        return $status === 200;
    }

    public function delete(string $path): bool
    {
        [$status] = $this->request('DELETE', $path);

        return $status >= 200 && $status < 300;
    }

    public function copy(string $from, string $to): bool
    {
        $contents = $this->get($from);

        return $contents !== null && $this->putContents($to, $contents);
    }

    public function move(string $from, string $to): bool
    {
        return $this->copy($from, $to) && $this->delete($from);
    }

    public function size(string $path): int
    {
        [$status, , $headers] = $this->request('HEAD', $path);

        return $status === 200 ? (int) ($headers['content-length'] ?? 0) : 0;
    }

    public function absolutePath(string $path): ?string
    {
        return null;
    }

    public function diskUsage(): array
    {
        return ['total' => 0, 'free' => 0, 'used' => 0];
    }

    /**
     * @param list<string> $extraHeaders
     * @return array{0:int, 1:string, 2:array<string,string>}
     */
    private function request(string $method, string $key, string $body = '', array $extraHeaders = []): array
    {
        $key = ltrim($key, '/');
        $url = sprintf('%s/%s/%s', $this->endpoint, $this->bucket, $key);
        $host = (string) parse_url($this->endpoint, PHP_URL_HOST);

        $amzDate = gmdate('Ymd\THis\Z');
        $dateStamp = gmdate('Ymd');
        $payloadHash = hash('sha256', $body);

        $canonicalHeaders = "host:{$host}\nx-amz-content-sha256:{$payloadHash}\nx-amz-date:{$amzDate}\n";
        $signedHeaders = 'host;x-amz-content-sha256;x-amz-date';

        $canonicalRequest = implode("\n", [
            $method,
            '/' . $this->bucket . '/' . $key,
            '',
            $canonicalHeaders,
            $signedHeaders,
            $payloadHash,
        ]);

        $scope = "{$dateStamp}/{$this->region}/s3/aws4_request";
        $stringToSign = implode("\n", [
            'AWS4-HMAC-SHA256',
            $amzDate,
            $scope,
            hash('sha256', $canonicalRequest),
        ]);

        $kDate = hash_hmac('sha256', $dateStamp, 'AWS4' . $this->secretKey, true);
        $kRegion = hash_hmac('sha256', $this->region, $kDate, true);
        $kService = hash_hmac('sha256', 's3', $kRegion, true);
        $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);
        $signature = hash_hmac('sha256', $stringToSign, $kSigning);

        $authorization = sprintf(
            'AWS4-HMAC-SHA256 Credential=%s/%s, SignedHeaders=%s, Signature=%s',
            $this->accessKey,
            $scope,
            $signedHeaders,
            $signature
        );

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_NOBODY         => $method === 'HEAD',
            CURLOPT_TIMEOUT        => 60,
            // Range is not part of the signed header set, so it can ride along.
            CURLOPT_HTTPHEADER     => array_merge([
                'Host: ' . $host,
                'x-amz-date: ' . $amzDate,
                'x-amz-content-sha256: ' . $payloadHash,
                'Authorization: ' . $authorization,
            ], $extraHeaders),
        ]);

        if ($body !== '') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        if ($response === false) {
            return [0, '', []];
        }

        $rawHeaders = substr((string) $response, 0, $headerSize);
        $responseBody = substr((string) $response, $headerSize);

        $headers = [];
        foreach (explode("\r\n", $rawHeaders) as $line) {
            if (str_contains($line, ':')) {
                [$name, $value] = explode(':', $line, 2);
                $headers[strtolower(trim($name))] = trim($value);
            }
        }

        return [$status, $responseBody, $headers];
    }
}
