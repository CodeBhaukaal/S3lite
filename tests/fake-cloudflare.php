<?php
/**
 * A stand-in for the bits of the Cloudflare v4 API the CDN cache service
 * uses. Run as a router script:
 *
 *   FAKE_CF_ROOT=/tmp/x php -S 127.0.0.1:PORT tests/fake-cloudflare.php
 *
 * Token "good-token", zone "abc123". State (rules, purges) is kept in
 * FAKE_CF_ROOT/state.json so the test can inspect it; seed it there too.
 */
declare(strict_types=1);

$root = rtrim(str_replace('\\', '/', (string) (getenv('FAKE_CF_ROOT') ?: sys_get_temp_dir() . '/fake-cf')), '/');
@mkdir($root, 0775, true);

$method = $_SERVER['REQUEST_METHOD'];
$path = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$file = $root . '/state.json';
$state = json_decode((string) @file_get_contents($file), true) ?: ['ruleset' => null, 'purged' => [], 'calls' => []];
$body = json_decode((string) file_get_contents('php://input'), true) ?: [];

$state['calls'][] = $method . ' ' . $path;

function out(int $status, array $payload, array $state, string $file): never
{
    file_put_contents($file, json_encode($state), LOCK_EX);
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($payload);
    exit;
}

$ok = static fn (mixed $result): array => ['success' => true, 'errors' => [], 'result' => $result];
$fail = static fn (string $message): array => ['success' => false, 'errors' => [['code' => 1000, 'message' => $message]], 'result' => null];

if (($_SERVER['HTTP_AUTHORIZATION'] ?? '') !== 'Bearer good-token') {
    out(403, $fail('Invalid API Token'), $state, $file);
}

if (!str_starts_with($path, '/zones/abc123')) {
    out(404, $fail('Zone not found'), $state, $file);
}

$rest = substr($path, strlen('/zones/abc123'));

if ($rest === '' && $method === 'GET') {
    out(200, $ok(['id' => 'abc123', 'name' => 'example.test']), $state, $file);
}

if ($rest === '/purge_cache' && $method === 'POST') {
    if (count($body['files'] ?? []) > 30) {
        out(400, $fail('Too many files'), $state, $file);
    }
    $state['purged'] = array_merge($state['purged'], $body['files'] ?? []);
    out(200, $ok(['id' => 'purge']), $state, $file);
}

if ($rest === '/rulesets/phases/http_request_cache_settings/entrypoint') {
    if ($method === 'GET') {
        $state['ruleset'] === null
            ? out(404, $fail('Ruleset not found'), $state, $file)
            : out(200, $ok($state['ruleset']), $state, $file);
    }

    if ($method === 'PUT') {
        $rules = array_map(static fn (array $r, int $i): array => $r + ['id' => 'rule' . $i], $body['rules'] ?? [], array_keys($body['rules'] ?? []));
        $state['ruleset'] = ['id' => 'rs1', 'rules' => $rules];
        out(200, $ok($state['ruleset']), $state, $file);
    }
}

if (preg_match('#^/rulesets/(\w+)/rules(?:/(\w+))?$#', $rest, $m) === 1 && $state['ruleset'] !== null && $m[1] === $state['ruleset']['id']) {
    if ($method === 'POST' && !isset($m[2])) {
        $state['ruleset']['rules'][] = $body + ['id' => 'rule' . count($state['ruleset']['rules'])];
        out(200, $ok($state['ruleset']), $state, $file);
    }

    if ($method === 'PATCH' && isset($m[2])) {
        foreach ($state['ruleset']['rules'] as $i => $rule) {
            if ($rule['id'] === $m[2]) {
                $state['ruleset']['rules'][$i] = $body + ['id' => $m[2]];
                out(200, $ok($state['ruleset']), $state, $file);
            }
        }
        out(404, $fail('Rule not found'), $state, $file);
    }
}

out(404, $fail('Unknown route ' . $method . ' ' . $path), $state, $file);
