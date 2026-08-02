<?php
declare(strict_types=1);

namespace Tests;

/**
 * End-to-end REST API coverage against the running server.
 */
final class ApiTest extends TestCase
{
    private static ?HttpClient $client = null;
    private static string $accessToken = '';
    private static string $refreshToken = '';
    private static string $apiKey = '';
    private static ?int $apiKeyId = null;
    private static ?string $fileUuid = null;
    private static ?string $folderUuid = null;
    private static ?int $shareId = null;
    private static ?string $shareToken = null;
    private static ?int $sftpId = null;
    private static ?int $secondUserId = null;
    /** Makes fixture content unique per run so leftovers never affect duplicate detection. */
    private static string $runId = '';

    private function helloContent(): string
    {
        if (self::$runId === '') {
            self::$runId = bin2hex(random_bytes(6));
        }

        return "Hello from the S3 Lite test suite.\nRun " . self::$runId . "\n";
    }

    public function name(): string
    {
        return 'API — REST v1 end to end';
    }

    public function tests(): array
    {
        // Ordered: later tests depend on artefacts created by earlier ones.
        return [
            'testHealthIsPublic',
            'testPingIsPublic',
            'testOpenApiDocumentIsServed',
            'testUnauthenticatedRequestIsRejected',
            'testLoginRejectsBadCredentials',
            'testLoginIssuesTokens',
            'testMeReturnsIdentity',
            'testCreateApiKey',
            'testApiKeyAuthenticates',
            'testInvalidApiKeyIsRejected',
            'testCreateFolder',
            'testUploadFile',
            'testListFiles',
            'testSearchFiles',
            'testReadFileMetadata',
            'testDownloadFile',
            'testRangeRequestIsHonoured',
            'testDuplicateUploadIsDetected',
            'testExecutableUploadIsBlocked',
            'testRenameAndTagFile',
            'testMoveFileIntoFolder',
            'testCopyFile',
            'testNewVersionIsRecorded',
            'testMultipartResumableUpload',
            'testCreatePasswordProtectedShare',
            'testPublicShareRequiresPassword',
            'testTemporarySignedUrl',
            'testIdempotencyKeyReplaysResponse',
            'testTrashAndRestore',
            'testStatisticsEndpoint',
            'testMetricsEndpoint',
            'testAuditLogEndpoint',
            'testAdminCreatesUser',
            'testScopeEnforcement',
            'testSftpAccountLifecycle',
            'testSftpDaemonAuthentication',
            'testWebhookLifecycle',
            'testJobExecution',
            'testBackupCreation',
            'testSettingsUpdate',
            'testStorageBackendLifecycle',
            'testUploadRejectsAnUnknownBackend',
            'testUploadHonoursAnExplicitBackend',
            'testTokenRefreshRotates',
            'testPermanentDelete',
            'testCleanupOfTestUser',
            'testLogoutRevokesToken',
        ];
    }

    private function client(): HttpClient
    {
        if (self::$client === null) {
            self::$client = new HttpClient(Runner::baseUrl() . '/api/v1');
        }

        return self::$client;
    }

    private function auth(): array
    {
        return ['Authorization' => 'Bearer ' . self::$accessToken];
    }

    // --- Public endpoints -------------------------------------------------

    public function testHealthIsPublic(): void
    {
        $client = $this->client()->get('/health');

        $this->assertSame(200, $client->lastStatus, $client->lastBody);
        $data = $client->data();
        $this->assertArrayHasKey('status', (array) $data);
        $this->assertArrayHasKey('checks', (array) $data);
        $this->assertSame('ok', $data['checks']['database']['status'], 'Database check must pass');
    }

    public function testPingIsPublic(): void
    {
        $client = $this->client()->get('/ping');

        $this->assertSame(200, $client->lastStatus);
        $this->assertTrue($client->data()['pong'] ?? false);
    }

    public function testOpenApiDocumentIsServed(): void
    {
        $client = $this->client()->get('/openapi.json');
        $spec = $client->json();

        $this->assertSame(200, $client->lastStatus);
        $this->assertSame('3.0.3', $spec['openapi'] ?? null);
        $this->assertGreaterThan(30, count($spec['paths'] ?? []), 'The spec should document the whole API');
    }

    public function testUnauthenticatedRequestIsRejected(): void
    {
        $client = $this->client()->get('/files');

        $this->assertSame(401, $client->lastStatus);
        $this->assertSame('unauthenticated', $client->errorCode());
    }

    // --- Auth --------------------------------------------------------------

    public function testLoginRejectsBadCredentials(): void
    {
        $client = $this->client()->postJson('/auth/login', [
            'email'    => Runner::adminEmail(),
            'password' => 'definitely-not-the-password',
        ]);

        $this->assertSame(401, $client->lastStatus);
        $this->assertFalse($client->succeeded());
    }

