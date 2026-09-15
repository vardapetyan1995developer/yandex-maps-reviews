#!/bin/sh
# Container start-up: prepare the application, then hand over to supervisor.
#
# Every step here is idempotent, because the container restarts on each deploy
# and, on a free plan, whenever the service wakes from idle.

set -e

echo "[entrypoint] rendering nginx config for port ${PORT}"
envsubst '${PORT}' < /etc/nginx/nginx.conf.template > /etc/nginx/nginx.conf

# The database is external and reached through DB_URL, which Laravel's config
# reads directly. Fail loudly if it is absent: without it the app would quietly
# fall back to 127.0.0.1 and report a connection error that looks like a network
# fault rather than a missing setting.
if [ -z "${DB_URL}" ] && [ -z "${DB_HOST}" ]; then
    echo "[entrypoint] FATAL: neither DB_URL nor DB_HOST is set" >&2
    exit 1
fi

echo "[entrypoint] waiting for the database"
ATTEMPT=0
until php artisan db:monitor >/dev/null 2>&1 || [ "${ATTEMPT}" -ge 30 ]; do
    ATTEMPT=$((ATTEMPT + 1))
    echo "[entrypoint] database not ready yet (${ATTEMPT}/30)"
    sleep 2
done

echo "[entrypoint] running migrations"
php artisan migrate --force

# The seeder keys on email and updates in place, so re-running it on every boot
# cannot create duplicates — and it guarantees the demo account exists even
# after the database is reset.
echo "[entrypoint] seeding the demo account"
php artisan db:seed --force

# Caches are built at start-up rather than at build time because they capture
# environment values that only exist at runtime
echo "[entrypoint] caching configuration"
php artisan config:cache
php artisan route:cache
php artisan view:cache

# storage/logs is written by both php-fpm and the worker
chown -R www-data:www-data storage bootstrap/cache

echo "[entrypoint] starting supervisor"
exec supervisord -c /etc/supervisord.conf
