<?php
declare(strict_types=1);

namespace App\Controllers\Web;

use App\Controllers\Controller;
use App\Core\Database;
use App\Core\Logger;
use App\Http\Exceptions\HttpException;
use App\Http\Request;
use App\Http\Response;
use App\Http\Session;
use App\Models\AuditLog;
use App\Models\FileRecord;
use App\Models\IpRule;
use App\Models\LoginAttempt;
use App\Models\Permission;
use App\Models\Role;
use App\Models\SftpAccount;
use App\Models\SftpSession;
use App\Models\User;
use App\Services\AuditService;
use App\Services\BackupService;
use App\Services\JobService;
use App\Services\MetricsService;
use App\Services\SettingService;
use App\Services\SftpService;
use App\Services\StatsService;
use App\Services\StorageBackendService;
use App\Services\UserService;

final class AdminController extends Controller
{
    public function dashboard(Request $request): Response
    {
        return $this->view('admin.dashboard', [
            'stats'    => StatsService::global(),
            'timeline' => StatsService::timeline(14),
            'byType'   => StatsService::storageByType(),
            'topUsers' => StatsService::topUsers(6),
            'topFiles' => StatsService::topFiles(6),
            'health'   => MetricsService::health(),
            'system'   => MetricsService::system(),
            'activity' => StatsService::recentActivity(12),
            'jobs'     => \App\Models\Job::stats(),
        ]);
    }

    // --- Users ----------------------------------------------------------

    public function users(Request $request): Response
    {
        $conditions = ['deleted_at' => null];

        if ($request->string('status') !== '') {
            $conditions['status'] = $request->string('status');
        }

        if ($request->string('q') !== '') {
            $term = '%' . $request->string('q') . '%';
            $conditions['__raw'] = ['u.name LIKE ? OR u.email LIKE ?', [$term, $term]];
        }

        return $this->view('admin.users', [
            'users' => User::listWithRoles($conditions, $this->pageNumber($request), $this->perPage($request, 20)),
            'roles' => Role::withCounts(),
            'query' => $request->string('q'),
            'status' => $request->string('status'),
            'newPassword' => Session::get('new_user_password'),
        ]);
    }

    public function storeUser(Request $request): Response
    {
        $this->validate($request, [
            'name'     => 'required|string|min:2|max:120',
            'email'    => 'required|email|max:190|unique:users,email',
            'password' => 'required|password',
            'role'     => 'required|string|max:64',
        ]);

        $user = UserService::create([
            'name'        => $request->string('name'),
            'email'       => $request->string('email'),
            'password'    => (string) $request->input('password', ''),
            'role'        => $request->string('role'),
            'status'      => $request->string('status', 'active'),
            'quota_bytes' => $request->has('quota_gb') ? $request->int('quota_gb') * 1073741824 : null,
        ]);

        if ($request->wantsJson()) {
            return $this->json(User::publicArray($user), 201);
        }

        return $this->back($request, 'success', 'User created.');
    }

    public function showUser(Request $request, string $id): Response
    {
        $user = $this->resolveUser($id);

        return $this->view('admin.user-detail', [
            'user'     => $user,
            'stats'    => StatsService::forUser((int) $user['id']),
            'files'    => FileRecord::search(['user_id' => (int) $user['id']], 1, 10),
            'logins'   => LoginAttempt::where(['user_id' => (int) $user['id']], 'id DESC', 15),
            'activity' => StatsService::recentActivity(15, (int) $user['id']),
            'sftp'     => SftpAccount::forUser((int) $user['id']),
            'roles'    => Role::all('id ASC'),
        ]);
    }

    public function updateUser(Request $request, string $id): Response
    {
        $user = $this->resolveUser($id);

        $data = [];
        foreach (['name', 'email', 'role', 'status'] as $field) {
            if ($request->filled($field)) {
                $data[$field] = $request->string($field);
            }
        }

        if ($request->filled('password')) {
            $data['password'] = (string) $request->input('password', '');
        }

        if ($request->has('quota_gb')) {
            $data['quota_bytes'] = $request->int('quota_gb') * 1073741824;
        }

        if ($request->bool('unlock')) {
            $data['unlock'] = true;
        }

        $updated = UserService::update((int) $user['id'], $data, true);

        if ($request->wantsJson()) {
            return $this->json(User::publicArray($updated));
        }

        return $this->back($request, 'success', 'User updated.');
    }

