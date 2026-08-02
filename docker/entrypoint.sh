#!/usr/bin/env bash
set -e

cd /var/www/html

# Only the web server needs the database wait and the first-run installer.
# One-off commands — `docker compose run app php bin/console …`, or a shell to
# look around with — should start immediately instead of sitting through a
# sixty second wait for a database they may not even use.
case "${1:-}" in
    apache2-foreground|apache2|httpd|httpd-foreground) ;;
    *) exec "$@" ;;
esac

echo "[s3lite] waiting for the database at ${DB_HOST:-db}:${DB_PORT:-3306} ..."
for i in $(seq 1 60); do
    if php -r '
        $h = getenv("DB_HOST") ?: "db";
        $p = (int) (getenv("DB_PORT") ?: 3306);
        exit(@fsockopen($h, $p, $e, $s, 1) ? 0 : 1);
    '; then
        echo "[s3lite] database is reachable"
        break
    fi
    sleep 1
done

if [ ! -f .env ]; then
    echo "[s3lite] creating .env from .env.example"
    cp .env.example .env
    php bin/console key:generate
fi

# In a container the environment is the source of truth, not the file.
#
# .env lives in the container's own layer while storage/installed.lock lives in
# a volume, so rebuilding the image hands the new container a pristine .env
# copied from .env.example — pointing at 127.0.0.1 — while the lock still says
# "installed". The installer is then skipped and every database-backed page
# fails. Re-applying the environment on each boot keeps the two in step.
php -r '
require "/var/www/html/autoload.php";

$values = [];
$keys = [
    "APP_NAME", "APP_ENV", "APP_URL", "APP_DEBUG", "APP_TIMEZONE", "FORCE_HTTPS",
    "DB_HOST", "DB_PORT", "DB_DATABASE", "DB_USERNAME", "DB_PASSWORD",
    "REDIS_HOST", "REDIS_PORT", "REDIS_PASSWORD",
    "MAIL_DRIVER", "MAIL_HOST", "MAIL_PORT", "MAIL_ENCRYPTION",
    "MAIL_USERNAME", "MAIL_PASSWORD", "MAIL_FROM", "MAIL_FROM_NAME",
    "STORAGE_DRIVER", "MAX_UPLOAD_SIZE", "CHUNK_SIZE", "DEFAULT_QUOTA",
];

foreach ($keys as $key) {
    $value = getenv($key);

    // An unset variable leaves whatever is already in the file alone; only
    // DB_PASSWORD may legitimately be set to an empty string.
    if ($value !== false && ($value !== "" || $key === "DB_PASSWORD")) {
        $values[$key] = $value;
    }
}

if (getenv("REDIS_HOST") !== false) {
    $values["REDIS_ENABLED"] = "true";
}

if ($values !== []) {
    App\Core\Env::write("/var/www/html/.env", $values);
    echo "[s3lite] applied " . count($values) . " setting(s) from the environment\n";
}
'

# Non-interactive first-run install when the operator supplied an admin account.
if [ ! -f storage/installed.lock ] && [ -n "${ADMIN_EMAIL}" ] && [ -n "${ADMIN_PASSWORD}" ]; then
    echo "[s3lite] running the installer"
    php bin/console install \
        --app-name="${APP_NAME:-S3 Lite}" \
        --app-url="${APP_URL:-http://localhost:8080}" \
        --app-env="${APP_ENV:-production}" \
        --db-host="${DB_HOST:-db}" \
        --db-port="${DB_PORT:-3306}" \
        --db-name="${DB_DATABASE:-s3lite}" \
        --db-user="${DB_USERNAME:-s3lite}" \
        --db-pass="${DB_PASSWORD:-}" \
        --redis \
        --redis-host="${REDIS_HOST:-redis}" \
        --redis-port="${REDIS_PORT:-6379}" \
        --admin-name="${ADMIN_NAME:-Administrator}" \
        --admin-email="${ADMIN_EMAIL}" \
        --admin-password="${ADMIN_PASSWORD}"
elif [ -f storage/installed.lock ]; then
    echo "[s3lite] applying pending migrations"
    php bin/console migrate || true
fi

chown -R www-data:www-data storage
service cron start || true

echo "[s3lite] ready"
exec "$@"
