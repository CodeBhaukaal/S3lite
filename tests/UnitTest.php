<?php
declare(strict_types=1);

namespace Tests;

use App\Core\Cache;
use App\Core\RedisClient;
use App\Http\Exceptions\ValidationException;
use App\Http\Validator;
use App\Models\IpRule;
use App\Models\StorageBackend;
use App\Services\MimeGuard;
use App\Storage\LocalDriver;
use App\Storage\StorageManager;
use App\Support\Crypto;
use App\Support\Jwt;
use App\Support\SignedUrl;
use App\Support\Str;
use App\Support\Totp;

final class UnitTest extends TestCase
{
    public function name(): string
    {
        return 'Unit — core libraries';
    }

    // --- Str -------------------------------------------------------------

    public function testUuidIsWellFormed(): void
    {
        $uuid = Str::uuid();

        $this->assertSame(36, strlen($uuid));
        $this->assertTrue((bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $uuid));
        $this->assertTrue(Str::uuid() !== Str::uuid(), 'UUIDs must be unique');
    }

    public function testByteFormatting(): void
    {
        $this->assertSame('0 B', Str::bytes(0));
        $this->assertSame('1 KB', Str::bytes(1024));
        $this->assertSame('1 MB', Str::bytes(1048576));
        $this->assertSame('1.5 GB', Str::bytes(1610612736));
    }

    public function testFilenameSanitisationBlocksTraversal(): void
    {
        $this->assertSame('passwd', Str::sanitizeFilename('../../etc/passwd'));
        $this->assertSame('boot.ini', Str::sanitizeFilename('..\\..\\windows\\boot.ini'));
        $this->assertSame('report_.pdf', Str::sanitizeFilename('report<.pdf'));
        $this->assertNotContains("\0", Str::sanitizeFilename("evil\0.php"));
        $this->assertNotEmpty(Str::sanitizeFilename('...'));
    }

    public function testTokensAreUrlSafe(): void
    {
        $token = Str::token(40);

        $this->assertSame(40, strlen($token));
        $this->assertSame($token, rawurlencode($token), 'Tokens must need no URL encoding');
    }

    // --- Crypto ----------------------------------------------------------

    public function testEncryptionRoundTrip(): void
    {
        $plain = 'sensitive value with UTF-8: नमस्ते';
        $cipher = Crypto::encrypt($plain);

        $this->assertTrue($cipher !== $plain);
        $this->assertSame($plain, Crypto::decrypt($cipher));
    }

    public function testTamperedCiphertextIsRejected(): void
    {
        $cipher = Crypto::encrypt('secret');
        $raw = base64_decode($cipher, true);
        $raw[30] = $raw[30] === 'A' ? 'B' : 'A';

        $this->assertNull(Crypto::decrypt(base64_encode($raw)), 'GCM must reject modified ciphertext');
    }

    public function testPasswordHashing(): void
    {
        $hash = Crypto::hashPassword('Secret12345');

        $this->assertTrue(Crypto::verifyPassword('Secret12345', $hash));
        $this->assertFalse(Crypto::verifyPassword('secret12345', $hash));
        $this->assertFalse(Crypto::verifyPassword('', $hash));
        $this->assertFalse(Crypto::verifyPassword('anything', ''));
    }

    // --- JWT -------------------------------------------------------------

    public function testJwtRoundTrip(): void
    {
        $token = Jwt::encode(['sub' => 42, 'typ' => 'access'], 60);
        $result = Jwt::verify($token);

        $this->assertTrue($result['valid']);
        $this->assertSame(42, $result['payload']['sub']);
    }

    public function testJwtRejectsBadSignature(): void
    {
        $token = Jwt::encode(['sub' => 1], 60);
        $parts = explode('.', $token);
        $parts[2] = strtr(base64_encode(str_repeat('x', 32)), '+/', '-_');

        $result = Jwt::verify(implode('.', $parts));

        $this->assertFalse($result['valid']);
        $this->assertSame('invalid_signature', $result['error']);
    }

    public function testJwtRejectsExpiredToken(): void
    {
        $token = Jwt::encode(['sub' => 1], -10);
        $result = Jwt::verify($token);

        $this->assertFalse($result['valid']);
        $this->assertSame('token_expired', $result['error']);
    }

    public function testJwtRejectsAlgNone(): void
    {
        $header = rtrim(strtr(base64_encode('{"typ":"JWT","alg":"none"}'), '+/', '-_'), '=');
        $payload = rtrim(strtr(base64_encode('{"sub":1,"exp":' . (time() + 600) . '}'), '+/', '-_'), '=');

        $result = Jwt::verify($header . '.' . $payload . '.');

        $this->assertFalse($result['valid'], 'The "none" algorithm must never be accepted');
    }

    // --- TOTP ------------------------------------------------------------

    public function testTotpVerifiesItsOwnCode(): void
    {
        $secret = Totp::generateSecret();
        $code = Totp::code($secret);

        $this->assertSame(6, strlen($code));
        $this->assertTrue(Totp::verify($secret, $code));
        $this->assertFalse(Totp::verify($secret, '000000') && $code !== '000000');
    }

    public function testTotpToleratesClockDrift(): void
    {
        $secret = Totp::generateSecret();
        $past = Totp::code($secret, time() - 30);

        $this->assertTrue(Totp::verify($secret, $past), 'One step of drift must be accepted');
    }

    // --- Signed URLs ------------------------------------------------------

    public function testSignedUrlVerification(): void
    {
        $url = SignedUrl::sign('/download/abc', ['uuid' => 'abc'], 300);
        $query = [];
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        $this->assertTrue(SignedUrl::verify('/download/abc', $query));

        $query['uuid'] = 'tampered';
        $this->assertFalse(SignedUrl::verify('/download/abc', $query));
    }

    public function testExpiredSignedUrlIsRejected(): void
    {
        $url = SignedUrl::sign('/download/abc', ['uuid' => 'abc'], -60);
        $query = [];
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        $this->assertFalse(SignedUrl::verify('/download/abc', $query));
    }

    // --- IP matching ------------------------------------------------------

    public function testCidrMatching(): void
    {
        $this->assertTrue(IpRule::matches('192.168.1.50', '192.168.1.0/24'));
        $this->assertFalse(IpRule::matches('192.168.2.50', '192.168.1.0/24'));
        $this->assertTrue(IpRule::matches('10.4.3.2', '10.0.0.0/8'));
        $this->assertTrue(IpRule::matches('203.0.113.9', '203.0.113.9'));
        $this->assertTrue(IpRule::matches('192.168.1.7', '192.168.1.*'));
        $this->assertFalse(IpRule::matches('8.8.8.8', '192.168.1.0/24'));
    }

