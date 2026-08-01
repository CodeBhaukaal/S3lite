<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Database;
use App\Core\Logger;
use App\Http\Exceptions\HttpException;
use App\Models\FileRecord;
use App\Models\Folder;
use App\Models\IpRule;
use App\Models\SftpAccount;
use App\Models\SftpKey;
use App\Models\SftpSession;
use App\Support\Crypto;
use App\Support\Str;

/**
 * FTP/FTPS/SFTP integration.
 *
 * The platform owns the account database, the isolated home directories, the
 * quotas and the activity log. An SFTP daemon (OpenSSH, FileZilla Server, …)
 * authenticates against `authenticate()` / the auth API endpoint and chroots
 * users into `home_dir`; `sync()` then indexes whatever lands there so uploads
 * appear in the web panel.
 */
final class SftpService
{
    public static function homeRoot(): string
    {
        return rtrim((string) Config::get('sftp.home_root'), '/\\');
    }

    public static function createAccount(int $ownerId, array $data): array
    {
        $username = strtolower(trim((string) ($data['username'] ?? '')));

        if (preg_match('/^[a-z0-9][a-z0-9._-]{2,31}$/', $username) !== 1) {
            throw new HttpException(422, 'Username must be 3-32 characters: letters, digits, dot, dash or underscore.', 'invalid_username');
        }

        if (SftpAccount::findByUsername($username) !== null) {
            throw new HttpException(409, 'That FTP/SFTP username is already taken.', 'username_taken');
        }

        $password = (string) ($data['password'] ?? '');
        if ($password !== '' && mb_strlen($password) < 8) {
            throw new HttpException(422, 'The account password must be at least 8 characters.', 'weak_password');
        }

        $protocol = in_array($data['protocol'] ?? 'sftp', ['sftp', 'ftp', 'ftps'], true) ? $data['protocol'] : 'sftp';
        $permission = ($data['permission'] ?? 'rw') === 'ro' ? 'ro' : 'rw';

        $home = self::homeRoot() . '/' . $username;
        self::ensureHome($home);

        $id = SftpAccount::create([
            'uuid'          => Str::uuid(),
            'user_id'       => (int) ($data['user_id'] ?? $ownerId),
            'username'      => $username,
            'password_hash' => $password === '' ? null : Crypto::hashPassword($password),
            'home_dir'      => $home,
            'protocol'      => $protocol,
            'permission'    => $permission,
            'quota_bytes'   => (int) ($data['quota_bytes'] ?? 1073741824),
            'status'        => ($data['status'] ?? 'active') === 'disabled' ? 'disabled' : 'active',
            'ip_allowlist'  => array_values(array_filter((array) ($data['ip_allowlist'] ?? []))),
        ]);

        AuditService::log('sftp.account_create', 'sftp_account', $id, "Created {$protocol} account {$username}", [
            'permission' => $permission,
            'home'       => $home,
        ]);

        return SftpAccount::find($id) ?? [];
    }

    public static function updateAccount(int $accountId, array $data, ?int $restrictToUserId = null): array
    {
        $account = self::accountOrFail($accountId, $restrictToUserId);
        $update = [];

        if (array_key_exists('permission', $data)) {
            $update['permission'] = $data['permission'] === 'ro' ? 'ro' : 'rw';
        }

        if (array_key_exists('status', $data)) {
            $update['status'] = $data['status'] === 'disabled' ? 'disabled' : 'active';
        }

        if (array_key_exists('quota_bytes', $data) && $data['quota_bytes'] !== null) {
            $update['quota_bytes'] = max(0, (int) $data['quota_bytes']);
        }

        if (array_key_exists('protocol', $data) && in_array($data['protocol'], ['sftp', 'ftp', 'ftps'], true)) {
            $update['protocol'] = $data['protocol'];
        }

        if (array_key_exists('ip_allowlist', $data)) {
            $update['ip_allowlist'] = array_values(array_filter((array) $data['ip_allowlist']));
        }

        if (!empty($data['password'])) {
            if (mb_strlen((string) $data['password']) < 8) {
                throw new HttpException(422, 'The account password must be at least 8 characters.', 'weak_password');
            }
            $update['password_hash'] = Crypto::hashPassword((string) $data['password']);
        }

        if ($update !== []) {
            SftpAccount::updateById($accountId, $update);
        }

        // Disabling an account must drop its live sessions.
        if (($update['status'] ?? '') === 'disabled') {
            self::disconnectAll($accountId);
        }

        AuditService::log('sftp.account_update', 'sftp_account', $accountId, "Updated account {$account['username']}", array_keys($update));

        return SftpAccount::find($accountId) ?? [];
    }

