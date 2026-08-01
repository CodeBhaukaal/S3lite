<?php
declare(strict_types=1);

namespace App\Console;

use App\Core\Cache;
use App\Core\Config;
use App\Core\Database;
use App\Core\Env;
use App\Core\Migrator;
use App\Models\Job;
use App\Models\User;
use App\Services\BackupService;
use App\Services\Installer;
use App\Services\JobService;
use App\Services\MetricsService;
use App\Services\QuotaService;
use App\Services\SettingService;
use App\Services\SftpService;
use App\Services\UserService;
use App\Support\Crypto;
use App\Support\Str;

final class Kernel
{
    private const COMMANDS = [
        'help'             => 'Show this help',
        'about'            => 'Show environment and platform information',
        'install'          => 'Run a non-interactive install (see --help)',
        'key:generate'     => 'Generate APP_KEY and JWT_SECRET in .env',
        'migrate'          => 'Apply pending database migrations',
        'migrate:status'   => 'List applied and pending migrations',
        'db:seed'          => 'Seed roles, permissions and default settings',
        'user:create'      => 'Create a user: user:create <email> <password> [name] [role]',
        'user:password'    => 'Reset a password: user:password <email> <new-password>',
        'user:list'        => 'List all users',
        'user:promote'     => 'Grant the admin role: user:promote <email>',
        'quota:recalculate' => 'Recalculate storage usage for every user',
        'job:run'          => 'Run a maintenance job now: job:run <type>',
        'job:list'         => 'List available job types',
        'queue:work'       => 'Process queued jobs until the queue is empty',
        'queue:run-once'   => 'Process a small batch of queued jobs (cron friendly)',
        'cron:run'         => 'Run every maintenance task that is due (one cron entry does it all)',
        'cron:status'      => 'Show the cron schedule, last runs and the setup line to paste',
        'backup:create'    => 'Create a database backup',
        'backup:list'      => 'List existing backups',
        'sftp:sync'        => 'Index files uploaded over SFTP',
        'sftp:config'      => 'Print an OpenSSH configuration snippet',
        'cache:clear'      => 'Flush the cache (Redis and file)',
        'health'           => 'Print a health report',
        'routes'           => 'List every registered route',
        'test'             => 'Run the bundled test suite',
    ];

    public function __construct(private string $basePath)
    {
    }

    public function run(array $argv): int
    {
        $command = $argv[0] ?? 'help';
        $args = array_slice($argv, 1);

        try {
            return match ($command) {
                'help', '--help', '-h' => $this->help(),
                'about'            => $this->about(),
                'key:generate'     => $this->keyGenerate(),
                'migrate'          => $this->migrate(),
                'migrate:status'   => $this->migrateStatus(),
                'db:seed'          => $this->seed(),
                'install'          => $this->install($args),
                'user:create'      => $this->userCreate($args),
                'user:password'    => $this->userPassword($args),
                'user:list'        => $this->userList(),
                'user:promote'     => $this->userPromote($args),
                'quota:recalculate' => $this->quotaRecalculate(),
                'job:run'          => $this->jobRun($args),
                'job:list'         => $this->jobList(),
                'queue:work'       => $this->queueWork(1000),
                'queue:run-once'   => $this->queueWork(20),
                'cron:run'         => $this->cronRun($args),
                'cron:status'      => $this->cronStatus(),
                'backup:create'    => $this->backupCreate($args),
                'backup:list'      => $this->backupList(),
                'sftp:sync'        => $this->sftpSync($args),
                'sftp:config'      => $this->sftpConfig(),
                'cache:clear'      => $this->cacheClear(),
                'health'           => $this->health(),
                'routes'           => $this->routes(),
                'test'             => $this->test(),
                default            => $this->unknown($command),
            };
        } catch (\Throwable $e) {
            $this->line('');
            $this->error($e::class . ': ' . $e->getMessage());
            $this->line('  at ' . $e->getFile() . ':' . $e->getLine());

            return 1;
        }
    }

    // --- Commands --------------------------------------------------------

