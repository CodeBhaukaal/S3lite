<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Controllers\Controller;
use App\Http\Exceptions\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Models\ApiKey;
use App\Services\AuditService;
use App\Services\TokenService;

final class ApiKeyApiController extends Controller
{
    public function index(Request $request): Response
    {
        return $this->json(array_map([ApiKey::class, 'publicArray'], ApiKey::forUser($this->userId())));
    }

    public function scopes(Request $request): Response
    {
        return $this->json(TokenService::SCOPES);
    }

    public function store(Request $request): Response
    {
        $this->validate($request, ['name' => 'required|string|max:120']);

        $scopes = $request->array('scopes');
        if ($scopes === []) {
            $scopes = ['files:read'];
        }

        $invalid = array_diff($scopes, array_keys(TokenService::SCOPES));
        if ($invalid !== []) {
            return $this->error('invalid_scope', 'Unknown scope(s): ' . implode(', ', $invalid), 422, [
                'available' => array_keys(TokenService::SCOPES),
            ]);
        }

        $expires = $request->string('expires_at');

        $result = TokenService::createApiKey(
            $this->userId(),
            $request->string('name'),
            $scopes,
            $request->array('ip_allowlist'),
            $expires === '' ? null : date('Y-m-d H:i:s', strtotime($expires) ?: time() + 31536000)
        );

        AuditService::log('apikey.create', 'api_key', (int) ($result['record']['id'] ?? 0), 'Created API key via API', ['scopes' => $scopes]);

        return $this->json([
            'key'     => $result['key'],
            'warning' => 'Store this key now — it cannot be retrieved again.',
            'record'  => ApiKey::publicArray($result['record']),
        ], 201);
    }

    public function revoke(Request $request, string $id): Response
    {
        $key = $this->resolve($id);

        ApiKey::updateById((int) $key['id'], ['revoked_at' => date('Y-m-d H:i:s')]);
        AuditService::log('apikey.revoke', 'api_key', (int) $key['id'], 'Revoked API key ' . $key['name']);

        return $this->json(['revoked' => true]);
    }

    public function destroy(Request $request, string $id): Response
    {
        $key = $this->resolve($id);

        ApiKey::deleteById((int) $key['id']);
        AuditService::log('apikey.delete', 'api_key', (int) $key['id'], 'Deleted API key ' . $key['name']);

        return $this->json(['deleted' => true]);
    }

    private function resolve(string $id): array
    {
        $key = ctype_digit($id) ? ApiKey::find((int) $id) : ApiKey::findBy('uuid', $id);

        if ($key === null || (int) $key['user_id'] !== $this->userId()) {
            throw new HttpException(404, 'API key not found.', 'key_not_found');
        }

        return $key;
    }
}