    public static function resetPassword(int $accountId, ?string $password = null, ?int $restrictToUserId = null): array
    {
        $account = self::accountOrFail($accountId, $restrictToUserId);
        $password ??= Str::random(20);

        if (mb_strlen($password) < 8) {
            throw new HttpException(422, 'The account password must be at least 8 characters.', 'weak_password');
        }

        SftpAccount::updateById($accountId, ['password_hash' => Crypto::hashPassword($password)]);
        self::disconnectAll($accountId);

        AuditService::log('sftp.password_reset', 'sftp_account', $accountId, "Reset password for {$account['username']}");

        return ['account' => SftpAccount::find($accountId) ?? [], 'password' => $password];
    }

    public static function deleteAccount(int $accountId, bool $removeFiles = false, ?int $restrictToUserId = null): void
    {
        $account = self::accountOrFail($accountId, $restrictToUserId);

        self::disconnectAll($accountId);
        SftpAccount::deleteById($accountId);

        if ($removeFiles) {
            self::removeDirectory((string) $account['home_dir']);
            self::removeMirrorFolder((int) $account['user_id'], (string) $account['username']);
        }

        AuditService::log('sftp.account_delete', 'sftp_account', $accountId, "Deleted account {$account['username']}", [
            'files_removed' => $removeFiles,
        ]);
    }

    // --- SSH keys -------------------------------------------------------

    public static function addKey(int $accountId, string $name, string $publicKey, ?int $restrictToUserId = null): array
    {
        $account = self::accountOrFail($accountId, $restrictToUserId);
        $publicKey = trim(preg_replace('/\s+/', ' ', $publicKey) ?? '');

        $parts = explode(' ', $publicKey);

        if (count($parts) < 2) {
            throw new HttpException(422, 'That does not look like an OpenSSH public key.', 'invalid_public_key');
        }

        [$type, $base64] = $parts;
        $allowedTypes = ['ssh-rsa', 'ssh-ed25519', 'ecdsa-sha2-nistp256', 'ecdsa-sha2-nistp384', 'ecdsa-sha2-nistp521', 'ssh-dss'];

        if (!in_array($type, $allowedTypes, true)) {
            throw new HttpException(422, 'Unsupported key type: ' . $type, 'invalid_public_key');
        }

        $binary = base64_decode($base64, true);

        if ($binary === false || strlen($binary) < 16) {
            throw new HttpException(422, 'The public key body is not valid base64.', 'invalid_public_key');
        }

        $fingerprint = 'SHA256:' . rtrim(base64_encode(hash('sha256', $binary, true)), '=');

        if (SftpKey::exists(['account_id' => $accountId, 'fingerprint' => $fingerprint])) {
            throw new HttpException(409, 'That key is already registered on this account.', 'key_exists');
        }

        $id = SftpKey::create([
            'account_id'  => $accountId,
            'name'        => mb_substr($name !== '' ? $name : 'key', 0, 120),
            'public_key'  => $publicKey,
            'fingerprint' => $fingerprint,
            'key_type'    => $type,
            'created_at'  => date('Y-m-d H:i:s'),
        ]);

        self::writeAuthorizedKeys($accountId);

        AuditService::log('sftp.key_add', 'sftp_account', $accountId, "Added SSH key to {$account['username']}", [
            'fingerprint' => $fingerprint,
        ]);

        return SftpKey::find($id) ?? [];
    }

    public static function removeKey(int $accountId, int $keyId, ?int $restrictToUserId = null): void
    {
        $account = self::accountOrFail($accountId, $restrictToUserId);
        $key = SftpKey::find($keyId);

        if ($key === null || (int) $key['account_id'] !== $accountId) {
            throw new HttpException(404, 'Key not found.', 'key_not_found');
        }

        SftpKey::deleteById($keyId);
        self::writeAuthorizedKeys($accountId);

        AuditService::log('sftp.key_remove', 'sftp_account', $accountId, "Removed SSH key from {$account['username']}", [
            'fingerprint' => $key['fingerprint'],
        ]);
    }

