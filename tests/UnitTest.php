<?php
declare(strict_types=1);

namespace Tests;

use App\Core\Cache;
use App\Core\RedisClient;
use App\Http\Exceptions\ValidationException;
use App\Http\Validator;
use App\Models\IpRule;
use App\Services\MimeGuard;
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
}
