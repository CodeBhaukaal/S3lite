# Deployment

## Nginx + PHP-FPM

```nginx
server {
    listen 443 ssl http2;
    server_name files.example.com;

    ssl_certificate     /etc/letsencrypt/live/files.example.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/files.example.com/privkey.pem;

    root /var/www/s3/public;
    index index.php;

    # Large uploads; chunked uploads are unaffected by this.
    client_max_body_size 2048M;
    client_body_timeout 600s;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_read_timeout 600;
        # Large downloads should stream, not buffer.
        fastcgi_buffering off;
    }

    # Nothing outside public/ is served, but be explicit.
    location ~ /\.(env|git) { deny all; }

    add_header X-Content-Type-Options nosniff always;
    add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;
}

server {
    listen 80;
    server_name files.example.com;
    return 301 https://$host$request_uri;
}
```

Set `FORCE_HTTPS=true` in `.env` once TLS is in place.

## Apache

Point the vhost `DocumentRoot` at `public/` and allow overrides:

```apache
<VirtualHost *:443>
    ServerName files.example.com
    DocumentRoot /var/www/s3/public

    <Directory /var/www/s3/public>
        AllowOverride All
        Require all granted
    </Directory>

    SSLEngine on
    SSLCertificateFile    /etc/letsencrypt/live/files.example.com/fullchain.pem
    SSLCertificateKeyFile /etc/letsencrypt/live/files.example.com/privkey.pem
</VirtualHost>
```

`mod_rewrite` and `mod_headers` should be enabled. The bundled `.htaccess`
handles routing; if `AllowOverride` is unavailable the router also accepts
`/index.php/path` URLs.

## PHP settings

```ini
upload_max_filesize = 2048M
post_max_size       = 2048M
memory_limit        = 512M
max_execution_time  = 600
max_input_time      = 600
expose_php          = Off

opcache.enable = 1
opcache.validate_timestamps = 0   ; production only — reload PHP after deploys
```

Downloads stream in 256 KB chunks and never load a whole file into memory, so
`memory_limit` does not cap file size.

## Permissions

```bash
chown -R www-data:www-data storage
chmod -R 775 storage
chmod 640 .env
```

Only `public/` should be reachable over HTTP. `storage/` ships with a deny-all
`.htaccess` as a second line of defence.

## Scheduled work

```cron
* * * * *    php /var/www/s3/bin/console queue:run-once
*/5 * * * *  php /var/www/s3/bin/console job:run metrics.sample
*/10 * * * * php /var/www/s3/bin/console sftp:sync
0 3 * * *    php /var/www/s3/bin/console job:run cleanup
0 4 * * *    php /var/www/s3/bin/console job:run backup
0 5 * * 0    php /var/www/s3/bin/console job:run integrity.check
```

`cleanup` purges expired trash, dead share links, stale upload sessions, expired
refresh tokens and idle transfer sessions.

## Backups

`bin/console backup:create` writes a full SQL dump to `storage/backups/` in pure
PHP — no `mysqldump` needed — and keeps the ten most recent. Restore with:

```bash
mysql -u root -p s3lite < storage/backups/backup-20260801-030000.sql
```

Back up `storage/files/` alongside the database; the two must be restored
together. `bin/console job:run integrity.check` reports index entries whose files
are missing and blobs with no index entry.

## Upgrades

```bash
git pull
php bin/console migrate
php bin/console cache:clear
```

Migrations are tracked in the `migrations` table and applied once each.

## Health monitoring

`GET /api/v1/health` returns `200` when healthy and `503` when a check fails —
suitable for a load balancer or uptime probe. `php bin/console health` gives the
same report on the command line and exits non-zero on failure.

## Scaling notes

- Enable Redis so rate limits and caches are shared across web nodes.
- `storage/files/` must be shared storage (NFS, EFS) across nodes, or switch
  `STORAGE_DRIVER=s3` and set the `S3_*` variables.
- Run exactly one queue worker, or partition by queue name.
- Sessions use PHP's default handler; configure a shared session store, or use
  sticky sessions, when running more than one node.
