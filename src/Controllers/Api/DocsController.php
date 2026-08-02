<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Controllers\Controller;
use App\Http\Request;
use App\Http\Response;
use App\Services\TokenService;

/**
 * Serves the OpenAPI 3.0 document and the built-in documentation page.
 * The spec is generated from the route table so it never drifts.
 */
final class DocsController extends Controller
{
    public function page(Request $request): Response
    {
        return $this->view('app.api-docs', [
            'spec'    => $this->spec(),
            'baseUrl' => url('api/v1'),
            'scopes'  => TokenService::SCOPES,
        ]);
    }

    public function openapi(Request $request): Response
    {
        return Response::json($this->spec())
            ->header('Content-Disposition', 'inline; filename="openapi.json"');
    }

    private function spec(): array
    {
        $base = url('api/v1');

        return [
            'openapi' => '3.0.3',
            'info'    => [
                'title'       => (string) config('app.name', 'S3 Lite') . ' API',
                'version'     => (string) config('app.version', '1.0.0'),
                'description' => 'Full control over users, storage, sharing, FTP/SFTP and monitoring. '
                    . 'Every endpoint returns {"success":bool,"data":…} or {"success":false,"error":{code,message}}.',
            ],
            'servers' => [['url' => $base]],
            'components' => [
                'securitySchemes' => [
                    'bearerAuth' => ['type' => 'http', 'scheme' => 'bearer', 'bearerFormat' => 'JWT'],
                    'apiKeyAuth' => ['type' => 'apiKey', 'in' => 'header', 'name' => 'X-Api-Key'],
                ],
                'schemas' => $this->schemas(),
            ],
            'security' => [['bearerAuth' => []], ['apiKeyAuth' => []]],
            'tags' => [
                ['name' => 'Auth', 'description' => 'Tokens and session management'],
                ['name' => 'Files', 'description' => 'Upload, download, versions and metadata'],
                ['name' => 'Folders', 'description' => 'Folder CRUD and navigation'],
                ['name' => 'Shares', 'description' => 'Public and signed links'],
                ['name' => 'Users', 'description' => 'Account administration'],
                ['name' => 'API keys', 'description' => 'Scoped machine credentials'],
                ['name' => 'SFTP', 'description' => 'FTP/FTPS/SFTP accounts, keys and sessions'],
                ['name' => 'Storage', 'description' => 'Where files are kept: local disk, FTP/FTPS/SFTP servers, S3 buckets'],
                ['name' => 'Webhooks', 'description' => 'Event subscriptions'],
                ['name' => 'System', 'description' => 'Health, metrics, logs, jobs and backups'],
            ],
            'paths' => $this->paths(),
        ];
    }

