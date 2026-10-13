<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Env;
use App\Http\Exceptions\HttpException;
use App\Http\Session;
use App\Models\StorageBackend;
use App\Storage\GoogleDriveDriver;
use App\Storage\StorageManager;
use App\Support\Crypto;
use App\Support\Str;

/**
 * "Connect Google Drive" in one click.
 *
 * Google only hands out tokens to a registered OAuth client, so the site
 * needs one client ID + secret — entered once, for the whole site (or set as
 * GOOGLE_CLIENT_ID / GOOGLE_CLIENT_SECRET in .env). After that, linking a
 * Drive is: click, pick the Google account, allow. The backend is created
 * (or reconnected) by the callback.
 */
final class GoogleDriveService
{
    public const GROUP = 'google';
    private const SESSION_KEY = 'gdrive_oauth';

    public static function clientId(): string
    {
        $saved = trim((string) SettingService::get('google_client_id', ''));

        return $saved !== '' ? $saved : trim((string) Env::get('GOOGLE_CLIENT_ID', ''));
    }

    public static function clientSecret(): string
    {
        $saved = (string) SettingService::get('google_client_secret', '');

        if ($saved !== '') {
            return (string) (Crypto::decrypt($saved) ?? '');
        }

        return trim((string) Env::get('GOOGLE_CLIENT_SECRET', ''));
    }

    public static function configured(): bool
    {
        return self::clientId() !== '' && self::clientSecret() !== '';
    }

    /** Where the client came from, so the panel can say whether it is editable. */
    public static function fromEnv(): bool
    {
        return trim((string) SettingService::get('google_client_id', '')) === ''
            && trim((string) Env::get('GOOGLE_CLIENT_ID', '')) !== '';
    }

    /** Save the site's OAuth client. A blank secret keeps the stored one. */
    public static function saveClient(string $clientId, string $clientSecret): void
    {
        $clientId = trim($clientId);

        if (!preg_match('/^[\w.-]+\.apps\.googleusercontent\.com$/', $clientId)) {
            throw new HttpException(422, 'That does not look like a Google OAuth client ID (it ends in .apps.googleusercontent.com).', 'invalid_client_id');
        }

        SettingService::set('google_client_id', $clientId, self::GROUP);

        if (trim($clientSecret) !== '') {
            SettingService::set('google_client_secret', Crypto::encrypt(trim($clientSecret)), self::GROUP);
        }

        AuditService::log('storage.google_client', 'settings', null, 'Saved the Google OAuth client');
    }

    /** The exact URL to register as an "Authorised redirect URI" in Google Cloud. */
    public static function redirectUri(): string
    {
        return url('/admin/storage/google/callback');
    }

    /**
     * The Google consent URL. Without $backendId the callback creates a new
     * backend; with one it reconnects that backend.
     */
    public static function start(?int $backendId = null, bool $asLayer = false): string
    {
        if (!self::configured()) {
            throw new HttpException(422, 'Add the site\'s Google OAuth client first.', 'google_not_configured');
        }

        $state = bin2hex(random_bytes(16));

        // Ties Google's redirect back to this admin and this request, so a
        // forged callback cannot attach someone else's Drive.
        Session::put(self::SESSION_KEY, [
            'state'      => $state,
            'backend_id' => $backendId,
            'as_layer'   => $asLayer,
            'at'         => time(),
        ]);

        return GoogleDriveDriver::authorizationUrl(self::clientId(), self::redirectUri(), $state);
    }

    /**
     * Finish the sign-in: swap the code for a refresh token, then create or
     * update the backend and pin its folder.
     *
     * @return array The backend row.
     */
    public static function finish(string $state, string $code, string $error = ''): array
    {
        $pending = Session::get(self::SESSION_KEY);
        Session::forget(self::SESSION_KEY);

        if (!is_array($pending) || !hash_equals((string) $pending['state'], $state) || time() - (int) $pending['at'] > 900) {
            throw new HttpException(400, 'The Google sign-in expired or did not match. Click "Connect Google Drive" again.', 'oauth_state');
        }

        if ($error !== '') {
            throw new HttpException(400, $error === 'access_denied' ? 'Google access was not allowed.' : 'Google said: ' . $error, 'oauth_denied');
        }

        $tokens = GoogleDriveDriver::exchangeCode(self::clientId(), self::clientSecret(), $code, self::redirectUri());
        $email = GoogleDriveDriver::accountEmail($tokens['access_token']);

        $backendId = $pending['backend_id'] === null ? null : (int) $pending['backend_id'];
        $backend = $backendId === null ? null : StorageBackend::find($backendId);

        if ($backend !== null && $backend['driver'] !== 'gdrive') {
            throw new HttpException(422, 'That is not a Google Drive backend.', 'not_gdrive');
        }

        if ($backend === null) {
            $name = $email !== '' ? 'Google Drive (' . $email . ')' : 'Google Drive';
            $id = StorageBackend::create([
                'uuid'             => Str::uuid(),
                'name'             => mb_substr($name, 0, 100),
                'slug'             => StorageBackend::uniqueSlug('gdrive-' . ($email !== '' ? strstr($email, '@', true) : 'drive')),
                'driver'           => 'gdrive',
                'root_path'        => '',
                'options'          => ['timeout' => 60, 'folder_id' => '', 'account' => $email],
                'secret'           => StorageBackend::mergeCredentials([], ['refresh_token' => $tokens['refresh_token']]),
                'is_active'        => 1,
                'replica_priority' => !empty($pending['as_layer']) ? self::nextLayer() : null,
                'status'           => 'unknown',
            ]);
        } else {
            $id = (int) $backend['id'];
            $options = is_array($backend['options'] ?? null) ? $backend['options'] : [];
            StorageBackend::updateById($id, [
                'secret'  => StorageBackend::mergeCredentials($backend, ['refresh_token' => $tokens['refresh_token']]),
                'options' => ['account' => $email !== '' ? $email : ($options['account'] ?? '')] + $options,
            ]);
        }

        StorageManager::reset();
        $backend = StorageBackend::find($id) ?? [];

        // Pin the folder now, so later requests skip the lookup.
        $options = is_array($backend['options'] ?? null) ? $backend['options'] : [];
        if (($options['folder_id'] ?? '') === '') {
            $driver = StorageManager::fromBackend($backend);
            if ($driver instanceof GoogleDriveDriver) {
                $options['folder_id'] = $driver->folderId();
                StorageBackend::updateById($id, ['options' => $options]);
                StorageManager::reset();
            }
        }

        if ($backend['replica_priority'] !== null) {
            ReplicationService::backfill((string) $backend['slug']);
        }

        AuditService::log('storage.gdrive_connect', 'storage', $id, 'Connected Google Drive ' . ($email !== '' ? $email : $backend['name']));

        return StorageBackend::find($id) ?? $backend;
    }

    private static function nextLayer(): int
    {
        return (int) \App\Core\Database::scalar('SELECT COALESCE(MAX(replica_priority), 0) + 1 FROM storage_backends') ?: 1;
    }
}
