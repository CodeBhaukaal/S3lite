<?php
/**
 * Web panel routes.
 *
 * @var \App\Http\Router $router
 */
declare(strict_types=1);

use App\Controllers\Web\AdminController;
use App\Controllers\Web\AuthController;
use App\Controllers\Web\DashboardController;
use App\Controllers\Web\FileController;
use App\Controllers\Web\FolderController;
use App\Controllers\Web\InstallController;
use App\Controllers\Web\ProfileController;
use App\Controllers\Web\ShareController;
use App\Controllers\Web\TrashController;
use App\Http\Response;

// --- Installer ---------------------------------------------------------

$router->group(['prefix' => '/install'], static function ($router): void {
    $router->get('/', InstallController::class . '@show')->name('install');
    $router->post('/test-database', InstallController::class . '@testDatabase')->middleware('throttle:20,60');
    $router->post('/test-redis', InstallController::class . '@testRedis')->middleware('throttle:20,60');
    $router->post('/run', InstallController::class . '@install')->middleware('throttle:5,300');
});

// --- Public ------------------------------------------------------------

$router->get('/', static fn () => Response::redirect(url('/dashboard')))->middleware('installed');

$router->group(['middleware' => ['installed']], static function ($router): void {
    $router->get('/login', AuthController::class . '@showLogin')->middleware('guest')->name('login');
    $router->post('/login', AuthController::class . '@login')->middleware('guest', 'csrf', 'throttle:login');
    $router->get('/register', AuthController::class . '@showRegister')->middleware('guest');
    $router->post('/register', AuthController::class . '@register')->middleware('guest', 'csrf', 'throttle:login');
    $router->post('/logout', AuthController::class . '@logout')->middleware('csrf');

    // Public share links
    $router->get('/s/{token}', ShareController::class . '@publicShow')->middleware('throttle:60,60');
    $router->post('/s/{token}/unlock', ShareController::class . '@publicUnlock')->middleware('throttle:20,60');
    $router->get('/s/{token}/download', ShareController::class . '@publicDownload')->middleware('throttle:120,60');
    $router->get('/s/{token}/preview', ShareController::class . '@publicPreview')->middleware('throttle:120,60');

    // Signed, expiring download
    $router->get('/download/{uuid}', FileController::class . '@signedDownload')->middleware('throttle:120,60');
});

// --- Authenticated panel -----------------------------------------------

$router->group(['middleware' => ['installed', 'auth']], static function ($router): void {
    $router->get('/dashboard', DashboardController::class . '@index')->name('dashboard');
    $router->get('/dashboard/stats', DashboardController::class . '@stats');
    $router->post('/notifications/read', DashboardController::class . '@readNotifications')->middleware('csrf');

    // Files
    $router->get('/files', FileController::class . '@index')->name('files');
    $router->post('/files/upload', FileController::class . '@upload')->middleware('csrf', 'throttle:upload');
    $router->post('/files/bulk', FileController::class . '@bulk')->middleware('csrf');
    $router->get('/files/{id}', FileController::class . '@show');
    $router->get('/files/{id}/download', FileController::class . '@download');
    $router->get('/files/{id}/preview', FileController::class . '@preview');
    $router->post('/files/{id}/rename', FileController::class . '@rename')->middleware('csrf');
    $router->post('/files/{id}/move', FileController::class . '@move')->middleware('csrf');
    $router->post('/files/{id}/copy', FileController::class . '@copy')->middleware('csrf');
    $router->post('/files/{id}/tags', FileController::class . '@tags')->middleware('csrf');
    $router->post('/files/{id}/trash', FileController::class . '@trash')->middleware('csrf');
    $router->post('/files/{id}/versions/{version}/restore', FileController::class . '@restoreVersion')->middleware('csrf');

    // Folders
    $router->post('/folders', FolderController::class . '@store')->middleware('csrf');
    $router->get('/folders/tree', FolderController::class . '@tree');
    $router->post('/folders/{id}/rename', FolderController::class . '@rename')->middleware('csrf');
    $router->post('/folders/{id}/move', FolderController::class . '@move')->middleware('csrf');
    $router->post('/folders/{id}/trash', FolderController::class . '@trash')->middleware('csrf');

    // Trash
    $router->get('/trash', TrashController::class . '@index')->name('trash');
    $router->post('/trash/empty', TrashController::class . '@empty')->middleware('csrf');
    $router->post('/trash/files/{id}/restore', TrashController::class . '@restoreFile')->middleware('csrf');
    $router->post('/trash/files/{id}/purge', TrashController::class . '@purgeFile')->middleware('csrf');
    $router->post('/trash/folders/{id}/restore', TrashController::class . '@restoreFolder')->middleware('csrf');
    $router->post('/trash/folders/{id}/purge', TrashController::class . '@purgeFolder')->middleware('csrf');

    // Shares
    $router->get('/shares', ShareController::class . '@index')->name('shares');
    $router->post('/shares', ShareController::class . '@store')->middleware('csrf');
    $router->post('/shares/{id}/update', ShareController::class . '@update')->middleware('csrf');
    $router->post('/shares/{id}/revoke', ShareController::class . '@destroy')->middleware('csrf');

    // Profile & settings
    $router->get('/settings', ProfileController::class . '@index')->name('settings');
    $router->post('/settings/profile', ProfileController::class . '@update')->middleware('csrf');
    $router->post('/settings/password', ProfileController::class . '@changePassword')->middleware('csrf');
    $router->get('/settings/two-factor', ProfileController::class . '@twoFactorSetup');
    $router->post('/settings/two-factor', ProfileController::class . '@twoFactorConfirm')->middleware('csrf');
    $router->post('/settings/two-factor/disable', ProfileController::class . '@twoFactorDisable')->middleware('csrf');

    $router->get('/settings/api-keys', ProfileController::class . '@apiKeys');
    $router->post('/settings/api-keys', ProfileController::class . '@createApiKey')->middleware('csrf');
    $router->post('/settings/api-keys/{id}/revoke', ProfileController::class . '@revokeApiKey')->middleware('csrf');
    $router->post('/settings/api-keys/{id}/delete', ProfileController::class . '@deleteApiKey')->middleware('csrf');
    $router->post('/settings/api-keys/dismiss', ProfileController::class . '@dismissKey')->middleware('csrf');

    $router->get('/settings/sftp', ProfileController::class . '@sftp');
    $router->post('/settings/sftp', ProfileController::class . '@createSftp')->middleware('csrf');
    $router->post('/settings/sftp/{id}/update', ProfileController::class . '@updateSftp')->middleware('csrf');
    $router->post('/settings/sftp/{id}/password', ProfileController::class . '@resetSftpPassword')->middleware('csrf');
    $router->post('/settings/sftp/{id}/delete', ProfileController::class . '@deleteSftp')->middleware('csrf');
    $router->post('/settings/sftp/{id}/keys', ProfileController::class . '@addSftpKey')->middleware('csrf');
    $router->post('/settings/sftp/{id}/keys/{keyId}/delete', ProfileController::class . '@removeSftpKey')->middleware('csrf');
    $router->post('/settings/sftp/{id}/sync', ProfileController::class . '@syncSftp')->middleware('csrf');
    $router->post('/settings/sftp/sessions/{sessionId}/close', ProfileController::class . '@closeSftpSession')->middleware('csrf');

    $router->get('/settings/webhooks', ProfileController::class . '@webhooks');
    $router->post('/settings/webhooks', ProfileController::class . '@createWebhook')->middleware('csrf');
    $router->post('/settings/webhooks/{id}/delete', ProfileController::class . '@deleteWebhook')->middleware('csrf');
    $router->post('/settings/webhooks/{id}/test', ProfileController::class . '@testWebhook')->middleware('csrf');

    // API documentation
    $router->get('/api-docs', \App\Controllers\Api\DocsController::class . '@page')->name('api-docs');
});

