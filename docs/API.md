# REST API v1

Base URL: `<APP_URL>/api/v1`

Machine-readable spec: `GET /api/v1/openapi.json` (OpenAPI 3.0.3, generated from
the live route table). The panel renders it under **API docs**.

---

## Authentication

Two mechanisms, interchangeable on every authenticated endpoint.

### API key (recommended for servers and automation)

```http
X-Api-Key: s3k_<prefix>_<secret>
```

Keys are shown once at creation, stored as a SHA-256 hash, carry scopes, may be
restricted to a set of IP ranges, and can be given an expiry date.

### JWT bearer token

```http
POST /auth/login
{"email": "you@example.com", "password": "…", "otp": "123456"}

→ {"access_token": "…", "refresh_token": "…", "expires_in": 3600}
```

```http
Authorization: Bearer <access_token>
```

Refresh tokens are single-use: `POST /auth/refresh` revokes the one you send and
returns a new pair. Replaying an old refresh token fails with `401`.

`otp` is required only when the account has two-factor enabled; without it the
login returns `401 two_factor_required`.

---

## Response envelope

Success:

```json
{
  "success": true,
  "data": { },
  "meta": { "pagination": { "total": 120, "page": 1, "per_page": 25, "last_page": 5 } }
}
```

Failure:

```json
{
  "success": false,
  "error": { "code": "file_not_found", "message": "File not found.", "details": { } }
}
```

`meta.pagination` is present on list endpoints. `error.details` carries
per-field messages for `validation_failed`, and context for things like
`quota_exceeded` or `incomplete_upload`.

### Status codes

| Code | Meaning |
|---|---|
| 200 / 201 / 202 | OK / created / queued |
| 400, 404, 405 | Bad request, not found, wrong verb |
| 401 | Missing or invalid credentials |
| 403 | Authenticated but not permitted (`forbidden`, `insufficient_scope`, `ip_blocked`, `csrf_mismatch`) |
| 409 | Conflict (`folder_exists`, `incomplete_upload`, `username_taken`) |
| 413 | `file_too_large` or `quota_exceeded` |
| 415 | `unsupported_file_type` |
| 422 | `validation_failed`, `checksum_mismatch`, `malware_detected` |
| 429 | `rate_limit_exceeded` — see `Retry-After` |
| 503 | Health check failing, or not installed |

---

## Scopes

| Scope | Grants |
|---|---|
| `files:read` | List, read and download files |
| `files:write` | Upload, rename, move, tag, delete |
| `folders:read` / `folders:write` | Folder listing / mutation |
| `shares:read` / `shares:write` | Share links |
| `users:read` / `users:write` | Account administration |
| `sftp:read` / `sftp:write` | Transfer accounts, keys, sessions |
| `metrics:read` | Metrics, statistics, health |
| `logs:read` | Audit and application logs |
| `jobs:write` | Maintenance jobs and backups |
| `admin` | Settings and IP rules |
| `*` | Everything the owning account can do |

A scope never widens what the underlying account may do: a `users:write` key held
by a non-admin still cannot list other users.

---

## Endpoints

### Auth
| Method | Path | Notes |
|---|---|---|
| POST | `/auth/login` | Public |
| POST | `/auth/refresh` | Public, rotates the token |
| POST | `/auth/logout` | Revokes one or all refresh tokens |
| GET | `/auth/me` | Identity, scopes, permissions, quota |
| GET | `/auth/sessions` | Active refresh tokens |
| DELETE | `/auth/sessions` | Revoke them all |

### Files
| Method | Path | Notes |
|---|---|---|
| GET | `/files` | `q`, `folder_id`, `mime`, `extension`, `tag`, `trashed`, `shared`, `min_size`, `max_size`, `from`, `to`, `sort`, `direction`, `page`, `per_page` |
| POST | `/files/upload` | multipart `file` or `files[]`; or a raw body with `X-File-Name` |
| GET | `/files/tags` | Tags in use, with counts |
| GET | `/files/{id}` | Metadata, versions, shares |
| PATCH | `/files/{id}` | `name`, `folder_id`, `tags` |
| DELETE | `/files/{id}` | `?permanent=1` to skip the trash |
| POST | `/files/{id}/restore` | Out of the trash |
| POST | `/files/{id}/copy` | Optional `folder_id` |
| GET | `/files/{id}/download` | Honours `Range`, `If-None-Match`; `?inline=1` to preview |
| GET | `/files/{id}/versions` | Version history |
| GET | `/files/{id}/versions/{v}/download` | A specific version |
| POST | `/files/{id}/versions/{v}/restore` | Roll back |
| POST | `/files/{id}/share` | `type`, `password`, `expires_at`/`expires_in`, `max_downloads`, `allow_preview` |
| DELETE | `/files/{id}/share` | Revoke every link for the file |
| POST | `/files/{id}/temporary-url` | Signed URL, `ttl` seconds |

`{id}` accepts either the numeric id or the UUID.

### Resumable uploads

```
POST   /files/multipart/init            {"filename","total_size","part_size?","folder_id?","mime?"}
POST   /files/multipart/{uploadId}/part multipart "part" + part_number (or raw body + X-Part-Number)
GET    /files/multipart/{uploadId}      → {"received_parts":[…],"missing_parts":[…],"progress":42.5}
POST   /files/multipart/{uploadId}/complete {"checksum?","tags?"}
DELETE /files/multipart/{uploadId}      abort and discard the parts
GET    /files/multipart                 your pending sessions
```