    private function help(): int
    {
        $this->banner();
        $this->line('Usage: php bin/console <command> [arguments]');
        $this->line('');
        $this->heading('Available commands');

        foreach (self::COMMANDS as $name => $description) {
            $this->line('  ' . str_pad($name, 20) . $description);
        }

        $this->line('');

        return 0;
    }

    private function about(): int
    {
        $this->banner();

        $installed = Installer::isInstalled();

        $rows = [
            'Version'      => (string) Config::get('app.version'),
            'Environment'  => (string) Config::get('app.env'),
            'Debug'        => Config::get('app.debug') ? 'on' : 'off',
            'Installed'    => $installed ? 'yes' : 'no',
            'URL'          => (string) Config::get('app.url'),
            'PHP'          => PHP_VERSION . ' (' . PHP_SAPI . ')',
            'Base path'    => $this->basePath,
            'Storage'      => (string) Config::get('storage.drivers.local.root'),
        ];

        if ($installed) {
            Database::connect();
            $rows['Database'] = (string) Config::get('database.database') . ' @ ' . Config::get('database.host');
            $rows['Cache'] = Cache::driver();
            $rows['Users'] = (string) User::count(['deleted_at' => null]);
            $rows['Files'] = (string) Database::scalar('SELECT COUNT(*) FROM files WHERE deleted_at IS NULL');
            $rows['Stored'] = Str::bytes((int) Database::scalar('SELECT COALESCE(SUM(size),0) FROM files'));
        }

        foreach ($rows as $key => $value) {
            $this->line('  ' . str_pad($key, 14) . $value);
        }

        $this->line('');

        return 0;
    }

    private function keyGenerate(): int
    {
        $envPath = $this->basePath . '/.env';

        if (!is_file($envPath) && is_file($this->basePath . '/.env.example')) {
            copy($this->basePath . '/.env.example', $envPath);
            $this->info('Created .env from .env.example');
        }

        $appKey = Crypto::generateKey();
        $jwtSecret = Str::random(64);

        Env::write($envPath, ['APP_KEY' => $appKey, 'JWT_SECRET' => $jwtSecret]);

        $this->success('APP_KEY and JWT_SECRET regenerated.');
        $this->warn('Existing encrypted values and issued JWTs are now invalid.');

        return 0;
    }

    private function migrate(): int
    {
        Database::connect();

        $migrator = new Migrator($this->basePath . '/database/migrations');
        $pending = $migrator->pending();

        if ($pending === []) {
            $this->info('Nothing to migrate — the schema is up to date.');

            return 0;
        }

        foreach ($migrator->run() as $result) {
            $this->success($result['migration'] . ' (' . $result['statements'] . ' statements)');
        }

        return 0;
    }

    private function migrateStatus(): int
    {
        Database::connect();

        $migrator = new Migrator($this->basePath . '/database/migrations');

        $this->heading('Applied');
        foreach ($migrator->applied() as $row) {
            $this->line('  [x] ' . $row['migration'] . '  batch ' . $row['batch'] . '  ' . $row['ran_at']);
        }

        $pending = $migrator->pending();

        $this->heading('Pending');
        if ($pending === []) {
            $this->line('  (none)');
        }
        foreach ($pending as $file) {
            $this->line('  [ ] ' . basename($file));
        }

        return 0;
    }

    private function seed(): int
    {
        Database::connect();
        Installer::seed();
        $this->success('Roles, permissions and default settings seeded.');

        return 0;
    }