    public function deleteUser(Request $request, string $id): Response
    {
        $user = $this->resolveUser($id);

        if ((int) $user['id'] === $this->userId()) {
            return $this->back($request, 'error', 'You cannot delete your own account.');
        }

        UserService::delete((int) $user['id'], $request->bool('purge_files'));

        if ($request->wantsJson()) {
            return $this->json(['deleted' => true]);
        }

        return $this->redirect('/admin/users', 'success', 'User deleted.');
    }

    public function recalculateUser(Request $request, string $id): Response
    {
        $user = $this->resolveUser($id);

        $used = \App\Services\QuotaService::recalculate((int) $user['id']);

        return $this->back($request, 'success', 'Storage recalculated: ' . bytes($used) . ' in use.');
    }

    // --- Roles ----------------------------------------------------------

    public function roles(Request $request): Response
    {
        $roles = Role::withCounts();

        $rolePermissions = [];
        foreach ($roles as $role) {
            $rolePermissions[(int) $role['id']] = Role::permissionNames((int) $role['id']);
        }

        return $this->view('admin.roles', [
            'roles'           => $roles,
            'permissions'     => Permission::grouped(),
            'rolePermissions' => $rolePermissions,
        ]);
    }

    public function storeRole(Request $request): Response
    {
        $this->validate($request, [
            'name'  => 'required|string|max:64|alpha_dash|unique:roles,name',
            'label' => 'required|string|max:128',
        ]);

        $id = Role::create([
            'name'        => strtolower($request->string('name')),
            'label'       => $request->string('label'),
            'description' => $request->string('description'),
            'is_system'   => 0,
        ]);

        Role::syncPermissions($id, $request->array('permissions'));
        AuditService::log('role.create', 'role', $id, 'Created role ' . $request->string('name'));

        return $this->back($request, 'success', 'Role created.');
    }

    public function updateRole(Request $request, string $id): Response
    {
        $role = Role::find((int) $id);

        if ($role === null) {
            throw new HttpException(404, 'Role not found.', 'role_not_found');
        }

        if ($role['name'] !== 'admin') {
            Role::syncPermissions((int) $role['id'], $request->array('permissions'));
        }

        if ($request->filled('label')) {
            Role::updateById((int) $role['id'], ['label' => $request->string('label')]);
        }

        AuditService::log('role.update', 'role', (int) $role['id'], 'Updated role ' . $role['name']);

        return $this->back($request, 'success', 'Role updated.');
    }

    public function deleteRole(Request $request, string $id): Response
    {
        $role = Role::find((int) $id);

        if ($role === null) {
            throw new HttpException(404, 'Role not found.', 'role_not_found');
        }

        if ((int) $role['is_system'] === 1) {
            return $this->back($request, 'error', 'System roles cannot be deleted.');
        }

        if (User::count(['role_id' => (int) $role['id']]) > 0) {
            return $this->back($request, 'error', 'Reassign this role\'s users before deleting it.');
        }

        Role::deleteById((int) $role['id']);
        AuditService::log('role.delete', 'role', (int) $role['id'], 'Deleted role ' . $role['name']);

        return $this->back($request, 'success', 'Role deleted.');
    }

    // --- All files ------------------------------------------------------

    public function files(Request $request): Response
    {
        $filters = [
            'q'    => $request->string('q'),
            'mime' => $request->string('type'),
            'trashed' => $request->bool('trashed'),
        ];

        if ($request->int('user_id') > 0) {
            $filters['user_id'] = $request->int('user_id');
        }

        $files = FileRecord::search(
            $filters,
            $this->pageNumber($request),
            $this->perPage($request, 30),
            $request->string('sort', 'created_at'),
            $request->string('dir', 'desc')
        );

        // Attach owner names for the listing.
        $ownerIds = array_values(array_unique(array_column($files['data'], 'user_id')));
        $owners = [];

        if ($ownerIds !== []) {
            $placeholders = implode(',', array_fill(0, count($ownerIds), '?'));
            foreach (Database::select("SELECT id, name, email FROM users WHERE id IN ({$placeholders})", $ownerIds) as $row) {
                $owners[(int) $row['id']] = $row;
            }
        }

        return $this->view('admin.files', [
            'files'   => $files,
            'owners'  => $owners,
            'filters' => $filters,
            'users'   => Database::select('SELECT id, name, email FROM users WHERE deleted_at IS NULL ORDER BY name LIMIT 500'),
        ]);
    }

