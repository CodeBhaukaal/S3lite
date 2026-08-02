<?php
declare(strict_types=1);

use App\Core\Env;

$root = (string) Env::get('STORAGE_ROOT', '');
if ($root === '') {
    $root = dirname(__DIR__) . '/storage/files';
}

return [
    'driver' => Env::get('STORAGE_DRIVER', 'local'),

    'drivers' => [
        'local' => [
            'root' => $root,
        ],
        's3' => [
            'endpoint'   => Env::get('S3_ENDPOINT', ''),
            'region'     => Env::get('S3_REGION', 'us-east-1'),
            'bucket'     => Env::get('S3_BUCKET', ''),
            'access_key' => Env::get('S3_ACCESS_KEY', ''),
            'secret_key' => Env::get('S3_SECRET_KEY', ''),
        ],
    ],

    // Defaults for FTP/FTPS/SFTP backends added from the admin panel. Each
    // backend can override these in its own options.
    'remote' => [
        'timeout' => (int) Env::get('STORAGE_REMOTE_TIMEOUT', 30),
        'passive' => true,
    ],

    'tmp_path'      => dirname(__DIR__) . '/storage/tmp',
    'chunk_path'    => dirname(__DIR__) . '/storage/chunks',
    'backup_path'   => dirname(__DIR__) . '/storage/backups',
    'sftp_path'     => dirname(__DIR__) . '/storage/sftp',

    'max_upload'    => (int) Env::get('MAX_UPLOAD_SIZE', 5368709120),
    'chunk_size'    => (int) Env::get('CHUNK_SIZE', 8388608),
    'default_quota' => (int) Env::get('DEFAULT_QUOTA', 10737418240),

    'trash_retention_days' => (int) Env::get('TRASH_RETENTION_DAYS', 30),
    'versions_kept'        => (int) Env::get('VERSIONS_KEPT', 10),

    'virus_scan' => [
        'enabled' => (bool) Env::get('VIRUS_SCAN_ENABLED', false),
        'command' => (string) Env::get('VIRUS_SCAN_COMMAND', ''),
    ],

    // Extensions that are never accepted, regardless of MIME type.
    'blocked_extensions' => [
        'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'phar',
        'exe', 'com', 'bat', 'cmd', 'msi', 'scr', 'cpl', 'jar',
        'sh', 'bash', 'ps1', 'psm1', 'vbs', 'vbe', 'js', 'jse', 'wsf', 'hta',
        'htaccess', 'htpasswd', 'ini', 'dll', 'so',
    ],

    'previewable' => [
        'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/bmp', 'image/svg+xml',
        'application/pdf',
        'text/plain', 'text/csv', 'text/markdown', 'application/json', 'text/xml', 'application/xml',
        'video/mp4', 'video/webm', 'audio/mpeg', 'audio/ogg', 'audio/wav',
    ],
];
