<?php
declare(strict_types=1);

namespace App\Controllers\Web;

use App\Controllers\Controller;
use App\Http\Request;
use App\Http\Response;
use App\Services\Installer;

final class InstallController extends Controller
{
    public function show(Request $request): Response
    {
        if (Installer::isInstalled()) {
            return $this->redirect('/login', 'info', 'The platform is already installed.');
        }

        $guessedUrl = $this->guessUrl($request);

        return $this->view('install.wizard', [
            'requirements' => Installer::requirements(),
            'defaults'     => [
                'app_url'    => $guessedUrl,
                'db_host'    => '127.0.0.1',
                'db_port'    => 3306,
                'db_name'    => 's3lite',
                'db_user'    => 'root',
                'redis_host' => '127.0.0.1',
                'redis_port' => 6379,
                'timezone'   => date_default_timezone_get(),

                // Shown pre-filled in the wizard and written as-is if untouched.
                'mail_port'            => 587,
                'mail_encryption'      => 'tls',
                'max_upload_size'      => 5368709120,
                'chunk_size'           => 8388608,
                'default_quota'        => 10737418240,
                'trash_retention_days' => 30,
                'versions_kept'        => 10,
            ],
            'timezones' => Installer::timezones(),
        ]);
    }

    public function testDatabase(Request $request): Response
    {
        if (Installer::isInstalled()) {
            return $this->error('already_installed', 'The platform is already installed.', 409);
        }

        return $this->json(Installer::testDatabase([
            'host'     => $request->string('db_host', '127.0.0.1'),
            'port'     => $request->int('db_port', 3306),
            'database' => $request->string('db_name'),
            'username' => $request->string('db_user', 'root'),
            'password' => (string) $request->input('db_pass', ''),
        ]));
    }

    public function testRedis(Request $request): Response
    {
        if (Installer::isInstalled()) {
            return $this->error('already_installed', 'The platform is already installed.', 409);
        }

        return $this->json(Installer::testRedis([
            'host'     => $request->string('redis_host', '127.0.0.1'),
            'port'     => $request->int('redis_port', 6379),
            'password' => (string) $request->input('redis_password', ''),
        ]));
    }

    public function testMail(Request $request): Response
    {
        if (Installer::isInstalled()) {
            return $this->error('already_installed', 'The platform is already installed.', 409);
        }

        $mailer = new \App\Services\Mailer([
            'driver'     => 'smtp',
            'host'       => $request->string('mail_host'),
            'port'       => $request->int('mail_port', 587),
            'encryption' => $request->string('mail_encryption', 'tls'),
            'username'   => (string) $request->input('mail_username', ''),
            'password'   => (string) $request->input('mail_password', ''),
            'timeout'    => 15,
            'from'       => [
                'address' => $request->string('mail_from') ?: 'no-reply@localhost',
                'name'    => $request->string('mail_from_name') ?: 'S3 Lite',
            ],
            'allow_self_signed' => $request->bool('mail_allow_self_signed'),
        ]);

        $recipient = $request->string('mail_test_to');

        // With a recipient, prove delivery end to end; without one, just prove
        // the credentials work.
        $result = $recipient === ''
            ? $mailer->testConnection()
            : $mailer->send(
                $recipient,
                'Test message from your new S3lite install',
                \App\Services\Mailer::template(
                    'Email is working',
                    '<p>If you are reading this, the SMTP settings you entered in the installer are correct.</p>'
                )
            );

        return $this->json([
            'ok'         => $result['ok'],
            'message'    => $result['message'],
            'transcript' => array_slice($result['transcript'] ?? [], -12),
        ]);
    }

    public function install(Request $request): Response
    {
        if (Installer::isInstalled()) {
            return $this->error('already_installed', 'The platform is already installed.', 409);
        }

        $data = $this->validate($request, [
            'app_name'       => 'required|string|max:64',
            'app_url'        => 'required|url|max:255',
            'timezone'       => 'required|string|max:64',
            'db_host'        => 'required|string|max:190',
            'db_port'        => 'required|int',
            'db_name'        => 'required|string|max:64|alpha_dash',
            'db_user'        => 'required|string|max:64',
            'admin_name'     => 'required|string|min:2|max:120',
            'admin_email'    => 'required|email|max:190',
            'admin_password' => 'required|password|confirmed',
        ]);

        try {
            $result = Installer::install(array_merge($data, [
                'db_pass'        => (string) $request->input('db_pass', ''),
                'app_env'        => $request->string('app_env', 'production'),
                'app_debug'      => $request->bool('app_debug', false),
                'force_https'    => $request->bool('force_https', false),

                'redis_enabled'  => $request->bool('redis_enabled', false),
                'redis_host'     => $request->string('redis_host', '127.0.0.1'),
                'redis_port'     => $request->int('redis_port', 6379),
                'redis_password' => (string) $request->input('redis_password', ''),

                'mail_driver'     => $request->string('mail_driver', 'none'),
                'mail_host'       => $request->string('mail_host'),
                'mail_port'       => $request->int('mail_port', 587),
                'mail_encryption' => $request->string('mail_encryption', 'tls'),
                'mail_username'   => (string) $request->input('mail_username', ''),
                'mail_password'   => (string) $request->input('mail_password', ''),
                'mail_from'       => $request->string('mail_from') ?: 'no-reply@localhost',
                'mail_from_name'  => $request->string('mail_from_name') ?: $request->string('app_name', 'S3 Lite'),

                // Left blank in the wizard? The documented default is written.
                'max_upload_size' => $request->int('max_upload_size', 5368709120),
                'chunk_size'      => $request->int('chunk_size', 8388608),
                'default_quota'   => $request->int('default_quota', 10737418240),
                'trash_retention_days' => $request->int('trash_retention_days', 30),
                'versions_kept'   => $request->int('versions_kept', 10),
            ]));
        } catch (\Throwable $e) {
            \App\Core\Logger::exception($e, ['stage' => 'install']);

            return $this->error('install_failed', $e->getMessage(), 500);
        }

        return $this->json([
            'installed'  => true,
            'login_url'  => url('/login'),
            'admin_email' => $result['admin']['email'] ?? null,
        ]);
    }

    private function guessUrl(Request $request): string
    {
        $scheme = $request->isSecure() ? 'https' : 'http';
        $host = (string) ($request->server['HTTP_HOST'] ?? 'localhost');

        return $scheme . '://' . $host . $request->basePath;
    }
}