    private function install(array $args): int
    {
        $options = $this->parseOptions($args);

        if (Installer::isInstalled() && !isset($options['force'])) {
            $this->error('Already installed. Pass --force to re-run (this rewrites .env).');

            return 1;
        }

        foreach (['admin-email', 'admin-password'] as $required) {
            if (!isset($options[$required])) {
                $this->error("Missing --{$required}");
                $this->line('');
                $this->line('Example:');
                $this->line('  php bin/console install \\');
                $this->line('    --app-url=http://localhost/s3/public \\');
                $this->line('    --db-name=s3lite --db-user=root --db-pass= \\');
                $this->line('    --admin-email=admin@example.com --admin-password=Secret123');

                return 1;
            }
        }

        $result = Installer::install([
            'app_name'       => $options['app-name'] ?? 'S3 Lite',
            'app_url'        => $options['app-url'] ?? 'http://localhost',
            'app_env'        => $options['app-env'] ?? 'production',
            'app_debug'      => isset($options['debug']),
            'timezone'       => $options['timezone'] ?? date_default_timezone_get(),
            'db_host'        => $options['db-host'] ?? '127.0.0.1',
            'db_port'        => (int) ($options['db-port'] ?? 3306),
            'db_name'        => $options['db-name'] ?? 's3lite',
            'db_user'        => $options['db-user'] ?? 'root',
            'db_pass'        => $options['db-pass'] ?? '',
            'redis_enabled'  => isset($options['redis']),
            'redis_host'     => $options['redis-host'] ?? '127.0.0.1',
            'redis_port'     => (int) ($options['redis-port'] ?? 6379),
            'admin_name'     => $options['admin-name'] ?? 'Administrator',
            'admin_email'    => $options['admin-email'],
            'admin_password' => $options['admin-password'],
        ]);

        $this->success((string) $result['message']);
        $this->line('  Administrator: ' . (string) ($result['admin']['email'] ?? ''));

        return 0;
    }

    private function userCreate(array $args): int
    {
        Database::connect();

        if (count($args) < 2) {
            $this->error('Usage: user:create <email> <password> [name] [role]');

            return 1;
        }

        $user = UserService::create([
            'email'    => $args[0],
            'password' => $args[1],
            'name'     => $args[2] ?? explode('@', $args[0])[0],
            'role'     => $args[3] ?? 'user',
        ]);

        $this->success('Created user #' . $user['id'] . ' — ' . $user['email'] . ' (' . $user['role_name'] . ')');

        return 0;
    }

    private function userPassword(array $args): int
    {
        Database::connect();

        if (count($args) < 2) {
            $this->error('Usage: user:password <email> <new-password>');

            return 1;
        }

        $user = User::findByEmail($args[0]);

        if ($user === null) {
            $this->error('No user with that email.');

            return 1;
        }

        UserService::update((int) $user['id'], ['password' => $args[1]], true);
        $this->success('Password updated for ' . $args[0] . '. All API sessions were revoked.');

        return 0;
    }

    private function userList(): int
    {
        Database::connect();

        $users = Database::select(
            'SELECT u.id, u.name, u.email, u.status, u.used_bytes, u.quota_bytes, r.name AS role
             FROM users u INNER JOIN roles r ON r.id = u.role_id
             WHERE u.deleted_at IS NULL ORDER BY u.id'
        );

        $this->heading(count($users) . ' users');
        $this->line('  ' . str_pad('ID', 5) . str_pad('EMAIL', 32) . str_pad('ROLE', 10) . str_pad('STATUS', 11) . 'USAGE');

        foreach ($users as $user) {
            $quota = (int) $user['quota_bytes'] === 0 ? 'unlimited' : Str::bytes((int) $user['quota_bytes']);
            $this->line(
                '  ' . str_pad((string) $user['id'], 5)
                . str_pad((string) $user['email'], 32)
                . str_pad((string) $user['role'], 10)
                . str_pad((string) $user['status'], 11)
                . Str::bytes((int) $user['used_bytes']) . ' / ' . $quota
            );
        }

        return 0;
    }

    private function userPromote(array $args): int
    {
        Database::connect();

        if ($args === []) {
            $this->error('Usage: user:promote <email>');

            return 1;
        }

        $user = User::findByEmail($args[0]);

        if ($user === null) {
            $this->error('No user with that email.');

            return 1;
        }

        UserService::update((int) $user['id'], ['role' => 'admin'], true);
        $this->success($args[0] . ' is now an administrator.');

        return 0;
    }

    private function quotaRecalculate(): int
    {
        Database::connect();
        $count = QuotaService::recalculateAll();
        $this->success("Recalculated storage usage for {$count} users.");

        return 0;
    }

