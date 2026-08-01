<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Controllers\Controller;
use App\Http\Request;
use App\Http\Response;
use App\Models\RefreshToken;
use App\Models\User;
use App\Services\Auth;
use App\Services\AuditService;
use App\Services\TokenService;

final class AuthApiController extends Controller
{
    public function login(Request $request): Response
    {
        $this->validate($request, [
            'email'    => 'required|email|max:190',
            'password' => 'required|string|max:200',
        ]);

        $result = Auth::attempt(
            strtolower($request->string('email')),
            (string) $request->input('password', ''),
            $request->string('otp') ?: null
        );

        if ($result['reason'] === 'two_factor_required') {
            return $this->error('two_factor_required', 'Provide the `otp` field with your authenticator code.', 401);
        }

        if (!$result['ok']) {
            return $this->error(
                (string) $result['reason'],
                match ($result['reason']) {
                    'too_many_attempts'  => 'Too many failed attempts. Try again later.',
                    'account_locked'     => 'This account is temporarily locked.',
                    'account_suspended'  => 'This account has been suspended.',
                    'invalid_two_factor' => 'The authentication code is not correct.',
                    default              => 'Invalid credentials.',
                },
                $result['reason'] === 'too_many_attempts' ? 429 : 401
            );
        }

        // Tells the throttle middleware not to count this attempt.
        $request->setAttribute('auth.succeeded', true);

        $tokens = TokenService::issue((array) $result['user'], $request->ip(), $request->userAgent());

        AuditService::log('auth.api_login', 'user', (int) $result['user']['id'], 'Issued API tokens', [], 'success', (int) $result['user']['id']);

        return $this->json($tokens);
    }

    public function refresh(Request $request): Response
    {
        $this->validate($request, ['refresh_token' => 'required|string|max:200']);

        $result = TokenService::refresh(
            $request->string('refresh_token'),
            $request->ip(),
            $request->userAgent()
        );

        if (!$result['ok']) {
            return $this->error((string) $result['error'], 'The refresh token is not valid or has expired.', 401);
        }

        $request->setAttribute('auth.succeeded', true);

        return $this->json($result['data']);
    }

    public function logout(Request $request): Response
    {
        $token = $request->string('refresh_token');

        if ($token !== '') {
            TokenService::revokeRefreshToken($token);
        } elseif (Auth::id() !== null) {
            RefreshToken::revokeAllForUser((int) Auth::id());
        }

        AuditService::log('auth.api_logout', 'user', Auth::id(), 'Revoked API tokens');

        return $this->json(['revoked' => true]);
    }

    public function me(Request $request): Response
    {
        $user = $this->user();

        return $this->json([
            'user'   => User::publicArray($user),
            'guard'  => Auth::guard(),
            'scopes' => Auth::scopes(),
            'permissions' => $user['permissions'] ?? [],
            'stats'  => \App\Services\StatsService::forUser((int) $user['id']),
        ]);
    }

    public function sessions(Request $request): Response
    {
        $tokens = RefreshToken::where(
            ['user_id' => $this->userId(), 'revoked_at' => null],
            'id DESC',
            50
        );

        return $this->json(array_map(static fn (array $t): array => [
            'id'         => (int) $t['id'],
            'ip'         => $t['ip'],
            'user_agent' => $t['user_agent'],
            'expires_at' => $t['expires_at'],
            'created_at' => $t['created_at'],
        ], $tokens));
    }

    public function revokeAll(Request $request): Response
    {
        $count = RefreshToken::revokeAllForUser($this->userId());

        return $this->json(['revoked' => $count]);
    }
}
