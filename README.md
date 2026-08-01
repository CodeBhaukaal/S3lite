# S3 Lite

**A self-hosted, S3-like file platform written in plain PHP 8.2.**

Upload, organise and share files through a modern web panel or a complete REST
API — with permanent and expiring share links, resumable chunked uploads,
per-user quotas, file versioning, FTP/FTPS/SFTP account management, live
monitoring and a full audit trail.

No framework. No Composer packages. Drop it in a web root and open the browser —
an install wizard does the rest.

[![PHP](https://img.shields.io/badge/PHP-8.2%2B-777bb4)](https://www.php.net/)
[![License](https://img.shields.io/badge/license-MIT-green)](LICENSE)
[![Tests](https://img.shields.io/badge/tests-96%20passing-brightgreen)](#tests)

---

## Why it exists

Most self-hosted file platforms want you to install a framework, a package
manager, a build toolchain and a queue daemon before you can upload a single
file. This one needs PHP and MySQL.

Everything else is optional and degrades gracefully: no Redis extension? A
pure-PHP client is bundled, and if Redis is unreachable it falls back to a file
cache. No `ext-zip` or `ext-gd`? Those features simply stay out of the way.

At the same time it does not cut corners where it counts — prepared statements
everywhere, CSRF on every form, real MIME/content inspection on uploads, bcrypt
passwords, AES-256-GCM secrets, scoped API keys, rate limiting and an audit log
of every write.

---

## Screens

| | |
|---|---|
| **Dashboard** | storage usage, transfer charts, recent files, activity |
| **Files** | grid/list browser, drag-and-drop upload, folders, tags, search, preview |
| **Share links** | permanent, expiring, password-protected, download-limited |
| **Admin** | users, roles, all files, FTP/SFTP, monitoring, jobs, backups, logs, IP rules |
| **API docs** | generated reference with copy-paste cURL / PHP / JS / Java examples |

The interface uses inline SVG icons only — no emoji, no icon fonts, no external
requests — and is fully responsive down to a 360 px phone.

---

## Quick start (XAMPP / WAMP / any Apache + PHP host)

1. Drop the project inside your web root, e.g. `htdocs/s3`.
2. Point a browser at `http://localhost/s3/public/`.
3. The install wizard checks requirements, tests the database and Redis, writes
   `.env`, creates the schema and **your administrator account**.

That is the whole setup.

### Install from the command line instead

```bash
php bin/console install \
  --app-url=http://localhost/s3/public \
  --db-name=s3lite --db-user=root --db-pass= \
  --redis --redis-host=127.0.0.1 \
  --admin-email=you@example.com --admin-password='ChooseAStrongOne123'
```

---

## Administrator account &amp; password

There is **no default account and no default password** — nothing is shipped
pre-seeded, so a fresh install cannot be reached by anyone until you create the
first administrator yourself.

### 1. Creating the first administrator

You do this once, during installation:

- **Install wizard** — step 4 asks for the name, email and password.
- **Command line** — the `--admin-email` and `--admin-password` flags above.
- **Docker** — set `ADMIN_EMAIL` and `ADMIN_PASSWORD` in `docker-compose.yml`
  before the first `docker compose up`; the entrypoint installs unattended.

Password rules: at least 8 characters, containing both letters and numbers.
The account is created with an unlimited quota and every permission.

### 2. Changing the password later

**From the panel** — sign in, then **Profile → Change password**. You must enter
the current password, and every API refresh token is revoked on success.

**From the command line** — useful when the password is lost, since this does not
ask for the old one:

```bash
php bin/console user:password you@example.com 'NewStrongPassword123'
```

**Over the API** — as an administrator:

```bash
curl -X PATCH "$BASE/users/{id}" \
     -H "X-Api-Key: s3k_…" \
     -d "password=NewStrongPassword123"
```

### 3. Locked out?

Five failed attempts lock an account for 15 minutes (both numbers are
configurable under **Admin → Settings → Security**). Clear it without waiting:

```bash
php bin/console user:list                      # find the account
php bin/console user:password you@example.com 'NewStrongPassword123'
```

Or, if you can still sign in as another administrator, use
**Admin → Users → ⋯ → Clear lockout**.

### 4. Adding more administrators

```bash
php bin/console user:create colleague@example.com 'StrongPassword123' 'Their Name' admin
php bin/console user:promote colleague@example.com     # promote an existing user
```

The last active administrator cannot be demoted, suspended or deleted, so you
can never lock yourself out of the admin panel entirely.

### 5. Hardening the account

- Turn on two-factor authentication: **Profile → Enable 2FA** (TOTP, with
  single-use recovery codes shown once).
- Prefer scoped API keys over your password for automation —
  **Profile → API keys** lets you limit a key to, say, `files:read` only.
- Set `APP_DEBUG=false` and `FORCE_HTTPS=true` in `.env` before going live.

> The credentials in `tests/run.php` (`admin@s3lite.test`) are local test
> fixtures for the bundled suite, not a real account. Override them with
> `--admin=` / `--password=` or the `S3_TEST_ADMIN` / `S3_TEST_PASSWORD`
> environment variables.

---

## Quick start (Docker)

```bash
docker compose up -d --build
```

The stack brings up the app on `http://localhost:8080`, MariaDB, Redis and an
optional SFTP front end on port 2222.

**Change these before the first run** in `docker-compose.yml` — they are
placeholders, not defaults to keep:

```yaml
ADMIN_EMAIL: you@example.com
ADMIN_PASSWORD: ChooseAStrongOne123
DB_PASSWORD: a-strong-database-password
```

---

## Requirements

| Requirement | Notes |
|---|---|
| PHP 8.2+ | `pdo_mysql`, `mbstring`, `openssl`, `fileinfo`, `json`, `curl` |
| MySQL 5.7+ / MariaDB 10.4+ | Created automatically if the user may do so |
| Apache or Nginx | `mod_rewrite` (an `index.php/path` fallback exists) |
| Redis | Optional. A bundled pure-PHP client is used, so `php_redis` is not needed |
| GD, zip | Optional |

The install wizard reports all of these before it writes anything.

---

## What it does

**Storage** — upload, download, preview, rename, move, copy, tag, search,
folders, trash with retention, versioning with rollback, SHA-256 duplicate
detection, per-user quotas, pluggable drivers (local today, S3-compatible
included).

**Sharing** — permanent links, expiring links, password protection, download
limits, preview toggles, and HMAC-signed temporary URLs that need no account.

**API** — every panel action is available over REST with JWT or scoped API keys,
including resumable multipart uploads, idempotency keys, request signing,
webhooks and a generated OpenAPI 3 document.

**Transfer accounts** — FTP/FTPS/SFTP accounts with isolated home directories,
read-only or read-write access, quotas, SSH public keys, session tracking and a
sync job that indexes anything uploaded out-of-band into the web panel.

**Operations** — health checks, CPU/memory/disk metrics, throughput, job queue,
database backups, audit log, application log viewer, IP allow/block rules.

---

## Layout

```
bin/console            CLI entry point
config/                app, database, cache, storage, sftp
database/migrations/   schema, applied once each and tracked
public/                web root — index.php and assets
resources/views/       templates (plain PHP, layout inheritance)
routes/                web.php and api.php
src/
  Core/                App, Config, Database, Cache, RedisClient, View, Logger
  Http/                Request, Response, Router, Session, Validator
  Middleware/          auth, CSRF, throttle, scopes, idempotency, IP filter
  Models/              thin data layer over PDO
  Services/            all business logic
  Storage/             driver interface, local + S3 drivers
  Controllers/         Web/ and Api/
storage/               files, chunks, logs, cache, backups, sftp homes
tests/                 unit + HTTP integration suite
```

---

## Console

```bash
php bin/console help              # every command
php bin/console about             # environment and platform summary
php bin/console health            # health report, non-zero exit on failure
php bin/console migrate           # apply pending migrations
php bin/console user:create <email> <password> [name] [role]
php bin/console user:promote <email>
php bin/console queue:work        # drain the job queue
php bin/console job:run cleanup   # run one maintenance job
php bin/console backup:create
php bin/console sftp:sync         # index files uploaded over SFTP
php bin/console routes            # list every route
php bin/console test              # run the test suite
```

### Scheduling

```cron
* * * * *   php /path/to/s3/bin/console queue:run-once
*/5 * * * * php /path/to/s3/bin/console job:run metrics.sample
0 3 * * *   php /path/to/s3/bin/console job:run cleanup
0 4 * * *   php /path/to/s3/bin/console job:run backup
*/10 * * * * php /path/to/s3/bin/console sftp:sync
```

On Windows, create equivalent Task Scheduler entries calling `php.exe bin\console`.

---

## API

Base URL: `<APP_URL>/api/v1`. Full reference in the panel under **API docs**, and
machine-readable at `/api/v1/openapi.json`.

```bash
# JWT
curl -X POST $BASE/auth/login -H 'Content-Type: application/json' \
     -d '{"email":"admin@example.com","password":"Secret123"}'

# API key
curl -H "X-Api-Key: s3k_…" $BASE/files

# Upload
curl -H "X-Api-Key: s3k_…" -F "file=@report.pdf" $BASE/files/upload

# Share
curl -H "X-Api-Key: s3k_…" -X POST -d "expires_in=86400" -d "password=hunter2" \
     $BASE/files/{id}/share
```

Responses always use one envelope:

```json
{ "success": true,  "data": { }, "meta": { "pagination": { } } }
{ "success": false, "error": { "code": "file_not_found", "message": "…" } }
```

Scopes: `files:read`, `files:write`, `folders:*`, `shares:*`, `users:*`,
`sftp:*`, `metrics:read`, `logs:read`, `jobs:write`, `admin`, `*`.

See [docs/API.md](docs/API.md) for the full endpoint list, and
[docs/SFTP.md](docs/SFTP.md) for wiring up a real SFTP daemon.

---

## Security

- Prepared statements everywhere; no string-built SQL.
- Output escaped by default in templates (`e()`), with a strict CSP.
- CSRF tokens on every state-changing form.
- bcrypt (cost 12) passwords, AES-256-GCM for secrets at rest, HS256 JWTs.
- Per-account lockout plus a looser per-IP ceiling, so shared NAT users cannot
  lock each other out.
- Uploads are checked by real MIME type and by content, not by filename; an
  extension blocklist and a pluggable virus-scan hook sit on top.
- Downloads are served with `nosniff` and a safe content type, never as HTML.
- Optional TOTP two-factor with single-use recovery codes.
- Rate limiting, IP allow/block rules, idempotency keys, optional request signing.
- Every write is recorded in the audit log.

Report the details you would want to know in an incident: the audit log records
actor, action, entity, IP, user agent, method, path and outcome.

---

## Tests

```bash
php bin/console test
php tests/run.php --filter=Api
php tests/run.php --url=http://localhost:8080 --admin=admin@example.com --password=Secret123
```

96 tests covering the support libraries, the REST API end to end (including
resumable uploads, share passwords, scope enforcement and SFTP account
lifecycle) and the web panel (sessions, CSRF, every page, security headers,
responsive layout).

The suite talks to a running server, so start Apache first. It cleans up
everything it creates, and clears its own rate-limit counters and login lockout
on start so consecutive runs stay repeatable — it does not weaken the defaults
it is testing.

---

## Configuration

All settings live in `.env`; runtime-editable ones are also in the admin panel
under **Settings**. Notable keys:

| Key | Default | Purpose |
|---|---|---|
| `APP_URL` | — | Public URL of `public/` |
| `STORAGE_DRIVER` | `local` | `local` or `s3` |
| `MAX_UPLOAD_SIZE` | 5 GB | Per-file ceiling |
| `CHUNK_SIZE` | 8 MB | Above this, uploads become resumable |
| `DEFAULT_QUOTA` | 10 GB | Per-user, `0` = unlimited |
| `TRASH_RETENTION_DAYS` | 30 | Auto-purge window |
| `VERSIONS_KEPT` | 10 | Versions retained per file |
| `RATE_LIMIT_API` | 600/min | Per identity |
| `REDIS_ENABLED` | true | Falls back to the file cache |
| `FORCE_HTTPS` | false | Redirect and send HSTS |

PHP's own `upload_max_filesize` and `post_max_size` still apply — raise them for
large uploads, or rely on chunked uploads, which are unaffected.

---

## License

MIT.