    // --- Validator --------------------------------------------------------

    public function testValidatorRules(): void
    {
        $validator = Validator::make(
            ['email' => 'not-an-email', 'name' => '', 'age' => 'abc'],
            ['email' => 'required|email', 'name' => 'required|string', 'age' => 'required|numeric']
        );

        $this->assertTrue($validator->fails());
        $this->assertCount(3, $validator->errors());
    }

    public function testValidatorPassesGoodInput(): void
    {
        $validator = Validator::make(
            ['email' => 'user@example.com', 'name' => 'Ada', 'password' => 'Secret123'],
            ['email' => 'required|email|max:190', 'name' => 'required|string|min:2', 'password' => 'required|password']
        );

        $this->assertTrue($validator->passes(), (string) $validator->firstError());
    }

    public function testValidatorThrowsOnFailure(): void
    {
        $this->assertThrows(ValidationException::class, static function (): void {
            Validator::validate(['email' => 'bad'], ['email' => 'required|email']);
        });
    }

    public function testWeakPasswordIsRejected(): void
    {
        $validator = Validator::make(['password' => 'abcdefgh'], ['password' => 'required|password']);

        $this->assertTrue($validator->fails(), 'A letters-only password must be rejected');
    }

    // --- MIME guard -------------------------------------------------------

    public function testBlockedExtensionsAreRejected(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'guard');
        file_put_contents($tmp, 'harmless text');

        $this->assertFalse(MimeGuard::inspect($tmp, 'shell.php')['ok']);
        $this->assertFalse(MimeGuard::inspect($tmp, 'invoice.pdf.php')['ok'], 'Double extensions must be caught');
        $this->assertFalse(MimeGuard::inspect($tmp, 'setup.exe')['ok']);
        $this->assertTrue(MimeGuard::inspect($tmp, 'notes.txt')['ok']);

