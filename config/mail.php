<?php
declare(strict_types=1);

use App\Core\Env;

return [
    /*
     * smtp     — talk to an SMTP server (the bundled client, no dependency)
     * sendmail — hand off to PHP's mail()
     * log      — write the message to storage/logs instead of sending
     * none     — silently drop mail (the default until SMTP is configured)
     */
    'driver' => Env::get('MAIL_DRIVER', Env::get('MAIL_ENABLED', false) ? 'smtp' : 'none'),

    'host'       => Env::get('MAIL_HOST', ''),
    'port'       => (int) Env::get('MAIL_PORT', 587),
    'encryption' => Env::get('MAIL_ENCRYPTION', 'tls'),   // tls | ssl | none
    'username'   => (string) Env::get('MAIL_USERNAME', ''),
    'password'   => (string) Env::get('MAIL_PASSWORD', ''),
    'timeout'    => (int) Env::get('MAIL_TIMEOUT', 15),

    'from' => [
        'address' => Env::get('MAIL_FROM', 'no-reply@localhost'),
        'name'    => Env::get('MAIL_FROM_NAME', Env::get('APP_NAME', 'S3 Lite')),
    ],

    // Skip certificate checks. Only for a self-signed server on a trusted network.
    'allow_self_signed' => (bool) Env::get('MAIL_ALLOW_SELF_SIGNED', false),
];