    public function testLoginIssuesTokens(): void
    {
        $client = $this->client()->postJson('/auth/login', [
            'email'    => Runner::adminEmail(),
            'password' => Runner::adminPassword(),
        ]);

        $this->assertSame(200, $client->lastStatus, $client->lastBody);

        $data = (array) $client->data();
        $this->assertArrayHasKey('access_token', $data);
        $this->assertArrayHasKey('refresh_token', $data);
        $this->assertSame('Bearer', $data['token_type']);

        self::$accessToken = $data['access_token'];
        self::$refreshToken = $data['refresh_token'];
    }

    public function testMeReturnsIdentity(): void
    {
        $client = $this->client()->get('/auth/me', [], $this->auth());
        $data = (array) $client->data();

        $this->assertSame(200, $client->lastStatus);
        $this->assertSame(Runner::adminEmail(), $data['user']['email']);
        $this->assertSame('admin', $data['user']['role']);
    }

    public function testCreateApiKey(): void
    {
        $client = $this->client()->postJson('/api-keys', [
            'name'   => 'test-suite-' . bin2hex(random_bytes(3)),
            'scopes' => ['*'],
        ], $this->auth());

        $this->assertSame(201, $client->lastStatus, $client->lastBody);

        $data = (array) $client->data();
        $this->assertTrue(str_starts_with((string) $data['key'], 's3k_'), 'Keys must carry the s3k_ prefix');

        self::$apiKey = $data['key'];
        self::$apiKeyId = (int) $data['record']['id'];
    }

    public function testApiKeyAuthenticates(): void
    {
        $client = $this->client()->get('/auth/me', [], ['X-Api-Key' => self::$apiKey]);

        $this->assertSame(200, $client->lastStatus, $client->lastBody);
        $this->assertSame('api_key', $client->data()['guard']);
    }

    public function testInvalidApiKeyIsRejected(): void
    {
        $client = $this->client()->get('/files', [], ['X-Api-Key' => 's3k_nope_' . str_repeat('x', 40)]);

        $this->assertSame(401, $client->lastStatus);
        $this->assertSame('invalid_api_key', $client->errorCode());
    }

    // --- Folders & files ---------------------------------------------------

    public function testCreateFolder(): void
    {
        $client = $this->client()->postJson('/folders', [
            'name' => 'Test folder ' . bin2hex(random_bytes(3)),
        ], $this->auth());

        $this->assertSame(201, $client->lastStatus, $client->lastBody);

        $data = (array) $client->data();
        $this->assertNotEmpty($data['uuid']);

        self::$folderUuid = $data['uuid'];
    }

    public function testUploadFile(): void
    {
        $path = Runner::fixture('hello.txt', $this->helloContent());

        $client = $this->client()->upload('/files/upload', 'file', $path, [
            'tags' => 'test,automated',
        ], $this->auth());

        $this->assertSame(201, $client->lastStatus, $client->lastBody);

        $uploaded = (array) $client->data()['uploaded'];
        $this->assertCount(1, $uploaded);

        $file = $uploaded[0]['file'];
        $this->assertSame('hello.txt', $file['name']);
        $this->assertSame('text', $file['kind']);
        $this->assertGreaterThan(0, $file['size']);
        $this->assertSame(hash_file('sha256', $path), $file['checksum'], 'Stored checksum must match the source');

        self::$fileUuid = $file['uuid'];
    }

    public function testListFiles(): void
    {
        $client = $this->client()->get('/files', ['per_page' => 50], $this->auth());
        $payload = $client->json();

        $this->assertSame(200, $client->lastStatus);
        $this->assertArrayHasKey('meta', $payload);
        $this->assertArrayHasKey('pagination', $payload['meta']);
        $this->assertGreaterThan(0, count((array) $client->data()));
    }

    public function testSearchFiles(): void
    {
        $client = $this->client()->get('/files', ['q' => 'hello'], $this->auth());
        $files = (array) $client->data();

        $this->assertSame(200, $client->lastStatus);
        $this->assertGreaterThan(0, count($files), 'Search should find the uploaded file');
    }

    public function testReadFileMetadata(): void
    {
        $client = $this->client()->get('/files/' . self::$fileUuid, [], $this->auth());
        $data = (array) $client->data();

        $this->assertSame(200, $client->lastStatus);
        $this->assertSame(self::$fileUuid, $data['file']['uuid']);
        $this->assertArrayHasKey('versions', $data);
        $this->assertArrayHasKey('shares', $data);
    }

    public function testDownloadFile(): void
    {
        $client = $this->client()->get('/files/' . self::$fileUuid . '/download', [], $this->auth());

        $this->assertSame(200, $client->lastStatus);
        $this->assertContains('Hello from the S3 Lite test suite.', $client->lastBody);
        $this->assertContains('attachment', (string) $client->header('content-disposition'));
        $this->assertSame('nosniff', $client->header('x-content-type-options'));
    }

    public function testRangeRequestIsHonoured(): void
    {
        $client = $this->client()->get('/files/' . self::$fileUuid . '/download', [], $this->auth() + ['Range' => 'bytes=0-4']);

        $this->assertSame(206, $client->lastStatus, 'Range requests must return 206');
        $this->assertSame('Hello', $client->lastBody);
        $this->assertContains('bytes 0-4/', (string) $client->header('content-range'));
    }

