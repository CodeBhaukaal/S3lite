<?php
declare(strict_types=1);

namespace Tests;

/**
 * Drives the web panel the way a browser does: real sessions, real CSRF tokens.
 */
final class WebTest extends TestCase
{
    private static ?HttpClient $guest = null;
    private static ?HttpClient $panel = null;

    public function name(): string
    {
        return 'Web — panel and security';
    }

    public function tests(): array
    {
        return [
            'testGuestIsRedirectedToLogin',
            'testLoginPageRenders',
            'testCsrfIsEnforced',
            'testLoginWithBadPasswordFails',
            'testLoginSucceeds',
            'testDashboardRenders',
            'testFilesPageRenders',
            'testUploadThroughThePanel',
            'testTrashPageRenders',
            'testSharesPageRenders',
            'testProfilePageRenders',
            'testApiKeysPageRenders',
            'testSftpPageRenders',
            'testWebhooksPageRenders',
            'testApiDocsPageRenders',
            'testAdminPagesRender',
            'testAdminSettingsSave',
            'testFileActionMenuIsCompleteAndUnclipped',
            'testInterfaceIsMobileReady',
            'testCronEntryPoint',
            'testNoEmojiInTheInterface',
            'testSecurityHeadersArePresent',
            'testUnknownPageReturns404',
            'testStorageDirectoryIsNotBrowsable',
            'testEnvFileIsNotServed',
            'testLogoutEndsTheSession',
        ];
    }

    private function guest(): HttpClient
    {
        return self::$guest ??= new HttpClient(Runner::baseUrl());
    }

    private function panel(): HttpClient
    {
        return self::$panel ??= new HttpClient(Runner::baseUrl());
    }

    // --- Guest ------------------------------------------------------------

    public function testGuestIsRedirectedToLogin(): void
    {
        $client = $this->guest()->get('/dashboard');

        $this->assertSame(302, $client->lastStatus);
        $this->assertContains('/login', (string) $client->location());
    }

    public function testLoginPageRenders(): void
    {
        $client = $this->guest()->get('/login');

        $this->assertSame(200, $client->lastStatus);
        $this->assertContains('Welcome back', $client->lastBody);
        $this->assertContains('csrf-token', $client->lastBody);
        $this->assertNotEmpty($client->csrfToken());
    }

    public function testCsrfIsEnforced(): void
    {
        $client = $this->guest()->post('/login', [
            'email'    => Runner::adminEmail(),
            'password' => Runner::adminPassword(),
            '_token'   => 'obviously-wrong-token',
        ]);

        $this->assertSame(403, $client->lastStatus, 'A bad CSRF token must be refused');
        $this->assertContains('Session expired', $client->lastBody);
    }

    public function testLoginWithBadPasswordFails(): void
    {
        $client = $this->panel()->get('/login');
        $token = $client->csrfToken();

        $client->post('/login', [
            'email'    => Runner::adminEmail(),
            'password' => 'wrong-password-here',
            '_token'   => $token,
        ]);

        $this->assertSame(302, $client->lastStatus);

        // Following the redirect should land back on the login page, not the dashboard.
        $client->get('/login');
        $this->assertContains('do not match our records', $client->lastBody);
    }

    public function testLoginSucceeds(): void
    {
        $client = $this->panel()->get('/login');
        $token = $client->csrfToken();

        $client->post('/login', [
            'email'    => Runner::adminEmail(),
            'password' => Runner::adminPassword(),
            '_token'   => $token,
        ]);

        $this->assertSame(302, $client->lastStatus, $client->lastBody);
        $this->assertContains('/dashboard', (string) $client->location());
    }

    // --- Panel pages -------------------------------------------------------

    public function testDashboardRenders(): void
    {
        $client = $this->panel()->get('/dashboard');

        $this->assertSame(200, $client->lastStatus);
        $this->assertContains('Welcome back', $client->lastBody);
        $this->assertContains('Storage used', $client->lastBody);
        $this->assertContains('chart-timeline', $client->lastBody);
    }