    /** Materialise authorized_keys so an OpenSSH daemon can consume it directly. */
    private static function writeAuthorizedKeys(int $accountId): void
    {
        $account = SftpAccount::find($accountId);

        if ($account === null) {
            return;
        }

        $dir = rtrim((string) $account['home_dir'], '/\\') . '/.ssh';

        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            return;
        }

        $lines = [];
        foreach (SftpKey::forAccount($accountId) as $key) {
            $lines[] = (string) $key['public_key'];
        }

        @file_put_contents($dir . '/authorized_keys', implode(PHP_EOL, $lines) . PHP_EOL);
        @chmod($dir . '/authorized_keys', 0600);
    }

    // --- Authentication for the external daemon --------------------------

    /**
     * @return array{ok:bool, account:?array, reason:?string}
     */
    public static function authenticate(string $username, string $password, string $ip = '', string $protocol = 'sftp'): array
    {
        $account = SftpAccount::findByUsername(strtolower($username));

        if ($account === null) {
            self::activity(null, 'auth', null, 0, 'failed', $ip);

            return ['ok' => false, 'account' => null, 'reason' => 'unknown_account'];
        }

        if ($account['status'] !== 'active') {
            self::activity((int) $account['id'], 'auth', null, 0, 'disabled', $ip);

            return ['ok' => false, 'account' => $account, 'reason' => 'account_disabled'];
        }

        if (!self::serviceEnabled((string) $account['protocol'])) {
            return ['ok' => false, 'account' => $account, 'reason' => 'service_disabled'];
        }

        $allowlist = $account['ip_allowlist'] ?: [];
        if ($allowlist !== [] && $ip !== '') {
            $matched = false;
            foreach ($allowlist as $cidr) {
                if (IpRule::matches($ip, (string) $cidr)) {
                    $matched = true;
                    break;
                }
            }
            if (!$matched) {
                self::activity((int) $account['id'], 'auth', null, 0, 'ip_blocked', $ip);

                return ['ok' => false, 'account' => $account, 'reason' => 'ip_not_allowed'];
            }
        }

        if ($account['password_hash'] === null || !Crypto::verifyPassword($password, (string) $account['password_hash'])) {
            self::activity((int) $account['id'], 'auth', null, 0, 'bad_password', $ip);

            return ['ok' => false, 'account' => $account, 'reason' => 'invalid_credentials'];
        }

        SftpAccount::updateById((int) $account['id'], [
            'last_login_at' => date('Y-m-d H:i:s'),
            'last_login_ip' => $ip,
        ]);

        self::activity((int) $account['id'], 'auth', null, 0, 'ok', $ip);

        return ['ok' => true, 'account' => $account, 'reason' => null];
    }

    /** @return array{ok:bool, account:?array, key:?array, reason:?string} */
    public static function authenticateKey(string $username, string $fingerprint, string $ip = ''): array
    {
        $account = SftpAccount::findByUsername(strtolower($username));

        if ($account === null || $account['status'] !== 'active') {
            return ['ok' => false, 'account' => $account, 'key' => null, 'reason' => 'account_unavailable'];
        }

        $key = SftpKey::findWhere(['account_id' => (int) $account['id'], 'fingerprint' => $fingerprint]);

        if ($key === null) {
            self::activity((int) $account['id'], 'auth', null, 0, 'bad_key', $ip);

            return ['ok' => false, 'account' => $account, 'key' => null, 'reason' => 'unknown_key'];
        }

        SftpKey::updateById((int) $key['id'], ['last_used_at' => date('Y-m-d H:i:s')]);
        SftpAccount::updateById((int) $account['id'], ['last_login_at' => date('Y-m-d H:i:s'), 'last_login_ip' => $ip]);
        self::activity((int) $account['id'], 'auth', null, 0, 'ok', $ip);

        return ['ok' => true, 'account' => $account, 'key' => $key, 'reason' => null];
    }

    public static function serviceEnabled(string $protocol): bool
    {
        return match ($protocol) {
            'ftp'   => (bool) SettingService::get('ftp_enabled', Config::get('sftp.ftp.enabled', false)),
            'ftps'  => (bool) SettingService::get('ftps_enabled', Config::get('sftp.ftps.enabled', false)),
            default => (bool) SettingService::get('sftp_enabled', Config::get('sftp.sftp.enabled', true)),
        };
    }

    // --- Sessions -------------------------------------------------------

    public static function openSession(int $accountId, string $ip, string $client = '', string $protocol = 'sftp'): array
    {
        $key = Str::random(32);

        $id = SftpSession::create([
            'account_id'       => $accountId,
            'session_key'      => $key,
            'protocol'         => $protocol,
            'ip'               => $ip,
            'client'           => mb_substr($client, 0, 190),
            'status'           => 'active',
            'started_at'       => date('Y-m-d H:i:s'),
            'last_activity_at' => date('Y-m-d H:i:s'),
        ]);

        return SftpSession::find($id) ?? [];
    }

    public static function touchSession(string $sessionKey, int $bytesIn = 0, int $bytesOut = 0): void
    {
        Database::statement(
            'UPDATE sftp_sessions
             SET last_activity_at = NOW(), bytes_in = bytes_in + ?, bytes_out = bytes_out + ?
             WHERE session_key = ? AND status = "active"',
            [$bytesIn, $bytesOut, $sessionKey]
        );
    }

    public static function closeSession(int $sessionId, ?int $restrictToUserId = null): void
    {
        $session = SftpSession::find($sessionId);

        if ($session === null) {
            throw new HttpException(404, 'Session not found.', 'session_not_found');
        }

        if ($restrictToUserId !== null) {
            $account = SftpAccount::find((int) $session['account_id']);
            if ($account === null || (int) $account['user_id'] !== $restrictToUserId) {
                throw new HttpException(404, 'Session not found.', 'session_not_found');
            }
        }

        SftpSession::close($sessionId);

        AuditService::log('sftp.session_close', 'sftp_session', $sessionId, 'Disconnected session', [
            'account_id' => (int) $session['account_id'],
        ]);
    }

    public static function disconnectAll(int $accountId): int
    {
        return Database::statement(
            "UPDATE sftp_sessions SET status = 'closed', ended_at = NOW() WHERE account_id = ? AND status = 'active'",
            [$accountId]
        );
    }

    public static function activity(?int $accountId, string $action, ?string $path = null, int $size = 0, string $status = 'ok', string $ip = '', ?int $sessionId = null): void
    {
        if ($accountId === null) {
            return;
        }

        try {
            Database::insert('sftp_activity', [
                'account_id' => $accountId,
                'session_id' => $sessionId,
                'action'     => mb_substr($action, 0, 32),
                'path'       => $path === null ? null : mb_substr($path, 0, 500),
                'size'       => $size,
                'status'     => mb_substr($status, 0, 16),
                'ip'         => $ip !== '' ? $ip : null,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            Logger::error('SFTP activity log failed: ' . $e->getMessage());
        }
    }

    public static function activityFor(int $accountId, int $limit = 100): array
    {
        return Database::select(
            'SELECT * FROM sftp_activity WHERE account_id = ? ORDER BY id DESC LIMIT ' . max(1, min(500, $limit)),
            [$accountId]
        );
    }

    // --- Synchronisation -------------------------------------------------

    /**
     * Index everything found in an account's home directory into the web panel,
     * and remove index entries whose files disappeared over SFTP.
     *
     * @return array{imported:int, removed:int, skipped:int, bytes:int}
     */
    public static function sync(int $accountId): array
    {
        $account = SftpAccount::find($accountId);

        if ($account === null) {
            throw new HttpException(404, 'Account not found.', 'account_not_found');
        }

        $home = rtrim((string) $account['home_dir'], '/\\');
        $userId = (int) $account['user_id'];

        if (!is_dir($home)) {
            self::ensureHome($home);

            return ['imported' => 0, 'removed' => 0, 'skipped' => 0, 'bytes' => 0];
        }

        $rootFolder = self::syncFolder($userId, (string) $account['username']);

        $imported = 0;
        $skipped = 0;
        $bytes = 0;
        $seen = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($home, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $entry) {
            if (!$entry instanceof \SplFileInfo || !$entry->isFile()) {
                continue;
            }

            $absolute = str_replace('\\', '/', $entry->getPathname());
            $relative = ltrim(substr($absolute, strlen($home)), '/');

            if (str_starts_with($relative, '.ssh/') || str_starts_with(basename($relative), '.')) {
                continue;
            }

            $seen[] = $absolute;

            $existing = Database::selectOne(
                'SELECT id, size, checksum FROM files WHERE user_id = ? AND source = ? AND JSON_UNQUOTE(JSON_EXTRACT(meta, "$.sftp_path")) = ? LIMIT 1',
                [$userId, 'sftp', $absolute]
            );

            $size = (int) $entry->getSize();
            $checksum = hash_file('sha256', $absolute);

            if ($checksum === false) {
                $skipped++;
                continue;
            }

            if ($existing !== null && $existing['checksum'] === $checksum) {
                $skipped++;
                continue;
            }

            $inspection = MimeGuard::inspect($absolute, basename($relative));

            if (!$inspection['ok']) {
                $skipped++;
                self::activity($accountId, 'reject', $relative, $size, 'blocked');
                continue;
            }

            $quota = QuotaService::check($userId, $size);
            if (!$quota['allowed']) {
                $skipped++;
                self::activity($accountId, 'reject', $relative, $size, 'quota');
                continue;
            }

            $folderId = self::mirrorFolders($userId, (int) $rootFolder['id'], dirname($relative));

            // Files stay in place on disk; the index points at the SFTP path.
            $tmp = (string) Config::get('storage.tmp_path') . '/sftp-' . bin2hex(random_bytes(6));

            if (!@copy($absolute, $tmp)) {
                $skipped++;
                continue;
            }

            try {
                if ($existing !== null) {
                    FileService::store($userId, ['path' => $tmp, 'name' => basename($relative), 'size' => $size], [
                        'overwrite_file_id' => (int) $existing['id'],
                        'source'            => 'sftp',
                        'note'              => 'Updated over SFTP',
                    ]);
                } else {
                    $result = FileService::store($userId, ['path' => $tmp, 'name' => basename($relative), 'size' => $size], [
                        'folder_id' => $folderId,
                        'source'    => 'sftp',
                    ]);

                    $fileId = (int) ($result['file']['id'] ?? 0);
                    if ($fileId > 0) {
                        $meta = (array) ($result['file']['meta'] ?? []);
                        $meta['sftp_path'] = $absolute;
                        $meta['sftp_account'] = $account['username'];
                        FileRecord::updateById($fileId, ['meta' => $meta, 'source' => 'sftp']);
                    }
                }

                $imported++;
                $bytes += $size;
                self::activity($accountId, 'import', $relative, $size, 'ok');
                WebhookService::dispatch('sftp.upload', [
                    'account'  => $account['username'],
                    'path'     => $relative,
                    'size'     => $size,
                ], $userId);
            } catch (\Throwable $e) {
                @unlink($tmp);
                $skipped++;
                Logger::warning('SFTP import failed: ' . $e->getMessage(), ['path' => $relative]);
                self::activity($accountId, 'import', $relative, $size, 'failed');
            }
        }

        // Remove index entries for files deleted over SFTP.
        $removed = 0;
        $indexed = Database::select(
            'SELECT id, meta FROM files WHERE user_id = ? AND source = ? AND deleted_at IS NULL',
            [$userId, 'sftp']
        );

        foreach ($indexed as $row) {
            $meta = json_decode((string) $row['meta'], true);
            $path = is_array($meta) ? ($meta['sftp_path'] ?? null) : null;

            if (is_string($path) && str_starts_with($path, $home) && !in_array($path, $seen, true)) {
                FileRecord::softDelete((int) $row['id']);
                $removed++;
            }
        }

        self::recalculateUsage($accountId);

        AuditService::log('sftp.sync', 'sftp_account', $accountId, "Synced account {$account['username']}", [
            'imported' => $imported,
            'removed'  => $removed,
            'skipped'  => $skipped,
        ]);

        return ['imported' => $imported, 'removed' => $removed, 'skipped' => $skipped, 'bytes' => $bytes];
    }

    public static function syncAll(): array
    {
        $totals = ['accounts' => 0, 'imported' => 0, 'removed' => 0, 'skipped' => 0];

        foreach (SftpAccount::where(['status' => 'active'], 'id ASC', 500) as $account) {
            try {
                $result = self::sync((int) $account['id']);
                $totals['accounts']++;
                $totals['imported'] += $result['imported'];
                $totals['removed'] += $result['removed'];
                $totals['skipped'] += $result['skipped'];
            } catch (\Throwable $e) {
                Logger::error('SFTP sync failed: ' . $e->getMessage(), ['account_id' => $account['id']]);
            }
        }

        return $totals;
    }

    /**
     * Drop the panel-side mirror of a deleted account, so removing an account
     * with its files does not leave an empty "SFTP - name" folder behind.
     */
    private static function removeMirrorFolder(int $userId, string $username): void
    {
        $folder = Folder::findWhere([
            'user_id'   => $userId,
            'name'      => 'SFTP - ' . $username,
            'parent_id' => null,
        ]);

        if ($folder === null) {
            return;
        }

        try {
            FolderService::purge($userId, (int) $folder['id']);
        } catch (\Throwable $e) {
            Logger::warning('Could not remove the SFTP mirror folder: ' . $e->getMessage(), [
                'folder_id' => (int) $folder['id'],
            ]);
        }
    }

    /** The panel-side folder that mirrors an account's home directory. */
    private static function syncFolder(int $userId, string $username): array
    {
        $name = 'SFTP - ' . $username;
        $existing = Folder::findWhere(['user_id' => $userId, 'name' => $name, 'parent_id' => null, 'deleted_at' => null]);

        if ($existing !== null) {
            return $existing;
        }

        return FolderService::create($userId, $name, null, '#38bdf8');
    }

    private static function mirrorFolders(int $userId, int $rootFolderId, string $relativeDir): int
    {
        $relativeDir = trim(str_replace('\\', '/', $relativeDir), './');

        if ($relativeDir === '' || $relativeDir === '.') {
            return $rootFolderId;
        }

        $parentId = $rootFolderId;

        foreach (explode('/', $relativeDir) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            $existing = Folder::findWhere([
                'user_id'    => $userId,
                'parent_id'  => $parentId,
                'name'       => $segment,
                'deleted_at' => null,
            ]);

            if ($existing !== null) {
                $parentId = (int) $existing['id'];
                continue;
            }

            try {
                $created = FolderService::create($userId, $segment, $parentId);
                $parentId = (int) $created['id'];
            } catch (\Throwable) {
                return $parentId;
            }
        }

        return $parentId;
    }

    public static function recalculateUsage(int $accountId): int
    {
        $account = SftpAccount::find($accountId);

        if ($account === null) {
            return 0;
        }

        $used = self::directorySize((string) $account['home_dir']);
        SftpAccount::updateById($accountId, ['used_bytes' => $used]);

        return $used;
    }

    public static function directorySize(string $dir): int
    {
        if (!is_dir($dir)) {
            return 0;
        }

        $total = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($iterator as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile()) {
                $total += $file->getSize();
            }
        }

        return $total;
    }

    /**
     * Config snippet an administrator can drop into an OpenSSH server so the
     * daemon chroots each account into its isolated home directory.
     */
    public static function opensshConfig(): string
    {
        $lines = [
            '# Generated by S3 Lite — append to /etc/ssh/sshd_config',
            'Subsystem sftp internal-sftp',
            '',
        ];

        foreach (SftpAccount::where(['status' => 'active'], 'username ASC', 500) as $account) {
            $lines[] = sprintf('Match User %s', $account['username']);
            $lines[] = sprintf('    ChrootDirectory %s', $account['home_dir']);
            $lines[] = '    ForceCommand internal-sftp' . ($account['permission'] === 'ro' ? ' -R' : '');
            $lines[] = '    AllowTcpForwarding no';
            $lines[] = '    X11Forwarding no';
            $lines[] = sprintf('    AuthorizedKeysFile %s/.ssh/authorized_keys', $account['home_dir']);
            $lines[] = '';
        }

        return implode(PHP_EOL, $lines);
    }

    private static function ensureHome(string $home): void
    {
        foreach ([$home, $home . '/upload', $home . '/.ssh'] as $dir) {
            if (!is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
        }
    }

    private static function removeDirectory(string $dir): void
    {
        $root = self::homeRoot();

        // Never delete outside the configured home root.
        if (!str_starts_with(str_replace('\\', '/', $dir), str_replace('\\', '/', $root)) || !is_dir($dir)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            if ($item instanceof \SplFileInfo) {
                $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
            }
        }

        @rmdir($dir);
    }

    private static function accountOrFail(int $accountId, ?int $restrictToUserId): array
    {
        $account = SftpAccount::find($accountId);

        if ($account === null || ($restrictToUserId !== null && (int) $account['user_id'] !== $restrictToUserId)) {
            throw new HttpException(404, 'FTP/SFTP account not found.', 'account_not_found');
        }

        return $account;
    }
}