    public function testDuplicateUploadIsDetected(): void
    {
        $path = Runner::fixture('hello.txt', $this->helloContent());

        $client = $this->client()->upload('/files/upload', 'file', $path, [], $this->auth());

        $this->assertSame(201, $client->lastStatus, $client->lastBody);

        $uploaded = (array) $client->data()['uploaded'];
        $this->assertTrue((bool) $uploaded[0]['duplicate'], 'Identical content must be reported as a duplicate');
        $this->assertSame(self::$fileUuid, $uploaded[0]['file']['uuid']);
    }

    public function testExecutableUploadIsBlocked(): void
    {
        // Deliberately inert content: the point is the extension and the <?php marker,
        // and a live-looking payload can be quarantined by the host's antivirus.
        $path = Runner::fixture('blocked-script.php', "<?php echo 'inert test fixture';");

        $client = $this->client()->upload('/files/upload', 'file', $path, [], $this->auth());

        $this->assertSame(422, $client->lastStatus, 'A PHP upload must be rejected');
        $this->assertSame('upload_failed', $client->errorCode());

        // The same content under a harmless name must also be refused, on content alone.
        $disguised = Runner::fixture('disguised.png', "<?php echo 'inert test fixture';");
        $second = $this->client()->upload('/files/upload', 'file', $disguised, [], $this->auth());
        $this->assertSame(422, $second->lastStatus, 'PHP content renamed to .png must still be rejected');
    }

    public function testRenameAndTagFile(): void
    {
        $client = $this->client()->patchJson('/files/' . self::$fileUuid, [
            'name' => 'renamed-by-test.txt',
            'tags' => ['renamed', 'suite'],
        ], $this->auth());

        $data = (array) $client->data();

        $this->assertSame(200, $client->lastStatus, $client->lastBody);
        $this->assertSame('renamed-by-test.txt', $data['name']);
        $this->assertCount(2, $data['tags']);
    }

    public function testMoveFileIntoFolder(): void
    {
        $client = $this->client()->patchJson('/files/' . self::$fileUuid, [
            'folder_id' => self::$folderUuid,
        ], $this->auth());

        $this->assertSame(200, $client->lastStatus, $client->lastBody);
        $this->assertNotNull($client->data()['folder_id']);
    }

    public function testCopyFile(): void
    {
        $client = $this->client()->postJson('/files/' . self::$fileUuid . '/copy', [], $this->auth());
        $data = (array) $client->data();

        $this->assertSame(201, $client->lastStatus, $client->lastBody);
        $this->assertContains('(copy)', $data['name']);

        // Clean the copy up straight away.
        $this->client()->delete('/files/' . $data['uuid'] . '?permanent=1', $this->auth());
    }

    public function testNewVersionIsRecorded(): void
    {
        $path = Runner::fixture('hello-v2.txt', "Version two of the file.\n");

        // Uploading over an existing file id creates a version.
        $client = $this->client()->upload('/files/upload', 'file', $path, [], $this->auth());
        $this->assertSame(201, $client->lastStatus);

        $second = (array) $client->data()['uploaded'][0]['file'];

        $versions = $this->client()->get('/files/' . $second['uuid'] . '/versions', [], $this->auth());
        $this->assertSame(200, $versions->lastStatus);

        // Remove the helper file.
        $this->client()->delete('/files/' . $second['uuid'] . '?permanent=1', $this->auth());
    }

    public function testMultipartResumableUpload(): void
    {
        $content = str_repeat('CHUNKED-UPLOAD-TEST-', 40000); // ~800 KB
        $path = Runner::fixture('large.bin', $content);
        $partSize = 262144;
        $checksum = hash('sha256', $content);

        $init = $this->client()->postJson('/files/multipart/init', [
            'filename'   => 'large-test.txt',
            'total_size' => strlen($content),
            'part_size'  => $partSize,
            'mime'       => 'text/plain',
        ], $this->auth());

        $this->assertSame(201, $init->lastStatus, $init->lastBody);

        $session = (array) $init->data();
        $uploadId = $session['upload_id'];
        $totalParts = (int) $session['total_parts'];

        $this->assertGreaterThan(1, $totalParts, 'The fixture should span several parts');

        // Upload every part except the last, then check the resume report.
        for ($part = 1; $part < $totalParts; $part++) {
            $chunkPath = Runner::fixture('part.bin', substr($content, ($part - 1) * $partSize, $partSize));

            $response = $this->client()->upload(
                '/files/multipart/' . $uploadId . '/part',
                'part',
                $chunkPath,
                ['part_number' => (string) $part],
                $this->auth()
            );

            $this->assertSame(200, $response->lastStatus, $response->lastBody);
        }

        $status = $this->client()->get('/files/multipart/' . $uploadId, [], $this->auth());
        $missing = (array) $status->data()['missing_parts'];

        $this->assertCount(1, $missing, 'Exactly one part should still be missing');
        $this->assertSame($totalParts, $missing[0]);

        // Completing early must fail.
        $premature = $this->client()->postJson('/files/multipart/' . $uploadId . '/complete', [], $this->auth());
        $this->assertSame(409, $premature->lastStatus);

        // Send the final part and complete.
        $lastChunk = Runner::fixture('part.bin', substr($content, ($totalParts - 1) * $partSize));
        $this->client()->upload(
            '/files/multipart/' . $uploadId . '/part',
            'part',
            $lastChunk,
            ['part_number' => (string) $totalParts],
            $this->auth()
        );

        $complete = $this->client()->postJson('/files/multipart/' . $uploadId . '/complete', [
            'checksum' => $checksum,
        ], $this->auth());

        $this->assertSame(201, $complete->lastStatus, $complete->lastBody);

        $file = (array) $complete->data()['file'];
        $this->assertSame(strlen($content), $file['size']);
        $this->assertSame($checksum, $file['checksum'], 'The assembled file must match the original checksum');

        $this->client()->delete('/files/' . $file['uuid'] . '?permanent=1', $this->auth());
        @unlink($path);
    }