    private function schemas(): array
    {
        return [
            'Error' => [
                'type'       => 'object',
                'properties' => [
                    'success' => ['type' => 'boolean', 'example' => false],
                    'error'   => [
                        'type'       => 'object',
                        'properties' => [
                            'code'    => ['type' => 'string', 'example' => 'file_not_found'],
                            'message' => ['type' => 'string'],
                            'details' => ['type' => 'object'],
                        ],
                    ],
                ],
            ],
            'File' => [
                'type'       => 'object',
                'properties' => [
                    'id'             => ['type' => 'integer'],
                    'uuid'           => ['type' => 'string', 'format' => 'uuid'],
                    'name'           => ['type' => 'string'],
                    'mime'           => ['type' => 'string'],
                    'kind'           => ['type' => 'string', 'enum' => ['image', 'video', 'audio', 'pdf', 'text', 'archive', 'file']],
                    'size'           => ['type' => 'integer'],
                    'checksum'       => ['type' => 'string', 'description' => 'SHA-256'],
                    'folder_id'      => ['type' => 'integer', 'nullable' => true],
                    'version'        => ['type' => 'integer'],
                    'is_public'      => ['type' => 'boolean'],
                    'download_count' => ['type' => 'integer'],
                    'tags'           => ['type' => 'array', 'items' => ['type' => 'string']],
                    'download_url'   => ['type' => 'string'],
                    'created_at'     => ['type' => 'string', 'format' => 'date-time'],
                ],
            ],
            'Folder' => [
                'type'       => 'object',
                'properties' => [
                    'id'        => ['type' => 'integer'],
                    'uuid'      => ['type' => 'string'],
                    'name'      => ['type' => 'string'],
                    'path'      => ['type' => 'string'],
                    'parent_id' => ['type' => 'integer', 'nullable' => true],
                ],
            ],
            'Share' => [
                'type'       => 'object',
                'properties' => [
                    'id'    => ['type' => 'integer'],
                    'token' => ['type' => 'string'],
                    'url'   => ['type' => 'string'],
                    'type'  => ['type' => 'string', 'enum' => ['permanent', 'temporary']],
                    'expires_at'         => ['type' => 'string', 'nullable' => true],
                    'password_protected' => ['type' => 'boolean'],
                    'max_downloads'      => ['type' => 'integer', 'nullable' => true],
                    'download_count'     => ['type' => 'integer'],
                ],
            ],
            'User' => [
                'type'       => 'object',
                'properties' => [
                    'id'     => ['type' => 'integer'],
                    'uuid'   => ['type' => 'string'],
                    'name'   => ['type' => 'string'],
                    'email'  => ['type' => 'string', 'format' => 'email'],
                    'role'   => ['type' => 'string'],
                    'status' => ['type' => 'string', 'enum' => ['active', 'suspended', 'pending']],
                    'quota'  => ['type' => 'object'],
                ],
            ],
            'SftpAccount' => [
                'type'       => 'object',
                'properties' => [
                    'id'          => ['type' => 'integer'],
                    'username'    => ['type' => 'string'],
                    'protocol'    => ['type' => 'string', 'enum' => ['sftp', 'ftp', 'ftps']],
                    'permission'  => ['type' => 'string', 'enum' => ['ro', 'rw']],
                    'home_dir'    => ['type' => 'string'],
                    'quota_bytes' => ['type' => 'integer'],
                    'status'      => ['type' => 'string', 'enum' => ['active', 'disabled']],
                ],
            ],
        ];
    }

