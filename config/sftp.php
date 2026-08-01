<?php
declare(strict_types=1);

use App\Core\Env;

return [
    'sftp' => [
        'enabled' => (bool) Env::get('SFTP_ENABLED', true),
        'host'    => Env::get('SFTP_HOST', '127.0.0.1'),
        'port'    => (int) Env::get('SFTP_PORT', 2222),
    ],
    'ftp' => [
        'enabled' => (bool) Env::get('FTP_ENABLED', false),
        'port'    => (int) Env::get('FTP_PORT', 21),
    ],
    'ftps' => [
        'enabled'  => (bool) Env::get('FTPS_ENABLED', false),
        'tls_cert' => Env::get('FTPS_TLS_CERT', ''),
        'tls_key'  => Env::get('FTPS_TLS_KEY', ''),
    ],
    'passive' => [
        'min' => (int) Env::get('FTP_PASSIVE_MIN', 50000),
        'max' => (int) Env::get('FTP_PASSIVE_MAX', 50100),
    ],
    'home_root'    => dirname(__DIR__) . '/storage/sftp',
    'sync_interval' => 60,
];