    private function jobRun(array $args): int
    {
        Database::connect();

        $type = $args[0] ?? '';

        if (!array_key_exists($type, JobService::TYPES)) {
            $this->error('Unknown job type: ' . ($type === '' ? '(none given)' : $type));

            return $this->jobList();
        }

        $this->info('Running ' . $type . ' …');
        $result = JobService::execute($type, $this->parseOptions(array_slice($args, 1)));

        if ($result['ok']) {
            $this->success($result['output'] === '' ? 'Done.' : $result['output']);

            return 0;
        }

        $this->error($result['output']);

        return 1;
    }

    private function jobList(): int
    {
        $this->heading('Job types');

        foreach (JobService::TYPES as $type => $description) {
            $this->line('  ' . str_pad($type, 20) . $description);
        }

        return 0;
    }

    private function queueWork(int $max): int
    {
        Database::connect();

        $total = JobService::workAll($max);

        if ($total['processed'] === 0 && $total['failed'] === 0) {
            $this->info('Queue is empty.');

            return 0;
        }

        $this->success("Processed {$total['processed']} job(s), {$total['failed']} failed.");

        return $total['failed'] > 0 ? 1 : 0;
    }

    private function cronRun(array $args): int
    {
        Database::connect();

        $options = $this->parseOptions($args);
        $report = Scheduler::run(300, isset($options['force']));

        if ($report['skipped']) {
            $this->warn('Skipped: ' . (string) $report['reason']);

            return 0;
        }

        if ($report['ran'] === []) {
            $this->info('Nothing due.');

            return 0;
        }

        foreach ($report['ran'] as $task => $result) {
            $this->line('  ' . str_pad((string) $task, 18) . $result);
        }

        $this->success('Finished in ' . $report['duration_ms'] . 'ms.');

        return 0;
    }

    private function cronStatus(): int
    {
        Database::connect();

        $this->heading('Cron schedule');
        $this->line('  ' . str_pad('TASK', 18) . str_pad('EVERY', 12) . str_pad('LAST RUN', 21) . 'DUE NOW');

        foreach (Scheduler::status() as $row) {
            $this->line(
                '  ' . str_pad((string) $row['task'], 18)
                . str_pad($row['interval'] === 0 ? 'every run' : MetricsService::humanDuration((int) $row['interval']), 12)
                . str_pad((string) ($row['last_run'] ?? 'never'), 21)
                . ($row['due'] ? 'yes' : 'no')
            );
        }

        $this->heading('Set it up');

        $file = $this->basePath . '/public/cron.php';
        $this->line('  Run this file every 5-10 minutes. One entry covers everything.');
        $this->line('');
        $this->line('  Command:  ' . PHP_BINARY . ' ' . $file);

        $token = Scheduler::token();

        if ($token === '') {
            $this->line('');
            $this->warn('CRON_TOKEN is not set, so triggering over a URL is disabled.');
            $this->line('       Add CRON_TOKEN to .env to enable it.');
        } else {
            $this->line('  URL:      ' . rtrim((string) Config::get('app.url'), '/') . '/cron.php?token=' . $token);
        }

        if (Scheduler::hasNeverRun()) {
            $this->line('');
            $this->warn('Cron has never run on this install yet.');
        }

        return 0;
    }

    private function backupCreate(array $args): int
    {
        Database::connect();

        $result = BackupService::create($args[0] ?? null);

        $this->success(sprintf(
            'Backup %s created — %s, %d tables, %d rows in %dms.',
            $result['file'],
            Str::bytes($result['size']),
            $result['tables'],
            $result['rows'],
            $result['duration_ms']
        ));

        return 0;
    }

    private function backupList(): int
    {
        $backups = BackupService::list();

        $this->heading(count($backups) . ' backups');

        foreach ($backups as $backup) {
            $this->line('  ' . str_pad($backup['name'], 34) . str_pad(Str::bytes($backup['size']), 12) . $backup['created_at']);
        }

        return 0;
    }

    private function sftpSync(array $args): int
    {
        Database::connect();

        if ($args !== [] && ctype_digit($args[0])) {
            $result = SftpService::sync((int) $args[0]);
            $this->success(sprintf('Imported %d, removed %d, skipped %d.', $result['imported'], $result['removed'], $result['skipped']));

            return 0;
        }

        $result = SftpService::syncAll();
        $this->success(sprintf(
            'Synced %d accounts: imported %d, removed %d, skipped %d.',
            $result['accounts'],
            $result['imported'],
            $result['removed'],
            $result['skipped']
        ));

        return 0;
    }

