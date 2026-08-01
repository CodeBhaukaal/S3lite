# plan.md

# PHP File Sharing System (S3-like) -- Master Plan

## Goal

Build a production-ready, self-hosted file sharing platform in PHP 8.2
that behaves like a simplified S3 service with a modern web panel, REST
API, SFTP support, monitoring, and permanent share links.

## Technology Stack

-   PHP 8.2
-   Composer (PSR-4)
-   MySQL / MariaDB
-   Nginx or Apache
-   Redis (optional)
-   JWT Authentication
-   OpenAPI / Swagger
-   Docker support
-   Queue/Cron support

## Core Features

-   User registration/login
-   Admin dashboard
-   API key management
-   JWT authentication
-   Upload / Download / Delete API
-   Permanent public links
-   Optional expiring links
-   Password-protected links
-   File preview (PDF/Image)
-   File versioning
-   Folder support
-   Trash & Restore
-   Soft delete
-   Duplicate detection (SHA-256)
-   Search
-   Tags
-   Storage quota
-   Download statistics
-   Upload statistics
-   Audit logs
-   Activity timeline

## Storage

-   Local disk
-   Future S3-compatible backend
-   Configurable storage drivers
-   Background cleanup jobs

## Security

-   MIME validation
-   Virus scan hook
-   Rate limiting
-   CSRF protection
-   XSS protection
-   SQL injection protection
-   Secure signed URLs
-   Optional 2FA
-   HTTPS only mode

## REST API

-   Login
-   Refresh token
-   Upload
-   Multipart upload
-   Download
-   Delete
-   Rename
-   Move
-   Folder CRUD
-   User CRUD
-   Admin APIs
-   Statistics
-   Health endpoint

## SFTP

-   Built-in SFTP integration
-   Per-user SFTP accounts
-   Isolated home directories
-   Read-only / Read-write permissions
-   Storage quotas
-   Password or SSH key authentication
-   Admin-managed credentials
-   Uploads visible instantly in web panel
-   Downloads reflected in logs
-   Disable/enable accounts
-   Activity logs


## Full API Control

The entire platform must be controllable through a secure REST API so that a PHP, Java, Android, web, desktop, or automation client can manage everything without opening the admin panel.

### API-Controlled Operations
- Create, update, suspend, and delete users
- Create and revoke API keys
- Create, update, disable, and delete FTP/FTPS/SFTP accounts
- Reset FTP/SFTP passwords
- Add and remove SFTP SSH public keys
- Set read-only or read-write permissions
- Set per-user storage quotas
- Create, rename, move, and delete folders
- Upload files
- Multipart and resumable upload
- Generate permanent download URLs
- Generate temporary signed URLs
- Enable or disable public sharing
- Set link passwords and expiry
- Rename, move, copy, restore, and permanently delete files
- Read file metadata
- List files and folders
- Search files
- View upload/download statistics
- View active FTP/SFTP sessions
- Disconnect active sessions
- View storage, bandwidth, and health metrics
- Read audit logs
- Trigger cleanup, indexing, backup, and rescan jobs
- Enable or disable FTP, FTPS, and SFTP services
- Update passive FTP ports and FTPS TLS settings
- Manage IP allowlists and blocklists

### Suggested API Endpoints
- `POST /api/v1/auth/login`
- `POST /api/v1/auth/refresh`
- `GET /api/v1/users`
- `POST /api/v1/users`
- `PATCH /api/v1/users/{id}`
- `DELETE /api/v1/users/{id}`
- `GET /api/v1/files`
- `POST /api/v1/files/upload`
- `POST /api/v1/files/multipart/init`
- `POST /api/v1/files/multipart/{uploadId}/part`
- `POST /api/v1/files/multipart/{uploadId}/complete`
- `GET /api/v1/files/{id}`
- `PATCH /api/v1/files/{id}`
- `DELETE /api/v1/files/{id}`
- `POST /api/v1/files/{id}/restore`
- `POST /api/v1/files/{id}/share`
- `DELETE /api/v1/files/{id}/share`
- `GET /api/v1/folders`
- `POST /api/v1/folders`
- `PATCH /api/v1/folders/{id}`
- `DELETE /api/v1/folders/{id}`
- `GET /api/v1/ftp-accounts`
- `POST /api/v1/ftp-accounts`
- `PATCH /api/v1/ftp-accounts/{id}`
- `DELETE /api/v1/ftp-accounts/{id}`
- `GET /api/v1/sftp-accounts`
- `POST /api/v1/sftp-accounts`
- `PATCH /api/v1/sftp-accounts/{id}`
- `DELETE /api/v1/sftp-accounts/{id}`
- `POST /api/v1/sftp-accounts/{id}/ssh-keys`
- `DELETE /api/v1/sftp-accounts/{id}/ssh-keys/{keyId}`
- `GET /api/v1/sessions`
- `DELETE /api/v1/sessions/{id}`
- `GET /api/v1/metrics`
- `GET /api/v1/audit-logs`
- `POST /api/v1/jobs/rescan`
- `POST /api/v1/jobs/cleanup`
- `POST /api/v1/jobs/backup`

### API Security
- JWT access and refresh tokens
- API keys with scopes
- Role-based access control
- Per-endpoint permissions
- Rate limiting
- IP restrictions
- Request signing for sensitive operations
- Idempotency keys for uploads and deletes
- Audit logging for every write request
- OpenAPI/Swagger documentation
- Consistent JSON response format
- Pagination, filtering, and sorting
- Webhooks for upload, download, delete, quota, and login events

## Monitoring

-   Dashboard
-   CPU/RAM usage
-   Disk usage
-   Upload speed
-   Download speed
-   Active sessions
-   Failed logins
-   API usage
-   Error logs
-   Health checks

## Admin Panel

-   User management
-   Roles & permissions
-   File browser
-   Quotas
-   Settings
-   Logs
-   API keys
-   SFTP management
-   Backup controls

## Database

Tables: - users - api_keys - roles - permissions - files - folders -
downloads - uploads - audit_logs - settings - sftp_accounts - sessions

## Development Phases

1.  Authentication
2.  Storage engine
3.  REST API
4.  Admin panel
5.  Public sharing
6.  Monitoring
7.  SFTP integration
8.  Testing
9.  Documentation
10. Production deployment

# AI Prompt

Build a production-ready, open-source style PHP 8.2 file sharing
platform similar to AWS S3 (simplified). Use clean PSR-4 architecture,
Composer, MySQL, REST API, JWT authentication, API keys, admin panel,
user management, folders, permanent share links, optional expiring
links, password-protected links, chunked uploads, resumable uploads,
local storage driver, duplicate detection using SHA-256, previews for
PDF/images, quotas, audit logs, monitoring dashboard, statistics,
OpenAPI documentation, Docker support, Redis optional caching,
background jobs, secure downloads, role-based permissions,
trash/restore, soft delete, search, tags, rate limiting, CSRF/XSS/SQL
injection protection, install wizard, .env configuration, backup tools,
health endpoints, and full SFTP integration with per-user accounts,
isolated home directories, SSH key support, storage quotas, read/write
permissions, activity logging, and synchronization between SFTP uploads
and the web panel. Produce clean production-quality code with tests,
documentation, and deployment instructions.
