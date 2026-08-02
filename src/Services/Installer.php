<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\App;
use App\Core\Config;
use App\Core\Database;
use App\Core\Env;
use App\Core\Migrator;
use App\Models\Permission;
use App\Models\Role;
use App\Support\Crypto;
use App\Support\Str;
use PDO;

/**
 * First-run installer: environment checks, schema, seed data and admin account.
 */
final class Installer
{
    public const PERMISSIONS = [
        'files' => [
            'files.view'   => 'View files',
            'files.upload' => 'Upload files',
            'files.edit'   => 'Rename, move and tag files',
            'files.delete' => 'Delete files',
            'files.share'  => 'Create share links',
        ],
        'folders' => [
            'folders.view'   => 'View folders',
            'folders.manage' => 'Create, rename, move and delete folders',
        ],
        'api' => [
            'api.keys' => 'Manage personal API keys',
        ],
        'sftp' => [
            'sftp.view'   => 'View own FTP/SFTP accounts',
            'sftp.manage' => 'Manage own FTP/SFTP accounts',
        ],
        'admin' => [
            'admin.access'   => 'Access the admin panel',
            'admin.users'    => 'Manage users and roles',
            'admin.files'    => 'Browse and manage all files',
            'admin.settings' => 'Change platform settings',
            'admin.logs'     => 'Read audit logs',
            'admin.sftp'     => 'Manage all FTP/SFTP accounts',
            'admin.backups'  => 'Create and restore backups',
            'admin.jobs'     => 'Run maintenance jobs',
        ],
    ];

    /**
     * @return array{ok:bool, checks:list<array{name:string, status:string, message:string, required:bool}>}
     */
    public static function requirements(): array
    {
        $checks = [];

        $checks[] = self::check('PHP 8.2+', version_compare(PHP_VERSION, '8.2.0', '>='), PHP_VERSION, true);

        foreach (['pdo_mysql', 'mbstring', 'openssl', 'fileinfo', 'json', 'curl'] as $extension) {
            $checks[] = self::check("ext-{$extension}", extension_loaded($extension), extension_loaded($extension) ? 'loaded' : 'missing', true);
        }

        foreach (['gd', 'zip', 'redis'] as $extension) {
            $checks[] = self::check("ext-{$extension}", extension_loaded($extension), extension_loaded($extension) ? 'loaded' : 'not installed (optional)', false);
        }

        $base = App::instance()->basePath();

        foreach ([
            'storage/'         => $base . '/storage',
            'storage/files/'   => $base . '/storage/files',
            'storage/logs/'    => $base . '/storage/logs',
            'storage/tmp/'     => $base . '/storage/tmp',
            'storage/chunks/'  => $base . '/storage/chunks',
            '.env'             => $base,
        ] as $label => $path) {
            $writable = is_dir($path) ? is_writable($path) : is_writable(dirname($path));
            $checks[] = self::check("Writable {$label}", $writable, $writable ? 'writable' : 'not writable', true);
        }

        $ok = true;
        foreach ($checks as $check) {
            if ($check['required'] && $check['status'] !== 'ok') {
                $ok = false;
            }
        }

        return ['ok' => $ok, 'checks' => $checks];
    }

    private static function check(string $name, bool $passed, string $message, bool $required): array
    {
        return [
            'name'     => $name,
            'status'   => $passed ? 'ok' : ($required ? 'fail' : 'warn'),
            'message'  => $message,
            'required' => $required,
        ];
    }

    /**
     * Verify the credentials *and* the named database.
     *
     * Shared hosting users usually cannot create databases — the panel does that
     * — so reporting "connected" from a server-level check alone would be a
     * green light followed by a failed install.
     *
     * @return array{ok:bool, message:string, database_exists?:bool, can_create?:bool}
     */
    public static function testDatabase(array $config): array
    {
        $name = trim((string) ($config['database'] ?? ''));

        $base = [
            'host'     => $config['host'],
            'port'     => (int) $config['port'],
            'username' => $config['username'],
            'password' => $config['password'],
            'charset'  => 'utf8mb4',
            'options'  => [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                // Without this a wrong host leaves the wizard apparently frozen
                // for however long the OS takes to give up on the connection.
                PDO::ATTR_TIMEOUT => 5,
            ],
        ];

        // Step 1: can we reach the server at all?
        try {
            $pdo = Database::connectServer($base);
            $version = (string) $pdo->query('SELECT VERSION()')?->fetchColumn();
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => 'Could not connect: ' . self::friendlyDbError($e)];
        }

