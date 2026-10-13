<?php
declare(strict_types=1);

namespace App\Storage;

use RuntimeException;

/**
 * Google Drive over its REST API (v3) with cURL — no SDK, like the S3 driver.
 *
 * Drive has no paths, only file ids, so every object is a file inside one
 * folder owned by this app, named by its full storage key
 * ("12/ab/cd/<hash>.pdf"). Lookups are a single `files.list` query and the
 * id is cached for the rest of the request.
 *
 * Auth is OAuth: the admin connects their Google account once, we keep the
 * refresh token (encrypted) and mint short-lived access tokens from it. The
 * `drive.file` scope only reaches files this app created, so connecting
 * never exposes the rest of the account's Drive.
 */
final class GoogleDriveDriver extends RemoteDriver
{
    public const SCOPE = 'https://www.googleapis.com/auth/drive.file';
    public const AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';
    public const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    public const FOLDER_NAME = 'S3 Lite storage';

    private const FOLDER_MIME = 'application/vnd.google-apps.folder';

    /** Defaults when a backend's options do not override them (tests point these at a fake). */
    public static string $defaultTokenUrl = self::TOKEN_URL;
    public static string $defaultApiBase = 'https://www.googleapis.com/drive/v3';
    public static string $defaultUploadBase = 'https://www.googleapis.com/upload/drive/v3';

    /** Access tokens per refresh token, shared by every instance in this request. */
    private static array $tokens = [];

    /** @var array<string, array{id:string, size:int}|null> */
    private array $lookups = [];

    public function __construct(
        string $slug,
        private string $clientId,
        private string $clientSecret,
        private string $refreshToken,
        private string $folderId = '',
        array $options = []
    ) {
        parent::__construct($slug, '', $options);

        if ($this->clientId === '' || $this->clientSecret === '') {
            throw new RuntimeException('Google Drive needs an OAuth client ID and client secret.');
        }

        if ($this->refreshToken === '') {
            throw new RuntimeException('Google Drive is not connected yet — use "Connect Google Drive" on the storage page.');
        }
    }

    public function driver(): string
    {
        return 'gdrive';
    }

    /** The folder objects live in; created on first use when none is set. */
    public function folderId(): string
    {
        if ($this->folderId !== '') {
            return $this->folderId;
        }

        $query = sprintf("name = '%s' and mimeType = '%s' and 'root' in parents and trashed = false", self::escape(self::FOLDER_NAME), self::FOLDER_MIME);
        $found = $this->json('GET', $this->api('/files', ['q' => $query, 'fields' => 'files(id)', 'pageSize' => 1]));

        $id = (string) ($found['files'][0]['id'] ?? '');

        if ($id === '') {
            $created = $this->json('POST', $this->api('/files', ['fields' => 'id']), [
                'name'     => self::FOLDER_NAME,
                'mimeType' => self::FOLDER_MIME,
            ]);
            $id = (string) ($created['id'] ?? '');
        }

        if ($id === '') {
            throw new RuntimeException('Could not create the storage folder in Google Drive.');
        }

        return $this->folderId = $id;
    }

