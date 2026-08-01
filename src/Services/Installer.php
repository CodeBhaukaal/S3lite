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
     * @return array{ok:bool, message:string}
     */
    public static function testDatabase(array $config): array
    {
        try {
            $pdo = Database::connectServer([
                'host'     => $config['host'],
                'port'     => (int) $config['port'],
                'username' => $config['username'],
                'password' => $config['password'],
                'charset'  => 'utf8mb4',
                'options'  => [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
            ]);

            $version = $pdo->query('SELECT VERSION()')?->fetchColumn();

            return ['ok' => true, 'message' => 'Connected to MySQL/MariaDB ' . (string) $version];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        }
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

        // 1. Create the database if needed.
        $pdo = Database::connectServer($dbConfig + ['charset' => 'utf8mb4', 'options' => [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]]);
        $pdo->exec(sprintf(
            'CREATE DATABASE IF NOT EXISTS `%s` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
            str_replace('`', '', $dbConfig['database'])
        ));

        // 2. Persist configuration.
        $appKey = (string) Config::get('app.key', '');
        if ($appKey === '') {
            $appKey = Crypto::generateKey();
        }

        $jwtSecret = (string) Config::get('app.jwt.secret', '');
        if ($jwtSecret === '') {
            $jwtSecret = Str::random(64);
        }

        $envPath = $base . '/.env';
        if (!is_file($envPath) && is_file($base . '/.env.example')) {
            copy($base . '/.env.example', $envPath);
        }

        Env::write($envPath, [
            'APP_NAME'      => (string) ($input['app_name'] ?? 'S3 Lite'),
            'APP_ENV'       => (string) ($input['app_env'] ?? 'production'),
            'APP_DEBUG'     => ($input['app_debug'] ?? false) ? 'true' : 'false',
            'APP_KEY'       => $appKey,
            'APP_URL'       => rtrim((string) ($input['app_url'] ?? ''), '/'),
            'APP_TIMEZONE'  => (string) ($input['timezone'] ?? 'UTC'),
            'DB_HOST'       => $dbConfig['host'],
            'DB_PORT'       => (string) $dbConfig['port'],
            'DB_DATABASE'   => $dbConfig['database'],
            'DB_USERNAME'   => $dbConfig['username'],
            'DB_PASSWORD'   => $dbConfig['password'],
            'JWT_SECRET'    => $jwtSecret,
            'REDIS_ENABLED' => ($input['redis_enabled'] ?? false) ? 'true' : 'false',
            'REDIS_HOST'    => (string) ($input['redis_host'] ?? '127.0.0.1'),
            'REDIS_PORT'    => (string) ($input['redis_port'] ?? 6379),
            'REDIS_PASSWORD' => (string) ($input['redis_password'] ?? ''),
        ]);

        // Reload configuration with the new values.
        Config::set('app.key', $appKey);
        Config::set('app.jwt.secret', $jwtSecret);
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

        Database::reset();
        Database::connect();

        // 3. Schema.
        $migrator = new Migrator($base . '/database/migrations');
        $migrator->run();

        // 4. Seed roles, permissions and settings.
        self::seed();

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

    public static function timezones(): array
    {
        return \DateTimeZone::listIdentifiers();
    }
}
