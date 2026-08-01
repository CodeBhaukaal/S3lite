<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Http\Exceptions\HttpException;
use App\Models\FileRecord;
use App\Models\Folder;
use App\Models\Share;
use App\Support\Crypto;
use App\Support\SignedUrl;
use App\Support\Str;

final class ShareService
{
    /**
     * @param array{
     *   type?:string, password?:?string, expires_at?:?string, expires_in?:?int,
     *   max_downloads?:?int, allow_preview?:bool
     * } $options
     */
    public static function createForFile(int $userId, int $fileId, array $options = []): array
    {
        $file = FileRecord::find($fileId);

        if ($file === null || (int) $file['user_id'] !== $userId || $file['deleted_at'] !== null) {
            throw new HttpException(404, 'File not found.', 'file_not_found');
        }

        $share = self::persist($userId, ['file_id' => $fileId], $options);

        FileRecord::updateById($fileId, ['is_public' => 1]);

        AuditService::log('share.create', 'file', $fileId, "Created share link for {$file['name']}", [
            'token'      => $share['token'],
            'type'       => $share['type'],
            'expires_at' => $share['expires_at'],
        ]);

        WebhookService::dispatch('share.created', [
            'share_id' => (int) $share['id'],
            'file_id'  => $fileId,
            'url'      => url('s/' . $share['token']),
        ], $userId);

        return $share;
    }

    public static function createForFolder(int $userId, int $folderId, array $options = []): array
    {
        $folder = Folder::find($folderId);

        if ($folder === null || (int) $folder['user_id'] !== $userId || $folder['deleted_at'] !== null) {
            throw new HttpException(404, 'Folder not found.', 'folder_not_found');
        }

        $share = self::persist($userId, ['folder_id' => $folderId], $options);

        AuditService::log('share.create', 'folder', $folderId, "Created share link for folder {$folder['name']}", [
            'token' => $share['token'],
        ]);

        return $share;
    }

    private static function persist(int $userId, array $target, array $options): array
    {
        $type = ($options['type'] ?? 'permanent') === 'temporary' ? 'temporary' : 'permanent';

        $expiresAt = null;
        if (!empty($options['expires_at'])) {
            $timestamp = strtotime((string) $options['expires_at']);
            if ($timestamp === false) {
                throw new HttpException(422, 'The expiry date is not valid.', 'invalid_expiry');
            }
            if ($timestamp <= time()) {
                throw new HttpException(422, 'The expiry date must be in the future.', 'invalid_expiry');
            }
            $expiresAt = date('Y-m-d H:i:s', $timestamp);
            $type = 'temporary';
        } elseif (!empty($options['expires_in'])) {
            $expiresAt = date('Y-m-d H:i:s', time() + (int) $options['expires_in']);
            $type = 'temporary';
        }

        $password = $options['password'] ?? null;
        if (is_string($password) && $password !== '' && mb_strlen($password) < 4) {
            throw new HttpException(422, 'A link password must be at least 4 characters.', 'weak_password');
        }

        $id = Share::create([
            'uuid'          => Str::uuid(),
            'file_id'       => $target['file_id'] ?? null,
            'folder_id'     => $target['folder_id'] ?? null,
            'user_id'       => $userId,
            'token'         => Str::token(40),
            'type'          => $type,
            'password_hash' => is_string($password) && $password !== '' ? Crypto::hashPassword($password) : null,
            'expires_at'    => $expiresAt,
            'max_downloads' => isset($options['max_downloads']) && $options['max_downloads'] !== null && (int) $options['max_downloads'] > 0
                ? (int) $options['max_downloads']
                : null,
            'allow_preview' => ($options['allow_preview'] ?? true) ? 1 : 0,
            'is_active'     => 1,
        ]);

        return Share::find($id) ?? [];
    }

