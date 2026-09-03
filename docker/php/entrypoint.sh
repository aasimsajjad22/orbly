#!/bin/sh
# Runs before the container's main process.
#
# Deliberately does NOT run migrations. With several replicas starting
# at once, they would race to apply the same migration. Migrations are
# a separate, single-run step — see the migrate service in the compose
# file.

set -e

# Wait for Postgres. Container start order is not guaranteed, and
# Symfony's cache warmup touches the database.
echo "Waiting for the database…"

until php -r "
    try {
        new PDO(getenv('DATABASE_URL_PDO') ?: 'pgsql:host=postgres;dbname=orbly',
                getenv('POSTGRES_USER') ?: 'orbly',
                getenv('POSTGRES_PASSWORD') ?: 'orbly');
        exit(0);
    } catch (Throwable \$e) {
        exit(1);
    }
" 2>/dev/null; do
    sleep 1
done

echo "Database is up."

# The cache was warmed at BUILD time, so nothing to do here — but the
# directories must exist and be writable, since var/ is excluded from
# the image by .dockerignore.
mkdir -p var/cache var/log

# exec replaces this shell with the real process, so it becomes PID 1.
# Without exec, signals from Docker (SIGTERM on stop) would go to the
# shell and never reach PHP-FPM — meaning no graceful shutdown, and
# in-flight requests dropped.
exec "$@"
