<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Controllers\Controller;
use App\Http\Exceptions\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Models\SftpAccount;
use App\Models\SftpKey;
use App\Models\SftpSession;
use App\Services\Auth;
use App\Services\SettingService;
use App\Services\SftpService;

final class SftpApiController extends Controller
{
    public function index(Request $request): Response
    {
        $conditions = [];

        if (!Auth::isAdmin()) {
            $conditions['user_id'] = $this->userId();
        } elseif ($request->int('user_id') > 0) {
            $conditions['user_id'] = $request->int('user_id');
        }

        if ($request->string('protocol') !== '') {
            $conditions['protocol'] = $request->string('protocol');
        }

        if ($request->string('status') !== '') {
            $conditions['status'] = $request->string('status');
        }

        $result = SftpAccount::withOwners($conditions, $this->pageNumber($request), $this->perPage($request, 25));

        return $this->json(
            array_map([SftpAccount::class, 'publicArray'], $result['data']),
            200,
            $this->paginationMeta($result)
        );
    }

    public function show(Request $request, string $id): Response
    {
        $account = $this->resolve($id);

        return $this->json([
            'account'  => SftpAccount::publicArray($account),
            'keys'     => array_map([SftpKey::class, 'publicArray'], SftpKey::forAccount((int) $account['id'])),
            'sessions' => array_map(
                [SftpSession::class, 'publicArray'],
                SftpSession::where(['account_id' => (int) $account['id']], 'id DESC', 20)
            ),
            'activity' => SftpService::activityFor((int) $account['id'], 50),
        ]);
    }

    public function store(Request $request): Response
    {
        $this->validate($request, [
            'username' => 'required|string|min:3|max:32',
            'password' => 'required|string|min:8|max:100',
        ]);

        $targetUser = $request->has('user_id') && Auth::isAdmin()
            ? $request->int('user_id')
            : $this->userId();

        $account = SftpService::createAccount($this->userId(), [
            'user_id'      => $targetUser,
            'username'     => $request->string('username'),
            'password'     => (string) $request->input('password', ''),
            'protocol'     => $request->string('protocol', 'sftp'),
            'permission'   => $request->string('permission', 'rw'),
            'quota_bytes'  => $request->int('quota_bytes', 1073741824),
            'status'       => $request->string('status', 'active'),
            'ip_allowlist' => $request->array('ip_allowlist'),
        ]);

        return $this->json(SftpAccount::publicArray($account), 201);
    }

    public function update(Request $request, string $id): Response
    {
        $account = $this->resolve($id);

        $data = [];
        foreach (['permission', 'status', 'protocol'] as $field) {
            if ($request->filled($field)) {
                $data[$field] = $request->string($field);
            }
        }

        if ($request->has('quota_bytes')) {
            $data['quota_bytes'] = $request->int('quota_bytes');
        }

        if ($request->has('ip_allowlist')) {
            $data['ip_allowlist'] = $request->array('ip_allowlist');
        }

        if ($request->filled('password')) {
            $data['password'] = (string) $request->input('password', '');
        }

        return $this->json(SftpAccount::publicArray(
            SftpService::updateAccount((int) $account['id'], $data, Auth::isAdmin() ? null : $this->userId())
        ));
    }

    public function resetPassword(Request $request, string $id): Response
    {
        $account = $this->resolve($id);

        $result = SftpService::resetPassword(
            (int) $account['id'],
            $request->string('password') ?: null,
            Auth::isAdmin() ? null : $this->userId()
        );

        return $this->json([
            'password' => $result['password'],
            'warning'  => 'Store this password now â€” it cannot be retrieved again.',
            'account'  => SftpAccount::publicArray($result['account']),
        ]);
    }

    public function destroy(Request $request, string $id): Response
    {
        $account = $this->resolve($id);

        SftpService::deleteAccount(
            (int) $account['id'],
            $request->bool('remove_files'),
            Auth::isAdmin() ? null : $this->userId()
        );

        return $this->json(['deleted' => true]);
    }

    // --- SSH keys -------------------------------------------------------

    public function keys(Request $request, string $id): Response
    {
        $account = $this->resolve($id);

        return $this->json(array_map([SftpKey::class, 'publicArray'], SftpKey::forAccount((int) $account['id'])));
    }

    public function addKey(Request $request, string $id): Response
    {
        $account = $this->resolve($id);

        $this->validate($request, [
            'name'       => 'required|string|max:120',
            'public_key' => 'required|string|max:8000',
        ]);

        $key = SftpService::addKey(
            (int) $account['id'],
            $request->string('name'),
            (string) $request->input('public_key', ''),
            Auth::isAdmin() ? null : $this->userId()
        );

        return $this->json(SftpKey::publicArray($key), 201);
    }

    public function removeKey(Request $request, string $id, string $keyId): Response
    {
        $account = $this->resolve($id);

        SftpService::removeKey(
            (int) $account['id'],
            (int) $keyId,
            Auth::isAdmin() ? null : $this->userId()
        );

        return $this->json(['deleted' => true]);
    }

    // --- Sessions -------------------------------------------------------

    public function sessions(Request $request): Response
    {
        $sessions = Auth::isAdmin() ? SftpSession::active() : SftpSession::forUser($this->userId());

        return $this->json(array_map([SftpSession::class, 'publicArray'], $sessions));
    }