    public function put(string $sourcePath, string $targetPath, bool $moveSource = true): bool
    {
        $key = $this->remotePath($targetPath);
        $size = filesize($sourcePath);

        if ($size === false) {
            return false;
        }

        // Overwrite in place if the key already exists, so it never ends up
        // as two Drive files with the same name.
        $existing = $this->lookup($key);

        [$status, , $headers] = $existing === null
            ? $this->request('POST', $this->upload('/files', ['uploadType' => 'resumable', 'fields' => 'id']), [
                'Content-Type: application/json; charset=UTF-8',
                'X-Upload-Content-Length: ' . $size,
            ], (string) json_encode(['name' => $key, 'parents' => [$this->folderId()]]))
            : $this->request('PATCH', $this->upload('/files/' . $existing['id'], ['uploadType' => 'resumable', 'fields' => 'id']), [
                'Content-Type: application/json; charset=UTF-8',
                'X-Upload-Content-Length: ' . $size,
            ], '{}');

        $session = (string) ($headers['location'] ?? '');

        if ($status !== 200 || $session === '') {
            throw new RuntimeException('Google Drive refused to start the upload (HTTP ' . $status . ').');
        }

        $in = fopen($sourcePath, 'rb');

        if ($in === false) {
            return false;
        }

        try {
            [$status, $body] = $this->request('PUT', $session, ['Content-Type: application/octet-stream'], null, $in, $size);
        } finally {
            fclose($in);
        }

        if ($status !== 200 && $status !== 201) {
            throw new RuntimeException('Google Drive upload failed (HTTP ' . $status . ').');
        }

        $id = (string) (json_decode($body, true)['id'] ?? ($existing['id'] ?? ''));
        $this->lookups[$key] = $id === '' ? null : ['id' => $id, 'size' => (int) $size];

        if ($moveSource) {
            @unlink($sourcePath);
        }

        return true;
    }

    /** @return resource|null */
    public function readStream(string $path, int $offset = 0)
    {
        $object = $this->lookup($this->remotePath($path));

        if ($object === null) {
            return null;
        }

        $sink = $this->temporaryStream();
        $headers = $offset > 0 ? ['Range: bytes=' . $offset . '-'] : [];

        [$status] = $this->request('GET', $this->api('/files/' . $object['id'], ['alt' => 'media']), $headers, null, null, 0, $sink);

        if ($status !== 200 && $status !== 206) {
            fclose($sink);

            return null;
        }

        // A server that ignored the Range header sent everything; skip ahead.
        if ($offset > 0 && $status === 200) {
            fseek($sink, $offset);
        } else {
            rewind($sink);
        }

        return $sink;
    }

    public function exists(string $path): bool
    {
        return $this->lookup($this->remotePath($path)) !== null;
    }

    public function size(string $path): int
    {
        return $this->lookup($this->remotePath($path))['size'] ?? 0;
    }

    public function delete(string $path): bool
    {
        $key = $this->remotePath($path);
        $object = $this->lookup($key);

        if ($object === null) {
            return true; // Already gone.
        }

        [$status] = $this->request('DELETE', $this->api('/files/' . $object['id']));
        $this->lookups[$key] = null;

        return ($status >= 200 && $status < 300) || $status === 404;
    }

    /** Drive copies server-side, so the bytes never pass through here. */
    public function copy(string $from, string $to): bool
    {
        $source = $this->lookup($this->remotePath($from));

        if ($source === null) {
            return false;
        }

        $target = $this->remotePath($to);
        $this->delete($to);

        $copied = $this->json('POST', $this->api('/files/' . $source['id'] . '/copy', ['fields' => 'id,size']), [
            'name'    => $target,
            'parents' => [$this->folderId()],
        ]);

        if (!isset($copied['id'])) {
            return false;
        }

        $this->lookups[$target] = ['id' => (string) $copied['id'], 'size' => (int) ($copied['size'] ?? $source['size'])];

        return true;
    }

    /** A rename is all a move is when everything sits in one folder. */
    public function move(string $from, string $to): bool
    {
        $fromKey = $this->remotePath($from);
        $source = $this->lookup($fromKey);

        if ($source === null) {
            return false;
        }

        $toKey = $this->remotePath($to);
        $this->delete($to);

        [$status] = $this->request('PATCH', $this->api('/files/' . $source['id'], ['fields' => 'id']), [
            'Content-Type: application/json; charset=UTF-8',
        ], (string) json_encode(['name' => $toKey]));

        if ($status !== 200) {
            return false;
        }

        $this->lookups[$fromKey] = null;
        $this->lookups[$toKey] = $source;

        return true;
    }