    public function testFilesPageRenders(): void
    {
        $client = $this->panel()->get('/files');

        $this->assertSame(200, $client->lastStatus);
        $this->assertContains('Drop files here', $client->lastBody);
        $this->assertContains('New folder', $client->lastBody);
    }

    public function testUploadThroughThePanel(): void
    {
        $client = $this->panel()->get('/files');
        $token = $client->csrfToken();

        $path = Runner::fixture('panel-upload.txt', 'uploaded through the web panel ' . bin2hex(random_bytes(4)));

        $client->upload('/files/upload', 'file', $path, [
            '_token'    => $token,
            'folder_id' => '',
        ], ['X-Requested-With' => 'XMLHttpRequest']);

        $this->assertSame(200, $client->lastStatus, $client->lastBody);
        $this->assertTrue($client->succeeded());

        $uploaded = (array) $client->data()['uploaded'];
        $this->assertCount(1, $uploaded);

        $uuid = $uploaded[0]['file']['uuid'];

        // It must show up on the files page.
        $client->get('/files', ['q' => 'panel-upload']);
        $this->assertContains('panel-upload.txt', $client->lastBody);

        // Detail page.
        $client->get('/files/' . $uuid);
        $this->assertSame(200, $client->lastStatus);
        $this->assertContains('SHA-256', $client->lastBody);

        // Download.
        $client->get('/files/' . $uuid . '/download');
        $this->assertSame(200, $client->lastStatus);
        $this->assertContains('uploaded through the web panel', $client->lastBody);

        // Trash it, then purge it so the suite leaves nothing behind.
        $client->get('/files');
        $token = $client->csrfToken();
        $client->post('/files/' . $uuid . '/trash', ['_token' => $token]);
        $this->assertSame(302, $client->lastStatus);

        $client->get('/trash');
        $token = $client->csrfToken();
        $client->post('/trash/files/' . $uuid . '/purge', ['_token' => $token]);
        $this->assertSame(302, $client->lastStatus);
    }

    public function testTrashPageRenders(): void
    {
        $client = $this->panel()->get('/trash');

        $this->assertSame(200, $client->lastStatus);
        $this->assertContains('Trash', $client->lastBody);
        $this->assertContains('Empty trash', $client->lastBody);
    }

    public function testSharesPageRenders(): void
    {
        $client = $this->panel()->get('/shares');

        $this->assertSame(200, $client->lastStatus);
        $this->assertContains('Share links', $client->lastBody);
    }

    public function testProfilePageRenders(): void
    {
        $client = $this->panel()->get('/settings');

        $this->assertSame(200, $client->lastStatus);
        $this->assertContains('Profile &amp; security', $client->lastBody);
        $this->assertContains('Two-factor authentication', $client->lastBody);
    }

    public function testApiKeysPageRenders(): void
    {
        $client = $this->panel()->get('/settings/api-keys');

        $this->assertSame(200, $client->lastStatus);
        $this->assertContains('API keys', $client->lastBody);
    }

    public function testSftpPageRenders(): void
    {
        $client = $this->panel()->get('/settings/sftp');

        $this->assertSame(200, $client->lastStatus);
        $this->assertContains('FTP / SFTP accounts', $client->lastBody);
        $this->assertContains('Connection details', $client->lastBody);
    }

    public function testWebhooksPageRenders(): void
    {
        $client = $this->panel()->get('/settings/webhooks');

        $this->assertSame(200, $client->lastStatus);
        $this->assertContains('Webhooks', $client->lastBody);
        $this->assertContains('X-S3Lite-Signature', $client->lastBody);
    }

