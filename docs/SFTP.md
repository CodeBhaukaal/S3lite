# FTP / FTPS / SFTP integration

## How the pieces fit

PHP cannot be an SSH daemon, so this platform does not pretend to be one. It owns
everything *around* the transfer:

- the account database (usernames, password hashes, SSH public keys, status),
- an isolated home directory per account under `storage/sftp/<username>/`,
- permissions (read-only or read-write), quotas and IP allowlists,
- session tracking, byte accounting and a per-account activity log,
- a sync job that indexes whatever lands in a home directory so it appears in
  the web panel, with quota and MIME rules applied exactly as for web uploads.

A real daemon — OpenSSH, FileZilla Server, ProFTPD, `atmoz/sftp`, anything —
does the wire protocol. There are two ways to connect it.

---

## Option A — OpenSSH with a generated config (simplest)

The panel generates a chroot configuration from the live account list.

```bash
php bin/console sftp:config >> /etc/ssh/sshd_config
systemctl reload sshd
```

or download it from **Admin → FTP / SFTP → sshd_config snippet**. It produces one
block per active account:

```
Match User backup-agent
    ChrootDirectory /var/www/html/storage/sftp/backup-agent
    ForceCommand internal-sftp
    AllowTcpForwarding no
    X11Forwarding no
    AuthorizedKeysFile /var/www/html/storage/sftp/backup-agent/.ssh/authorized_keys
```

Read-only accounts get `internal-sftp -R`. Every SSH key added through the panel
or the API is written to that account's `authorized_keys` immediately, so key
management stays in one place.

Regenerate after adding or removing accounts.

> OpenSSH requires a chroot directory owned by `root` and not group/world
> writable. Create the home as root, then make the `upload/` subdirectory
> writable by the transfer user.

---

## Option B — a daemon that calls the API (dynamic)

For daemons that support an external authentication hook, no config regeneration
is needed. Create an API key with the `sftp:write` scope and point the daemon at:

```http
POST /api/v1/sftp/authenticate
X-Api-Key: s3k_…

{ "username": "backup-agent", "password": "…", "client_ip": "203.0.113.9", "client": "OpenSSH_9.6" }
```

```json
{
  "success": true,
  "data": {
    "authenticated": true,
    "home_dir": "/var/www/html/storage/sftp/backup-agent",
    "permission": "rw",
    "session_key": "…"
  }
}
```

For key-based auth send `fingerprint` (`SHA256:…`) instead of `password`.

Failures return `401` with a precise reason: `unknown_account`,
`account_disabled`, `service_disabled`, `ip_not_allowed`, `invalid_credentials`,
`unknown_key`. Every attempt, successful or not, is written to the activity log.

Then keep the session alive and report transfers:

```http
POST /api/v1/sftp/heartbeat
{ "session_key": "…", "bytes_in": 4096, "bytes_out": 0,
  "action": "put", "path": "/upload/report.pdf", "size": 4096 }
```

Sessions appear live in **Admin → FTP / SFTP** and on the monitoring page, and
can be disconnected from there or via `DELETE /api/v1/sessions/{id}`. Sessions
idle for 15 minutes are closed by the `cleanup` job.

---

## Making uploads appear in the panel

Files dropped over SFTP are on disk but not yet indexed. The sync job walks each
active account's home directory and:

- creates a `SFTP - <username>` folder in the owner's file browser,
- mirrors the directory structure below it,
- imports new files and records a new **version** for changed ones,
- skips files that would breach the quota or the MIME rules, logging why,
- soft-deletes index entries whose files disappeared over SFTP.

Run it on a schedule:

```cron
*/10 * * * * php /path/to/s3/bin/console sftp:sync
```

or on demand from the panel, or via `POST /api/v1/sftp-accounts/{id}/sync`.

Hidden files and `.ssh/` are never imported.

---

## Docker

`docker-compose.yml` includes an optional `atmoz/sftp` service sharing the same
storage volume:

```yaml
sftp:
  image: atmoz/sftp:alpine
  ports: ["2222:22"]
  volumes:
    - storage:/home
```

Because both containers mount the same volume, the sync job sees the uploads.

---

## Managing accounts

Panel: **Settings → FTP / SFTP** for your own accounts, **Admin → FTP / SFTP**
for everyone's.

API:

```bash
# Create
curl -H "X-Api-Key: $KEY" -X POST $BASE/sftp-accounts \
     -d username=backup-agent -d password='S3cretPass!' \
     -d permission=rw -d quota_bytes=10737418240

# Switch to read-only, then disable
curl -H "X-Api-Key: $KEY" -X PATCH $BASE/sftp-accounts/backup-agent -d permission=ro
curl -H "X-Api-Key: $KEY" -X PATCH $BASE/sftp-accounts/backup-agent -d status=disabled

# Rotate the password (returned once)
curl -H "X-Api-Key: $KEY" -X POST $BASE/sftp-accounts/backup-agent/password

# Add an SSH key
curl -H "X-Api-Key: $KEY" -X POST $BASE/sftp-accounts/backup-agent/ssh-keys \
     -d name=laptop --data-urlencode "public_key=$(cat ~/.ssh/id_ed25519.pub)"
```

Disabling an account or resetting its password drops its live sessions.

CLI: `php bin/console sftp:sync`, `php bin/console sftp:config`.

---

## Notes

- Usernames are 3–32 characters: letters, digits, dot, dash, underscore.
- Deleting an account leaves its files unless you pass `remove_files=1`; the
  delete never touches anything outside the configured home root.
- Plain FTP is off by default. Prefer SFTP, or FTPS with a certificate
  configured under **Admin → Settings → Transfer services**.
- Account quota (bytes on disk in the home directory) is tracked separately from
  the owner's platform quota; the sync job enforces the platform quota on import.
