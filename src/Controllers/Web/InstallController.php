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
                'redis_enabled'  => $request->bool('redis_enabled', false),
                'redis_host'     => $request->string('redis_host', '127.0.0.1'),
                'redis_port'     => $request->int('redis_port', 6379),
                'redis_password' => (string) $request->input('redis_password', ''),
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