    private function sftpConfig(): int
    {
        Database::connect();
        $this->line(SftpService::opensshConfig());

        return 0;
    }

    private function cacheClear(): int
    {
        Cache::flush();
        SettingService::flush();
        $this->success('Cache flushed (driver: ' . Cache::driver() . ').');

        return 0;
    }

    private function health(): int
    {
        Database::connect();

        $health = MetricsService::health();
        $system = MetricsService::system();

        $this->heading('Health: ' . strtoupper($health['status']));

        foreach ($health['checks'] as $name => $check) {
            $marker = match ($check['status']) {
                'ok'       => '[ok]  ',
                'degraded' => '[warn]',
                'fail'     => '[FAIL]',
                default    => '[skip]',
            };
            $this->line('  ' . $marker . ' ' . str_pad((string) $name, 14) . $check['message']);
        }

        $this->heading('Resources');
        $this->line('  CPU     ' . $system['cpu']['percent'] . '% of ' . $system['cpu']['cores'] . ' cores');
        $this->line('  Memory  ' . Str::bytes($system['memory']['used']) . ' / ' . Str::bytes($system['memory']['total']));
        $this->line('  Disk    ' . Str::bytes($system['disk']['free']) . ' free (' . $system['disk']['percent'] . '% used)');
        $this->line('  Stored  ' . Str::bytes($system['disk']['stored']));

        return $health['status'] === 'fail' ? 1 : 0;
    }

    private function routes(): int
    {
        $app = \App\Core\App::instance();
        $router = $app->router();

        require $this->basePath . '/routes/web.php';
        require $this->basePath . '/routes/api.php';

        $count = 0;

        foreach ($router->all() as $method => $routes) {
            foreach ($routes as $route) {
                $count++;
                $this->line(
                    '  ' . str_pad($method, 8)
                    . str_pad($route['pattern'], 52)
                    . (is_string($route['handler']) ? $this->shortHandler($route['handler']) : 'closure')
                );
            }
        }

        $this->heading($count . ' routes');

        return 0;
    }

    private function test(): int
    {
        $runner = $this->basePath . '/tests/run.php';

        if (!is_file($runner)) {
            $this->error('Test runner not found.');

            return 1;
        }

        return (int) (require $runner);
    }

    private function unknown(string $command): int
    {
        $this->error('Unknown command: ' . $command);
        $this->line('Run "php bin/console help" to see the available commands.');

        return 1;
    }

    // --- Output helpers --------------------------------------------------

    private function shortHandler(string $handler): string
    {
        $parts = explode('\\', $handler);

        return end($parts) ?: $handler;
    }

    /** @return array<string,string> */
    private function parseOptions(array $args): array
    {
        $options = [];

        foreach ($args as $arg) {
            if (!str_starts_with($arg, '--')) {
                continue;
            }

            $arg = substr($arg, 2);

            if (str_contains($arg, '=')) {
                [$key, $value] = explode('=', $arg, 2);
                $options[$key] = $value;
            } else {
                $options[$arg] = '1';
            }
        }

        return $options;
    }

    private function banner(): void
    {
        $this->line('');
        $this->line('  S3 Lite  ·  self-hosted object storage  ·  v' . Config::get('app.version'));
        $this->line('');
    }

    private function heading(string $text): void
    {
        $this->line('');
        $this->line('  ' . strtoupper($text));
        $this->line('  ' . str_repeat('-', max(10, strlen($text))));
    }

    private function line(string $text = ''): void
    {
        fwrite(STDOUT, $text . PHP_EOL);
    }

    private function info(string $text): void
    {
        $this->line('  ' . $text);
    }

    private function success(string $text): void
    {
        $this->line('  OK   ' . $text);
    }

    private function warn(string $text): void
    {
        $this->line('  WARN ' . $text);
    }

    private function error(string $text): void
    {
        fwrite(STDERR, '  ERR  ' . $text . PHP_EOL);
    }
}
