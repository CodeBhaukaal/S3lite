<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Http\Request;
use App\Http\Response;
use App\Models\User;
use App\Services\Auth;
use App\Services\AuditService;
use App\Services\SettingService;
use App\Services\TokenService;

/**
 * Accepts either a JWT bearer token or an API key (`X-Api-Key`).
 */
final class ApiAuthenticate implements MiddlewareInterface
{
    public function handle(Request $request, callable $next, array $params = []): Response
    {
        $apiKey = $request->apiKey();

        if ($apiKey !== null) {
            $result = TokenService::verifyApiKey($apiKey, $request->ip());

            if (!$result['ok']) {
                AuditService::failure('api.auth_failed', 'API key rejected: ' . (string) $result['error']);

                return Response::apiError((string) $result['error'], 'The provided API key is not valid.', 401);
            }

            $record = $result['key'] ?? [];
            $scopes = $record['scopes'] ?: [];

            if ($this->signingRequired($request) && !$this->signatureValid($request, (string) ($record['key_hash'] ?? ''))) {
                return Response::apiError('invalid_signature', 'Request signature is missing or invalid.', 401);
            }

            Auth::setUser((array) $result['user'], 'api_key', $record, $scopes);
            $request->setAttribute('auth.guard', 'api_key');
            $request->setAttribute('auth.key_id', (int) ($record['id'] ?? 0));

            return $next($request);
        }

        $bearer = $request->bearerToken();

        if ($bearer === null) {
            // Same-origin panel requests: fall back to the web session, but only
            // when a valid CSRF token is present so this is not a CSRF hole.
            $session = $this->sessionUser($request);

            if ($session !== null) {
                Auth::setUser($session, 'session', null, ['*']);
                $request->setAttribute('auth.guard', 'session');

                return $next($request);
            }

            return Response::apiError(
                'unauthenticated',
                'Provide a Bearer token or an X-Api-Key header.',
                401
            );
        }

        $result = TokenService::verifyAccessToken($bearer);

        if (!$result['ok']) {
            return Response::apiError((string) $result['error'], 'The provided access token is not valid.', 401);
        }

        /** @var array $user */
        $user = $result['user'];

        Auth::setUser($user, 'jwt', null, User::isAdmin($user) ? ['*'] : ['*']);
        $request->setAttribute('auth.guard', 'jwt');

        return $next($request);
    }

    /**
     * Resolve the logged-in panel user for a same-origin XHR.
     */
    private function sessionUser(Request $request): ?array
    {
        if ($request->header('Cookie') === null) {
            return null;
        }

        \App\Http\Session::start();

        $user = Auth::user();

        if ($user === null) {
            return null;
        }

        if (in_array($request->method, ['GET', 'HEAD', 'OPTIONS'], true)) {
            return $user;
        }

        return \App\Http\Session::verifyCsrf((string) $request->header('X-CSRF-Token', '')) ? $user : null;
    }

    private function signingRequired(Request $request): bool
    {
        if (!(bool) SettingService::get('require_request_signing', false)) {
            return false;
        }

        return !in_array($request->method, ['GET', 'HEAD', 'OPTIONS'], true);
    }

    private function signatureValid(Request $request, string $keyHash): bool
    {
        $signature = (string) $request->header('X-Signature', '');
        $timestamp = (string) $request->header('X-Timestamp', '');

        if ($signature === '' || $timestamp === '' || $keyHash === '') {
            return false;
        }

        return TokenService::verifySignature(
            $request->method,
            $request->path,
            $request->rawBody,
            $timestamp,
            $signature,
            $keyHash
        );
    }
}
