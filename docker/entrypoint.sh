#!/usr/bin/env bash
set -e

cd /var/www/html

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
