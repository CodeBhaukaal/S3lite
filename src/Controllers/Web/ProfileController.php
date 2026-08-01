<?php
declare(strict_types=1);

namespace App\Controllers\Web;

use App\Controllers\Controller;
use App\Http\Exceptions\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Http\Session;
use App\Models\ApiKey;
use App\Models\SftpAccount;
use App\Models\SftpKey;
use App\Models\SftpSession;
use App\Models\Webhook;
use App\Services\AuditService;
use App\Services\SftpService;
use App\Services\StatsService;
use App\Services\TokenService;
use App\Services\UserService;
use App\Support\Str;

final class ProfileController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $this->user();

        return $this->view('app.profile', [
            'user'   => $user,
            'stats'  => StatsService::forUser((int) $user['id']),
            'logins' => \App\Models\LoginAttempt::where(['user_id' => (int) $user['id']], 'id DESC', 10),
        ]);
    }

    public function update(Request $request): Response
    {
        $this->validate($request, [
            'name'  => 'required|string|min:2|max:120',
            'email' => 'required|email|max:190',
        ]);

        UserService::update($this->userId(), [
            'name'  => $request->string('name'),
            'email' => $request->string('email'),
        ]);

        return $this->back($request, 'success', 'Profile updated.');
    }

    public function changePassword(Request $request): Response
    {
        $this->validate($request, [
            'current_password' => 'required|string',
            'password'         => 'required|password|confirmed',
        ]);

        UserService::changePassword(
            $this->userId(),
            (string) $request->input('current_password', ''),
            (string) $request->input('password', '')
        );

        return $this->back($request, 'success', 'Password changed. Other sessions have been signed out.');
    }

    // --- Two-factor -----------------------------------------------------

    public function twoFactorSetup(Request $request): Response
    {
        $setup = UserService::beginTwoFactor($this->userId());

        Session::put('2fa_setup_secret', $setup['secret']);

        return $this->view('app.two-factor', [
            'secret' => $setup['secret'],
            'uri'    => $setup['uri'],
        ]);
    }

    public function twoFactorConfirm(Request $request): Response
    {
        $this->validate($request, ['code' => 'required|string|min:6|max:6']);

        $codes = UserService::confirmTwoFactor($this->userId(), $request->string('code'));

        Session::forget('2fa_setup_secret');
        Session::put('2fa_recovery_codes', $codes);

        return $this->view('app.two-factor-codes', ['codes' => $codes]);
    }

    public function twoFactorDisable(Request $request): Response
    {
        $this->validate($request, ['password' => 'required|string']);

        UserService::disableTwoFactor($this->userId(), (string) $request->input('password', ''));

        return $this->back($request, 'success', 'Two-factor authentication disabled.');
    }

    // --- API keys -------------------------------------------------------

    public function apiKeys(Request $request): Response
    {
        return $this->view('app.api-keys', [
            'keys'      => ApiKey::forUser($this->userId()),
            'scopes'    => TokenService::SCOPES,
            'plainKey'  => Session::get('new_api_key'),
        ]);
    }

    public function createApiKey(Request $request): Response
    {
        $this->validate($request, ['name' => 'required|string|max:120']);

        $scopes = $request->array('scopes');
        if ($scopes === []) {
            $scopes = ['files:read'];
        }

        $allowlist = array_values(array_filter(array_map('trim', explode("\n", $request->string('ip_allowlist')))));
        $expires = $request->string('expires_at');

        $result = TokenService::createApiKey(
            $this->userId(),
            $request->string('name'),
            $scopes,
            $allowlist,
            $expires === '' ? null : date('Y-m-d H:i:s', strtotime($expires) ?: time() + 31536000)
        );

        AuditService::log('apikey.create', 'api_key', (int) ($result['record']['id'] ?? 0), 'Created API key ' . $request->string('name'), [
            'scopes' => $scopes,
        ]);

        if ($request->wantsJson()) {
            return $this->json([
                'key'    => $result['key'],
                'record' => ApiKey::publicArray($result['record']),
            ], 201);
        }

        Session::put('new_api_key', $result['key']);

        return $this->redirect('/settings/api-keys', 'success', 'API key created. Copy it now — it will not be shown again.');
    }

    public function revokeApiKey(Request $request, string $id): Response
    {
        $key = ctype_digit($id) ? ApiKey::find((int) $id) : ApiKey::findBy('uuid', $id);

        if ($key === null || (int) $key['user_id'] !== $this->userId()) {
            throw new HttpException(404, 'API key not found.', 'key_not_found');
        }

        ApiKey::updateById((int) $key['id'], ['revoked_at' => date('Y-m-d H:i:s')]);
        AuditService::log('apikey.revoke', 'api_key', (int) $key['id'], 'Revoked API key ' . $key['name']);

        if ($request->wantsJson()) {
            return $this->json(['revoked' => true]);
        }

        return $this->back($request, 'success', 'API key revoked.');
    }

    public function deleteApiKey(Request $request, string $id): Response
    {
        $key = ctype_digit($id) ? ApiKey::find((int) $id) : ApiKey::findBy('uuid', $id);

        if ($key === null || (int) $key['user_id'] !== $this->userId()) {
            throw new HttpException(404, 'API key not found.', 'key_not_found');
        }

        ApiKey::deleteById((int) $key['id']);
        AuditService::log('apikey.delete', 'api_key', (int) $key['id'], 'Deleted API key ' . $key['name']);

        return $this->back($request, 'success', 'API key deleted.');
    }

    public function dismissKey(Request $request): Response
    {
        Session::forget('new_api_key');

        return $this->back($request);
    }

    // --- FTP / SFTP -----------------------------------------------------

    public function sftp(Request $request): Response
    {
        $userId = $this->userId();
        $accounts = SftpAccount::forUser($userId);

        $keys = [];
        foreach ($accounts as $account) {
            $keys[(int) $account['id']] = SftpKey::forAccount((int) $account['id']);
        }

        return $this->view('app.sftp', [
            'accounts' => $accounts,
            'keys'     => $keys,
            'sessions' => SftpSession::forUser($userId),
            'newPassword' => Session::get('new_sftp_password'),
            'connection' => [
                'host' => config('sftp.sftp.host', '127.0.0.1'),
                'port' => config('sftp.sftp.port', 2222),
            ],
        ]);
    }

    public function createSftp(Request $request): Response
    {
        $this->validate($request, [
            'username' => 'required|string|min:3|max:32|alpha_dash',
            'password' => 'required|string|min:8|max:100',
        ]);

        $account = SftpService::createAccount($this->userId(), [
            'user_id'     => $this->userId(),
            'username'    => $request->string('username'),
            'password'    => (string) $request->input('password', ''),
            'protocol'    => $request->string('protocol', 'sftp'),
            'permission'  => $request->string('permission', 'rw'),
            'quota_bytes' => $request->int('quota_bytes', 1073741824),
        ]);

        if ($request->wantsJson()) {
            return $this->json(SftpAccount::publicArray($account), 201);
        }

        return $this->back($request, 'success', 'FTP/SFTP account created.');
    }

    public function updateSftp(Request $request, string $id): Response
    {
        $account = $this->resolveSftp($id);

        SftpService::updateAccount((int) $account['id'], [
            'permission'  => $request->string('permission', (string) $account['permission']),
            'status'      => $request->string('status', (string) $account['status']),
            'quota_bytes' => $request->has('quota_bytes') ? $request->int('quota_bytes') : null,
            'protocol'    => $request->string('protocol', (string) $account['protocol']),
        ], $this->userId());

        return $this->back($request, 'success', 'Account updated.');
    }

    public function resetSftpPassword(Request $request, string $id): Response
    {
        $account = $this->resolveSftp($id);

        $password = $request->string('password');
        $result = SftpService::resetPassword((int) $account['id'], $password === '' ? null : $password, $this->userId());

        if ($request->wantsJson()) {
            return $this->json(['password' => $result['password']]);
        }

        Session::put('new_sftp_password', $result['password']);

        return $this->back($request, 'success', 'Password reset.');
    }

    public function deleteSftp(Request $request, string $id): Response
    {
        $account = $this->resolveSftp($id);

        SftpService::deleteAccount((int) $account['id'], $request->bool('remove_files'), $this->userId());

        return $this->back($request, 'success', 'Account deleted.');
    }

    public function addSftpKey(Request $request, string $id): Response
    {
        $account = $this->resolveSftp($id);

        $this->validate($request, [
            'name'       => 'required|string|max:120',
            'public_key' => 'required|string|max:8000',
        ]);

        SftpService::addKey(
            (int) $account['id'],
            $request->string('name'),
            (string) $request->input('public_key', ''),
            $this->userId()
        );

        return $this->back($request, 'success', 'SSH key added.');
    }

    public function removeSftpKey(Request $request, string $id, string $keyId): Response
    {
        $account = $this->resolveSftp($id);

        SftpService::removeKey((int) $account['id'], (int) $keyId, $this->userId());

        return $this->back($request, 'success', 'SSH key removed.');
    }

    public function syncSftp(Request $request, string $id): Response
    {
        $account = $this->resolveSftp($id);

        $result = SftpService::sync((int) $account['id']);

        if ($request->wantsJson()) {
            return $this->json($result);
        }

        return $this->back($request, 'success', "Sync complete: {$result['imported']} imported, {$result['removed']} removed.");
    }

    public function closeSftpSession(Request $request, string $sessionId): Response
    {
        SftpService::closeSession((int) $sessionId, $this->userId());

        return $this->back($request, 'success', 'Session disconnected.');
    }

    // --- Webhooks -------------------------------------------------------

    public function webhooks(Request $request): Response
    {
        return $this->view('app.webhooks', [
            'webhooks' => Webhook::forUser($this->userId()),
            'events'   => Webhook::EVENTS,
        ]);
    }

    public function createWebhook(Request $request): Response
    {
        $this->validate($request, [
            'name' => 'required|string|max:120',
            'url'  => 'required|url|max:500',
        ]);

        $events = array_values(array_intersect($request->array('events'), Webhook::EVENTS));

        if ($events === []) {
            return $this->back($request, 'error', 'Select at least one event to subscribe to.');
        }

        $id = Webhook::create([
            'uuid'      => Str::uuid(),
            'user_id'   => $this->userId(),
            'name'      => $request->string('name'),
            'url'       => $request->string('url'),
            'events'    => $events,
            'secret'    => Str::random(48),
            'is_active' => 1,
        ]);

        AuditService::log('webhook.create', 'webhook', $id, 'Created webhook ' . $request->string('name'), ['events' => $events]);

        return $this->back($request, 'success', 'Webhook created.');
    }

    public function deleteWebhook(Request $request, string $id): Response
    {
        $hook = ctype_digit($id) ? Webhook::find((int) $id) : Webhook::findBy('uuid', $id);

        if ($hook === null || (int) $hook['user_id'] !== $this->userId()) {
            throw new HttpException(404, 'Webhook not found.', 'webhook_not_found');
        }

        Webhook::deleteById((int) $hook['id']);
        AuditService::log('webhook.delete', 'webhook', (int) $hook['id'], 'Deleted webhook ' . $hook['name']);

        return $this->back($request, 'success', 'Webhook deleted.');
    }

    public function testWebhook(Request $request, string $id): Response
    {
        $hook = ctype_digit($id) ? Webhook::find((int) $id) : Webhook::findBy('uuid', $id);

        if ($hook === null || (int) $hook['user_id'] !== $this->userId()) {
            throw new HttpException(404, 'Webhook not found.', 'webhook_not_found');
        }

        $result = \App\Services\WebhookService::deliver((int) $hook['id'], 'ping', ['message' => 'Test delivery from S3 Lite']);

        return $this->back(
            $request,
            $result['ok'] ? 'success' : 'error',
            $result['ok'] ? 'Test delivered (HTTP ' . $result['status'] . ').' : 'Delivery failed: HTTP ' . $result['status']
        );
    }

    private function resolveSftp(string $id): array
    {
        $account = SftpAccount::resolve($id);

        if ($account === null || (int) $account['user_id'] !== $this->userId()) {
            throw new HttpException(404, 'FTP/SFTP account not found.', 'account_not_found');
        }

        return $account;
    }
}