        @unlink($tmp);
    }

    public function testPhpContentIsRejectedRegardlessOfName(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'guard');
        file_put_contents($tmp, "<?php echo 'pwned';");

        $result = MimeGuard::inspect($tmp, 'image.png');

        $this->assertFalse($result['ok'], 'PHP content disguised as a PNG must be rejected');

        @unlink($tmp);
    }

    public function testDownloadsAreNeverServedAsHtml(): void
    {
        $this->assertSame('application/octet-stream', MimeGuard::safeServingMime('text/html'));
        $this->assertSame('application/octet-stream', MimeGuard::safeServingMime('image/svg+xml'));
        $this->assertSame('image/png', MimeGuard::safeServingMime('image/png'));
    }

    // --- Installer --------------------------------------------------------

    public function testInstallerReportsUnreachableDatabaseClearly(): void
    {
        $result = \App\Services\Installer::testDatabase([
            'host'     => '10.255.255.1',
            'port'     => 3306,
            'database' => 'anything',
            'username' => 'someone',
            'password' => 'secret',
        ]);

        $this->assertFalse($result['ok']);
        $this->assertContains('unreachable', $result['message'], 'A bad host must be reported in plain language');
    }

    public function testInstallerReportsBadCredentialsClearly(): void
    {
        $config = (array) config('database');

        $result = \App\Services\Installer::testDatabase([
            'host'     => $config['host'],
            'port'     => $config['port'],
            'database' => $config['database'],
            'username' => 'definitely-not-a-user-' . Str::random(6),
            'password' => 'definitely-not-the-password',
        ]);

        $this->assertFalse($result['ok']);
        $this->assertContains('username and password', $result['message']);
    }

    public function testInstallerAcceptsAnExistingDatabase(): void
    {
        $config = (array) config('database');

        $result = \App\Services\Installer::testDatabase([
            'host'     => $config['host'],
            'port'     => $config['port'],
            'database' => $config['database'],
            'username' => $config['username'],
            'password' => $config['password'],
        ]);

        // The install database exists, so this must pass without needing the
        // CREATE privilege that shared hosting accounts do not have.
        $this->assertTrue($result['ok'], $result['message']);
        $this->assertTrue($result['database_exists'] ?? false);
        $this->assertFalse($result['can_create'] ?? true, 'An existing database must not be re-created');
    }

    public function testInstallerVerifiesTheInstallIsUsable(): void
    {
        $state = \App\Services\Installer::verifyInstall();

        $this->assertTrue($state['installed'], 'The test environment must be installed');
        $this->assertTrue($state['usable'], (string) $state['reason']);
        $this->assertNull($state['reason']);
    }

    public function testInstallerDetectsALockPointingAtAMissingDatabase(): void
    {
        // Copying a project between servers brings installed.lock along; the
        // installer has to notice that the recorded install cannot work rather
        // than refusing to run and sending the operator to a login that is
        // equally broken.
        $original = (array) config('database');

        \App\Core\Config::set('database.database', 'database_that_does_not_exist_' . Str::random(8));
        \App\Core\Database::reset();

        try {
            $state = \App\Services\Installer::verifyInstall();

            $this->assertTrue($state['installed']);
            $this->assertFalse($state['usable'], 'A lock pointing at a missing database must not count as installed');
            $this->assertContains('unreachable', (string) $state['reason']);
        } finally {
            \App\Core\Config::set('database.database', $original['database']);
            \App\Core\Database::reset();
            \App\Core\Database::connect();
        }
    }

    // --- Mailer -----------------------------------------------------------

    public function testMailerSpeaksSmtp(): void
    {
        $port = random_int(21000, 21900);
        $capture = sys_get_temp_dir() . '/s3lite-smtp-' . $port . '.json';
        $server = $this->startFakeSmtp($port, $capture);

        if ($server === null) {
            $this->skip('Could not start the fake SMTP server');
        }

        try {
            $mailer = new \App\Services\Mailer([
                'driver'     => 'smtp',
                'host'       => '127.0.0.1',
                'port'       => $port,
                'encryption' => 'none',
                'username'   => 'user@example.com',
                'password'   => 'secret123',
                'timeout'    => 8,
                'from'       => ['address' => 'noreply@example.com', 'name' => 'S3lite'],
            ]);

            $result = $mailer->send('dest@example.com', 'Subject with UTF-8: नमस्ते', '<p>Hello <b>world</b></p>');

            $this->assertTrue($result['ok'], $result['message']);

            // A password must never appear in the transcript shown to the operator.
            $transcript = implode("\n", $result['transcript'] ?? []);
            $this->assertNotContains('secret123', $transcript);
            $this->assertNotContains(base64_encode('secret123'), $transcript);

            $session = $this->readCapture($capture);

            $this->assertSame('user@example.com', $session['auth_user'] ?? null, 'Credentials must reach the server');
            $this->assertTrue($session['authenticated'] ?? false);
            $this->assertContains('noreply@example.com', (string) ($session['mail_from'] ?? ''));
            $this->assertContains('dest@example.com', (string) ($session['rcpt_to'] ?? ''));

            $body = (string) ($session['data'] ?? '');
            $this->assertContains('text/html', $body, 'The message must carry an HTML part');
            $this->assertContains('text/plain', $body, 'The message must carry a plain-text part');
            $this->assertContains('=?UTF-8?B?', $body, 'A non-ASCII subject must be encoded');
        } finally {
            @unlink($capture);
        }
    }

    public function testMailerRejectsBadAuth(): void
    {
        $port = random_int(22000, 22900);
        $capture = sys_get_temp_dir() . '/s3lite-smtp-' . $port . '.json';

        if ($this->startFakeSmtp($port, $capture, true) === null) {
            $this->skip('Could not start the fake SMTP server');
        }

        try {
            $mailer = new \App\Services\Mailer([
                'driver'   => 'smtp', 'host' => '127.0.0.1', 'port' => $port,
                'encryption' => 'none', 'username' => 'u', 'password' => 'wrong', 'timeout' => 8,
                'from'     => ['address' => 'a@b.c', 'name' => 'x'],
            ]);

            $result = $mailer->testConnection();

            $this->assertFalse($result['ok']);
            $this->assertContains('Authentication failed', $result['message']);
            $this->assertContains('app password', $result['message'], 'The hint about app passwords is the usual cause');
        } finally {
            @unlink($capture);
        }
    }

    public function testMailerReportsUnreachableServer(): void
    {
        $mailer = new \App\Services\Mailer([
            'driver' => 'smtp', 'host' => '127.0.0.1', 'port' => 1, 'encryption' => 'none',
            'timeout' => 3, 'from' => ['address' => 'a@b.c', 'name' => 'x'],
        ]);

        $result = $mailer->testConnection();

        $this->assertFalse($result['ok']);
        $this->assertContains('Could not reach', $result['message']);
    }

    public function testMailerValidatesRecipient(): void
    {
        $result = (new \App\Services\Mailer(['driver' => 'log']))->send('not-an-address', 'x', 'y');

        $this->assertFalse($result['ok']);
        $this->assertContains('valid recipient', $result['message']);
    }

    /**
     * Start the bundled fake SMTP listener in its own process.
     *
     * proc_open with bypass_shell is the portable option here: a shell wrapper
     * (start /B, or a trailing &) either loses the child or blocks on Windows.
     */
    private function startFakeSmtp(int $port, string $capture, bool $rejectAuth = false): ?string
    {
        $php = str_replace('\\', '/', PHP_BINARY);
        $script = str_replace('\\', '/', dirname(__DIR__) . '/tests/fake-smtp.php');
        $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';

        $command = sprintf(
            '"%s" "%s" %d "%s"%s',
            $php,
            $script,
            $port,
            str_replace('\\', '/', $capture),
            $rejectAuth ? ' --reject-auth' : ''
        );

        $process = @proc_open(
            $command,
            [['pipe', 'r'], ['file', $null, 'w'], ['file', $null, 'w']],
            $pipes,
            null,
            null,
            ['bypass_shell' => true]
        );

        if (!is_resource($process)) {
            return null;
        }

        self::$processes[] = $process;

        // Wait on the marker the server writes, not on a TCP probe: the server
        // accepts a single connection, and a probe would consume it.
        for ($i = 0; $i < 40; $i++) {
            usleep(100000);
            $raw = @file_get_contents($capture);
            $state = $raw === false ? null : json_decode($raw, true);

            if (is_array($state) && ($state['listening'] ?? false) === true) {
                return $capture;
            }
        }

        return null;
    }

    /** Start the bundled fake FTP listener in its own process. */
    private function startFakeFtp(int $port, string $root, string $marker): ?string
    {
        $command = sprintf(
            '"%s" "%s" %d "%s" "%s"',
            str_replace('\\', '/', PHP_BINARY),
            str_replace('\\', '/', dirname(__DIR__) . '/tests/fake-ftp.php'),
            $port,
            $root,
            $marker
        );

        $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';

        $process = @proc_open(
            $command,
            [['pipe', 'r'], ['file', $null, 'w'], ['file', $null, 'w']],
            $pipes,
            null,
            null,
            ['bypass_shell' => true]
        );

        if (!is_resource($process)) {
            return null;
        }

        self::$processes[] = $process;

        for ($i = 0; $i < 40; $i++) {
            usleep(100000);
            $raw = @file_get_contents($marker);
            $state = $raw === false ? null : json_decode($raw, true);

            if (is_array($state) && ($state['listening'] ?? false) === true) {
                return $marker;
            }
        }

        return null;
    }

    /** @var list<resource> */
    private static array $processes = [];

    public function tearDown(): void
    {
        foreach (self::$processes as $process) {
            if (is_resource($process)) {
                $status = @proc_get_status($process);

                if (($status['running'] ?? false) === true) {
                    @proc_terminate($process);
                }

                @proc_close($process);
            }
        }

        self::$processes = [];
    }

    private function readCapture(string $path): array
    {
        for ($i = 0; $i < 30; $i++) {
            usleep(100000);
            $raw = @file_get_contents($path);
            $data = $raw === false ? null : json_decode($raw, true);

            if (is_array($data) && isset($data['commands'])) {
                return $data;
            }
        }

        return [];
    }

    // --- Cache / Redis ----------------------------------------------------

    public function testCacheRoundTrip(): void
    {
        $key = 'test:' . Str::random(8);

        Cache::put($key, ['hello' => 'world'], 30);
        $this->assertSame('world', Cache::get($key)['hello'] ?? null);

        Cache::forget($key);
        $this->assertNull(Cache::get($key));
    }

    public function testCacheIncrement(): void
    {
        $key = 'counter:' . Str::random(8);

        $this->assertSame(1, Cache::increment($key, 1, 30));
        $this->assertSame(3, Cache::increment($key, 2, 30));

        Cache::forget($key);
    }

    public function testRedisClientSpeaksResp(): void
    {
        $config = (array) config('cache.redis');

        if (!($config['enabled'] ?? false)) {
            $this->skip('Redis is disabled in configuration');
        }

        $client = new RedisClient(
            (string) $config['host'],
            (int) $config['port'],
            (string) $config['password'],
            (int) $config['database'],
            2.0,
            'test:'
        );

        if (!$client->isAvailable()) {
            $this->skip('Redis is not reachable');
        }

        $this->assertTrue($client->ping());

        $key = 'probe-' . Str::random(6);
        $this->assertTrue($client->set($key, 'value', 20));
        $this->assertSame('value', $client->get($key));
        $this->assertSame(1, $client->del($key));
        $this->assertNull($client->get($key));

        $info = $client->info();
        $this->assertArrayHasKey('redis_version', $info);
    }

    // --- Storage backends -------------------------------------------------

    public function testRemotePathsCannotEscapeTheRoot(): void
    {
        $driver = new class ('probe', 'base/dir') extends \App\Storage\RemoteDriver {
            public function driver(): string
            {
                return 'probe';
            }

            public function put(string $sourcePath, string $targetPath, bool $moveSource = true): bool
            {
                return false;
            }

            public function readStream(string $path, int $offset = 0)
            {
                return null;
            }

            public function exists(string $path): bool
            {
                return false;
            }

            public function delete(string $path): bool
            {
                return false;
            }

            public function move(string $from, string $to): bool
            {
                return false;
            }

            public function size(string $path): int
            {
                return 0;
            }

            public function expose(string $path): string
            {
                return $this->remotePath($path);
            }
        };

        $this->assertSame('base/dir/7/ab/cd/file.bin', $driver->expose('7/ab/cd/file.bin'));
        $this->assertSame('base/dir/7/file.bin', $driver->expose('/7/file.bin'));

        foreach (['../etc/passwd', '7/../../secret', "7/evil\0.bin", ''] as $bad) {
            $this->assertThrows(\RuntimeException::class, static fn () => $driver->expose($bad), $bad . ' should be rejected');
        }
    }

    public function testLocalDriverReadsFromAnOffset(): void
    {
        $root = rtrim(str_replace('\\', '/', sys_get_temp_dir()), '/') . '/s3lite-driver-' . Str::random(6);
        $driver = new LocalDriver($root, 'probe');

        $this->assertSame('probe', $driver->name());
        $this->assertSame('local', $driver->driver());

        $path = 'offsets/sample.txt';
        $this->assertTrue($driver->putContents($path, 'HEADERBODY'));

        $stream = $driver->readStream($path, 6);
        $this->assertNotNull($stream);
        $this->assertSame('BODY', stream_get_contents($stream));
        fclose($stream);

        $driver->delete($path);
        @rmdir($root);
    }

    public function testBackendPublicArrayHidesCredentials(): void
    {
        $secret = StorageBackend::mergeCredentials([], [
            'password'    => 's3cret',
            'private_key' => '',
        ]);

        $this->assertNotNull($secret);

        $row = [
            'id' => 1, 'uuid' => Str::uuid(), 'name' => 'Box', 'slug' => 'box', 'driver' => 'ftp',
            'host' => 'ftp.example.com', 'port' => 21, 'username' => 'user', 'secret' => $secret,
            'root_path' => 'files', 'options' => ['passive' => true], 'is_default' => 0, 'is_active' => 1,
            'status' => 'unknown', 'last_error' => null, 'last_checked_at' => null, 'created_at' => null,
        ];

        $public = StorageBackend::publicArray($row);

        $this->assertTrue($public['has_password']);
        $this->assertFalse($public['has_private_key']);
        $this->assertFalse(in_array('secret', array_keys($public), true), 'The encrypted blob must not be exposed');
        $this->assertNotContains('s3cret', (string) json_encode($public));
        $this->assertSame('ftp.example.com:21/files', StorageBackend::endpointLabel($row));

        // A blank value keeps the stored credential; null clears it.
        $kept = StorageBackend::credentials(['secret' => StorageBackend::mergeCredentials($row, ['password' => ''])]);
        $this->assertSame('s3cret', $kept['password'] ?? null);

        $cleared = StorageBackend::mergeCredentials($row, ['password' => null]);
        $this->assertNull($cleared);
    }

    /**
     * Drive the real FtpDriver against the bundled fake server: connect,
     * create the sharded directories, transfer, resume, rename and clean up.
     */
    public function testFtpDriverRoundTrip(): void
    {
        if (!extension_loaded('ftp')) {
            $this->skip('The ftp extension is not loaded');
        }

        $port = random_int(24000, 24800);
        $root = rtrim(str_replace('\\', '/', sys_get_temp_dir()), '/') . '/fake-ftp-' . Str::random(6);
        $marker = $root . '.json';

        if ($this->startFakeFtp($port, $root, $marker) === null) {
            $this->skip('Could not start the fake FTP server');
        }

        $driver = new \App\Storage\FtpDriver(
            'suite-ftp',
            '127.0.0.1',
            $port,
            'tester',
            'secret',
            false,
            'base',
            ['timeout' => 5]
        );

        $this->assertSame('suite-ftp', $driver->name());
        $this->assertSame('ftp', $driver->driver());

        $path = '9/ab/cd/object.bin';
        $body = 'HEADER' . str_repeat('payload', 64);

        // Writing creates base/9/ab/cd on the way, as an upload would.
        $this->assertTrue($driver->putContents($path, $body));
        $this->assertTrue(is_file($root . '/base/' . $path), 'The file should land under the configured root');

        $this->assertTrue($driver->exists($path));
        $this->assertSame(strlen($body), $driver->size($path));
        $this->assertSame($body, $driver->get($path));

        // Range reads must resume server-side, not fetch and discard.
        $stream = $driver->readStream($path, 6);
        $this->assertNotNull($stream);
        $this->assertSame(substr($body, 6), stream_get_contents($stream));
        fclose($stream);

        $copy = '9/ab/cd/copy.bin';
        $this->assertTrue($driver->copy($path, $copy));
        $this->assertSame($body, $driver->get($copy));

        $moved = '9/ef/gh/moved.bin';
        $this->assertTrue($driver->move($copy, $moved));
        $this->assertFalse($driver->exists($copy));
        $this->assertSame($body, $driver->get($moved));

        $this->assertTrue($driver->delete($moved));
        $this->assertFalse($driver->exists($moved));

        // Deleting the last file in a branch prunes the empty directories,
        // but never climbs past the configured root.
        $this->assertTrue($driver->delete($path));
        $this->assertFalse(is_dir($root . '/base/9/ab'), 'Empty directories should be pruned');
        $this->assertTrue(is_dir($root . '/base'), 'The root itself must survive');

        // Deleting something that is already gone is not an error.
        $this->assertTrue($driver->delete($path));

        $driver->close();
        @unlink($marker);
    }

    public function testFtpDriverRejectsBadCredentials(): void
    {
        if (!extension_loaded('ftp')) {
            $this->skip('The ftp extension is not loaded');
        }

        $port = random_int(24801, 25400);
        $root = rtrim(str_replace('\\', '/', sys_get_temp_dir()), '/') . '/fake-ftp-' . Str::random(6);
        $marker = $root . '.json';

        if ($this->startFakeFtp($port, $root, $marker) === null) {
            $this->skip('Could not start the fake FTP server');
        }

        $driver = new \App\Storage\FtpDriver('bad-ftp', '127.0.0.1', $port, 'tester', 'wrong', false, '', ['timeout' => 5]);

        $error = $this->assertThrows(\RuntimeException::class, static fn () => $driver->exists('anything'));
        $this->assertContains('rejected', $error->getMessage());

        @unlink($marker);
    }

    /**
     * Upload lands on the primary, the queue copies it to the backup layer,
     * and once the primary loses it (or the whole backend goes away) reads
     * come from the backup instead. Deleting removes every copy.
     */
    public function testBackupLayerServesWhenPrimaryFails(): void
    {
        $base = rtrim(str_replace('\\', '/', sys_get_temp_dir()), '/') . '/replica-' . Str::random(6);
        $primarySlug = 'suite-primary-' . strtolower(Str::random(6));
        $layerSlug = 'suite-layer-' . strtolower(Str::random(6));
        $userId = (int) \App\Core\Database::scalar('SELECT id FROM users ORDER BY id LIMIT 1');

        if ($userId === 0) {
            $this->skip('No user to own the test file');
        }

        // Created directly, not through the service, so no backfill copies
        // the whole dev library into the temp layer.
        $ids = [];
        foreach ([[$primarySlug, null, '/a'], [$layerSlug, 1, '/b']] as [$slug, $priority, $dir]) {
            $ids[] = \App\Models\StorageBackend::create([
                'uuid' => Str::uuid(), 'name' => $slug, 'slug' => $slug, 'driver' => 'local',
                'root_path' => $base . $dir, 'options' => [], 'is_active' => 1,
                'replica_priority' => $priority, 'status' => 'ok',
            ]);
        }
        StorageManager::reset();

        $path = $userId . '/ab/cd/replica-' . Str::random(8) . '.bin';
        $body = 'backed up ' . Str::random(32);
        $fileId = 0;

        try {
            $this->assertTrue(StorageManager::disk($primarySlug)->putContents($path, $body));
            $fileId = \App\Models\FileRecord::create([
                'uuid' => Str::uuid(), 'user_id' => $userId, 'name' => 'replica.bin', 'original_name' => 'replica.bin',
                'size' => strlen($body), 'checksum' => hash('sha256', $body), 'disk' => $primarySlug, 'storage_path' => $path,
            ]);

            \App\Services\ReplicationService::queue($primarySlug, $path);
            $this->assertSame('pending', \App\Core\Database::scalar(
                'SELECT status FROM file_replicas WHERE storage_path = ? AND disk = ?', [$path, $layerSlug]
            ));

            \App\Services\ReplicationService::run();
            $this->assertSame('synced', \App\Core\Database::scalar(
                'SELECT status FROM file_replicas WHERE storage_path = ? AND disk = ?', [$path, $layerSlug]
            ));
            $this->assertSame($body, StorageManager::disk($layerSlug)->get($path), 'The layer should hold a copy');

            // Healthy primary: it is preferred.
            $this->assertSame($primarySlug, \App\Services\ReplicationService::readableDisk($primarySlug, $path)->name());

            // Primary lost the object: the backup answers.
            StorageManager::disk($primarySlug)->delete($path);
            $disk = \App\Services\ReplicationService::readableDisk($primarySlug, $path);
            $this->assertSame($layerSlug, $disk->name());
            $this->assertSame($body, $disk->get($path));

            // Primary backend unreachable altogether: still served.
            $this->assertSame($layerSlug, \App\Services\ReplicationService::readableDisk('suite-gone-' . Str::random(6), $path)->name());

            // Migrating off the primary that lost it reads from the backup.
            $result = \App\Services\StorageBackendService::migrate($primarySlug, 'local', 10);
            $this->assertSame(1, $result['moved'], 'Migration should recover the file from the backup: ' . implode('; ', $result['errors']));
            $this->assertSame($body, StorageManager::disk('local')->get($path));

            // Delete clears every copy and the bookkeeping.
            \App\Services\ReplicationService::deleteEverywhere('local', $path);
            $this->assertFalse(StorageManager::disk($layerSlug)->exists($path));
            $this->assertFalse(StorageManager::disk('local')->exists($path));
            $this->assertSame(0, (int) \App\Core\Database::scalar('SELECT COUNT(*) FROM file_replicas WHERE storage_path = ?', [$path]));

            $this->assertThrows(
                \App\Http\Exceptions\HttpException::class,
                static fn () => \App\Services\ReplicationService::readableDisk('local', $path)
            );
        } finally {
            if ($fileId > 0) {
                \App\Models\FileRecord::deleteById($fileId);
            }
            \App\Core\Database::statement('DELETE FROM file_replicas WHERE storage_path = ?', [$path]);
            foreach ($ids as $id) {
                \App\Models\StorageBackend::deleteById($id);
            }
            StorageManager::reset();
        }
    }

    /** Start a tests/ router script under php -S on a free port; returns its base URL or null. */
    private function startRouterServer(string $script, array $extraEnv): ?string
    {
        $port = random_int(25401, 26000);
        $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        $env = array_merge(getenv(), $extraEnv);

        $process = @proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . $port, str_replace('\\', '/', dirname(__DIR__) . '/tests/' . $script)],
            [['pipe', 'r'], ['file', $null, 'w'], ['file', $null, 'w']],
            $pipes,
            null,
            $env,
            ['bypass_shell' => true]
        );

        if (!is_resource($process)) {
            return null;
        }

        self::$processes[] = $process;

        for ($i = 0; $i < 40; $i++) {
            usleep(100000);
            $socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
            if ($socket !== false) {
                fclose($socket);

                return 'http://127.0.0.1:' . $port;
            }
        }

        return null;
    }

    /** @return array<string, string> Driver options pointing at the fake server. */
    private static function fakeGdriveOptions(string $base): array
    {
        return [
            'api_base'    => $base . '/drive/v3',
            'upload_base' => $base . '/upload/drive/v3',
            'token_url'   => $base . '/token',
            'timeout'     => 5,
        ];
    }

    public function testGoogleDriveDriverRoundTrip(): void
    {
        $root = rtrim(str_replace('\\', '/', sys_get_temp_dir()), '/') . '/fake-gdrive-' . Str::random(6);
        $base = $this->startRouterServer('fake-gdrive.php', ['FAKE_GDRIVE_ROOT' => $root]);

        if ($base === null) {
            $this->skip('Could not start the fake Google Drive server');
        }

        $driver = new \App\Storage\GoogleDriveDriver('suite-gdrive', 'client', 'secret', 'good-refresh', '', self::fakeGdriveOptions($base));

        $this->assertSame('gdrive', $driver->driver());

        // No folder given: one is created, and found again rather than duplicated.
        $folder = $driver->folderId();
        $this->assertNotEmpty($folder);
        $again = new \App\Storage\GoogleDriveDriver('suite-gdrive', 'client', 'secret', 'good-refresh', '', self::fakeGdriveOptions($base));
        $this->assertSame($folder, $again->folderId(), 'A second instance must reuse the folder');

        $path = '7/ab/cd/it\'s a "file".bin';
        $body = 'HEADER' . str_repeat('drive', 500);

        $this->assertFalse($driver->exists($path));
        $this->assertTrue($driver->putContents($path, $body));
        $this->assertTrue($driver->exists($path));
        $this->assertSame(strlen($body), $driver->size($path));
        $this->assertSame($body, $again->get($path), 'A fresh instance finds it by name');

        $stream = $driver->readStream($path, 6);
        $this->assertNotNull($stream);
        $this->assertSame(substr($body, 6), stream_get_contents($stream));
        fclose($stream);

        // Overwriting keeps one object under that name.
        $this->assertTrue($driver->putContents($path, 'v2'));
        $this->assertSame('v2', $again->get($path));
        $state = json_decode((string) file_get_contents($root . '/state.json'), true);
        $named = array_filter($state['files'], static fn (array $f): bool => $f['name'] === $path);
        $this->assertCount(1, $named, 'An overwrite must not create a duplicate');

        $this->assertTrue($driver->copy($path, '7/copy.bin'));
        $this->assertSame('v2', $driver->get('7/copy.bin'));
        $this->assertTrue($driver->move('7/copy.bin', '7/moved.bin'));
        $this->assertFalse($driver->exists('7/copy.bin'));
        $this->assertSame('v2', $driver->get('7/moved.bin'));

        $this->assertTrue($driver->delete('7/moved.bin'));
        $this->assertTrue($driver->delete('7/moved.bin'), 'Deleting twice is fine');
        $this->assertNull($driver->readStream('7/moved.bin'));

        $this->assertSame(1000000, $driver->diskUsage()['total']);

        $this->assertThrows(\RuntimeException::class, static fn () => $driver->exists('../escape'));
    }

    public function testGoogleDriveOauthAndBadToken(): void
    {
        $root = rtrim(str_replace('\\', '/', sys_get_temp_dir()), '/') . '/fake-gdrive-' . Str::random(6);
        $base = $this->startRouterServer('fake-gdrive.php', ['FAKE_GDRIVE_ROOT' => $root]);

        if ($base === null) {
            $this->skip('Could not start the fake Google Drive server');
        }

        $tokens = \App\Storage\GoogleDriveDriver::exchangeCode('client', 'secret', 'good-code', 'http://localhost/cb', $base . '/token');
        $this->assertSame('good-refresh', $tokens['refresh_token']);

        $error = $this->assertThrows(
            \RuntimeException::class,
            static fn () => \App\Storage\GoogleDriveDriver::exchangeCode('client', 'secret', 'bad-code', 'http://localhost/cb', $base . '/token')
        );
        $this->assertContains('Google refused', $error->getMessage());

        $url = \App\Storage\GoogleDriveDriver::authorizationUrl('client', 'http://localhost/cb', 'st4te');
        $this->assertContains('access_type=offline', $url);
        $this->assertContains('state=st4te', $url);
        $this->assertContains(rawurlencode(\App\Storage\GoogleDriveDriver::SCOPE), $url);

        $revoked = new \App\Storage\GoogleDriveDriver('suite-gdrive', 'client', 'secret', 'revoked', 'f', self::fakeGdriveOptions($base));
        $error = $this->assertThrows(\RuntimeException::class, static fn () => $revoked->exists('a.bin'));
        $this->assertContains('connect Google Drive again', $error->getMessage());

        $this->assertThrows(
            \RuntimeException::class,
            static fn () => new \App\Storage\GoogleDriveDriver('suite-gdrive', 'client', 'secret', '', '', [])
        );
    }

    /** The case the feature exists for: FTP dies, Drive serves, then takes over. */
    public function testGoogleDriveAsBackupLayer(): void
    {
        $root = rtrim(str_replace('\\', '/', sys_get_temp_dir()), '/') . '/fake-gdrive-' . Str::random(6);
        $base = $this->startRouterServer('fake-gdrive.php', ['FAKE_GDRIVE_ROOT' => $root]);

        if ($base === null) {
            $this->skip('Could not start the fake Google Drive server');
        }

        $userId = (int) \App\Core\Database::scalar('SELECT id FROM users ORDER BY id LIMIT 1');
        $primarySlug = 'suite-primary-' . strtolower(Str::random(6));
        $driveSlug = 'suite-drive-' . strtolower(Str::random(6));
        $temp = rtrim(str_replace('\\', '/', sys_get_temp_dir()), '/') . '/drive-primary-' . Str::random(6);

        $ids = [
            \App\Models\StorageBackend::create([
                'uuid' => Str::uuid(), 'name' => $primarySlug, 'slug' => $primarySlug, 'driver' => 'local',
                'root_path' => $temp, 'options' => [], 'is_active' => 1, 'status' => 'ok',
            ]),
            \App\Models\StorageBackend::create([
                'uuid' => Str::uuid(), 'name' => $driveSlug, 'slug' => $driveSlug, 'driver' => 'gdrive',
                'root_path' => '', 'options' => self::fakeGdriveOptions($base) + ['client_id' => 'client'],
                'secret' => \App\Models\StorageBackend::mergeCredentials([], ['client_secret' => 'secret', 'refresh_token' => 'good-refresh']),
                'is_active' => 1, 'replica_priority' => 1, 'status' => 'ok',
            ]),
        ];
        StorageManager::reset();

        $path = $userId . '/ef/01/drive-' . Str::random(8) . '.txt';
        $body = 'safe in drive ' . Str::random(16);
        $fileId = 0;

        try {
            $this->assertTrue(\App\Services\StorageBackendService::test($ids[1])['ok'], 'Connection test should pass');

            StorageManager::disk($primarySlug)->putContents($path, $body);
            $fileId = \App\Models\FileRecord::create([
                'uuid' => Str::uuid(), 'user_id' => $userId, 'name' => 'd.txt', 'original_name' => 'd.txt',
                'size' => strlen($body), 'checksum' => hash('sha256', $body), 'disk' => $primarySlug, 'storage_path' => $path,
            ]);

            \App\Services\ReplicationService::queue($primarySlug, $path);
            $run = \App\Services\ReplicationService::run();
            $this->assertSame(1, $run['synced'], 'The copy to Drive should succeed');

            StorageManager::disk($primarySlug)->delete($path);
            $disk = \App\Services\ReplicationService::readableDisk($primarySlug, $path);
            $this->assertSame($driveSlug, $disk->name(), 'Drive should serve once the primary lost it');
            $this->assertSame($body, $disk->get($path));

            $result = \App\Services\StorageBackendService::migrate($primarySlug, $driveSlug, 10);
            $this->assertSame(1, $result['moved'], implode('; ', $result['errors']));
            $this->assertSame($driveSlug, \App\Core\Database::scalar('SELECT disk FROM files WHERE id = ?', [$fileId]));

            \App\Services\ReplicationService::deleteEverywhere($driveSlug, $path);
            $this->assertFalse(StorageManager::disk($driveSlug)->exists($path));
        } finally {
            if ($fileId > 0) {
                \App\Models\FileRecord::deleteById($fileId);
            }
            \App\Core\Database::statement('DELETE FROM file_replicas WHERE storage_path = ?', [$path]);
            foreach ($ids as $id) {
                \App\Models\StorageBackend::deleteById($id);
            }
            StorageManager::reset();
        }
    }

    /** Run $test with CDN settings applied, then remove them again. */
    private function withCdnSettings(array $values, callable $test): void
    {
        $keys = ['cdn_cache_enabled', 'cdn_cache_days', 'cdn_min_lifetime_days', 'cloudflare_zone_id', 'cloudflare_api_token'];
        $before = [];
        foreach ($keys as $key) {
            $before[$key] = \App\Core\Database::selectOne('SELECT * FROM settings WHERE key_name = ?', [$key]);
        }

        try {
            \App\Services\SettingService::setMany($values, 'cdn');
            $test();
        } finally {
            foreach ($before as $key => $row) {
                \App\Core\Database::delete('settings', 'key_name = ?', [$key]);
                if ($row !== null) {
                    \App\Core\Database::insert('settings', $row);
                }
            }
            \App\Services\SettingService::flush();
        }
    }

    public function testCdnCachesOnlySafeLinks(): void
    {
        $now = 1_800_000_000;
        $share = ['is_active' => 1, 'password_hash' => null, 'max_downloads' => null, 'expires_at' => null];
        $in = static fn (int $days): string => date('Y-m-d H:i:s', $now + $days * 86400);

        $this->withCdnSettings(['cdn_cache_enabled' => false], function () use ($share, $now): void {
            $this->assertSame(0, \App\Services\CdnCacheService::ttlForShare($share, $now), 'Off means never cached');
        });

        $this->withCdnSettings(['cdn_cache_enabled' => true, 'cdn_cache_days' => 30, 'cdn_min_lifetime_days' => 7], function () use ($share, $now, $in): void {
            $ttl = static fn (array $s): int => \App\Services\CdnCacheService::ttlForShare($s, $now);

            $this->assertSame(30 * 86400, $ttl($share), 'A permanent link is cached for the configured days');
            $this->assertSame(20 * 86400, $ttl(['expires_at' => $in(20)] + $share), 'Never past the expiry');
            $this->assertSame(30 * 86400, $ttl(['expires_at' => $in(90)] + $share));
            $this->assertSame(0, $ttl(['expires_at' => $in(3)] + $share), 'Under a week left: not cached');
            $this->assertSame(0, $ttl(['password_hash' => 'x'] + $share), 'Password links would leak');
            $this->assertSame(0, $ttl(['max_downloads' => 5] + $share), 'A limit cannot be enforced from a cache');
            $this->assertSame(0, $ttl(['is_active' => 0] + $share));

            $headers = \App\Services\CdnCacheService::headers(86400);
            $this->assertContains('public', $headers['Cache-Control']);
            $this->assertContains('s-maxage=86400', $headers['Cache-Control']);

            $url = \App\Services\CdnCacheService::url(['token' => 'tok', 'file_id' => 1], ['name' => 'my file.zip', 'uuid' => 'u']);
            $this->assertContains('/cdn/tok/my%20file.zip', $url);
            $this->assertNotContains('?file=', $url);
            $folderUrl = \App\Services\CdnCacheService::url(['token' => 'tok', 'file_id' => null], ['name' => 'a.pdf', 'uuid' => 'u-1']);
            $this->assertContains('a.pdf?file=u-1', $folderUrl);
        });
    }

    public function testCloudflareCacheRuleAndPurge(): void
    {
        $root = rtrim(str_replace('\\', '/', sys_get_temp_dir()), '/') . '/fake-cf-' . Str::random(6);
        $base = $this->startRouterServer('fake-cloudflare.php', ['FAKE_CF_ROOT' => $root]);

        if ($base === null) {
            $this->skip('Could not start the fake Cloudflare server');
        }

        $original = \App\Services\CdnCacheService::$apiBase;
        \App\Services\CdnCacheService::$apiBase = $base;
        $state = static fn (): array => json_decode((string) file_get_contents($root . '/state.json'), true);

        try {
            $this->withCdnSettings([
                'cdn_cache_enabled'    => true,
                'cloudflare_zone_id'   => 'abc123',
                'cloudflare_api_token' => \App\Support\Crypto::encrypt('good-token'),
            ], function () use ($root, $state): void {
                $this->assertTrue(\App\Services\CdnCacheService::apiConfigured());
                $this->assertTrue(\App\Services\CdnCacheService::testConnection()['ok']);

                // No cache rules on the zone yet: the entrypoint is created.
                $this->assertContains('Created', \App\Services\CdnCacheService::ensureCacheRule());
                $rules = $state()['ruleset']['rules'];
                $this->assertCount(1, $rules);
                $this->assertContains(\App\Services\CdnCacheService::pathPrefix(), $rules[0]['expression']);
                $this->assertTrue($rules[0]['action_parameters']['cache']);

                // Running it again updates ours rather than adding a duplicate.
                $this->assertContains('Updated', \App\Services\CdnCacheService::ensureCacheRule());
                $this->assertCount(1, $state()['ruleset']['rules']);

                // A zone with someone else's rules keeps them.
                file_put_contents($root . '/state.json', json_encode([
                    'ruleset' => ['id' => 'rs9', 'rules' => [['id' => 'theirs', 'ref' => 'other', 'expression' => 'true']]],
                    'purged'  => [],
                    'calls'   => [],
                ]));
                $this->assertContains('alongside', \App\Services\CdnCacheService::ensureCacheRule());
                $rules = $state()['ruleset']['rules'];
                $this->assertCount(2, $rules);
                $this->assertSame('theirs', $rules[0]['id'], 'Existing rules must survive');
                $this->assertNotContains('PUT', implode(' ', $state()['calls']), 'Must never replace the whole ruleset');

                // Purges go out in batches Cloudflare accepts.
                $urls = array_map(static fn (int $i): string => 'https://example.test/cdn/t/' . $i, range(1, 65));
                \App\Services\CdnCacheService::purgeUrls($urls);
                $this->assertCount(65, $state()['purged']);
            });

            // A bad token reports, it does not throw.
            $this->withCdnSettings([
                'cloudflare_zone_id'   => 'abc123',
                'cloudflare_api_token' => \App\Support\Crypto::encrypt('wrong'),
            ], function (): void {
                $result = \App\Services\CdnCacheService::testConnection();
                $this->assertFalse($result['ok']);
                $this->assertContains('Invalid API Token', $result['message']);
                \App\Services\CdnCacheService::purgeUrls(['https://example.test/x']); // logged, not thrown
            });
        } finally {
            \App\Services\CdnCacheService::$apiBase = $original;
        }
    }

    /**
     * Through the real web server: a cacheable link answers with public
     * caching headers and no cookie; once it gains a password it is bounced
     * to the normal download, which asks for it.
     */
    public function testCdnDownloadOverHttp(): void
    {
        $userId = (int) \App\Core\Database::scalar('SELECT id FROM users ORDER BY id LIMIT 1');
        $body = 'cdn body ' . Str::random(24);
        $path = $userId . '/cd/n0/cdn-' . Str::random(8) . '.txt';
        $fileId = 0;
        $shareId = 0;

        try {
            StorageManager::disk('local')->putContents($path, $body);
            $fileId = \App\Models\FileRecord::create([
                'uuid' => Str::uuid(), 'user_id' => $userId, 'name' => 'cdn check.txt', 'original_name' => 'cdn check.txt',
                'mime' => 'text/plain', 'extension' => 'txt',
                'size' => strlen($body), 'checksum' => hash('sha256', $body), 'disk' => 'local', 'storage_path' => $path,
            ]);
            $share = \App\Services\ShareService::createForFile($userId, $fileId);
            $shareId = (int) $share['id'];
            $file = \App\Models\FileRecord::find($fileId);

            $this->withCdnSettings(['cdn_cache_enabled' => true, 'cdn_cache_days' => 30], function () use ($share, $file, $body, $userId, $shareId): void {
                $url = \App\Services\CdnCacheService::url($share, $file);
                $url = Runner::baseUrl() . substr($url, strpos($url, '/cdn/'));

                $response = $this->rawGet($url);
                $this->assertSame(200, $response['status'], 'The CDN link should download');
                $this->assertSame($body, $response['body']);
                $this->assertContains('public', $response['headers']['cache-control'] ?? '');
                $this->assertContains('max-age=2592000', $response['headers']['cache-control'] ?? '');
                $this->assertFalse(isset($response['headers']['set-cookie']), 'A cached response must not set a cookie');

                // Lock it with a password: the CDN URL must stop serving bytes.
                \App\Services\ShareService::update($userId, $shareId, ['password' => 'secret1']);
                $locked = $this->rawGet($url);
                $this->assertSame(302, $locked['status']);
                $this->assertContains('/s/', $locked['headers']['location'] ?? '');
                $this->assertContains('no-store', $locked['headers']['cache-control'] ?? '');
            });
        } finally {
            if ($shareId > 0) {
                \App\Models\Share::deleteById($shareId);
            }
            if ($fileId > 0) {
                \App\Models\FileRecord::deleteById($fileId);
            }
            StorageManager::disk('local')->delete($path);
        }
    }

    /** @return array{status:int, body:string, headers:array<string,string>} */
    private function rawGet(string $url): array
    {
        $headers = [];
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$headers): int {
                if (str_contains($line, ':')) {
                    [$name, $value] = explode(':', $line, 2);
                    $headers[strtolower(trim($name))] = trim($value);
                }

                return strlen($line);
            },
        ]);
        $body = (string) curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return ['status' => $status, 'body' => $body, 'headers' => $headers];
    }

    public function testUnknownBackendSlugFailsLoudly(): void
    {
        $this->assertThrows(
            \App\Http\Exceptions\HttpException::class,
            static fn () => StorageManager::disk('no-such-backend-' . Str::random(6))
        );

        // Slugs defined only in config/storage.php still resolve.
        $this->assertSame('local', StorageManager::disk('local')->driver());
    }
}