    public function testApiDocsPageRenders(): void
    {
        $client = $this->panel()->get('/api-docs');

        $this->assertSame(200, $client->lastStatus);
        $this->assertContains('API reference', $client->lastBody);
        $this->assertContains('Resumable uploads', $client->lastBody);
        $this->assertContains('X-Api-Key', $client->lastBody);
    }

    public function testAdminPagesRender(): void
    {
        $pages = [
            '/admin'            => 'Platform overview',
            '/admin/users'      => 'Users',
            '/admin/roles'      => 'Roles &amp; permissions',
            '/admin/files'      => 'All files',
            '/admin/storage'    => 'Storage backends',
            '/admin/sftp'       => 'FTP / FTPS / SFTP',
            '/admin/monitoring' => 'Monitoring',
            '/admin/jobs'       => 'Jobs &amp; backups',
            '/admin/logs'       => 'Logs',
            '/admin/ip-rules'   => 'IP allow',
            '/admin/settings'   => 'Platform settings',
        ];

        foreach ($pages as $path => $marker) {
            $client = $this->panel()->get($path);

            $this->assertSame(200, $client->lastStatus, $path . ' returned ' . $client->lastStatus);
            $this->assertContains($marker, $client->lastBody, $path . ' is missing its heading');
            $this->assertNotContains('Fatal error', $client->lastBody, $path . ' produced a fatal error');
            $this->assertNotContains('Warning:', $client->lastBody, $path . ' produced a warning');
        }
    }

    public function testAdminSettingsSave(): void
    {
        $client = $this->panel()->get('/admin/settings');
        $token = $client->csrfToken();

        $client->post('/admin/settings', [
            '_token'       => $token,
            'group'        => 'branding',
            'site_name'    => 'S3 Lite',
            'site_tagline' => 'Self-hosted object storage',
            'accent_color' => '#4f7cff',
        ]);

        $this->assertSame(302, $client->lastStatus);

        $client->get('/admin/settings');
        $this->assertContains('Self-hosted object storage', $client->lastBody);
    }

    // --- Interface rules ----------------------------------------------------

    /**
     * The card action menu used to be clipped by the card's own `overflow:
     * hidden`, hiding half its entries. Guard both the full item list and the
     * styling/scripting that keeps the menu out of clipping ancestors.
     */
    public function testFileActionMenuIsCompleteAndUnclipped(): void
    {
        $client = $this->panel()->get('/files');
        $token = $client->csrfToken();

        $path = Runner::fixture('menu-check.txt', 'menu regression fixture ' . bin2hex(random_bytes(4)));
        $client->upload('/files/upload', 'file', $path, ['_token' => $token], ['X-Requested-With' => 'XMLHttpRequest']);
        $this->assertSame(200, $client->lastStatus, $client->lastBody);

        $uuid = (string) $client->data()['uploaded'][0]['file']['uuid'];

        try {
            $expected = ['Open', 'Download', 'Rename', 'Move', 'Tags', 'Duplicate', 'Share link', 'Move to trash'];

            foreach (['grid', 'list'] as $view) {
                $body = $this->panel()->get('/files', ['view' => $view])->lastBody;

                foreach ($expected as $item) {
                    // Entries are rendered as either links or submit buttons.
                    $present = str_contains($body, $item . '</a>') || str_contains($body, $item . '</button>');

                    if (!$present) {
                        throw new AssertionFailed($view . ' view is missing the "' . $item . '" action');
                    }
                }
            }

            // The card must not clip its own menu.
            $css = (new HttpClient(Runner::baseUrl()))->get('/assets/css/app.css')->lastBody;
            $this->assertNotEmpty($css);

            if (preg_match('/\.file-card\s*\{([^}]*)\}/', $css, $m) !== 1) {
                throw new AssertionFailed('The .file-card rule was not found in the stylesheet');
            }

            $this->assertNotContains('overflow: hidden', $m[1], '.file-card must not clip its action menu');
            $this->assertContains('.dropdown__menu.is-floating', $css, 'The floating-menu style must exist');

            $js = (new HttpClient(Runner::baseUrl()))->get('/assets/js/app.js')->lastBody;
            $this->assertContains('is-floating', $js, 'app.js must lift open menus out of clipping ancestors');
            $this->assertContains('document.body.appendChild(menu)', $js, 'An open menu must be moved to <body>');

            // A stale stylesheet paired with fresh script is what broke this
            // before, so assets must carry a cache buster.
            $page = $this->panel()->get('/files')->lastBody;
            $this->assertContains('assets/css/app.css?v=', $page, 'The stylesheet must be cache-busted');
            $this->assertContains('assets/js/app.js?v=', $page, 'The script must be cache-busted');
        } finally {
            // Clean up even when an assertion fails, so a red run leaves nothing behind.
            $page = $this->panel()->get('/files');
            $page->post('/files/' . $uuid . '/trash', ['_token' => $page->csrfToken()]);
            $page->get('/trash');
            $page->post('/trash/files/' . $uuid . '/purge', ['_token' => $page->csrfToken()]);
        }
    }