        if ($name === '') {
            return ['ok' => true, 'message' => 'Connected to MySQL/MariaDB ' . $version];
        }

        // Step 2: does the named database already exist and can we use it?
        try {
            Database::connect($base + ['database' => $name]);
            Database::reset();

            return [
                'ok'              => true,
                'message'         => sprintf('Connected to MySQL/MariaDB %s — database "%s" is ready.', $version, $name),
                'database_exists' => true,
                'can_create'      => false,
            ];
        } catch (\Throwable) {
            Database::reset();
        }

        // Step 3: it is missing — may this account create it?
        try {
            $pdo->exec(sprintf(
                'CREATE DATABASE IF NOT EXISTS `%s` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
                str_replace('`', '', $name)
            ));

            return [
                'ok'              => true,
                'message'         => sprintf('Connected to MySQL/MariaDB %s — database "%s" created.', $version, $name),
                'database_exists' => true,
                'can_create'      => true,
            ];
        } catch (\Throwable $e) {
            return [
                'ok'      => false,
                'message' => sprintf(
                    'Connected to MySQL/MariaDB %s, but the database "%s" does not exist and this account may not create one (%s). '
                    . 'Create the database in your hosting control panel first, then run the installer again.',
                    $version,
                    $name,
                    self::friendlyDbError($e, true)
                ),
                'database_exists' => false,
                'can_create'      => false,
            ];
        }
    }

    /**
     * @throws \RuntimeException when the database can be neither reached nor created
     */
    private static function ensureDatabase(array $dbConfig): void
    {
        $base = $dbConfig + ['charset' => 'utf8mb4', 'options' => [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]];

        // Already there and usable? Nothing to do.
        try {
            Database::connect($base);
            Database::reset();

            return;
        } catch (\Throwable) {
            Database::reset();
        }

        try {
            $pdo = Database::connectServer($base);
        } catch (\Throwable $e) {
            throw new \RuntimeException('Could not connect to the database server: ' . self::friendlyDbError($e), 0, $e);
        }

        try {
            $pdo->exec(sprintf(
                'CREATE DATABASE IF NOT EXISTS `%s` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
                str_replace('`', '', (string) $dbConfig['database'])
            ));
        } catch (\Throwable $e) {
            throw new \RuntimeException(sprintf(
                'The database "%s" does not exist and this MySQL account may not create one (%s). '
                . 'Most shared hosting panels require you to create the database and its user yourself — '
                . 'do that in the panel, then run the installer again with those details.',
                $dbConfig['database'],
                self::friendlyDbError($e, true)
            ), 0, $e);
        }
    }

    /**
     * @param bool $whileCreating "Access denied" means different things when
     *                            connecting (bad credentials) and when issuing
     *                            CREATE DATABASE (credentials fine, no privilege).
     */
    private static function friendlyDbError(\Throwable $e, bool $whileCreating = false): string
    {
        $message = $e->getMessage();

        return match (true) {
            str_contains($message, 'Access denied') && $whileCreating
                => 'the account has no CREATE privilege',
            str_contains($message, 'Access denied')
                => 'access denied — check the username and password',
            str_contains($message, 'Unknown database')
                => 'the database does not exist',
            // [2002] is MySQL's "can't reach the server"; the wording that
            // follows it differs per platform, so match on the code.
            str_contains($message, '[2002]'),
            str_contains($message, 'Connection refused'),
            str_contains($message, 'No such host'),
            str_contains($message, 'getaddrinfo'),
            str_contains($message, 'timed out'),
            str_contains($message, 'did not properly respond')
                => 'the server is unreachable — check the host and port',
            default => $message,
        };
    }

    public static function testRedis(array $config): array
    {
        $client = new \App\Core\RedisClient(
            (string) $config['host'],
            (int) $config['port'],
            (string) ($config['password'] ?? ''),
            (int) ($config['database'] ?? 0),
            2.0,
            'install-probe:'
        );

        if (!$client->isAvailable() || !$client->ping()) {
            return ['ok' => false, 'message' => 'Could not reach Redis — the file cache will be used instead.'];
        }

        $info = $client->info();

        return ['ok' => true, 'message' => 'Connected to Redis ' . ($info['redis_version'] ?? '')];
    }

    /**
     * Write .env, create the schema and seed the first administrator.
     *
     * @return array{ok:bool, message:string, admin:?array}
     */
    public static function install(array $input): array
    {
        $base = App::instance()->basePath();

        $dbConfig = [
            'host'     => (string) ($input['db_host'] ?? '127.0.0.1'),
            'port'     => (int) ($input['db_port'] ?? 3306),
            'database' => (string) ($input['db_name'] ?? 's3lite'),
            'username' => (string) ($input['db_user'] ?? 'root'),
            'password' => (string) ($input['db_pass'] ?? ''),
        ];

        // 1. Make sure the database is usable.
        //
        // Prefer connecting to it directly: on shared hosting the database is
        // created through the control panel and the account has no privilege to
        // issue CREATE DATABASE, so attempting that first would fail an install
        // that was otherwise perfectly fine.
        self::ensureDatabase($dbConfig);

        // 2. Persist configuration.
        $appKey = (string) Config::get('app.key', '');
        if ($appKey === '') {
            $appKey = Crypto::generateKey();
        }

        $jwtSecret = (string) Config::get('app.jwt.secret', '');
        if ($jwtSecret === '') {
            $jwtSecret = Str::random(64);
        }

        $cronToken = (string) Config::get('app.cron_token', '');
        if ($cronToken === '') {
            $cronToken = Str::random(48);
        }

        $envPath = $base . '/.env';
        if (!is_file($envPath) && is_file($base . '/.env.example')) {
            copy($base . '/.env.example', $envPath);
        }

        // Anything the operator left alone is written with its documented
        // default, so the finished .env is complete and self-explanatory rather
        // than a handful of keys with the rest implied.
        $appName = (string) ($input['app_name'] ?? 'S3 Lite');
        $mailDriver = (string) ($input['mail_driver'] ?? 'none');

        Env::write($envPath, [
            'APP_NAME'       => $appName,
            'APP_ENV'        => (string) ($input['app_env'] ?? 'production'),
            'APP_DEBUG'      => ($input['app_debug'] ?? false) ? 'true' : 'false',
            'APP_KEY'        => $appKey,
            'APP_URL'        => rtrim((string) ($input['app_url'] ?? ''), '/'),
            'APP_TIMEZONE'   => (string) ($input['timezone'] ?? 'UTC'),
            'FORCE_HTTPS'    => ($input['force_https'] ?? false) ? 'true' : 'false',

            'DB_HOST'        => $dbConfig['host'],
            'DB_PORT'        => (string) $dbConfig['port'],
            'DB_DATABASE'    => $dbConfig['database'],
            'DB_USERNAME'    => $dbConfig['username'],
            'DB_PASSWORD'    => $dbConfig['password'],

            'JWT_SECRET'     => $jwtSecret,
            'CRON_TOKEN'     => $cronToken,

            'REDIS_ENABLED'  => ($input['redis_enabled'] ?? false) ? 'true' : 'false',
            'REDIS_HOST'     => (string) ($input['redis_host'] ?? '127.0.0.1'),
            'REDIS_PORT'     => (string) ($input['redis_port'] ?? 6379),
            'REDIS_PASSWORD' => (string) ($input['redis_password'] ?? ''),

            'MAIL_DRIVER'     => $mailDriver,
            'MAIL_HOST'       => (string) ($input['mail_host'] ?? ''),
            'MAIL_PORT'       => (string) ($input['mail_port'] ?? 587),
            'MAIL_ENCRYPTION' => (string) ($input['mail_encryption'] ?? 'tls'),
            'MAIL_USERNAME'   => (string) ($input['mail_username'] ?? ''),
            'MAIL_PASSWORD'   => (string) ($input['mail_password'] ?? ''),
            'MAIL_FROM'       => (string) ($input['mail_from'] ?? 'no-reply@localhost'),
            'MAIL_FROM_NAME'  => (string) ($input['mail_from_name'] ?? $appName),

            'STORAGE_DRIVER'  => 'local',
            'MAX_UPLOAD_SIZE' => (string) ($input['max_upload_size'] ?? 5368709120),
            'CHUNK_SIZE'      => (string) ($input['chunk_size'] ?? 8388608),
            'DEFAULT_QUOTA'   => (string) ($input['default_quota'] ?? 10737418240),

            'TRASH_RETENTION_DAYS' => (string) ($input['trash_retention_days'] ?? 30),
            'VERSIONS_KEPT'        => (string) ($input['versions_kept'] ?? 10),
        ]);

        // Reload configuration with the new values.
        Config::set('app.key', $appKey);
        Config::set('app.jwt.secret', $jwtSecret);
        Config::set('app.cron_token', $cronToken);
        Config::set('app.url', rtrim((string) ($input['app_url'] ?? ''), '/'));
        Config::set('app.name', (string) ($input['app_name'] ?? 'S3 Lite'));
        Config::set('database.host', $dbConfig['host']);
        Config::set('database.port', $dbConfig['port']);
        Config::set('database.database', $dbConfig['database']);
        Config::set('database.username', $dbConfig['username']);
        Config::set('database.password', $dbConfig['password']);
        Config::set('cache.redis.enabled', (bool) ($input['redis_enabled'] ?? false));
        Config::set('cache.redis.host', (string) ($input['redis_host'] ?? '127.0.0.1'));
        Config::set('cache.redis.port', (int) ($input['redis_port'] ?? 6379));
        Config::set('mail.driver', $mailDriver);
        Config::set('mail.host', (string) ($input['mail_host'] ?? ''));
        Config::set('mail.port', (int) ($input['mail_port'] ?? 587));
        Config::set('mail.encryption', (string) ($input['mail_encryption'] ?? 'tls'));
        Config::set('mail.username', (string) ($input['mail_username'] ?? ''));
        Config::set('mail.password', (string) ($input['mail_password'] ?? ''));
        Config::set('mail.from.address', (string) ($input['mail_from'] ?? 'no-reply@localhost'));
        Config::set('mail.from.name', (string) ($input['mail_from_name'] ?? $appName));

        Database::reset();
        Database::connect();

        // A cache left over from an earlier install would make the seeder think
        // settings already exist and skip writing them into the new database.
        \App\Core\Cache::flush();
        SettingService::flush();

        // 3. Schema.
        $migrator = new Migrator($base . '/database/migrations');
        $migrator->run();

        // 4. Seed roles, permissions and settings.
        self::seed();
        StorageBackendService::adoptConfiguredDriver();

        // 5. Administrator account.
        $admin = UserService::create([
            'name'     => (string) ($input['admin_name'] ?? 'Administrator'),
            'email'    => (string) ($input['admin_email'] ?? ''),
            'password' => (string) ($input['admin_password'] ?? ''),
            'role'     => 'admin',
            'status'   => 'active',
            'quota_bytes' => 0,
        ]);

        // 6. Lock the installer.
        file_put_contents($base . '/storage/installed.lock', json_encode([
            'installed_at' => date('c'),
            'version'      => Config::get('app.version', '1.0.0'),
            'admin'        => $admin['email'] ?? null,
        ], JSON_PRETTY_PRINT));

        AuditService::system('platform.install', 'Platform installed', ['admin' => $admin['email'] ?? null]);

        return ['ok' => true, 'message' => 'Installation complete.', 'admin' => $admin];
    }

    public static function seed(): void
    {
        // Permissions
        foreach (self::PERMISSIONS as $group => $permissions) {
            foreach ($permissions as $name => $label) {
                Database::statement(
                    'INSERT INTO permissions (name, label, group_name, created_at) VALUES (?, ?, ?, NOW())
                     ON DUPLICATE KEY UPDATE label = VALUES(label), group_name = VALUES(group_name)',
                    [$name, $label, $group]
                );
            }
        }

        // Roles
        $roles = [
            'admin'  => ['Administrator', 'Full access to every feature and every user\'s data.'],
            'user'   => ['User', 'Standard account with personal storage.'],
            'viewer' => ['Viewer', 'Read-only access to their own files.'],
        ];

        foreach ($roles as $name => [$label, $description]) {
            Database::statement(
                'INSERT INTO roles (name, label, description, is_system, created_at, updated_at)
                 VALUES (?, ?, ?, 1, NOW(), NOW())
                 ON DUPLICATE KEY UPDATE label = VALUES(label), description = VALUES(description)',
                [$name, $label, $description]
            );
        }

        $all = [];
        foreach (self::PERMISSIONS as $permissions) {
            foreach (array_keys($permissions) as $name) {
                $all[] = $name;
            }
        }

        $adminRole = Role::findByName('admin');
        $userRole = Role::findByName('user');
        $viewerRole = Role::findByName('viewer');

        if ($adminRole !== null) {
            Role::syncPermissions((int) $adminRole['id'], $all);
        }

        if ($userRole !== null) {
            Role::syncPermissions((int) $userRole['id'], [
                'files.view', 'files.upload', 'files.edit', 'files.delete', 'files.share',
                'folders.view', 'folders.manage',
                'api.keys',
                'sftp.view', 'sftp.manage',
            ]);
        }

        if ($viewerRole !== null) {
            Role::syncPermissions((int) $viewerRole['id'], ['files.view', 'folders.view']);
        }

        // Settings
        foreach (SettingService::defaults() as $group => $values) {
            foreach ($values as $key => $value) {
                if (!array_key_exists($key, SettingService::all())) {
                    SettingService::set($key, $value, $group);
                }
            }
        }

        SettingService::flush();
    }

    public static function isInstalled(): bool
    {
        return is_file(App::instance()->basePath('storage/installed.lock'));
    }

    /**
     * Is the recorded installation actually usable?
     *
     * The lock file travels with the source, so copying a project onto a new
     * server (or restoring a backup of the whole folder) leaves a lock that
     * points at a database which is not there. Without this check the installer
     * refuses to run and the login it redirects to cannot work either — the
     * site is bricked with no way forward.
     *
     * @return array{installed:bool, usable:bool, reason:?string}
     */
    public static function verifyInstall(): array
    {
        if (!self::isInstalled()) {
            return ['installed' => false, 'usable' => false, 'reason' => null];
        }

        try {
            Database::connect();
        } catch (\Throwable $e) {
            return [
                'installed' => true,
                'usable'    => false,
                'reason'    => 'the configured database is unreachable (' . self::friendlyDbError($e) . ')',
            ];
        }

        try {
            if (!Database::tableExists('users')) {
                return ['installed' => true, 'usable' => false, 'reason' => 'the database has no tables yet'];
            }

            $admins = (int) Database::scalar(
                'SELECT COUNT(*) FROM users u INNER JOIN roles r ON r.id = u.role_id
                 WHERE r.name = ? AND u.deleted_at IS NULL',
                ['admin']
            );

            if ($admins === 0) {
                return ['installed' => true, 'usable' => false, 'reason' => 'there is no administrator account'];
            }
        } catch (\Throwable $e) {
            return ['installed' => true, 'usable' => false, 'reason' => 'the database is incomplete (' . $e->getMessage() . ')'];
        }

        return ['installed' => true, 'usable' => true, 'reason' => null];
    }

    public static function timezones(): array
    {
        return \DateTimeZone::listIdentifiers();
    }
}