    public function deleteFile(Request $request, string $id): Response
    {
        $file = FileRecord::resolve($id);

        if ($file === null) {
            throw new HttpException(404, 'File not found.', 'file_not_found');
        }

        \App\Services\FileService::purge((int) $file['user_id'], (int) $file['id']);

        return $this->back($request, 'success', 'File permanently deleted.');
    }

    // --- Settings -------------------------------------------------------

    public function settings(Request $request): Response
    {
        return $this->view('admin.settings', [
            'settings' => SettingService::grouped(),
            'defaults' => SettingService::defaults(),
            'mail'     => [
                'configured'   => \App\Services\Mailer::isConfigured(),
                'driver'       => (string) config('mail.driver', 'none'),
                'host'         => (string) config('mail.host', ''),
                'port'         => (int) config('mail.port', 587),
                'encryption'   => (string) config('mail.encryption', 'tls'),
                'username'     => (string) config('mail.username', ''),
                'has_password' => (string) config('mail.password', '') !== '',
                'from'         => (string) config('mail.from.address', ''),
                'from_name'    => (string) config('mail.from.name', ''),
            ],
        ]);
    }

    public function updateSettings(Request $request): Response
    {
        $group = $request->string('group', 'general');

        $booleans = [
            'allow_registration', 'require_email_verify', 'allow_duplicates', 'virus_scan_enabled',
            'force_2fa_admins', 'require_request_signing', 'sftp_enabled', 'ftp_enabled', 'ftps_enabled',
        ];

        $integers = [
            'default_quota', 'trash_retention_days', 'versions_kept', 'max_login_attempts',
            'lockout_minutes', 'signed_url_ttl', 'max_upload_size', 'chunk_size',
        ];

        $values = [];

        foreach ($request->all() as $key => $value) {
            if (in_array($key, ['_token', '_method', 'group'], true)) {
                continue;
            }

            if (in_array($key, $integers, true)) {
                $values[$key] = (int) $value;
            } elseif (in_array($key, $booleans, true)) {
                $values[$key] = in_array((string) $value, ['1', 'true', 'on', 'yes'], true);
            } else {
                $values[$key] = is_array($value) ? $value : (string) $value;
            }
        }

        // Unchecked checkboxes never post; treat them as false within their group.
        foreach ($booleans as $flag) {
            if (!array_key_exists($flag, $values) && $request->has('__flags_' . $flag)) {
                $values[$flag] = false;
            }
        }

        foreach ($request->array('__flags') as $flag) {
            if (in_array($flag, $booleans, true) && !$request->has($flag)) {
                $values[$flag] = false;
            }
        }

        unset($values['__flags']);

        SettingService::setMany($values, $group);
        AuditService::log('settings.update', 'settings', $group, 'Updated ' . $group . ' settings', array_keys($values));

        return $this->back($request, 'success', 'Settings saved.');
    }

    public function testMail(Request $request): Response
    {
        $this->validate($request, ['test_to' => 'required|email']);

        $to = $request->string('test_to');
        $result = (new \App\Services\Mailer())->send(
            $to,
            'Test message from ' . SettingService::get('site_name', config('app.name')),
            \App\Services\Mailer::template(
                'Email is working',
                '<p>Your mail settings are correct — this message was sent from your own server.</p>'
            )
        );

        AuditService::log('mail.test', 'settings', null, 'Sent a test email to ' . $to, [], $result['ok'] ? 'success' : 'failed');

        return $this->back($request, $result['ok'] ? 'success' : 'error', $result['message']);
    }

    // --- Monitoring -----------------------------------------------------

    public function monitoring(Request $request): Response
    {
        return $this->view('admin.monitoring', [
            'system'     => MetricsService::system(),
            'health'     => MetricsService::health(),
            'throughput' => StatsService::throughput(60),
            'cpuSeries'  => MetricsService::series('cpu_percent', 24),
            'memSeries'  => MetricsService::series('memory_percent', 24),
            'diskSeries' => MetricsService::series('disk_percent', 24),
            'stats'      => StatsService::global(),
            'sessions'   => SftpSession::active(),
            'failedLogins' => LoginAttempt::recent(15, false),
            'jobs'       => \App\Models\Job::stats(),
        ]);
    }

    public function metricsJson(Request $request): Response
    {
        return $this->json([
            'system'     => MetricsService::system(),
            'health'     => MetricsService::health(),
            'throughput' => StatsService::throughput(60),
            'stats'      => StatsService::global(),
        ]);
    }