    /**
     * The panel has to work on a phone: a viewport meta tag on every layout,
     * a dismissable sidebar drawer, and tables that stack into labelled cards
     * instead of scrolling sideways.
     */
    public function testInterfaceIsMobileReady(): void
    {
        // The panel client is signed in; the guest one covers the auth layout.
        $pages = [
            '/dashboard'   => $this->panel(),
            '/files'       => $this->panel(),
            '/admin/users' => $this->panel(),
            '/login'       => new HttpClient(Runner::baseUrl()),
        ];

        foreach ($pages as $path => $client) {
            $body = $client->get($path)->lastBody;

            $this->assertContains(
                'name="viewport" content="width=device-width, initial-scale=1"',
                $body,
                $path . ' is missing the responsive viewport tag'
            );
        }

        $css = (new HttpClient(Runner::baseUrl()))->get('/assets/css/app.css')->lastBody;
        $js = (new HttpClient(Runner::baseUrl()))->get('/assets/js/app.js')->lastBody;

        // Phone layout rules.
        $this->assertContains('@media (max-width: 700px)', $css, 'A phone breakpoint must exist');
        $this->assertContains('.table thead { display: none; }', $css, 'Tables must stack on phones');
        $this->assertContains('content: attr(data-label)', $css, 'Stacked cells must show their column heading');
        $this->assertContains('body.sidebar-open', $css, 'The drawer must lock background scrolling');

        // Controls that would otherwise refuse to shrink.
        $this->assertContains('.grid > * { min-width: 0; }', $css, 'Grid items must be allowed to shrink');

        // Drawer behaviour.
        $this->assertContains('sidebar-scrim', $js, 'The drawer needs a scrim to tap away');
        $this->assertContains('App.sidebar', $js, 'The sidebar helper must exist');

        // Column headings are copied into cells for the stacked layout.
        $this->assertContains('labelTableCells', $js, 'Table cells must be labelled for the stacked layout');
        $this->assertContains("setAttribute('data-label'", $js, 'Cell labels must be applied from the column headings');
    }

