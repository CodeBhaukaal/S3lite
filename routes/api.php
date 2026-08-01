<?php
/**
 * REST API v1.
 *
 * Every endpoint accepts a JWT bearer token or a scoped API key, and returns
 * the same {success, data|error} envelope.
 *
 * @var \App\Http\Router $router
 */
declare(strict_types=1);

use App\Controllers\Api\ApiKeyApiController;
use App\Controllers\Api\AuthApiController;
use App\Controllers\Api\DocsController;
use App\Controllers\Api\FileApiController;
use App\Controllers\Api\FolderApiController;
use App\Controllers\Api\ShareApiController;
use App\Controllers\Api\SftpApiController;
use App\Controllers\Api\SystemApiController;
use App\Controllers\Api\UserApiController;
use App\Controllers\Api\WebhookApiController;

$router->group(['prefix' => '/api/v1', 'middleware' => ['cors']], static function ($router): void {
    // --- Unauthenticated -------------------------------------------------
    $router->get('/health', SystemApiController::class . '@health');
    $router->get('/ping', SystemApiController::class . '@ping');
    $router->get('/openapi.json', DocsController::class . '@openapi');

    $router->post('/auth/login', AuthApiController::class . '@login')->middleware('installed', 'throttle:login');
    $router->post('/auth/refresh', AuthApiController::class . '@refresh')->middleware('installed', 'throttle:login');

    // --- Authenticated ---------------------------------------------------
    $router->group(['middleware' => ['installed', 'api', 'throttle:api', 'audit', 'idempotent']], static function ($router): void {
        // Auth / identity
        $router->post('/auth/logout', AuthApiController::class . '@logout');
        $router->get('/auth/me', AuthApiController::class . '@me');
        $router->get('/auth/sessions', AuthApiController::class . '@sessions');
        $router->delete('/auth/sessions', AuthApiController::class . '@revokeAll');

        // Files
        $router->get('/files', FileApiController::class . '@index')->middleware('api.scope:files:read');
        $router->get('/files/tags', FileApiController::class . '@tags')->middleware('api.scope:files:read');
        $router->post('/files/upload', FileApiController::class . '@upload')->middleware('api.scope:files:write', 'throttle:upload');
        $router->put('/files/upload', FileApiController::class . '@upload')->middleware('api.scope:files:write', 'throttle:upload');

        // Multipart / resumable
        $router->get('/files/multipart', FileApiController::class . '@multipartList')->middleware('api.scope:files:write');
        $router->post('/files/multipart/init', FileApiController::class . '@multipartInit')->middleware('api.scope:files:write');
        $router->post('/files/multipart/{uploadId}/part', FileApiController::class . '@multipartPart')->middleware('api.scope:files:write');
        $router->put('/files/multipart/{uploadId}/part', FileApiController::class . '@multipartPart')->middleware('api.scope:files:write');
        $router->post('/files/multipart/{uploadId}/complete', FileApiController::class . '@multipartComplete')->middleware('api.scope:files:write');
        $router->get('/files/multipart/{uploadId}', FileApiController::class . '@multipartStatus')->middleware('api.scope:files:write');
        $router->delete('/files/multipart/{uploadId}', FileApiController::class . '@multipartAbort')->middleware('api.scope:files:write');

        $router->get('/files/{id}', FileApiController::class . '@show')->middleware('api.scope:files:read');
        $router->get('/files/{id}/download', FileApiController::class . '@download')->middleware('api.scope:files:read');
        $router->patch('/files/{id}', FileApiController::class . '@update')->middleware('api.scope:files:write');
        $router->put('/files/{id}', FileApiController::class . '@update')->middleware('api.scope:files:write');
        $router->delete('/files/{id}', FileApiController::class . '@destroy')->middleware('api.scope:files:write');
        $router->post('/files/{id}/restore', FileApiController::class . '@restore')->middleware('api.scope:files:write');
        $router->post('/files/{id}/copy', FileApiController::class . '@copy')->middleware('api.scope:files:write');
        $router->get('/files/{id}/versions', FileApiController::class . '@versions')->middleware('api.scope:files:read');
        $router->get('/files/{id}/versions/{version}/download', FileApiController::class . '@downloadVersion')->middleware('api.scope:files:read');
        $router->post('/files/{id}/versions/{version}/restore', FileApiController::class . '@restoreVersion')->middleware('api.scope:files:write');
        $router->post('/files/{id}/share', FileApiController::class . '@share')->middleware('api.scope:shares:write');
        $router->delete('/files/{id}/share', FileApiController::class . '@unshare')->middleware('api.scope:shares:write');
        $router->post('/files/{id}/temporary-url', FileApiController::class . '@temporaryUrl')->middleware('api.scope:shares:write');

        // Folders
        $router->get('/folders', FolderApiController::class . '@index')->middleware('api.scope:folders:read');
        $router->post('/folders', FolderApiController::class . '@store')->middleware('api.scope:folders:write');
        $router->get('/folders/{id}', FolderApiController::class . '@show')->middleware('api.scope:folders:read');
        $router->patch('/folders/{id}', FolderApiController::class . '@update')->middleware('api.scope:folders:write');
        $router->put('/folders/{id}', FolderApiController::class . '@update')->middleware('api.scope:folders:write');
        $router->delete('/folders/{id}', FolderApiController::class . '@destroy')->middleware('api.scope:folders:write');
        $router->post('/folders/{id}/restore', FolderApiController::class . '@restore')->middleware('api.scope:folders:write');
        $router->post('/folders/{id}/share', FolderApiController::class . '@share')->middleware('api.scope:shares:write');

        // Shares
        $router->get('/shares', ShareApiController::class . '@index')->middleware('api.scope:shares:read');
        $router->get('/shares/{id}', ShareApiController::class . '@show')->middleware('api.scope:shares:read');
        $router->patch('/shares/{id}', ShareApiController::class . '@update')->middleware('api.scope:shares:write');
        $router->delete('/shares/{id}', ShareApiController::class . '@destroy')->middleware('api.scope:shares:write');

        // Users
        $router->get('/users', UserApiController::class . '@index')->middleware('api.scope:users:read');
        $router->post('/users', UserApiController::class . '@store')->middleware('api.scope:users:write');
        $router->get('/users/roles', UserApiController::class . '@roles')->middleware('api.scope:users:read');
        $router->get('/users/{id}', UserApiController::class . '@show')->middleware('api.scope:users:read');
        $router->patch('/users/{id}', UserApiController::class . '@update')->middleware('api.scope:users:write');
        $router->put('/users/{id}', UserApiController::class . '@update')->middleware('api.scope:users:write');
        $router->delete('/users/{id}', UserApiController::class . '@destroy')->middleware('api.scope:users:write');
        $router->post('/users/{id}/suspend', UserApiController::class . '@suspend')->middleware('api.scope:users:write');
        $router->post('/users/{id}/activate', UserApiController::class . '@activate')->middleware('api.scope:users:write');
        $router->get('/users/{id}/quota', UserApiController::class . '@quota')->middleware('api.scope:users:read');
        $router->put('/users/{id}/quota', UserApiController::class . '@quota')->middleware('api.scope:users:write');
        $router->post('/users/{id}/recalculate', UserApiController::class . '@recalculate')->middleware('api.scope:users:write');

        // API keys
        $router->get('/api-keys', ApiKeyApiController::class . '@index');
        $router->get('/api-keys/scopes', ApiKeyApiController::class . '@scopes');
        $router->post('/api-keys', ApiKeyApiController::class . '@store');
        $router->post('/api-keys/{id}/revoke', ApiKeyApiController::class . '@revoke');
        $router->delete('/api-keys/{id}', ApiKeyApiController::class . '@destroy');

        // FTP / FTPS / SFTP
        $router->get('/sftp-accounts', SftpApiController::class . '@index')->middleware('api.scope:sftp:read');
        $router->post('/sftp-accounts', SftpApiController::class . '@store')->middleware('api.scope:sftp:write');
        $router->post('/sftp-accounts/sync-all', SftpApiController::class . '@syncAll')->middleware('api.scope:sftp:write');
        $router->get('/sftp-accounts/{id}', SftpApiController::class . '@show')->middleware('api.scope:sftp:read');
        $router->patch('/sftp-accounts/{id}', SftpApiController::class . '@update')->middleware('api.scope:sftp:write');
        $router->put('/sftp-accounts/{id}', SftpApiController::class . '@update')->middleware('api.scope:sftp:write');
        $router->delete('/sftp-accounts/{id}', SftpApiController::class . '@destroy')->middleware('api.scope:sftp:write');
        $router->post('/sftp-accounts/{id}/password', SftpApiController::class . '@resetPassword')->middleware('api.scope:sftp:write');
        $router->get('/sftp-accounts/{id}/ssh-keys', SftpApiController::class . '@keys')->middleware('api.scope:sftp:read');
        $router->post('/sftp-accounts/{id}/ssh-keys', SftpApiController::class . '@addKey')->middleware('api.scope:sftp:write');
        $router->delete('/sftp-accounts/{id}/ssh-keys/{keyId}', SftpApiController::class . '@removeKey')->middleware('api.scope:sftp:write');
        $router->post('/sftp-accounts/{id}/sync', SftpApiController::class . '@sync')->middleware('api.scope:sftp:write');
        $router->get('/sftp-accounts/{id}/activity', SftpApiController::class . '@activity')->middleware('api.scope:sftp:read');

        // Aliases kept for the documented endpoint names
        $router->get('/ftp-accounts', SftpApiController::class . '@index')->middleware('api.scope:sftp:read');
        $router->post('/ftp-accounts', SftpApiController::class . '@store')->middleware('api.scope:sftp:write');
        $router->patch('/ftp-accounts/{id}', SftpApiController::class . '@update')->middleware('api.scope:sftp:write');
        $router->delete('/ftp-accounts/{id}', SftpApiController::class . '@destroy')->middleware('api.scope:sftp:write');

        $router->get('/sessions', SftpApiController::class . '@sessions')->middleware('api.scope:sftp:read');
        $router->delete('/sessions/{id}', SftpApiController::class . '@closeSession')->middleware('api.scope:sftp:write');

        $router->get('/sftp/services', SftpApiController::class . '@services')->middleware('api.scope:sftp:read');
        $router->put('/sftp/services', SftpApiController::class . '@services')->middleware('api.scope:sftp:write');
        $router->get('/sftp/openssh-config', SftpApiController::class . '@opensshConfig')->middleware('api.scope:sftp:read');

        // Daemon hooks (require an sftp:write key)
        $router->post('/sftp/authenticate', SftpApiController::class . '@authenticate')->middleware('api.scope:sftp:write');
        $router->post('/sftp/heartbeat', SftpApiController::class . '@heartbeat')->middleware('api.scope:sftp:write');

        // Webhooks
        $router->get('/webhooks', WebhookApiController::class . '@index');
        $router->get('/webhooks/events', WebhookApiController::class . '@events');
        $router->post('/webhooks', WebhookApiController::class . '@store');
        $router->patch('/webhooks/{id}', WebhookApiController::class . '@update');
        $router->delete('/webhooks/{id}', WebhookApiController::class . '@destroy');
        $router->post('/webhooks/{id}/test', WebhookApiController::class . '@test');
        $router->get('/webhooks/{id}/deliveries', WebhookApiController::class . '@deliveries');

        // System
        $router->get('/metrics', SystemApiController::class . '@metrics')->middleware('api.scope:metrics:read');
        $router->get('/metrics/{metric}/series', SystemApiController::class . '@series')->middleware('api.scope:metrics:read');
        $router->get('/statistics', SystemApiController::class . '@statistics')->middleware('api.scope:metrics:read');
        $router->get('/audit-logs', SystemApiController::class . '@auditLogs')->middleware('api.scope:logs:read');
        $router->get('/logs/application', SystemApiController::class . '@appLogs')->middleware('api.scope:logs:read');
        $router->get('/logs/login-attempts', SystemApiController::class . '@loginAttempts')->middleware('api.scope:logs:read');

        $router->get('/jobs', SystemApiController::class . '@jobs')->middleware('api.scope:jobs:write');
        $router->post('/jobs/work', SystemApiController::class . '@workQueue')->middleware('api.scope:jobs:write');
        $router->post('/jobs/{type}', SystemApiController::class . '@runJob')->middleware('api.scope:jobs:write');
        $router->post('/jobs/{id}/retry', SystemApiController::class . '@retryJob')->middleware('api.scope:jobs:write');

        $router->get('/backups', SystemApiController::class . '@backups')->middleware('api.scope:jobs:write');
        $router->post('/backups', SystemApiController::class . '@createBackup')->middleware('api.scope:jobs:write');
        $router->get('/backups/{name}', SystemApiController::class . '@downloadBackup')->middleware('api.scope:jobs:write');
        $router->delete('/backups/{name}', SystemApiController::class . '@deleteBackup')->middleware('api.scope:jobs:write');

        $router->get('/settings', SystemApiController::class . '@settings')->middleware('api.scope:admin');
        $router->put('/settings', SystemApiController::class . '@settings')->middleware('api.scope:admin');
        $router->get('/ip-rules', SystemApiController::class . '@ipRules')->middleware('api.scope:admin');
        $router->post('/ip-rules', SystemApiController::class . '@ipRules')->middleware('api.scope:admin');
        $router->delete('/ip-rules/{id}', SystemApiController::class . '@deleteIpRule')->middleware('api.scope:admin');
    });
});