    // --- Logs -----------------------------------------------------------

    public function logs(Request $request): Response
    {
        $filters = [
            'q'      => $request->string('q'),
            'action' => $request->string('action'),
            'status' => $request->string('status'),
            'from'   => $request->string('from'),
            'to'     => $request->string('to'),
        ];

        return $this->view('admin.logs', [
            'logs'      => AuditLog::search($filters, $this->pageNumber($request), $this->perPage($request, 40)),
            'actions'   => AuditLog::distinctActions(),
            'filters'   => $filters,
            'appLogs'   => Logger::tail(100, $request->string('level') ?: null, $request->string('date') ?: null),
            'logDates'  => Logger::availableDates(),
            'level'     => $request->string('level'),
            'date'      => $request->string('date'),
            'tab'       => $request->string('tab', 'audit'),
        ]);
    }

    public function purgeLogs(Request $request): Response
    {
        $days = max(1, $request->int('days', 90));
        $deleted = AuditService::purge($days);

        return $this->back($request, 'success', "{$deleted} audit entries older than {$days} days were removed.");
    }

    // --- Jobs & backups -------------------------------------------------

    public function jobs(Request $request): Response
    {
        $cronToken = \App\Console\Scheduler::token();

        return $this->view('admin.jobs', [
            'jobs'    => JobService::recent(50),
            'stats'   => \App\Models\Job::stats(),
            'types'   => JobService::TYPES,
            'backups' => BackupService::list(),
            'cron'    => [
                'schedule'     => \App\Console\Scheduler::status(),
                'never_run'    => \App\Console\Scheduler::hasNeverRun(),
                'last_activity' => \App\Console\Scheduler::lastActivity(),
                'file'         => $this->basePathFor('public/cron.php'),
                'command'      => (\App\Console\Scheduler::phpBinary() ?? 'php')
                                  . ' ' . $this->basePathFor('public/cron.php'),
                'php_found'    => \App\Console\Scheduler::phpBinary() !== null,
                'url'          => $cronToken === '' ? null : url('/cron.php?token=' . $cronToken),
                'token_set'    => $cronToken !== '',
            ],
        ]);
    }

    public function runJob(Request $request): Response
    {
        $type = $request->string('type');

        if (!array_key_exists($type, JobService::TYPES)) {
            return $this->back($request, 'error', 'Unknown job type.');
        }

        if ($request->bool('queue')) {
            JobService::dispatch($type);

            return $this->back($request, 'success', 'Job queued.');
        }

        $result = JobService::execute($type);

        if ($request->wantsJson()) {
            return $this->json($result, $result['ok'] ? 200 : 500);
        }

        return $this->back(
            $request,
            $result['ok'] ? 'success' : 'error',
            ($result['ok'] ? 'Job finished: ' : 'Job failed: ') . $result['output']
        );
    }

    public function retryJob(Request $request, string $id): Response
    {
        JobService::retry((int) $id);

        return $this->back($request, 'success', 'Job re-queued.');
    }

    public function workQueue(Request $request): Response
    {
        // No queue named: drain them all, so webhook deliveries are not missed.
        $queue = $request->string('queue');

        $result = $queue === ''
            ? JobService::workAll(25)
            : JobService::work($queue, 25);

        return $this->back($request, 'success', "Processed {$result['processed']} job(s), {$result['failed']} failed.");
    }

    public function createBackup(Request $request): Response
    {
        $result = BackupService::create($request->string('label'));

        return $this->back($request, 'success', sprintf(
            'Backup created: %s (%s, %d tables, %d rows).',
            $result['file'],
            bytes($result['size']),
            $result['tables'],
            $result['rows']
        ));
    }

    public function downloadBackup(Request $request, string $name): Response
    {
        $file = BackupService::resolve($name);

        AuditService::log('backup.download', 'backup', $name, "Downloaded backup {$name}");

        return Response::stream(static function () use ($file): void {
            $handle = fopen($file, 'rb');
            if ($handle === false) {
                return;
            }
            while (!feof($handle)) {
                echo fread($handle, 262144);
                flush();
            }
            fclose($handle);
        }, 200, [
            'Content-Type'        => 'application/sql',
            'Content-Disposition' => 'attachment; filename="' . basename($file) . '"',
            'Content-Length'      => (string) filesize($file),
        ]);
    }

    public function deleteBackup(Request $request, string $name): Response
    {
        BackupService::delete($name);

        return $this->back($request, 'success', 'Backup deleted.');
    }