    private function paths(): array
    {
        $ok = static fn (string $ref = '', string $description = 'Success'): array => [
            '200' => [
                'description' => $description,
                'content'     => ['application/json' => ['schema' => [
                    'type'       => 'object',
                    'properties' => array_filter([
                        'success' => ['type' => 'boolean'],
                        'data'    => $ref === '' ? ['type' => 'object'] : ['$ref' => '#/components/schemas/' . $ref],
                    ]),
                ]]],
            ],
            'default' => [
                'description' => 'Error',
                'content'     => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Error']]],
            ],
        ];

        $pathParam = static fn (string $name, string $description): array => [
            'name'     => $name,
            'in'       => 'path',
            'required' => true,
            'schema'   => ['type' => 'string'],
            'description' => $description,
        ];

        return [
            '/auth/login' => ['post' => [
                'tags' => ['Auth'], 'summary' => 'Exchange credentials for tokens', 'security' => [],
                'requestBody' => ['required' => true, 'content' => ['application/json' => ['schema' => [
                    'type' => 'object',
                    'required' => ['email', 'password'],
                    'properties' => [
                        'email'    => ['type' => 'string', 'format' => 'email'],
                        'password' => ['type' => 'string', 'format' => 'password'],
                        'otp'      => ['type' => 'string', 'description' => 'Required when 2FA is enabled'],
                    ],
                ]]]],
                'responses' => $ok(),
            ]],
            '/auth/refresh' => ['post' => [
                'tags' => ['Auth'], 'summary' => 'Rotate a refresh token', 'security' => [],
                'responses' => $ok(),
            ]],
            '/auth/logout' => ['post' => ['tags' => ['Auth'], 'summary' => 'Revoke refresh tokens', 'responses' => $ok()]],
            '/auth/me' => ['get' => ['tags' => ['Auth'], 'summary' => 'Current identity, scopes and quota', 'responses' => $ok('User')]],
            '/auth/sessions' => ['get' => ['tags' => ['Auth'], 'summary' => 'List active refresh tokens', 'responses' => $ok()]],

            '/files' => [
                'get' => [
                    'tags' => ['Files'], 'summary' => 'List and search files',
                    'parameters' => [
                        ['name' => 'q', 'in' => 'query', 'schema' => ['type' => 'string']],
                        ['name' => 'folder_id', 'in' => 'query', 'schema' => ['type' => 'string']],
                        ['name' => 'mime', 'in' => 'query', 'schema' => ['type' => 'string']],
                        ['name' => 'tag', 'in' => 'query', 'schema' => ['type' => 'string']],
                        ['name' => 'trashed', 'in' => 'query', 'schema' => ['type' => 'boolean']],
                        ['name' => 'page', 'in' => 'query', 'schema' => ['type' => 'integer']],
                        ['name' => 'per_page', 'in' => 'query', 'schema' => ['type' => 'integer']],
                        ['name' => 'sort', 'in' => 'query', 'schema' => ['type' => 'string', 'enum' => ['created_at', 'name', 'size', 'download_count']]],
                    ],
                    'responses' => $ok('File'),
                ],
            ],
            '/files/upload' => ['post' => [
                'tags' => ['Files'], 'summary' => 'Upload one or more files',
                'requestBody' => ['content' => ['multipart/form-data' => ['schema' => [
                    'type' => 'object',
                    'properties' => [
                        'file'      => ['type' => 'string', 'format' => 'binary'],
                        'folder_id' => ['type' => 'string'],
                        'tags'      => ['type' => 'string', 'description' => 'Comma separated'],
                    ],
                ]]]],
                'responses' => $ok('File', 'Created'),
            ]],
            '/files/multipart/init' => ['post' => ['tags' => ['Files'], 'summary' => 'Start a resumable upload', 'responses' => $ok()]],
            '/files/multipart/{uploadId}/part' => ['post' => [
                'tags' => ['Files'], 'summary' => 'Upload one part',
                'parameters' => [$pathParam('uploadId', 'Upload session UUID')],
                'responses' => $ok(),
            ]],
            '/files/multipart/{uploadId}/complete' => ['post' => [
                'tags' => ['Files'], 'summary' => 'Assemble the parts into a file',
                'parameters' => [$pathParam('uploadId', 'Upload session UUID')],
                'responses' => $ok('File'),
            ]],
            '/files/multipart/{uploadId}' => [
                'get'    => ['tags' => ['Files'], 'summary' => 'Resume status: which parts are missing', 'parameters' => [$pathParam('uploadId', 'Upload session UUID')], 'responses' => $ok()],
                'delete' => ['tags' => ['Files'], 'summary' => 'Abort the upload', 'parameters' => [$pathParam('uploadId', 'Upload session UUID')], 'responses' => $ok()],
            ],
            '/files/{id}' => [
                'get'    => ['tags' => ['Files'], 'summary' => 'Read file metadata', 'parameters' => [$pathParam('id', 'File id or UUID')], 'responses' => $ok('File')],
                'patch'  => ['tags' => ['Files'], 'summary' => 'Rename, move or retag', 'parameters' => [$pathParam('id', 'File id or UUID')], 'responses' => $ok('File')],
                'delete' => ['tags' => ['Files'], 'summary' => 'Trash or permanently delete', 'parameters' => [$pathParam('id', 'File id or UUID')], 'responses' => $ok()],
            ],
            '/files/{id}/download' => ['get' => ['tags' => ['Files'], 'summary' => 'Download (supports Range)', 'parameters' => [$pathParam('id', 'File id or UUID')], 'responses' => ['200' => ['description' => 'File bytes']]]],
            '/files/{id}/restore'  => ['post' => ['tags' => ['Files'], 'summary' => 'Restore from trash', 'parameters' => [$pathParam('id', 'File id or UUID')], 'responses' => $ok('File')]],
            '/files/{id}/copy'     => ['post' => ['tags' => ['Files'], 'summary' => 'Duplicate a file', 'parameters' => [$pathParam('id', 'File id or UUID')], 'responses' => $ok('File')]],
            '/files/{id}/versions' => ['get' => ['tags' => ['Files'], 'summary' => 'List versions', 'parameters' => [$pathParam('id', 'File id or UUID')], 'responses' => $ok()]],
            '/files/{id}/versions/{version}/restore' => ['post' => ['tags' => ['Files'], 'summary' => 'Roll back to a version', 'parameters' => [$pathParam('id', 'File id or UUID'), $pathParam('version', 'Version number')], 'responses' => $ok('File')]],
            '/files/{id}/share' => [
                'post'   => ['tags' => ['Shares'], 'summary' => 'Create a permanent or expiring link', 'parameters' => [$pathParam('id', 'File id or UUID')], 'responses' => $ok('Share')],
                'delete' => ['tags' => ['Shares'], 'summary' => 'Revoke every link for this file', 'parameters' => [$pathParam('id', 'File id or UUID')], 'responses' => $ok()],
            ],
            '/files/{id}/temporary-url' => ['post' => ['tags' => ['Shares'], 'summary' => 'Generate a signed, expiring URL', 'parameters' => [$pathParam('id', 'File id or UUID')], 'responses' => $ok()]],

            '/folders' => [
                'get'  => ['tags' => ['Folders'], 'summary' => 'List folders (or the whole tree)', 'responses' => $ok('Folder')],
                'post' => ['tags' => ['Folders'], 'summary' => 'Create a folder', 'responses' => $ok('Folder', 'Created')],
            ],
            '/folders/{id}' => [
                'get'    => ['tags' => ['Folders'], 'summary' => 'Folder contents', 'parameters' => [$pathParam('id', 'Folder id or UUID')], 'responses' => $ok()],
                'patch'  => ['tags' => ['Folders'], 'summary' => 'Rename, move or recolour', 'parameters' => [$pathParam('id', 'Folder id or UUID')], 'responses' => $ok('Folder')],
                'delete' => ['tags' => ['Folders'], 'summary' => 'Trash or permanently delete', 'parameters' => [$pathParam('id', 'Folder id or UUID')], 'responses' => $ok()],
            ],
            '/folders/{id}/restore' => ['post' => ['tags' => ['Folders'], 'summary' => 'Restore from trash', 'parameters' => [$pathParam('id', 'Folder id or UUID')], 'responses' => $ok()]],
            '/folders/{id}/share'   => ['post' => ['tags' => ['Shares'], 'summary' => 'Share a whole folder', 'parameters' => [$pathParam('id', 'Folder id or UUID')], 'responses' => $ok('Share')]],

            '/shares' => ['get' => ['tags' => ['Shares'], 'summary' => 'List your share links', 'responses' => $ok('Share')]],
            '/shares/{id}' => [
                'get'    => ['tags' => ['Shares'], 'summary' => 'Read a share link', 'parameters' => [$pathParam('id', 'Share id, UUID or token')], 'responses' => $ok('Share')],
                'patch'  => ['tags' => ['Shares'], 'summary' => 'Change password, expiry or limits', 'parameters' => [$pathParam('id', 'Share id, UUID or token')], 'responses' => $ok('Share')],
                'delete' => ['tags' => ['Shares'], 'summary' => 'Revoke the link', 'parameters' => [$pathParam('id', 'Share id, UUID or token')], 'responses' => $ok()],
            ],

            '/users' => [
                'get'  => ['tags' => ['Users'], 'summary' => 'List users (admin)', 'responses' => $ok('User')],
                'post' => ['tags' => ['Users'], 'summary' => 'Create a user (admin)', 'responses' => $ok('User', 'Created')],
            ],
            '/users/{id}' => [
                'get'    => ['tags' => ['Users'], 'summary' => 'Read a user', 'parameters' => [$pathParam('id', 'User id or UUID')], 'responses' => $ok('User')],
                'patch'  => ['tags' => ['Users'], 'summary' => 'Update a user', 'parameters' => [$pathParam('id', 'User id or UUID')], 'responses' => $ok('User')],
                'delete' => ['tags' => ['Users'], 'summary' => 'Delete a user (admin)', 'parameters' => [$pathParam('id', 'User id or UUID')], 'responses' => $ok()],
            ],
            '/users/{id}/suspend'  => ['post' => ['tags' => ['Users'], 'summary' => 'Suspend an account', 'parameters' => [$pathParam('id', 'User id or UUID')], 'responses' => $ok('User')]],
            '/users/{id}/activate' => ['post' => ['tags' => ['Users'], 'summary' => 'Reactivate an account', 'parameters' => [$pathParam('id', 'User id or UUID')], 'responses' => $ok('User')]],
            '/users/{id}/quota'    => [
                'get' => ['tags' => ['Users'], 'summary' => 'Read quota usage', 'parameters' => [$pathParam('id', 'User id or UUID')], 'responses' => $ok()],
                'put' => ['tags' => ['Users'], 'summary' => 'Set the storage quota (admin)', 'parameters' => [$pathParam('id', 'User id or UUID')], 'responses' => $ok()],
            ],

            '/api-keys' => [
                'get'  => ['tags' => ['API keys'], 'summary' => 'List your API keys', 'responses' => $ok()],
                'post' => ['tags' => ['API keys'], 'summary' => 'Create a scoped API key', 'responses' => $ok('', 'Created — the plaintext key is returned once')],
            ],
            '/api-keys/{id}' => ['delete' => ['tags' => ['API keys'], 'summary' => 'Delete a key', 'parameters' => [$pathParam('id', 'Key id or UUID')], 'responses' => $ok()]],
            '/api-keys/{id}/revoke' => ['post' => ['tags' => ['API keys'], 'summary' => 'Revoke a key', 'parameters' => [$pathParam('id', 'Key id or UUID')], 'responses' => $ok()]],

            '/sftp-accounts' => [
                'get'  => ['tags' => ['SFTP'], 'summary' => 'List FTP/FTPS/SFTP accounts', 'responses' => $ok('SftpAccount')],
                'post' => ['tags' => ['SFTP'], 'summary' => 'Create an account with an isolated home', 'responses' => $ok('SftpAccount', 'Created')],
            ],
            '/sftp-accounts/{id}' => [
                'get'    => ['tags' => ['SFTP'], 'summary' => 'Account, keys, sessions and activity', 'parameters' => [$pathParam('id', 'Account id, UUID or username')], 'responses' => $ok()],
                'patch'  => ['tags' => ['SFTP'], 'summary' => 'Change permission, quota, protocol or status', 'parameters' => [$pathParam('id', 'Account id, UUID or username')], 'responses' => $ok('SftpAccount')],
                'delete' => ['tags' => ['SFTP'], 'summary' => 'Delete the account', 'parameters' => [$pathParam('id', 'Account id, UUID or username')], 'responses' => $ok()],
            ],
            '/sftp-accounts/{id}/password' => ['post' => ['tags' => ['SFTP'], 'summary' => 'Reset the password', 'parameters' => [$pathParam('id', 'Account id, UUID or username')], 'responses' => $ok()]],
            '/sftp-accounts/{id}/ssh-keys' => [
                'get'  => ['tags' => ['SFTP'], 'summary' => 'List SSH public keys', 'parameters' => [$pathParam('id', 'Account id, UUID or username')], 'responses' => $ok()],
                'post' => ['tags' => ['SFTP'], 'summary' => 'Add an SSH public key', 'parameters' => [$pathParam('id', 'Account id, UUID or username')], 'responses' => $ok('', 'Created')],
            ],
            '/sftp-accounts/{id}/ssh-keys/{keyId}' => ['delete' => ['tags' => ['SFTP'], 'summary' => 'Remove an SSH key', 'parameters' => [$pathParam('id', 'Account'), $pathParam('keyId', 'Key id')], 'responses' => $ok()]],
            '/sftp-accounts/{id}/sync' => ['post' => ['tags' => ['SFTP'], 'summary' => 'Index files uploaded over SFTP', 'parameters' => [$pathParam('id', 'Account id, UUID or username')], 'responses' => $ok()]],
            '/sessions' => ['get' => ['tags' => ['SFTP'], 'summary' => 'Active transfer sessions', 'responses' => $ok()]],
            '/sessions/{id}' => ['delete' => ['tags' => ['SFTP'], 'summary' => 'Disconnect a session', 'parameters' => [$pathParam('id', 'Session id')], 'responses' => $ok()]],
            '/sftp/services' => [
                'get' => ['tags' => ['SFTP'], 'summary' => 'Read FTP/FTPS/SFTP service state', 'responses' => $ok()],
                'put' => ['tags' => ['SFTP'], 'summary' => 'Enable/disable services, passive ports and TLS', 'responses' => $ok()],
            ],

            '/storage-backends' => [
                'get'  => ['tags' => ['Storage'], 'summary' => 'List storage backends and their usage (admin)', 'responses' => $ok()],
                'post' => ['tags' => ['Storage'], 'summary' => 'Add a local, FTP, FTPS, SFTP or S3 backend (admin)', 'responses' => $ok('', 'Created')],
            ],
            '/storage-backends/{id}' => [
                'get'    => ['tags' => ['Storage'], 'summary' => 'Read a backend', 'parameters' => [$pathParam('id', 'Backend id, UUID or slug')], 'responses' => $ok()],
                'patch'  => ['tags' => ['Storage'], 'summary' => 'Update a backend; blank credentials are kept', 'parameters' => [$pathParam('id', 'Backend id, UUID or slug')], 'responses' => $ok()],
                'delete' => ['tags' => ['Storage'], 'summary' => 'Delete a backend that holds no files', 'parameters' => [$pathParam('id', 'Backend id, UUID or slug')], 'responses' => $ok()],
            ],
            '/storage-backends/{id}/test' => ['post' => ['tags' => ['Storage'], 'summary' => 'Probe the connection', 'parameters' => [$pathParam('id', 'Backend id, UUID or slug')], 'responses' => $ok()]],
            '/storage-backends/{id}/default' => ['post' => ['tags' => ['Storage'], 'summary' => 'Send new uploads to this backend', 'parameters' => [$pathParam('id', 'Backend id, UUID or slug')], 'responses' => $ok()]],
            '/storage-backends/{id}/migrate' => ['post' => ['tags' => ['Storage'], 'summary' => 'Move stored files to another backend', 'parameters' => [$pathParam('id', 'Backend id, UUID or slug')], 'responses' => $ok()]],

            '/webhooks' => [
                'get'  => ['tags' => ['Webhooks'], 'summary' => 'List webhooks', 'responses' => $ok()],
                'post' => ['tags' => ['Webhooks'], 'summary' => 'Subscribe to events', 'responses' => $ok('', 'Created')],
            ],
            '/webhooks/{id}' => [
                'patch'  => ['tags' => ['Webhooks'], 'summary' => 'Update a subscription', 'parameters' => [$pathParam('id', 'Webhook id or UUID')], 'responses' => $ok()],
                'delete' => ['tags' => ['Webhooks'], 'summary' => 'Delete a subscription', 'parameters' => [$pathParam('id', 'Webhook id or UUID')], 'responses' => $ok()],
            ],
            '/webhooks/{id}/test' => ['post' => ['tags' => ['Webhooks'], 'summary' => 'Send a test delivery', 'parameters' => [$pathParam('id', 'Webhook id or UUID')], 'responses' => $ok()]],

            '/health'  => ['get' => ['tags' => ['System'], 'summary' => 'Health probe', 'security' => [], 'responses' => $ok()]],
            '/metrics' => ['get' => ['tags' => ['System'], 'summary' => 'CPU, memory, disk and throughput', 'responses' => $ok()]],
            '/statistics' => ['get' => ['tags' => ['System'], 'summary' => 'Upload/download statistics', 'responses' => $ok()]],
            '/audit-logs' => ['get' => ['tags' => ['System'], 'summary' => 'Read the audit trail', 'responses' => $ok()]],
            '/jobs' => ['get' => ['tags' => ['System'], 'summary' => 'Job queue state (admin)', 'responses' => $ok()]],
            '/jobs/{type}' => ['post' => ['tags' => ['System'], 'summary' => 'Run or queue a maintenance job', 'parameters' => [$pathParam('type', 'cleanup | rescan | backup | sftp.sync | …')], 'responses' => $ok()]],
            '/backups' => [
                'get'  => ['tags' => ['System'], 'summary' => 'List backups (admin)', 'responses' => $ok()],
                'post' => ['tags' => ['System'], 'summary' => 'Create a backup (admin)', 'responses' => $ok('', 'Created')],
            ],
            '/settings' => [
                'get' => ['tags' => ['System'], 'summary' => 'Read platform settings (admin)', 'responses' => $ok()],
                'put' => ['tags' => ['System'], 'summary' => 'Update platform settings (admin)', 'responses' => $ok()],
            ],
            '/ip-rules' => [
                'get'  => ['tags' => ['System'], 'summary' => 'List IP allow/block rules (admin)', 'responses' => $ok()],
                'post' => ['tags' => ['System'], 'summary' => 'Add an IP rule (admin)', 'responses' => $ok()],
            ],
        ];
    }
}