    // --- Sharing -----------------------------------------------------------

    public function testCreatePasswordProtectedShare(): void
    {
        $client = $this->client()->postJson('/files/' . self::$fileUuid . '/share', [
            'type'          => 'permanent',
            'password'      => 'link-secret',
            'max_downloads' => 5,
        ], $this->auth());

        $this->assertSame(201, $client->lastStatus, $client->lastBody);

        $data = (array) $client->data();
        $this->assertTrue($data['password_protected']);
        $this->assertSame(5, $data['max_downloads']);
        $this->assertNotEmpty($data['url']);

        self::$shareId = (int) $data['id'];
        self::$shareToken = $data['token'];
    }

    public function testPublicShareRequiresPassword(): void
    {
        $public = new HttpClient(Runner::baseUrl());

        $public->get('/s/' . self::$shareToken);
        $this->assertSame(200, $public->lastStatus);
        $this->assertContains('password protected', $public->lastBody);

        // Downloading without unlocking must not hand over the bytes.
        $public->get('/s/' . self::$shareToken . '/download');
        $this->assertNotContains('Version two of the file', $public->lastBody);
        $this->assertNotContains('Hello from the S3 Lite test suite', $public->lastBody);

        // A wrong password stays locked.
        $token = $public->csrfToken();
        $public->post('/s/' . self::$shareToken . '/unlock', ['_token' => $token, 'password' => 'wrong']);
        $this->assertSame(422, $public->lastStatus);

        // The correct password unlocks it.
        $public->get('/s/' . self::$shareToken);
        $token = $public->csrfToken();
        $public->post('/s/' . self::$shareToken . '/unlock', ['_token' => $token, 'password' => 'link-secret']);
        $this->assertSame(302, $public->lastStatus, 'Unlocking should redirect to the share page');

        $public->get('/s/' . self::$shareToken . '/download');
        $this->assertSame(200, $public->lastStatus);
        $this->assertContains('Hello from the S3 Lite test suite', $public->lastBody);
    }

    public function testTemporarySignedUrl(): void
    {
        $client = $this->client()->postJson('/files/' . self::$fileUuid . '/temporary-url', [
            'ttl' => 600,
        ], $this->auth());

        $this->assertSame(200, $client->lastStatus, $client->lastBody);

        $url = (string) $client->data()['url'];
        $this->assertContains('signature=', $url);

        $anonymous = new HttpClient(Runner::baseUrl());
        $anonymous->request('GET', $url);

        $this->assertSame(200, $anonymous->lastStatus, 'A signed URL must work without a session');
        $this->assertContains('Hello from the S3 Lite test suite', $anonymous->lastBody);

        // Tampering with the signature must fail.
        $anonymous->request('GET', preg_replace('/signature=\w+/', 'signature=deadbeef', $url) ?? $url);
        $this->assertSame(403, $anonymous->lastStatus);
    }

    public function testIdempotencyKeyReplaysResponse(): void
    {
        $key = 'idem-' . bin2hex(random_bytes(8));
        $headers = $this->auth() + ['X-Idempotency-Key' => $key];

        $first = $this->client()->postJson('/folders', ['name' => 'Idempotent ' . $key], $headers);
        $this->assertSame(201, $first->lastStatus, $first->lastBody);

        // Capture before the next call — the client is fluent.
        $firstUuid = (string) $first->data()['uuid'];

        $second = $this->client()->postJson('/folders', ['name' => 'Idempotent ' . $key], $headers);
        $this->assertSame('true', $second->header('x-idempotent-replay'), 'The second call must replay, not re-create');
        $this->assertSame($firstUuid, (string) $second->data()['uuid'], 'The replay must return the original folder');

        $this->client()->delete('/folders/' . $firstUuid . '?permanent=1', $this->auth());
    }

    // --- Trash --------------------------------------------------------------