    // --- Storage backends -------------------------------------------------

    public function storage(Request $request): Response
    {
        $backends = array_map(
            [\App\Models\StorageBackend::class, 'publicArray'],
            StorageBackendService::list()
        );

        return $this->view('admin.storage', [
            'backends'    => $backends,
            'usage'       => StorageBackendService::usageBySlug(),
            'drivers'     => \App\Models\StorageBackend::DRIVERS,
            'defaultSlug' => \App\Storage\StorageManager::defaultSlug(),
            'capabilities' => [
                'ftp'  => extension_loaded('ftp'),
                'sftp' => \App\Storage\SftpDriver::isSupported(),
            ],
        ]);
    }

    public function storeBackend(Request $request): Response
    {
        $backend = StorageBackendService::create($this->backendInput($request));

        // Tell the admin straight away whether the credentials actually work.
        $test = StorageBackendService::test($backend);

        return $this->back(
            $request,
            $test['ok'] ? 'success' : 'warning',
            $test['ok']
                ? 'Backend added and connected.'
                : 'Backend saved, but the connection test failed: ' . $test['message']
        );
    }

    public function updateBackend(Request $request, string $id): Response
    {
        StorageBackendService::update((int) $id, $this->backendInput($request));

        return $this->back($request, 'success', 'Backend updated.');
    }

    public function testBackend(Request $request, string $id): Response
    {
        $result = StorageBackendService::test((int) $id);

        if ($request->wantsJson()) {
            return $this->json($result, $result['ok'] ? 200 : 502);
        }

        return $this->back($request, $result['ok'] ? 'success' : 'error', $result['message']);
    }

    public function defaultBackend(Request $request, string $id): Response
    {
        $backend = StorageBackendService::makeDefault((int) $id);

        return $this->back($request, 'success', $backend['name'] . ' now receives new uploads.');
    }

    public function deleteBackend(Request $request, string $id): Response
    {
        StorageBackendService::delete((int) $id);

        return $this->back($request, 'success', 'Backend deleted.');
    }

    public function migrateBackend(Request $request, string $id): Response
    {
        $backend = \App\Models\StorageBackend::find((int) $id);

        if ($backend === null) {
            return $this->back($request, 'error', 'Backend not found.');
        }

        $target = $request->string('to');

        if ($target === '') {
            return $this->back($request, 'error', 'Choose a destination backend.');
        }

        // Large migrations belong in the queue; the panel only kicks it off.
        JobService::dispatch('storage.migrate', [
            'from'  => (string) $backend['slug'],
            'to'    => $target,
            'limit' => max(1, min(1000, $request->int('limit', 200))),
        ]);

        return $this->back($request, 'success', sprintf(
            'Queued a migration from %s to %s. Run the queue (or wait for cron) to move the files.',
            $backend['slug'],
            $target
        ));
    }

    /** Shape a posted backend form into service input. */
    private function backendInput(Request $request): array
    {
        $input = $request->all();
        unset($input['_token'], $input['_method']);

        // Every checkbox in the form is paired with a hidden "0", so an
        // unchecked box still posts a value.
        foreach (['is_active', 'is_default', 'passive', 'use_pasv_address'] as $flag) {
            $input[$flag] = $request->bool($flag) ? '1' : '0';
        }

        return $input;
    }

    // --- SFTP administration --------------------------------------------

    public function sftp(Request $request): Response
    {
        $conditions = [];

        if ($request->string('status') !== '') {
            $conditions['status'] = $request->string('status');
        }

        return $this->view('admin.sftp', [
            'accounts' => SftpAccount::withOwners($conditions, $this->pageNumber($request), $this->perPage($request, 20)),
            'sessions' => SftpSession::active(),
            'users'    => Database::select('SELECT id, name, email FROM users WHERE deleted_at IS NULL ORDER BY name LIMIT 500'),
            'services' => [
                'sftp' => SftpService::serviceEnabled('sftp'),
                'ftp'  => SftpService::serviceEnabled('ftp'),
                'ftps' => SftpService::serviceEnabled('ftps'),
            ],
            'newPassword' => Session::get('new_sftp_password'),
            'connection'  => [
                'host' => config('sftp.sftp.host'),
                'port' => config('sftp.sftp.port'),
            ],
        ]);
    }

