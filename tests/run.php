<?php
/**
 * Test runner.
 *
 * Usage:
 *   php bin/console test
 *   php tests/run.php [--filter=Api] [--url=http://localhost/s3/public]
 */
declare(strict_types=1);

namespace Tests;

use App\Core\App;
use App\Core\Config;

if (!class_exists(App::class)) {
    require dirname(__DIR__) . '/autoload.php';
    require dirname(__DIR__) . '/src/Core/helpers.php';
    App::boot(dirname(__DIR__));
}

require __DIR__ . '/TestCase.php';
require __DIR__ . '/HttpClient.php';

/**
 * Shared configuration for the integration suites.
 */
final class Runner
{
    private static string $baseUrl = '';
    private static string $adminEmail = '';
    private static string $adminPassword = '';
    private static string $fixtureDir = '';

    public static function configure(array $options): void
    {
        self::$baseUrl = rtrim($options['url'] ?? (string) Config::get('app.url', 'http://localhost'), '/');
        self::$adminEmail = $options['admin'] ?? (string) (getenv('S3_TEST_ADMIN') ?: 'admin@s3lite.test');
        self::$adminPassword = $options['password'] ?? (string) (getenv('S3_TEST_PASSWORD') ?: 'Admin12345');

        self::$fixtureDir = rtrim(str_replace('\\', '/', sys_get_temp_dir()), '/') . '/s3lite-fixtures';
        if (!is_dir(self::$fixtureDir)) {
            @mkdir(self::$fixtureDir, 0775, true);
        }

        self::resetThrottles();
    }

    /**
     * The suite makes a few hundred requests and deliberately submits bad
     * credentials, so without this two runs in the same minute would trip the
     * platform's own (correctly working) throttles. Clearing them here keeps
     * the suite repeatable without weakening the defaults it is testing.
     */
    private static function resetThrottles(): void
    {
        try {
            \App\Core\Database::statement(
                'DELETE FROM login_attempts WHERE success = 0 AND email = ?',
                [self::$adminEmail]
            );
            \App\Core\Database::statement(
                'UPDATE users SET failed_attempts = 0, locked_until = NULL WHERE email = ?',
                [self::$adminEmail]
            );

            \App\Core\Cache::flush();
        } catch (\Throwable) {
            // Not installed yet — the suite will report that on its own.
        }
    }

    public static function baseUrl(): string
    {
        return self::$baseUrl;
    }

    /** The web server root, one level above the app's base path. */
    public static function rootUrl(): string
    {
        return preg_replace('#/public$#', '', self::$baseUrl) ?? self::$baseUrl;
    }

    public static function adminEmail(): string
    {
        return self::$adminEmail;
    }

    public static function adminPassword(): string
    {
        return self::$adminPassword;
    }

    public static function fixture(string $name, string $contents): string
    {
        $path = self::$fixtureDir . '/' . $name;
        file_put_contents($path, $contents);

        return $path;
    }

    public static function cleanup(): void
    {
        foreach (glob(self::$fixtureDir . '/*') ?: [] as $file) {
            @unlink($file);
        }
    }
}

// --- Parse options ---------------------------------------------------------

$options = [];
foreach (($argv ?? []) as $arg) {
    if (preg_match('/^--([a-z]+)=(.*)$/', (string) $arg, $m) === 1) {
        $options[$m[1]] = $m[2];
    }
}

Runner::configure($options);

require __DIR__ . '/UnitTest.php';
require __DIR__ . '/ApiTest.php';
require __DIR__ . '/WebTest.php';

$suites = [
    new UnitTest(),
    new ApiTest(),
    new WebTest(),
];

$filter = $options['filter'] ?? '';

echo PHP_EOL;
echo '  S3 Lite test suite' . PHP_EOL;
echo '  Target: ' . Runner::baseUrl() . PHP_EOL;
echo '  Admin:  ' . Runner::adminEmail() . PHP_EOL;

// Fail fast with a clear message if the server is not reachable.
$probe = new HttpClient(Runner::baseUrl());

try {
    $probe->get('/api/v1/health');
} catch (\Throwable $e) {
    echo PHP_EOL . '  Cannot reach the server at ' . Runner::baseUrl() . PHP_EOL;
    echo '  ' . $e->getMessage() . PHP_EOL . PHP_EOL;

    return 1;
}

if ($probe->lastStatus === 0) {
    echo PHP_EOL . '  The server did not respond. Is Apache running?' . PHP_EOL . PHP_EOL;

    return 1;
}

$started = microtime(true);

foreach ($suites as $suite) {
    if ($filter !== '' && stripos($suite->name(), $filter) === false && stripos($suite::class, $filter) === false) {
        continue;
    }

    $suite->run();
}

Runner::cleanup();

$elapsed = round(microtime(true) - $started, 2);
$total = TestCase::$passed + TestCase::$failed + TestCase::$skipped;

echo PHP_EOL;
echo '  ' . str_repeat('=', 58) . PHP_EOL;
echo sprintf(
    '  %d tests · %d passed · %d failed · %d skipped · %ss%s',
    $total,
    TestCase::$passed,
    TestCase::$failed,
    TestCase::$skipped,
    $elapsed,
    PHP_EOL
);
echo '  ' . str_repeat('=', 58) . PHP_EOL;

if (TestCase::$failures !== []) {
    echo PHP_EOL . '  FAILURES' . PHP_EOL;
    foreach (TestCase::$failures as $i => $failure) {
        echo '   ' . ($i + 1) . '. ' . $failure . PHP_EOL;
    }
    echo PHP_EOL;

    return 1;
}

echo PHP_EOL . '  All tests passed.' . PHP_EOL . PHP_EOL;

return 0;