    public function diskUsage(): array
    {
        try {
            $about = $this->json('GET', $this->api('/about', ['fields' => 'storageQuota']));
        } catch (RuntimeException) {
            return ['total' => 0, 'free' => 0, 'used' => 0];
        }

        $quota = (array) ($about['storageQuota'] ?? []);
        $total = (int) ($quota['limit'] ?? 0); // Absent on unlimited plans.
        $used = (int) ($quota['usage'] ?? 0);

        return ['total' => $total, 'free' => $total > 0 ? max(0, $total - $used) : 0, 'used' => $used];
    }

    /**
     * Exchange an authorisation code for tokens (the OAuth callback).
     *
     * @return array{refresh_token:string, access_token:string}
     */
    public static function exchangeCode(string $clientId, string $clientSecret, string $code, string $redirectUri, ?string $tokenUrl = null): array
    {
        $tokens = self::postToken($tokenUrl ?? self::$defaultTokenUrl, [
            'grant_type'    => 'authorization_code',
            'code'          => $code,
            'client_id'     => $clientId,
            'client_secret' => $clientSecret,
            'redirect_uri'  => $redirectUri,
        ]);

        if (($tokens['refresh_token'] ?? '') === '') {
            throw new RuntimeException('Google did not return a refresh token. Remove the app from your Google account permissions and connect again.');
        }

        return ['refresh_token' => (string) $tokens['refresh_token'], 'access_token' => (string) ($tokens['access_token'] ?? '')];
    }

    /** The signed-in account's email, for naming the backend. '' if Google will not say. */
    public static function accountEmail(string $accessToken): string
    {
        if ($accessToken === '') {
            return '';
        }

        $ch = curl_init(rtrim(self::$defaultApiBase, '/') . '/about?fields=' . rawurlencode('user(emailAddress)'));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $accessToken],
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $decoded = $status === 200 && is_string($body) ? json_decode($body, true) : null;