    public function closeSession(Request $request, string $id): Response
    {
        SftpService::closeSession((int) $id, Auth::isAdmin() ? null : $this->userId());

        return $this->json(['closed' => true]);
    }

    // --- Sync & activity ------------------------------------------------

    public function sync(Request $request, string $id): Response
    {
        $account = $this->resolve($id);

        return $this->json(SftpService::sync((int) $account['id']));
    }

    public function syncAll(Request $request): Response
    {
        $this->requireAdmin();

        return $this->json(SftpService::syncAll());
    }

    public function activity(Request $request, string $id): Response
    {
        $account = $this->resolve($id);

        return $this->json(SftpService::activityFor((int) $account['id'], $this->perPage($request, 100, 500)));
    }

    // --- Service control -------------------------------------------------

    public function services(Request $request): Response
    {
        $this->requireAdmin();

        if ($request->method === 'GET') {
            return $this->json($this->serviceState());
        }

        foreach (['sftp_enabled', 'ftp_enabled', 'ftps_enabled'] as $flag) {
            if ($request->has($flag)) {
                SettingService::set($flag, $request->bool($flag), 'services');
            }
        }

        foreach (['ftp_passive_min', 'ftp_passive_max', 'ftp_port'] as $numeric) {
            if ($request->has($numeric)) {
                SettingService::set($numeric, $request->int($numeric), 'services');
            }
        }

        foreach (['ftps_tls_cert', 'ftps_tls_key'] as $path) {
            if ($request->has($path)) {
                SettingService::set($path, $request->string($path), 'services');
            }
        }

        \App\Services\AuditService::log('sftp.services_update', 'settings', 'services', 'Updated FTP/SFTP service settings');

        return $this->json($this->serviceState());
    }

    public function opensshConfig(Request $request): Response
    {
        $this->requireAdmin();

        return $this->json(['config' => SftpService::opensshConfig()]);
    }

    /**
     * Credential check endpoint for an external FTP/SFTP daemon.
     * Requires the `sftp:write` scope, so only a trusted daemon key can call it.
     */
    public function authenticate(Request $request): Response
    {
        $this->validate($request, ['username' => 'required|string|max:64']);

        $username = $request->string('username');
        $ip = $request->string('client_ip', $request->ip());

        if ($request->filled('fingerprint')) {
            $result = SftpService::authenticateKey($username, $request->string('fingerprint'), $ip);
        } else {
            $result = SftpService::authenticate($username, (string) $request->input('password', ''), $ip, $request->string('protocol', 'sftp'));
        }

        if (!$result['ok']) {
            return $this->error((string) $result['reason'], 'Authentication failed.', 401);
        }

        /** @var array $account */
        $account = $result['account'];

        $session = SftpService::openSession(
            (int) $account['id'],
            $ip,
            $request->string('client'),
            $request->string('protocol', (string) $account['protocol'])
        );

        return $this->json([
            'authenticated' => true,
            'account'       => SftpAccount::publicArray($account),
            'home_dir'      => $account['home_dir'],
            'permission'    => $account['permission'],
            'session_key'   => $session['session_key'] ?? null,
        ]);
    }

    /** Heartbeat + transfer accounting from the external daemon. */
    public function heartbeat(Request $request): Response
    {
        $this->validate($request, ['session_key' => 'required|string|max:64']);

        SftpService::touchSession(
            $request->string('session_key'),
            $request->int('bytes_in'),
            $request->int('bytes_out')
        );

        if ($request->filled('action')) {
            $session = SftpSession::findBy('session_key', $request->string('session_key'));

            if ($session !== null) {
                SftpService::activity(
                    (int) $session['account_id'],
                    $request->string('action'),
                    $request->string('path') ?: null,
                    $request->int('size'),
                    $request->string('status', 'ok'),
                    $request->ip(),
                    (int) $session['id']
                );
            }
        }

        return $this->json(['ok' => true]);
    }

    private function serviceState(): array
    {
        return [
            'sftp' => [
                'enabled' => SftpService::serviceEnabled('sftp'),
                'host'    => config('sftp.sftp.host'),
                'port'    => (int) SettingService::get('sftp_port', config('sftp.sftp.port')),
            ],
            'ftp' => [
                'enabled' => SftpService::serviceEnabled('ftp'),
                'port'    => (int) SettingService::get('ftp_port', config('sftp.ftp.port')),
                'passive' => [
                    'min' => (int) SettingService::get('ftp_passive_min', config('sftp.passive.min')),
                    'max' => (int) SettingService::get('ftp_passive_max', config('sftp.passive.max')),
                ],
            ],
            'ftps' => [
                'enabled'  => SftpService::serviceEnabled('ftps'),
                'tls_cert' => SettingService::get('ftps_tls_cert', config('sftp.ftps.tls_cert')),
                'tls_key'  => SettingService::get('ftps_tls_key', config('sftp.ftps.tls_key')),
            ],
            'home_root' => SftpService::homeRoot(),
        ];
    }

    private function resolve(string $id): array
    {
        $account = SftpAccount::resolve($id);

        if ($account === null) {
            $account = SftpAccount::findByUsername($id);
        }

        if ($account === null) {
            throw new HttpException(404, 'FTP/SFTP account not found.', 'account_not_found');
        }

        if (!Auth::isAdmin() && (int) $account['user_id'] !== $this->userId()) {
            throw new HttpException(404, 'FTP/SFTP account not found.', 'account_not_found');
        }

        return $account;
    }
}