    public function storeSftp(Request $request): Response
    {
        $this->validate($request, [
            'user_id'  => 'required|int|exists:users,id',
            'username' => 'required|string|min:3|max:32',
            'password' => 'required|string|min:8|max:100',
        ]);

        $account = SftpService::createAccount($this->userId(), [
            'user_id'     => $request->int('user_id'),
            'username'    => $request->string('username'),
            'password'    => (string) $request->input('password', ''),
            'protocol'    => $request->string('protocol', 'sftp'),
            'permission'  => $request->string('permission', 'rw'),
            'quota_bytes' => $request->int('quota_gb', 1) * 1073741824,
        ]);

        if ($request->wantsJson()) {
            return $this->json(SftpAccount::publicArray($account), 201);
        }

        return $this->back($request, 'success', 'FTP/SFTP account created.');
    }

    public function updateSftp(Request $request, string $id): Response
    {
        SftpService::updateAccount((int) $id, [
            'permission'  => $request->string('permission'),
            'status'      => $request->string('status'),
            'protocol'    => $request->string('protocol'),
            'quota_bytes' => $request->has('quota_gb') ? $request->int('quota_gb') * 1073741824 : null,
        ]);

        return $this->back($request, 'success', 'Account updated.');
    }

    public function resetSftpPassword(Request $request, string $id): Response
    {
        $result = SftpService::resetPassword((int) $id, $request->string('password') ?: null);

        Session::put('new_sftp_password', $result['password']);

        return $this->back($request, 'success', 'Password reset.');
    }

    public function deleteSftp(Request $request, string $id): Response
    {
        SftpService::deleteAccount((int) $id, $request->bool('remove_files'));

        return $this->back($request, 'success', 'Account deleted.');
    }

    public function syncSftp(Request $request, string $id): Response
    {
        $result = SftpService::sync((int) $id);

        return $this->back($request, 'success', "Sync complete: {$result['imported']} imported, {$result['removed']} removed, {$result['skipped']} skipped.");
    }

    public function syncAllSftp(Request $request): Response
    {
        $result = SftpService::syncAll();

        return $this->back($request, 'success', "Synced {$result['accounts']} accounts: {$result['imported']} imported.");
    }

    public function closeSession(Request $request, string $id): Response
    {
        SftpService::closeSession((int) $id);

        return $this->back($request, 'success', 'Session disconnected.');
    }

    public function opensshConfig(Request $request): Response
    {
        return Response::text(SftpService::opensshConfig())
            ->header('Content-Disposition', 'attachment; filename="sshd_config.snippet"');
    }

    // --- IP rules -------------------------------------------------------

    public function ipRules(Request $request): Response
    {
        return $this->view('admin.ip-rules', [
            'rules' => IpRule::all('id DESC', 500),
        ]);
    }

    public function storeIpRule(Request $request): Response
    {
        $this->validate($request, [
            'cidr' => 'required|string|max:64',
            'type' => 'required|in:allow,block',
        ]);

        $cidr = $request->string('cidr');

        // Reject a rule that would immediately lock the operator out.
        if ($request->string('type') === 'block' && IpRule::matches($request->ip(), $cidr)) {
            return $this->back($request, 'error', 'That rule would block your own IP address.');
        }

        try {
            IpRule::create([
                'type'       => $request->string('type'),
                'cidr'       => $cidr,
                'scope'      => $request->string('scope', 'global'),
                'note'       => $request->string('note'),
                'created_by' => $this->userId(),
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable) {
            return $this->back($request, 'error', 'That rule already exists.');
        }

        \App\Core\Cache::flush();
        AuditService::log('ip_rule.create', 'ip_rule', null, "Added {$request->string('type')} rule for {$cidr}");

        return $this->back($request, 'success', 'IP rule added.');
    }

    public function deleteIpRule(Request $request, string $id): Response
    {
        IpRule::deleteById((int) $id);
        \App\Core\Cache::flush();

        AuditService::log('ip_rule.delete', 'ip_rule', $id, 'Deleted IP rule');

        return $this->back($request, 'success', 'IP rule removed.');
    }

    private function basePathFor(string $relative): string
    {
        return str_replace('\\', '/', \App\Core\App::instance()->basePath($relative));
    }

    private function resolveUser(string $id): array
    {
        $user = ctype_digit($id) ? User::withRole((int) $id) : null;

        if ($user === null) {
            $found = User::findByUuid($id);
            $user = $found === null ? null : User::withRole((int) $found['id']);
        }

        if ($user === null) {
            throw new HttpException(404, 'User not found.', 'user_not_found');
        }

        return $user;
    }
}