Parts may be sent in any order and re-sent safely. `complete` refuses with `409
incomplete_upload` and lists the gaps. If you pass `checksum` (SHA-256 of the
whole file), a mismatch fails with `422 checksum_mismatch` and nothing is stored.
Abandoned sessions expire after 24 hours and are swept by the `cleanup` job.

### Folders
| Method | Path | Notes |
|---|---|---|
| GET | `/folders` | `parent_id`, or `tree=1` for a flat tree |
| POST | `/folders` | `name`, `parent_id`, `color` |
| GET | `/folders/{id}` | Contents, breadcrumbs, total size |
| PATCH | `/folders/{id}` | Rename, move, recolour |
| DELETE | `/folders/{id}` | `?permanent=1` |
| POST | `/folders/{id}/restore` | |
| POST | `/folders/{id}/share` | |

Moving a folder into one of its own descendants is refused.

### Shares
`GET /shares`, `GET|PATCH|DELETE /shares/{id}` — `{id}` accepts an id, UUID or
token. Public consumption happens on the web routes `/s/{token}`,
`/s/{token}/download`, `/s/{token}/preview`.

### Users
`GET|POST /users`, `GET|PATCH|DELETE /users/{id}`,
`POST /users/{id}/suspend|activate|recalculate`,
`GET|PUT /users/{id}/quota`, `GET /users/roles`.

Non-admins may read and update only their own account. The last active
administrator cannot be demoted, suspended or deleted.

### API keys
`GET|POST /api-keys`, `GET /api-keys/scopes`, `POST /api-keys/{id}/revoke`,
`DELETE /api-keys/{id}`.

### FTP / FTPS / SFTP
| Method | Path | Notes |
|---|---|---|
| GET/POST | `/sftp-accounts` | Also aliased as `/ftp-accounts` |
| GET/PATCH/DELETE | `/sftp-accounts/{id}` | `{id}` = id, UUID or username |
| POST | `/sftp-accounts/{id}/password` | Returns the new password once |
| GET/POST | `/sftp-accounts/{id}/ssh-keys` | OpenSSH format |
| DELETE | `/sftp-accounts/{id}/ssh-keys/{keyId}` | |
| POST | `/sftp-accounts/{id}/sync` | Index the home directory |
| GET | `/sftp-accounts/{id}/activity` | Per-account transfer log |
| GET | `/sessions` · DELETE `/sessions/{id}` | Live sessions, disconnect |
| GET/PUT | `/sftp/services` | Enable/disable, ports, passive range, TLS paths |
| GET | `/sftp/openssh-config` | Generated `sshd_config` snippet |
| POST | `/sftp/authenticate` | For the daemon — see docs/SFTP.md |
| POST | `/sftp/heartbeat` | Session keepalive and byte accounting |

### Webhooks
`GET|POST /webhooks`, `PATCH|DELETE /webhooks/{id}`,
`POST /webhooks/{id}/test`, `GET /webhooks/{id}/deliveries`,
`GET /webhooks/events`.

Deliveries are signed:

```http
X-S3Lite-Event: file.uploaded
X-S3Lite-Signature: sha256=<hmac of the raw body, using the webhook secret>
```

Events: `file.uploaded`, `file.downloaded`, `file.deleted`, `file.restored`,
`share.created`, `share.accessed`, `quota.exceeded`, `user.login`,
`user.login_failed`, `sftp.upload`, `sftp.download`.

A hook that fails 20 times in a row is disabled automatically.

### System
`GET /health` and `GET /ping` are public. `GET /metrics`,
`GET /metrics/{metric}/series`, `GET /statistics`, `GET /audit-logs`,
`GET /logs/application`, `GET /logs/login-attempts`, `GET /jobs`,
`POST /jobs/{type}`, `POST /jobs/work`, `GET|POST /backups`,
`GET|DELETE /backups/{name}`, `GET|PUT /settings`, `GET|POST /ip-rules`,
`DELETE /ip-rules/{id}`.

`/health` returns `503` when a check fails, so it can drive a load balancer.

---

## Idempotency

Send `X-Idempotency-Key: <unique>` on any write. The first response is stored for
24 hours and replayed verbatim for repeats of the same key, with
`X-Idempotent-Replay: true`. A dropped connection can be retried without
uploading or deleting twice.

## Rate limiting

`X-RateLimit-Limit`, `X-RateLimit-Remaining` and `X-RateLimit-Reset` accompany
every response. Login throttling counts failures only, so signing in repeatedly
is never punished; brute force is still stopped by the per-account lockout.

## Request signing (optional)

Enable **Require signed API writes** in Settings, then send on every write:

```
X-Timestamp: <unix seconds, within 5 minutes>
X-Signature: HMAC_SHA256(
    METHOD + "\n" + PATH + "\n" + TIMESTAMP + "\n" + SHA256(body),
    <the API key's stored hash>
)
```

## Pagination, filtering, sorting

`page`, `per_page` (max 200), `sort` (`created_at`, `updated_at`, `name`, `size`,
`download_count`) and `direction` (`asc`/`desc`) are accepted by every list
endpoint. Totals come back in `meta.pagination`.
