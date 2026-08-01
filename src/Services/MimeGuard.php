<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Config;

/**
 * Content inspection for uploads: real MIME detection plus an extension
 * blocklist, so a renamed .php never lands in storage.
 */
final class MimeGuard
{
    private const EXTENSION_MAP = [
        'txt' => 'text/plain', 'md' => 'text/markdown', 'csv' => 'text/csv',
        'json' => 'application/json', 'xml' => 'application/xml',
        'pdf' => 'application/pdf',
        'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
        'gif' => 'image/gif', 'webp' => 'image/webp', 'bmp' => 'image/bmp',
        'svg' => 'image/svg+xml', 'ico' => 'image/x-icon',
        'mp4' => 'video/mp4', 'webm' => 'video/webm', 'mov' => 'video/quicktime',
        'mp3' => 'audio/mpeg', 'wav' => 'audio/wav', 'ogg' => 'audio/ogg',
        'zip' => 'application/zip', 'tar' => 'application/x-tar', 'gz' => 'application/gzip',
        '7z' => 'application/x-7z-compressed', 'rar' => 'application/vnd.rar',
        'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls' => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'ppt' => 'application/vnd.ms-powerpoint',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
    ];

    public static function detect(string $path, string $filename = ''): string
    {
        if (is_file($path) && function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);

            if ($finfo !== false) {
                $mime = finfo_file($finfo, $path);
                finfo_close($finfo);

                if (is_string($mime) && $mime !== '' && $mime !== 'application/x-empty') {
                    return $mime;
                }
            }
        }

        $extension = strtolower(pathinfo($filename !== '' ? $filename : $path, PATHINFO_EXTENSION));

        return self::EXTENSION_MAP[$extension] ?? 'application/octet-stream';
    }

    /**
     * @return array{ok:bool, mime:string, extension:string, reason:?string}
     */
    public static function inspect(string $path, string $filename): array
    {
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $blocked = array_map('strtolower', (array) Config::get('storage.blocked_extensions', []));

        if ($extension !== '' && in_array($extension, $blocked, true)) {
            return [
                'ok'        => false,
                'mime'      => 'application/octet-stream',
                'extension' => $extension,
                'reason'    => "Files with the .{$extension} extension are not allowed.",
            ];
        }

        // Double extensions like `invoice.pdf.php` must also be rejected.
        foreach (explode('.', strtolower($filename)) as $segment) {
            if (in_array($segment, $blocked, true)) {
                return [
                    'ok'        => false,
                    'mime'      => 'application/octet-stream',
                    'extension' => $extension,
                    'reason'    => "The filename contains a blocked extension: .{$segment}",
                ];
            }
        }

        $mime = self::detect($path, $filename);

        // A name-only inspection (multipart init, rename) has no bytes to read.
        if ($path !== '' && is_file($path) && self::looksExecutable($path, $mime)) {
            return [
                'ok'        => false,
                'mime'      => $mime,
                'extension' => $extension,
                'reason'    => 'Executable content is not allowed.',
            ];
        }

        $allowed = SettingService::get('allowed_mime_types', '');
        if (is_string($allowed) && trim($allowed) !== '') {
            $list = array_filter(array_map('trim', explode(',', $allowed)));
            $matched = false;

            foreach ($list as $pattern) {
                if ($pattern === $mime || (str_ends_with($pattern, '*') && str_starts_with($mime, rtrim($pattern, '*')))) {
                    $matched = true;
                    break;
                }
            }

            if (!$matched) {
                return [
                    'ok'        => false,
                    'mime'      => $mime,
                    'extension' => $extension,
                    'reason'    => "Files of type {$mime} are not accepted.",
                ];
            }
        }

        return ['ok' => true, 'mime' => $mime, 'extension' => $extension, 'reason' => null];
    }

    private static function looksExecutable(string $path, string $mime): bool
    {
        $executableMimes = [
            'application/x-dosexec',
            'application/x-executable',
            'application/x-msdownload',
            'application/x-sharedlib',
            'application/x-mach-binary',
            'text/x-php',
            'application/x-httpd-php',
        ];

        if (in_array($mime, $executableMimes, true)) {
            return true;
        }

        if ($path === '' || !is_file($path)) {
            return false;
        }

        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return false;
        }

        $head = (string) fread($handle, 512);
        fclose($handle);

        if (str_starts_with($head, "MZ") || str_starts_with($head, "\x7fELF")) {
            return true;
        }

        return stripos($head, '<?php') !== false || stripos($head, '<?=') !== false;
    }

    /** Content-Type used when serving a download, hardened against sniffing attacks. */
    public static function safeServingMime(string $mime): string
    {
        $inlineSafe = [
            'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/bmp',
            'application/pdf', 'text/plain', 'text/csv', 'application/json',
            'video/mp4', 'video/webm', 'audio/mpeg', 'audio/ogg', 'audio/wav',
        ];

        return in_array($mime, $inlineSafe, true) ? $mime : 'application/octet-stream';
    }

    public static function canInline(string $mime): bool
    {
        return self::safeServingMime($mime) === $mime;
    }
}