    public static function update(int $userId, int $shareId, array $options): array
    {
        $share = self::ownedOrFail($userId, $shareId);
        $data = [];

        if (array_key_exists('is_active', $options)) {
            $data['is_active'] = $options['is_active'] ? 1 : 0;
        }

        if (array_key_exists('allow_preview', $options)) {
            $data['allow_preview'] = $options['allow_preview'] ? 1 : 0;
        }

        if (array_key_exists('max_downloads', $options)) {
            $max = $options['max_downloads'];
            $data['max_downloads'] = ($max === null || $max === '' || (int) $max <= 0) ? null : (int) $max;
        }

        if (array_key_exists('expires_at', $options)) {
            if ($options['expires_at'] === null || $options['expires_at'] === '') {
                $data['expires_at'] = null;
                $data['type'] = 'permanent';
            } else {
                $timestamp = strtotime((string) $options['expires_at']);
                if ($timestamp === false || $timestamp <= time()) {
                    throw new HttpException(422, 'The expiry date must be a valid future date.', 'invalid_expiry');
                }
                $data['expires_at'] = date('Y-m-d H:i:s', $timestamp);
                $data['type'] = 'temporary';
            }
        }

        if (array_key_exists('password', $options)) {
            $password = $options['password'];
            if ($password === null || $password === '') {
                $data['password_hash'] = null;
            } else {
                if (mb_strlen((string) $password) < 4) {
                    throw new HttpException(422, 'A link password must be at least 4 characters.', 'weak_password');
                }
                $data['password_hash'] = Crypto::hashPassword((string) $password);
            }
        }

        if ($data !== []) {
            Share::updateById($shareId, $data);
        }

        AuditService::log('share.update', 'share', $shareId, 'Updated share link', array_keys($data));

        return Share::find($shareId) ?? [];
    }

    public static function revoke(int $userId, int $shareId): void
    {
        $share = self::ownedOrFail($userId, $shareId);

        Share::deleteById($shareId);

        if ($share['file_id'] !== null) {
            $remaining = (int) Database::scalar(
                'SELECT COUNT(*) FROM shares WHERE file_id = ? AND is_active = 1',
                [(int) $share['file_id']]
            );

            if ($remaining === 0) {
                FileRecord::updateById((int) $share['file_id'], ['is_public' => 0]);
            }
        }

        AuditService::log('share.revoke', 'share', $shareId, 'Revoked share link', ['token' => $share['token']]);
    }

    public static function revokeAllForFile(int $userId, int $fileId): int
    {
        $file = FileRecord::find($fileId);

        if ($file === null || (int) $file['user_id'] !== $userId) {
            throw new HttpException(404, 'File not found.', 'file_not_found');
        }

        $count = Database::delete('shares', 'file_id = ? AND user_id = ?', [$fileId, $userId]);
        FileRecord::updateById($fileId, ['is_public' => 0]);

        AuditService::log('share.revoke_all', 'file', $fileId, "Revoked all share links for {$file['name']}");

        return $count;
    }

    /**
     * Resolve a public token.
     *
     * @return array{ok:bool, share:?array, file:?array, folder:?array, error:?string}
     */
    public static function resolve(string $token): array
    {
        $share = Share::findByToken($token);

        if ($share === null) {
            return ['ok' => false, 'share' => null, 'file' => null, 'folder' => null, 'error' => 'This link does not exist.'];
        }

        if (!Share::isUsable($share)) {
            return ['ok' => false, 'share' => $share, 'file' => null, 'folder' => null, 'error' => Share::reason($share)];
        }

        $file = $share['file_id'] === null ? null : FileRecord::find((int) $share['file_id']);
        $folder = $share['folder_id'] === null ? null : Folder::find((int) $share['folder_id']);

        if ($file !== null && $file['deleted_at'] !== null) {
            return ['ok' => false, 'share' => $share, 'file' => null, 'folder' => null, 'error' => 'The shared file has been deleted.'];
        }

        if ($folder !== null && $folder['deleted_at'] !== null) {
            return ['ok' => false, 'share' => $share, 'file' => null, 'folder' => null, 'error' => 'The shared folder has been deleted.'];
        }

        if ($file === null && $folder === null) {
            return ['ok' => false, 'share' => $share, 'file' => null, 'folder' => null, 'error' => 'The shared item is no longer available.'];
        }

        return ['ok' => true, 'share' => $share, 'file' => $file, 'folder' => $folder, 'error' => null];
    }

    public static function requiresPassword(array $share): bool
    {
        return $share['password_hash'] !== null && $share['password_hash'] !== '';
    }

    public static function verifyPassword(array $share, string $password): bool
    {
        return Crypto::verifyPassword($password, (string) $share['password_hash']);
    }

    /** Short-lived signed URL, independent of any share record. */
    public static function temporaryUrl(array $file, int $ttl = 3600): string
    {
        return SignedUrl::sign('/download/' . $file['uuid'], ['uuid' => $file['uuid']], $ttl);
    }

    public static function purgeExpired(): int
    {
        return Database::statement(
            'DELETE FROM shares WHERE expires_at IS NOT NULL AND expires_at < DATE_SUB(NOW(), INTERVAL 7 DAY)'
        );
    }
}
