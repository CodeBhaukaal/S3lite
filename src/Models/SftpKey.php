<?php
declare(strict_types=1);

namespace App\Models;

final class SftpKey extends Model
{
    protected static string $table = 'sftp_keys';
    protected static bool $timestamps = false;

    public static function forAccount(int $accountId): array
    {
        return self::where(['account_id' => $accountId], 'id DESC', 100);
    }

    public static function publicArray(array $key): array
    {
        return [
            'id'          => (int) $key['id'],
            'account_id'  => (int) $key['account_id'],
            'name'        => $key['name'],
            'key_type'    => $key['key_type'],
            'fingerprint' => $key['fingerprint'],
            'last_used_at' => $key['last_used_at'],
            'created_at'  => $key['created_at'],
        ];
    }
}