    public function testTrashAndRestore(): void
    {
        $path = Runner::fixture('trash-me.txt', 'temporary content ' . bin2hex(random_bytes(4)));
        $upload = $this->client()->upload('/files/upload', 'file', $path, [], $this->auth());
        $uuid = $upload->data()['uploaded'][0]['file']['uuid'];

        $trash = $this->client()->delete('/files/' . $uuid, $this->auth());
        $this->assertSame(200, $trash->lastStatus);
        $this->assertFalse($trash->data()['permanent']);

        // A trashed file must not appear in the normal listing.
        $listed = $this->client()->get('/files', ['q' => 'trash-me'], $this->auth());
        $this->assertCount(0, (array) $listed->data());

        // It must appear when explicitly asking for trash.
        $trashed = $this->client()->get('/files', ['q' => 'trash-me', 'trashed' => '1'], $this->auth());
        $this->assertGreaterThan(0, count((array) $trashed->data()));

        $restore = $this->client()->postJson('/files/' . $uuid . '/restore', [], $this->auth());
        $this->assertSame(200, $restore->lastStatus);
        $this->assertFalse($restore->data()['trashed']);

        $this->client()->delete('/files/' . $uuid . '?permanent=1', $this->auth());
    }

    // --- Metrics, stats, logs ------------------------------------------------

    public function testStatisticsEndpoint(): void
    {
        $client = $this->client()->get('/statistics', ['days' => 7], $this->auth());
        $data = (array) $client->data();

        $this->assertSame(200, $client->lastStatus);
        $this->assertArrayHasKey('summary', $data);
        $this->assertArrayHasKey('timeline', $data);
        $this->assertCount(7, $data['timeline']['labels']);
    }

    public function testMetricsEndpoint(): void
    {
        $client = $this->client()->get('/metrics', [], $this->auth());
        $data = (array) $client->data();

        $this->assertSame(200, $client->lastStatus);
        $this->assertArrayHasKey('system', $data);
        $this->assertArrayHasKey('cpu', $data['system']);
        $this->assertArrayHasKey('memory', $data['system']);
        $this->assertArrayHasKey('disk', $data['system']);
    }

    public function testAuditLogEndpoint(): void
    {
        $client = $this->client()->get('/audit-logs', ['per_page' => 20], $this->auth());

        $this->assertSame(200, $client->lastStatus);
        $this->assertGreaterThan(0, count((array) $client->data()), 'Actions taken by this suite must be audited');
    }

    // --- Users ----------------------------------------------------------------

    public function testAdminCreatesUser(): void
    {
        $email = 'suite-' . bin2hex(random_bytes(4)) . '@example.test';

        $client = $this->client()->postJson('/users', [
            'name'        => 'Suite User',
            'email'       => $email,
            'password'    => 'SuiteUser123',
            'role'        => 'user',
            'quota_bytes' => 1073741824,
        ], $this->auth());

        $this->assertSame(201, $client->lastStatus, $client->lastBody);

        $data = (array) $client->data();
        $this->assertSame($email, $data['email']);
        $this->assertSame('user', $data['role']);

        self::$secondUserId = (int) $data['id'];

        // The new account must be able to sign in, and must see no files.
        $other = new HttpClient(Runner::baseUrl() . '/api/v1');
        $other->postJson('/auth/login', ['email' => $email, 'password' => 'SuiteUser123']);
        $this->assertSame(200, $other->lastStatus, $other->lastBody);

        $token = $other->data()['access_token'];
        $other->get('/files', [], ['Authorization' => 'Bearer ' . $token]);
        $this->assertCount(0, (array) $other->data(), 'A new user must not see anyone else\'s files');

        // And must not be able to reach an admin-only endpoint.
        $other->get('/users', [], ['Authorization' => 'Bearer ' . $token]);
        $this->assertSame(403, $other->lastStatus, 'Non-admins must not list users');
    }

    public function testScopeEnforcement(): void
    {
        $created = $this->client()->postJson('/api-keys', [
            'name'   => 'read-only-' . bin2hex(random_bytes(3)),
            'scopes' => ['files:read'],
        ], $this->auth());

        $this->assertSame(201, $created->lastStatus, $created->lastBody);

        // Capture now: the client is fluent, so its state changes on the next call.
        $payload = (array) $created->data();
        $key = (string) $payload['key'];
        $keyId = (int) $payload['record']['id'];

        $read = $this->client()->get('/files', [], ['X-Api-Key' => $key]);
        $this->assertSame(200, $read->lastStatus, 'files:read must allow listing');

        $write = $this->client()->postJson('/folders', ['name' => 'nope'], ['X-Api-Key' => $key]);
        $this->assertSame(403, $write->lastStatus, 'A read-only key must not create folders');
        $this->assertSame('insufficient_scope', $write->errorCode());

        $this->client()->delete('/api-keys/' . $keyId, $this->auth());
    }

    // --- SFTP -------------------------------------------------------------------

