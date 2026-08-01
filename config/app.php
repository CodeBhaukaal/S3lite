<?php
declare(strict_types=1);

use App\Core\Env;

return [
    'name'        => Env::get('APP_NAME', 'S3 Lite'),
    'env'         => Env::get('APP_ENV', 'production'),
    'debug'       => (bool) Env::get('APP_DEBUG', false),
    'key'         => (string) Env::get('APP_KEY', ''),
    'url'         => rtrim((string) Env::get('APP_URL', ''), '/'),
    'timezone'    => Env::get('APP_TIMEZONE', 'UTC'),
    'force_https' => (bool) Env::get('FORCE_HTTPS', false),
    'version'     => '1.0.0',

    'log_level'   => Env::get('LOG_LEVEL', 'info'),

    // Shared secret for triggering public/cron.php over HTTP. Empty disables it.
    'cron_token'  => (string) Env::get('CRON_TOKEN', ''),

    'jwt' => [
        'secret'      => (string) Env::get('JWT_SECRET', ''),
        'access_ttl'  => (int) Env::get('JWT_ACCESS_TTL', 3600),
        'refresh_ttl' => (int) Env::get('JWT_REFRESH_TTL', 1209600),
        'issuer'      => (string) Env::get('JWT_ISSUER', 's3lite'),
        'algo'        => 'HS256',
    ],

    'rate_limit' => [
        'enabled' => (bool) Env::get('RATE_LIMIT_ENABLED', true),
        'api'     => (int) Env::get('RATE_LIMIT_API', 600),
        'login'   => (int) Env::get('RATE_LIMIT_LOGIN', 10),
        'upload'  => (int) Env::get('RATE_LIMIT_UPLOAD', 300),
        'window'  => 60,
    ],

    'security' => [
        'max_login_attempts' => 5,
        'lockout_minutes'    => 15,
        'password_min'       => 8,
        'session_lifetime'   => 7200,
    ],

    'mail' => [
        'enabled' => (bool) Env::get('MAIL_ENABLED', false),
        'from'    => Env::get('MAIL_FROM', 'no-reply@localhost'),
    ],
];
