<?php
declare(strict_types=1);

namespace App\Support;

final class Str
{
    public static function uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    public static function random(int $length = 32): string
    {
        return substr(bin2hex(random_bytes((int) ceil($length / 2))), 0, $length);
    }

    /** URL-safe token without ambiguous characters. */
    public static function token(int $length = 40): string
    {
        $alphabet = 'abcdefghijkmnopqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $max = strlen($alphabet) - 1;
        $out = '';

        for ($i = 0; $i < $length; $i++) {
            $out .= $alphabet[random_int(0, $max)];
        }

        return $out;
    }

    public static function slug(string $value, string $separator = '-'): string
    {
        $value = preg_replace('/[^\pL\d]+/u', $separator, $value) ?? '';
        $value = trim($value, $separator);
        $value = strtolower($value);

        return preg_replace('/' . preg_quote($separator, '/') . '+/', $separator, $value) ?? '';
    }

    /**
     * Strip anything that could escape a directory or hide an extension.
     */
    public static function sanitizeFilename(string $name): string
    {
        // Strip any directory component first, so `../../etc/passwd` becomes `passwd`.
        $name = basename(str_replace('\\', '/', $name));
        $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?? '';
        $name = preg_replace('/[<>:"|?*]/', '_', $name) ?? '';
        $name = trim($name, ". \t\n\r\0\x0B");

        if ($name === '') {
            $name = 'file-' . self::random(8);
        }

        if (mb_strlen($name) > 200) {
            $ext = pathinfo($name, PATHINFO_EXTENSION);
            $base = pathinfo($name, PATHINFO_FILENAME);
            $name = mb_substr($base, 0, 180) . ($ext !== '' ? '.' . $ext : '');
        }

        return $name;
    }

    public static function bytes(int|float|null $bytes, int $precision = 2): string
    {
        $bytes = (float) ($bytes ?? 0);
        if ($bytes <= 0) {
            return '0 B';
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
        $power = (int) floor(log($bytes, 1024));
        $power = min($power, count($units) - 1);

        return round($bytes / (1024 ** $power), $precision) . ' ' . $units[$power];
    }

    public static function initials(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        $initials = '';

        foreach (array_slice($parts, 0, 2) as $part) {
            $initials .= mb_strtoupper(mb_substr($part, 0, 1));
        }

        return $initials !== '' ? $initials : 'U';
    }

    public static function limit(string $value, int $limit = 100, string $end = '...'): string
    {
        return mb_strlen($value) <= $limit ? $value : mb_substr($value, 0, $limit) . $end;
    }

    public static function maskEmail(string $email): string
    {
        if (!str_contains($email, '@')) {
            return $email;
        }

        [$user, $domain] = explode('@', $email, 2);
        $visible = mb_substr($user, 0, 2);

        return $visible . str_repeat('*', max(1, mb_strlen($user) - 2)) . '@' . $domain;
    }

    public static function timeAgo(?string $datetime): string
    {
        if ($datetime === null || $datetime === '') {
            return 'never';
        }

        $ts = strtotime($datetime);
        if ($ts === false) {
            return $datetime;
        }

        $diff = time() - $ts;
        if ($diff < 0) {
            return 'in the future';
        }
        if ($diff < 60) {
            return $diff . 's ago';
        }
        if ($diff < 3600) {
            return floor($diff / 60) . 'm ago';
        }
        if ($diff < 86400) {
            return floor($diff / 3600) . 'h ago';
        }
        if ($diff < 2592000) {
            return floor($diff / 86400) . 'd ago';
        }

        return date('d M Y', $ts);
    }
}