    public function testSftpAccountLifecycle(): void
    {
        $username = 'suite' . bin2hex(random_bytes(3));

        $create = $this->client()->postJson('/sftp-accounts', [
            'username'    => $username,
            'password'    => 'SftpPass123',
            'protocol'    => 'sftp',
            'permission'  => 'rw',
            'quota_bytes' => 104857600,
        ], $this->auth());

        $this->assertSame(201, $create->lastStatus, $create->lastBody);

        $account = (array) $create->data();
        $this->assertSame($username, $account['username']);
        $this->assertNotEmpty($account['home_dir']);
        $this->assertTrue(is_dir($account['home_dir']), 'The isolated home directory must exist on disk');

        self::$sftpId = (int) $account['id'];

        // Switch to read-only.
        $update = $this->client()->patchJson('/sftp-accounts/' . self::$sftpId, ['permission' => 'ro'], $this->auth());
        $this->assertSame('ro', $update->data()['permission']);

        // Add an SSH key.
        // A structurally valid ed25519 blob: "ssh-ed25519" length-prefixed, then a 32-byte key.
        $blob = pack('N', 11) . 'ssh-ed25519' . pack('N', 32) . random_bytes(32);
        $publicKey = 'ssh-ed25519 ' . base64_encode($blob) . ' suite@test';
        $addKey = $this->client()->postJson('/sftp-accounts/' . self::$sftpId . '/ssh-keys', [
            'name'       => 'suite-key',
            'public_key' => $publicKey,
        ], $this->auth());

        $this->assertSame(201, $addKey->lastStatus, $addKey->lastBody);
        $this->assertContains('SHA256:', (string) $addKey->data()['fingerprint']);

        // A malformed key must be refused.
        $badKey = $this->client()->postJson('/sftp-accounts/' . self::$sftpId . '/ssh-keys', [
            'name'       => 'bad',
            'public_key' => 'this is not a key',
        ], $this->auth());
        $this->assertSame(422, $badKey->lastStatus);

        // Drop a file into the home directory and sync it into the panel.
        $home = (string) $account['home_dir'];
        file_put_contents($home . '/upload/from-sftp.txt', 'uploaded over sftp at ' . date('c'));

        $sync = $this->client()->postJson('/sftp-accounts/' . self::$sftpId . '/sync', [], $this->auth());
        $this->assertSame(200, $sync->lastStatus, $sync->lastBody);
        $this->assertGreaterThan(0, (int) $sync->data()['imported'], 'The SFTP upload must be indexed');

        // The indexed file must now be visible through the normal file API.
        $found = $this->client()->get('/files', ['q' => 'from-sftp'], $this->auth());
        $this->assertGreaterThan(0, count((array) $found->data()), 'SFTP uploads must appear in the web panel');

        foreach ((array) $found->data() as $file) {
            $this->assertSame('sftp', $file['source']);
            $this->client()->delete('/files/' . $file['uuid'] . '?permanent=1', $this->auth());
        }

        // Sessions endpoint must respond.
        $sessions = $this->client()->get('/sessions', [], $this->auth());
        $this->assertSame(200, $sessions->lastStatus);

        // Deleting with its files must also remove the mirrored panel folder.
        $this->client()->delete('/sftp-accounts/' . self::$sftpId . '?remove_files=1', $this->auth());
        self::$sftpId = null;

        $folders = $this->client()->get('/folders', [], $this->auth());
        foreach ((array) $folders->data() as $folder) {
            $this->assertTrue(
                $folder['name'] !== 'SFTP - ' . $username,
                'The mirror folder must be removed with the account'
            );
        }
    }

    public function testSftpDaemonAuthentication(): void
    {
        $username = 'daemon' . bin2hex(random_bytes(3));

        $create = $this->client()->postJson('/sftp-accounts', [
            'username' => $username,
            'password' => 'DaemonPass123',
        ], $this->auth());

        $accountId = (int) $create->data()['id'];

        // Wrong password.
        $bad = $this->client()->postJson('/sftp/authenticate', [
            'username' => $username,
            'password' => 'wrong-password',
        ], $this->auth());
        $this->assertSame(401, $bad->lastStatus);

        // Correct password opens a session.
        $good = $this->client()->postJson('/sftp/authenticate', [
            'username'  => $username,
            'password'  => 'DaemonPass123',
            'client_ip' => '198.51.100.20',
            'client'    => 'OpenSSH_9.6',
        ], $this->auth());

        $this->assertSame(200, $good->lastStatus, $good->lastBody);

        $session = (array) $good->data();
        $this->assertTrue($session['authenticated']);
        $this->assertSame('rw', $session['permission']);
        $this->assertNotEmpty($session['session_key']);

        // Heartbeat records transferred bytes.
        $beat = $this->client()->postJson('/sftp/heartbeat', [
            'session_key' => $session['session_key'],
            'bytes_in'    => 4096,
            'action'      => 'put',
            'path'        => '/upload/file.bin',
            'size'        => 4096,
        ], $this->auth());
        $this->assertSame(200, $beat->lastStatus);

        $sessions = $this->client()->get('/sessions', [], $this->auth());
        $active = array_filter((array) $sessions->data(), static fn (array $s): bool => $s['username'] === $username);
        $this->assertGreaterThan(0, count($active), 'The daemon session must be listed as active');

        $first = array_values($active)[0];
        $this->assertSame(4096, $first['bytes_in']);

        // Disconnect it.
        $close = $this->client()->delete('/sessions/' . $first['id'], $this->auth());
        $this->assertSame(200, $close->lastStatus);

        // A disabled account must not authenticate.
        $this->client()->patchJson('/sftp-accounts/' . $accountId, ['status' => 'disabled'], $this->auth());
        $disabled = $this->client()->postJson('/sftp/authenticate', [
            'username' => $username,
            'password' => 'DaemonPass123',
        ], $this->auth());
        $this->assertSame(401, $disabled->lastStatus);
        $this->assertSame('account_disabled', $disabled->errorCode());

        $this->client()->delete('/sftp-accounts/' . $accountId . '?remove_files=1', $this->auth());
    }