        return is_array($decoded) ? (string) ($decoded['user']['emailAddress'] ?? '') : '';
    }

    public static function authorizationUrl(string $clientId, string $redirectUri, string $state): string
    {
        return self::AUTH_URL . '?' . http_build_query([
            'client_id'              => $clientId,
            'redirect_uri'           => $redirectUri,
            'response_type'          => 'code',
            'scope'                  => self::SCOPE,
            // offline + consent is what makes Google hand out a refresh token.
            'access_type'            => 'offline',
            'prompt'                 => 'consent',
            'include_granted_scopes' => 'true',
            'state'                  => $state,
        ]);
    }

    /** @return array{id:string, size:int}|null */
    private function lookup(string $key): ?array
    {
        if (array_key_exists($key, $this->lookups)) {
            return $this->lookups[$key];
        }

        $query = sprintf("name = '%s' and '%s' in parents and trashed = false", self::escape($key), self::escape($this->folderId()));
        $found = $this->json('GET', $this->api('/files', ['q' => $query, 'fields' => 'files(id,size)', 'pageSize' => 1]));
        $file = $found['files'][0] ?? null;

        return $this->lookups[$key] = $file === null ? null : ['id' => (string) $file['id'], 'size' => (int) ($file['size'] ?? 0)];
    }

    private function accessToken(bool $fresh = false): string
    {
        $cached = self::$tokens[$this->refreshToken] ?? null;

        if (!$fresh && $cached !== null && $cached['expires'] > time() + 60) {
            return $cached['token'];
        }

        try {
            $tokens = self::postToken((string) $this->option('token_url', self::$defaultTokenUrl), [
                'grant_type'    => 'refresh_token',
                'refresh_token' => $this->refreshToken,
                'client_id'     => $this->clientId,
                'client_secret' => $this->clientSecret,
            ]);
        } catch (RuntimeException $e) {
            // A revoked or expired grant comes back as a 400; what the admin
            // needs to hear is what to do about it.
            if (str_contains($e->getMessage(), 'Could not reach')) {
                throw $e;
            }

            throw new RuntimeException($e->getMessage() . ' — connect Google Drive again.', 0, $e);
        }

        $token = (string) ($tokens['access_token'] ?? '');

        if ($token === '') {
            throw new RuntimeException('Google rejected the stored connection — connect Google Drive again.');
        }

        self::$tokens[$this->refreshToken] = ['token' => $token, 'expires' => time() + (int) ($tokens['expires_in'] ?? 3600)];

        return $token;
    }

    private static function postToken(string $url, array $fields): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($fields),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
        ]);

        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            throw new RuntimeException('Could not reach Google: ' . $error);
        }

        $decoded = json_decode((string) $body, true);

        if ($status !== 200 || !is_array($decoded)) {
            $reason = is_array($decoded) ? (string) ($decoded['error_description'] ?? $decoded['error'] ?? '') : '';

            throw new RuntimeException('Google refused the token request' . ($reason !== '' ? ': ' . $reason : ' (HTTP ' . $status . ')'));
        }

        return $decoded;
    }

    /** A JSON API call that must succeed. */
    private function json(string $method, string $url, ?array $payload = null): array
    {
        [$status, $body] = $this->request(
            $method,
            $url,
            $payload === null ? [] : ['Content-Type: application/json; charset=UTF-8'],
            $payload === null ? null : (string) json_encode($payload)
        );

        $decoded = json_decode($body, true);

        if ($status < 200 || $status >= 300) {
            $message = is_array($decoded) ? (string) ($decoded['error']['message'] ?? '') : '';

            throw new RuntimeException('Google Drive error (HTTP ' . $status . ')' . ($message !== '' ? ': ' . $message : ''));
        }

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * One HTTP call. A 401 means the access token expired early; mint a new
     * one and retry once.
     *
     * @param resource|null $upload Streamed as the request body.
     * @param resource|null $sink   Receives the response body instead of RAM.
     * @return array{0:int, 1:string, 2:array<string,string>}
     */
    private function request(string $method, string $url, array $headers = [], ?string $body = null, $upload = null, int $uploadSize = 0, $sink = null, bool $retried = false): array
    {
        $responseHeaders = [];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => array_merge(['Authorization: Bearer ' . $this->accessToken($retried)], $headers),
            CURLOPT_CONNECTTIMEOUT => $this->timeout(),
            // Big transfers are bounded by stall detection, not wall time.
            CURLOPT_TIMEOUT        => $upload !== null || $sink !== null ? 0 : $this->timeout(),
            CURLOPT_LOW_SPEED_LIMIT => 1,
            CURLOPT_LOW_SPEED_TIME  => $this->timeout(),
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$responseHeaders): int {
                if (str_contains($line, ':')) {
                    [$name, $value] = explode(':', $line, 2);
                    $responseHeaders[strtolower(trim($name))] = trim($value);
                }

                return strlen($line);
            },
        ]);

        if ($upload !== null) {
            curl_setopt_array($ch, [CURLOPT_UPLOAD => true, CURLOPT_INFILE => $upload, CURLOPT_INFILESIZE => $uploadSize]);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        } elseif ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        } elseif ($method === 'POST' || $method === 'PATCH') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, '');
        }

        if ($sink !== null) {
            curl_setopt($ch, CURLOPT_FILE, $sink);
        } else {
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        }

        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new RuntimeException('Could not reach Google Drive: ' . $error);
        }

        if ($status === 401 && !$retried && $upload === null) {
            if ($sink !== null) {
                ftruncate($sink, 0);
                rewind($sink);
            }

            return $this->request($method, $url, $headers, $body, null, 0, $sink, true);
        }

        return [$status, is_string($response) ? $response : '', $responseHeaders];
    }

    private function api(string $path, array $query = []): string
    {
        $base = rtrim((string) $this->option('api_base', self::$defaultApiBase), '/');

        return $base . $path . ($query === [] ? '' : '?' . http_build_query($query));
    }

    private function upload(string $path, array $query): string
    {
        $base = rtrim((string) $this->option('upload_base', self::$defaultUploadBase), '/');

        return $base . $path . '?' . http_build_query($query);
    }

    /** Quote a value for a Drive search query. */
    private static function escape(string $value): string
    {
        return str_replace(['\\', "'"], ['\\\\', "\\'"], $value);
    }
}
