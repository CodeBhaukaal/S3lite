<?php
declare(strict_types=1);

use App\Core\Env;

return [
    'redis' => [
        'enabled'  => (bool) Env::get('REDIS_ENABLED', false),
        'host'     => Env::get('REDIS_HOST', '127.0.0.1'),
        'port'     => (int) Env::get('REDIS_PORT', 6379),
        'password' => (string) Env::get('REDIS_PASSWORD', ''),
        'database' => (int) Env::get('REDIS_DATABASE', 0),
        'prefix'   => Env::get('REDIS_PREFIX', 's3lite:'),
        'timeout'  => 2.0,
    ],
    'file' => [
        'path' => dirname(__DIR__) . '/storage/cache',
    ],
];