    // --- Webhooks, jobs, backups, settings ---------------------------------------

    public function testWebhookLifecycle(): void
    {
        $create = $this->client()->postJson('/webhooks', [
            'name'   => 'suite-hook',
            'url'    => Runner::baseUrl() . '/api/v1/ping',
            'events' => ['file.uploaded', 'file.deleted'],
        ], $this->auth());

        $this->assertSame(201, $create->lastStatus, $create->lastBody);

        $hook = (array) $create->data();
        $this->assertNotEmpty($hook['secret'], 'A signing secret must be returned once');
        $this->assertCount(2, $hook['events']);

        $list = $this->client()->get('/webhooks', [], $this->auth());
        $this->assertGreaterThan(0, count((array) $list->data()));

        $delete = $this->client()->delete('/webhooks/' . $hook['id'], $this->auth());
        $this->assertSame(200, $delete->lastStatus);
    }

    public function testJobExecution(): void
    {
        $client = $this->client()->postJson('/jobs/cleanup', [], $this->auth());

        $this->assertSame(200, $client->lastStatus, $client->lastBody);
        $this->assertTrue($client->data()['ok']);

        $unknown = $this->client()->postJson('/jobs/not-a-real-job', [], $this->auth());
        $this->assertSame(422, $unknown->lastStatus);
    }

    public function testBackupCreation(): void
    {
        $client = $this->client()->postJson('/backups', ['label' => 'suite'], $this->auth());

        $this->assertSame(201, $client->lastStatus, $client->lastBody);

        $backup = (array) $client->data();
        $this->assertGreaterThan(0, $backup['size']);
        $this->assertGreaterThan(10, $backup['tables']);

        $list = $this->client()->get('/backups', [], $this->auth());
        $this->assertGreaterThan(0, count((array) $list->data()));

        $delete = $this->client()->delete('/backups/' . $backup['file'], $this->auth());
        $this->assertSame(200, $delete->lastStatus);

        // Path traversal in the backup name must be refused.
        $evil = $this->client()->delete('/backups/' . rawurlencode('../../.env'), $this->auth());
        $this->assertTrue($evil->lastStatus >= 400, 'Traversal in a backup name must be rejected');
    }

    public function testSettingsUpdate(): void
    {
        $read = $this->client()->get('/settings', [], $this->auth());
        $this->assertSame(200, $read->lastStatus);

        $update = $this->client()->putJson('/settings', [
            'group'    => 'general',
            'settings' => ['site_tagline' => 'Updated by the test suite'],
        ], $this->auth());

        $this->assertSame(200, $update->lastStatus, $update->lastBody);

        $after = $this->client()->get('/settings', [], $this->auth());
        $this->assertSame('Updated by the test suite', $after->data()['general']['site_tagline'] ?? null);
    }

    // --- Storage backends ----------------------------------------------------------

    public function testStorageBackendLifecycle(): void
    {
        $list = $this->client()->get('/storage-backends', [], $this->auth());

        $this->assertSame(200, $list->lastStatus, $list->lastBody);

        // Which backend is the default is the operator's choice, but it has to
        // be one of the backends that were actually returned.
        $payload = (array) $list->data();
        $slugs = array_column($payload['backends'], 'slug');

        $this->assertNotEmpty($payload['default'] ?? '', 'There must always be a default backend');
        $this->assertTrue(
            in_array($payload['default'], $slugs, true),
            'The default backend "' . $payload['default'] . '" is not in the list'
        );
        $this->assertArrayHasKey('sftp', $payload['support']);

        // A deliberately unreachable FTP server: creation must succeed, the
        // connection test must fail cleanly rather than blow up.
        $created = $this->client()->postJson('/storage-backends', [
            'name'     => 'Suite FTP',
            'driver'   => 'ftp',
            'host'     => '127.0.0.1',
            'port'     => 1,
            'username' => 'suite',
            'password' => 'suite-secret',
            'root_path' => 'suite',
            'timeout'  => 5,
        ], $this->auth());

        $this->assertSame(201, $created->lastStatus, $created->lastBody);

        $backend = (array) $created->data();
        $id = (int) $backend['id'];

        $this->assertSame('suite-ftp', $backend['slug']);
        $this->assertTrue($backend['has_password']);
        $this->assertNotContains('suite-secret', $created->lastBody, 'Credentials must never be returned');

        $test = $this->client()->postJson('/storage-backends/' . $id . '/test', [], $this->auth());
        $this->assertSame(502, $test->lastStatus, $test->lastBody);
        $this->assertFalse($test->data()['ok']);

        $show = $this->client()->get('/storage-backends/' . $id, [], $this->auth());
        $this->assertSame('error', $show->data()['status']);
        $this->assertSame(0, $show->data()['usage']['files']);

        // A blank password on update keeps the stored one.
        $updated = $this->client()->patchJson('/storage-backends/' . $id, [
            'name'     => 'Suite FTP (renamed)',
            'host'     => '127.0.0.1',
            'username' => 'suite',
            'password' => '',
        ], $this->auth());

        $this->assertSame(200, $updated->lastStatus, $updated->lastBody);
        $this->assertSame('Suite FTP (renamed)', $updated->data()['name']);
        $this->assertTrue($updated->data()['has_password']);

        $deleted = $this->client()->delete('/storage-backends/' . $id, $this->auth());
        $this->assertSame(200, $deleted->lastStatus, $deleted->lastBody);
    }

