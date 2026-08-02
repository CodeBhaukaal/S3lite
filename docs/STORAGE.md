# Storage backends

Files do not have to live next to the code. A backend is any place bytes can go:
the local disk, an FTP or FTPS server, an SFTP server, or an S3-compatible bucket.
Add as many as you like from **Admin → Storage**.

> Not to be confused with [docs/SFTP.md](SFTP.md). That is about letting *other
> people* upload **into** this platform over FTP/SFTP. This page is about where
> this platform **puts** the files it already has.

---

## How routing works

- One backend is the **default**. Every new upload — web panel, API, SFTP sync,
  multipart — goes there.
- Every file row remembers the backend it was written to (`files.disk`), so
  changing the default never breaks an existing download. Old files keep being
  read from where they actually are.
- API clients can override the destination per upload with a `storage` form
  field or an `X-Storage-Backend` header (see below).
- To move files that already exist, use **Migrate files** — see
  [Moving files between backends](#moving-files-between-backends).

---

## Adding an FTP or FTPS backend

**Admin → Storage → Add backend**, choose FTP or FTPS, then fill in:

| Field | Notes |
| --- | --- |
| Host | Host name or IP of the remote server. |
| Port | Defaults to 21 for both FTP and FTPS. |
| Username / Password | The FTP account. The password is encrypted with `APP_KEY` before it is stored. |
| Base directory | Everything is written under this path, e.g. `s3lite`. Leave blank to use the login directory. |
| Passive mode | Leave on. Almost every server behind a firewall needs it. |
| Trust the address in PASV replies | Turn **off** if the server sits behind NAT and transfers hang after connecting. |
| Timeout | Seconds to wait for the control connection. |

Press **Add and test** — the backend is saved and immediately probed by writing,
reading back and deleting a small file. The result shows in the status column,
and you can re-run it any time with **Test connection**.

FTPS here means *explicit* TLS (`AUTH TLS` on the normal port), which is what
almost every host means by FTPS. Implicit FTPS on port 990 is not supported.

**Requirements:** the PHP `ftp` extension. The Storage page warns you if it is
missing.

### FTPS is correct but slower

PHP's `ftp` extension cannot reuse a connection for more than one TLS data
transfer against most servers — the first upload succeeds and everything after
it fails. The driver works around this by reconnecting for each transfer, so
FTPS is roughly three times slower than plain FTP on the same server. Logins
also get one automatic retry, because busy servers reject the occasional
handshake for no lasting reason.

If the link between the two machines is already private (same datacentre, VPN,
or a firewalled internal address), plain FTP is the faster choice. Across the
open internet, take the slowdown and use FTPS — or SFTP, which has neither
problem.

### Keep your files in their own directory

Set a base directory (say `s3lite`) whenever the FTP account is shared with
something else, such as cPanel account backups. Everything this platform writes
then stays inside that one folder, and the orphan-pruning and integrity jobs
never look at anything outside it.

### Plain FTP sends credentials in the clear

Prefer FTPS or SFTP whenever the remote server offers them. Plain FTP is fine
inside a private network and a bad idea across the internet.

---

## Adding an SFTP backend

Same form, with two extra options:

- **Private key** — paste an OpenSSH private key instead of using a password.
  It is encrypted at rest and written to a `0600` scratch file only for the
  duration of a transfer.
- **Host fingerprint** — optional pinning. A 32-character hex string is treated
  as an MD5 fingerprint; anything else as a base64 SHA-256 fingerprint. Without
  it, any host key is accepted.

**Requirements:** a cURL build with SSH support. Check with:

```bash
php -r "print_r(curl_version()['protocols']);"
```

If `sftp` is not in that list, this server cannot use SFTP backends — the
Storage page says so, and FTPS is the next best option.

A base directory starting with `/` is absolute; anything else is relative to the
login user's home directory.

---

## Adding an S3 bucket

Works with AWS S3 and anything that speaks the same API (MinIO, Backblaze B2,
Wasabi, Hetzner Object Storage, …). You need the endpoint, region, bucket,
access key and secret key. No SDK is required — requests are signed with SigV4
directly.

The older `.env`-only configuration (`STORAGE_DRIVER=s3` plus the `S3_*`
variables) still works and needs no migration, but a panel-managed backend is
easier to change and lets you have more than one.

---

## Moving files between backends

**Admin → Storage → ⋯ → Migrate files** queues a background job. Run the queue
to start it:

```bash
php bin/console queue:work
```

Each object is streamed to the destination, verified by size, and only then
removed from the source. The job works in batches and re-queues itself until the
source is empty, so it is safe to stop and restart — whatever is left behind is
simply picked up next time.

Over the API:

```bash
curl -X POST https://example.com/api/v1/storage-backends/2/migrate \
  -H "X-Api-Key: $KEY" \
  -H "Content-Type: application/json" \
  -d '{"to": "hetzner-box", "limit": 200}'
```

Add `"queue": true` to hand it to the worker instead of running it inline.
The synchronous response includes `remaining`, so a script can loop until zero.

A backend cannot be deleted while files still point at it, and the default
backend cannot be deleted at all — make another one the default first.

---

## Choosing a destination per upload

```bash
# Form field
curl -X POST https://example.com/api/v1/files/upload \
  -H "X-Api-Key: $KEY" \
  -F "file=@report.pdf" \
  -F "storage=hetzner-box"

# Or a header, which also works for raw-body and multipart uploads
curl -X PUT https://example.com/api/v1/files/upload \
  -H "X-Api-Key: $KEY" \
  -H "X-File-Name: report.pdf" \
  -H "X-Storage-Backend: hetzner-box" \
  --data-binary @report.pdf
```

The value is the backend **slug**, shown under its name on the Storage page.
An unknown or disabled slug returns `422 unknown_storage_backend`; omit the
field entirely to use the default backend.

---

## Managing backends over the API

All of these need an `admin` scope key.

| Method | Path | Purpose |
| --- | --- | --- |
| `GET` | `/api/v1/storage-backends` | List backends, usage, the current default and which protocols this server supports |
| `POST` | `/api/v1/storage-backends` | Create one |
| `GET` | `/api/v1/storage-backends/{id}` | Read one (accepts an id, uuid or slug) |
| `PATCH` | `/api/v1/storage-backends/{id}` | Update. Blank credential fields keep the stored value |
| `DELETE` | `/api/v1/storage-backends/{id}` | Delete, if no files point at it |
| `POST` | `/api/v1/storage-backends/{id}/test` | Probe the connection (`502` when it fails) |
| `POST` | `/api/v1/storage-backends/{id}/default` | Send new uploads here |
| `POST` | `/api/v1/storage-backends/{id}/migrate` | Move files to another backend |

Credentials are write-only. Responses only tell you *whether* a password, key or
secret is set, never what it is.

---

## Things worth knowing

- **Credentials are encrypted** with `APP_KEY` (AES-256-GCM). Rotating that key
  makes stored credentials unreadable; re-enter them if you ever do.
- **Free-space reporting** only works for local disks. Remote backends report
  nothing, so the monitoring page shows the disk check as *skipped* rather than
  inventing a number. The Storage page still shows how many bytes this platform
  has put on each one.
- **Orphan pruning and the local integrity scan** only walk local backends;
  listing a remote server recursively is neither portable nor cheap. Missing-file
  detection still runs against every backend.
- **Range requests** (video seeking, resumable downloads) are passed through to
  the backend, so a seek does not drag the whole file across the network first.
- **Latency is real.** Every upload to a remote backend is a second network hop.
  If the remote server is far away, uploads feel slower even though nothing is
  wrong. Measure before you worry: a quick `Test connection` reports the
  round-trip for a write, a read and a delete.
- **Watch `max_execution_time`** on slow links. A large file has to be pushed to
  the remote server inside the same PHP request that received it, so a 100 MB
  upload over a 200 KB/s link needs more than the default 30 seconds. Raise the
  limit, or keep large files on a backend that is close to the server.
- **Thumbnails and metadata** (image dimensions, EXIF, PDF page counts) are
  extracted from the temporary file before it leaves this server, so they work
  the same on every backend.