// --- Admin -------------------------------------------------------------

$router->group(['prefix' => '/admin', 'middleware' => ['installed', 'auth', 'admin']], static function ($router): void {
    $router->get('/', AdminController::class . '@dashboard')->name('admin');

    $router->get('/users', AdminController::class . '@users');
    $router->post('/users', AdminController::class . '@storeUser')->middleware('csrf');
    $router->get('/users/{id}', AdminController::class . '@showUser');
    $router->post('/users/{id}/update', AdminController::class . '@updateUser')->middleware('csrf');
    $router->post('/users/{id}/delete', AdminController::class . '@deleteUser')->middleware('csrf');
    $router->post('/users/{id}/recalculate', AdminController::class . '@recalculateUser')->middleware('csrf');

    $router->get('/roles', AdminController::class . '@roles');
    $router->post('/roles', AdminController::class . '@storeRole')->middleware('csrf');
    $router->post('/roles/{id}/update', AdminController::class . '@updateRole')->middleware('csrf');
    $router->post('/roles/{id}/delete', AdminController::class . '@deleteRole')->middleware('csrf');

    $router->get('/files', AdminController::class . '@files');
    $router->post('/files/{id}/delete', AdminController::class . '@deleteFile')->middleware('csrf');

    $router->get('/settings', AdminController::class . '@settings');
    $router->post('/settings', AdminController::class . '@updateSettings')->middleware('csrf');

    $router->get('/monitoring', AdminController::class . '@monitoring');
    $router->get('/monitoring/data', AdminController::class . '@metricsJson');

    $router->get('/logs', AdminController::class . '@logs');
    $router->post('/logs/purge', AdminController::class . '@purgeLogs')->middleware('csrf');

    $router->get('/jobs', AdminController::class . '@jobs');
    $router->post('/jobs/run', AdminController::class . '@runJob')->middleware('csrf');
    $router->post('/jobs/{id}/retry', AdminController::class . '@retryJob')->middleware('csrf');
    $router->post('/jobs/work', AdminController::class . '@workQueue')->middleware('csrf');
    $router->post('/backups', AdminController::class . '@createBackup')->middleware('csrf');
    $router->get('/backups/{name}/download', AdminController::class . '@downloadBackup');
    $router->post('/backups/{name}/delete', AdminController::class . '@deleteBackup')->middleware('csrf');

    $router->get('/sftp', AdminController::class . '@sftp');
    $router->post('/sftp', AdminController::class . '@storeSftp')->middleware('csrf');
    $router->post('/sftp/sync-all', AdminController::class . '@syncAllSftp')->middleware('csrf');
    $router->get('/sftp/openssh-config', AdminController::class . '@opensshConfig');
    $router->post('/sftp/{id}/update', AdminController::class . '@updateSftp')->middleware('csrf');
    $router->post('/sftp/{id}/password', AdminController::class . '@resetSftpPassword')->middleware('csrf');
    $router->post('/sftp/{id}/delete', AdminController::class . '@deleteSftp')->middleware('csrf');
    $router->post('/sftp/{id}/sync', AdminController::class . '@syncSftp')->middleware('csrf');
    $router->post('/sftp/sessions/{id}/close', AdminController::class . '@closeSession')->middleware('csrf');

    $router->get('/ip-rules', AdminController::class . '@ipRules');
    $router->post('/ip-rules', AdminController::class . '@storeIpRule')->middleware('csrf');
    $router->post('/ip-rules/{id}/delete', AdminController::class . '@deleteIpRule')->middleware('csrf');
});