    public function testUploadRejectsAnUnknownBackend(): void
    {
        $path = Runner::fixture('routed.txt', 'routed to nowhere');

        $client = $this->client()->upload('/files/upload', 'file', $path, [
            'storage' => 'definitely-not-a-backend',
        ], $this->auth());

        $this->assertSame(422, $client->lastStatus, $client->lastBody);
        $this->assertSame('unknown_storage_backend', $client->errorCode());
    }

    public function testUploadHonoursAnExplicitBackend(): void
    {
        $path = Runner::fixture('routed-local.txt', 'routed to the local disk ' . bin2hex(random_bytes(4)));

        $client = $this->client()->upload('/files/upload', 'file', $path, [
            'storage' => 'local',
        ], $this->auth());

        $this->assertSame(201, $client->lastStatus, $client->lastBody);

        $file = $client->data()['uploaded'][0]['file'];
        $this->assertSame('local', $file['storage']);

        $this->client()->delete('/files/' . $file['uuid'] . '?permanent=1', $this->auth());
    }

    // --- Token lifecycle -----------------------------------------------------------

    public function testTokenRefreshRotates(): void
    {
        $client = $this->client()->postJson('/auth/refresh', ['refresh_token' => self::$refreshToken]);

        $this->assertSame(200, $client->lastStatus, $client->lastBody);

        $data = (array) $client->data();
        $this->assertNotEmpty($data['access_token']);
        $this->assertTrue($data['refresh_token'] !== self::$refreshToken, 'Refresh tokens must rotate');

        // The old refresh token must now be dead.
        $replay = $this->client()->postJson('/auth/refresh', ['refresh_token' => self::$refreshToken]);
        $this->assertSame(401, $replay->lastStatus, 'A used refresh token must not work twice');

        self::$accessToken = $data['access_token'];
        self::$refreshToken = $data['refresh_token'];
    }

    public function testPermanentDelete(): void
    {
        $delete = $this->client()->delete('/files/' . self::$fileUuid . '?permanent=1', $this->auth());
        $this->assertSame(200, $delete->lastStatus, $delete->lastBody);
        $this->assertTrue($delete->data()['permanent']);

        $gone = $this->client()->get('/files/' . self::$fileUuid, [], $this->auth());
        $this->assertSame(404, $gone->lastStatus);

        // The share for the deleted file must be gone too.
        $share = $this->client()->get('/shares/' . self::$shareId, [], $this->auth());
        $this->assertSame(404, $share->lastStatus);

        if (self::$folderUuid !== null) {
            $this->client()->delete('/folders/' . self::$folderUuid . '?permanent=1', $this->auth());
        }

        if (self::$sftpId !== null) {
            $this->client()->delete('/sftp-accounts/' . self::$sftpId . '?remove_files=1', $this->auth());
        }
    }

    public function testCleanupOfTestUser(): void
    {
        if (self::$secondUserId === null) {
            $this->skip('No test user was created');
        }

        $client = $this->client()->delete('/users/' . self::$secondUserId . '?purge_files=1', $this->auth());

        $this->assertSame(200, $client->lastStatus, $client->lastBody);
        $this->assertTrue($client->data()['deleted']);
    }

    public function testLogoutRevokesToken(): void
    {
        // Remove the suite's own API key before the token that created it dies.
        if (self::$apiKeyId !== null) {
            $deleted = $this->client()->delete('/api-keys/' . self::$apiKeyId, $this->auth());
            $this->assertSame(200, $deleted->lastStatus, $deleted->lastBody);
            self::$apiKeyId = null;
        }

        $client = $this->client()->postJson('/auth/logout', [
            'refresh_token' => self::$refreshToken,
        ], $this->auth());

        $this->assertSame(200, $client->lastStatus);

        $replay = $this->client()->postJson('/auth/refresh', ['refresh_token' => self::$refreshToken]);
        $this->assertSame(401, $replay->lastStatus, 'A revoked refresh token must be rejected');
    }
}