    /**
     * Hosting panels that can only schedule a PHP file need one self-scheduling
     * entry point, and it must not be an open endpoint when reachable over HTTP.
     */
    public function testCronEntryPoint(): void
    {
        $guest = new HttpClient(Runner::baseUrl());

        // Never runnable without the shared secret.
        $guest->get('/cron.php');
        $this->assertSame(403, $guest->lastStatus, 'cron.php must refuse an unauthenticated request');
        $this->assertSame('forbidden', $guest->errorCode());

        $guest->get('/cron.php', ['token' => 'not-the-right-token']);
        $this->assertSame(403, $guest->lastStatus, 'cron.php must refuse a wrong token');

        $token = (string) config('app.cron_token');

        if ($token === '') {
            $this->skip('CRON_TOKEN is not configured on this install');
        }

        $guest->get('/cron.php', ['token' => $token]);
        $this->assertSame(200, $guest->lastStatus, $guest->lastBody);
        $this->assertTrue($guest->succeeded());

        $data = (array) $guest->data();
        $this->assertArrayHasKey('ran', $data);
        $this->assertArrayHasKey('duration_ms', $data);

        // The queue has no interval, so a run always covers it.
        $this->assertArrayHasKey('queue', (array) $data['ran'], 'Every tick must drain the queue');

        // A second call must not re-run the interval-based tasks.
        $guest->get('/cron.php', ['token' => $token]);
        $second = (array) $guest->data();
        $this->assertCount(1, (array) $second['ran'], 'Only the queue should be due immediately after a run');

        // The panel must expose the setup details.
        $page = $this->panel()->get('/admin/jobs')->lastBody;
        $this->assertContains('Automatic maintenance', $page, 'The jobs page must document cron setup');
        $this->assertContains('cron.php', $page, 'The jobs page must name the cron file');
        $this->assertNotContains('&amp;amp;', $page, 'Page titles must not be double-escaped');
    }

    public function testNoEmojiInTheInterface(): void
    {
        $pages = ['/dashboard', '/files', '/trash', '/shares', '/settings', '/settings/api-keys',
                  '/settings/sftp', '/api-docs', '/admin', '/admin/users', '/admin/monitoring', '/admin/settings'];

        // Emoji and pictographic ranges — the UI must use SVG icons only.
        $pattern = '/[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}\x{FE0F}\x{1F000}-\x{1F0FF}\x{2190}-\x{21FF}\x{2B00}-\x{2BFF}]/u';

        foreach ($pages as $path) {
            $client = $this->panel()->get($path);
            $body = $client->lastBody;

            if (preg_match($pattern, $body, $matches) === 1) {
                throw new AssertionFailed(
                    $path . ' contains a non-icon glyph: U+' . strtoupper(dechex(mb_ord($matches[0], 'UTF-8')))
                );
            }

            $this->assertContains('<svg class="icon', $body, $path . ' should render SVG icons');
        }
    }

    public function testSecurityHeadersArePresent(): void
    {
        $client = $this->panel()->get('/dashboard');

        $this->assertSame('nosniff', $client->header('x-content-type-options'));
        $this->assertSame('SAMEORIGIN', $client->header('x-frame-options'));
        $this->assertContains('default-src', (string) $client->header('content-security-policy'));
        $this->assertContains("object-src 'none'", (string) $client->header('content-security-policy'));
        $this->assertSame('strict-origin-when-cross-origin', $client->header('referrer-policy'));
    }

    public function testUnknownPageReturns404(): void
    {
        $client = $this->panel()->get('/this-page-does-not-exist');

        $this->assertSame(404, $client->lastStatus);
        $this->assertContains('Page not found', $client->lastBody);
    }

    public function testStorageDirectoryIsNotBrowsable(): void
    {
        $client = new HttpClient(Runner::rootUrl());
        $client->get('/storage/files/');

        $this->assertTrue(
            $client->lastStatus >= 400,
            'storage/ must not be reachable over HTTP (got ' . $client->lastStatus . ')'
        );
    }

    public function testEnvFileIsNotServed(): void
    {
        $client = new HttpClient(Runner::rootUrl());
        $client->get('/.env');

        $this->assertNotContains('DB_PASSWORD', $client->lastBody, 'The .env file must never be served');
    }

    public function testLogoutEndsTheSession(): void
    {
        $client = $this->panel()->get('/dashboard');
        $token = $client->csrfToken();

        $client->post('/logout', ['_token' => $token]);
        $this->assertSame(302, $client->lastStatus);

        $client->get('/dashboard');
        $this->assertSame(302, $client->lastStatus, 'The session must be gone after signing out');
        $this->assertContains('/login', (string) $client->location());
    }
}
