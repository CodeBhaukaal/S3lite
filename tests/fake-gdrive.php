<?php
/**
 * A tiny stand-in for the Google OAuth token endpoint and Drive v3 API, just
 * enough for the GoogleDriveDriver tests. Run as a router script:
 *
 *   FAKE_GDRIVE_ROOT=/tmp/x php -S 127.0.0.1:PORT tests/fake-gdrive.php
 *
 * Accepts refresh token "good-refresh" and auth code "good-code"; every API
 * call needs "Authorization: Bearer good-access".
 */
declare(strict_types=1);

$root = rtrim(str_replace('\\', '/', (string) (getenv('FAKE_GDRIVE_ROOT') ?: sys_get_temp_dir() . '/fake-gdrive')), '/');
@mkdir($root . '/blobs', 0775, true);

$method = $_SERVER['REQUEST_METHOD'];
$path = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$host = 'http://' . $_SERVER['HTTP_HOST'];

function state(string $root): array
{
    $raw = @file_get_contents($root . '/state.json');
    $state = $raw === false ? null : json_decode($raw, true);

    return is_array($state) ? $state : ['files' => [], 'sessions' => [], 'next' => 1];
}

function save(string $root, array $state): void
{
    file_put_contents($root . '/state.json', json_encode($state), LOCK_EX);
}

function reply(int $status, mixed $body = null, array $headers = []): never
{
    foreach ($headers as $name => $value) {
        header($name . ': ' . $value);
    }
    // After the headers: a Location header would otherwise force a 302.
    http_response_code($status);
    if ($body !== null) {
        header('Content-Type: application/json');
        echo json_encode($body);
    }
    exit;
}

function unquote(string $value): string
{
    return (string) preg_replace('/\\\\(.)/', '$1', $value);
}

// --- OAuth --------------------------------------------------------------
if ($path === '/token') {
    $grant = $_POST['grant_type'] ?? '';

    if ($grant === 'refresh_token' && ($_POST['refresh_token'] ?? '') === 'good-refresh') {
        reply(200, ['access_token' => 'good-access', 'expires_in' => 3600]);
    }

    if ($grant === 'authorization_code' && ($_POST['code'] ?? '') === 'good-code') {
        reply(200, ['access_token' => 'good-access', 'refresh_token' => 'good-refresh', 'expires_in' => 3600]);
    }

    reply(400, ['error' => 'invalid_grant', 'error_description' => 'Bad Request']);
}

if (($_SERVER['HTTP_AUTHORIZATION'] ?? '') !== 'Bearer good-access') {
    reply(401, ['error' => ['message' => 'Invalid Credentials']]);
}

$state = state($root);
$input = (string) file_get_contents('php://input');
$json = json_decode($input, true) ?: [];
$newId = static function () use (&$state): string {
    return 'id' . ($state['next']++);
};

// --- Resumable uploads -------------------------------------------------
if (preg_match('#^/upload/drive/v3/files(?:/([^/]+))?$#', $path, $m) === 1 && in_array($method, ['POST', 'PATCH'], true)) {
    $sid = bin2hex(random_bytes(6));
    $state['sessions'][$sid] = ['existing' => $m[1] ?? null, 'meta' => $json];
    save($root, $state);
    reply(200, null, ['Location' => $host . '/upload/session/' . $sid]);
}

if (preg_match('#^/upload/session/([a-f0-9]+)$#', $path, $m) === 1 && $method === 'PUT') {
    $session = $state['sessions'][$m[1]] ?? null;
    if ($session === null) {
        reply(404, ['error' => ['message' => 'No such session']]);
    }

    $id = $session['existing'] ?? $newId();
    $state['files'][$id] = array_merge($state['files'][$id] ?? ['mimeType' => 'application/octet-stream'], array_filter([
        'name'    => $session['meta']['name'] ?? null,
        'parents' => $session['meta']['parents'] ?? null,
    ]), ['size' => strlen($input)]);
    file_put_contents($root . '/blobs/' . $id, $input);
    unset($state['sessions'][$m[1]]);
    save($root, $state);
    reply(200, ['id' => $id]);
}

// --- Drive v3 ------------------------------------------------------------
if ($path === '/drive/v3/about') {
    reply(200, ['storageQuota' => ['limit' => '1000000', 'usage' => '1234']]);
}

if ($path === '/drive/v3/files' && $method === 'GET') {
    $q = (string) ($_GET['q'] ?? '');
    preg_match("/name = '((?:\\\\.|[^'\\\\])*)'/", $q, $name);
    preg_match("/'((?:\\\\.|[^'\\\\])*)' in parents/", $q, $parent);
    preg_match("/mimeType = '([^']*)'/", $q, $mime);

    $matches = [];
    foreach ($state['files'] as $id => $file) {
        if (isset($name[1]) && $file['name'] !== unquote($name[1])) continue;
        if (isset($parent[1]) && !in_array(unquote($parent[1]), $file['parents'] ?? ['root'], true)) continue;
        if (isset($mime[1]) && $file['mimeType'] !== $mime[1]) continue;
        $matches[] = ['id' => (string) $id, 'size' => (string) ($file['size'] ?? 0)];
    }

    reply(200, ['files' => $matches]);
}

if ($path === '/drive/v3/files' && $method === 'POST') {
    $id = $newId();
    $state['files'][$id] = [
        'name'     => (string) ($json['name'] ?? ''),
        'parents'  => $json['parents'] ?? ['root'],
        'mimeType' => (string) ($json['mimeType'] ?? 'application/octet-stream'),
        'size'     => 0,
    ];
    save($root, $state);
    reply(200, ['id' => $id]);
}

if (preg_match('#^/drive/v3/files/([^/]+)(/copy)?$#', $path, $m) === 1) {
    $id = $m[1];
    $file = $state['files'][$id] ?? null;

    if ($file === null) {
        reply(404, ['error' => ['message' => 'File not found']]);
    }

    if (($m[2] ?? '') === '/copy' && $method === 'POST') {
        $copy = $newId();
        $state['files'][$copy] = array_merge($file, array_filter(['name' => $json['name'] ?? null, 'parents' => $json['parents'] ?? null]));
        copy($root . '/blobs/' . $id, $root . '/blobs/' . $copy);
        save($root, $state);
        reply(200, ['id' => $copy, 'size' => (string) $file['size']]);
    }

    if ($method === 'GET' && ($_GET['alt'] ?? '') === 'media') {
        $body = (string) file_get_contents($root . '/blobs/' . $id);
        if (preg_match('/bytes=(\d+)-/', (string) ($_SERVER['HTTP_RANGE'] ?? ''), $r) === 1) {
            http_response_code(206);
            echo substr($body, (int) $r[1]);
            exit;
        }
        echo $body;
        exit;
    }

    if ($method === 'PATCH') {
        $state['files'][$id]['name'] = (string) ($json['name'] ?? $file['name']);
        save($root, $state);
        reply(200, ['id' => $id]);
    }

    if ($method === 'DELETE') {
        unset($state['files'][$id]);
        @unlink($root . '/blobs/' . $id);
        save($root, $state);
        reply(204);
    }
}

reply(404, ['error' => ['message' => 'Unknown route ' . $method . ' ' . $path]]);
