#!/bin/sh
set -eu

# Docker runs this script as root. It prepares the environment, then switches
# to the non-root application user (www-data with configurable UID/GID) through
# su-exec before starting FrankenPHP. This makes the host bind mount
# (./var/data), which Docker creates as root, writable.

APP_USER="${APP_USER:-www-data}"
APP_GROUP="${APP_GROUP:-www-data}"

DATA_DIR="${DATA_DIR:-/var/www/html/var/data}"

# 1) Create the SQLite data directory if needed and align permissions. The
#    directory is recursively owned by the application user so an existing
#    SQLite database remains writable.
mkdir -p "$DATA_DIR"
if [ -n "${HOST_UID:-}" ] && [ -n "${HOST_GID:-}" ]; then
    # Create an ephemeral application user with the host UID/GID so the bind
    # mount is readable and writable inside the container.
    if ! getent group "$HOST_GID" >/dev/null; then
        addgroup -g "$HOST_GID" -S hostgroup 2>/dev/null || true
        APP_GROUP="hostgroup"
    fi
    if ! getent passwd "$HOST_UID" >/dev/null; then
        adduser -u "$HOST_UID" -G "$APP_GROUP" -S -D -H hostuser 2>/dev/null || true
        APP_USER="hostuser"
    fi
fi

# 2) Apply permissions recursively. The directory may contain a SQLite file
#    created by an earlier container.
chown -R "$APP_USER:$APP_GROUP" "$DATA_DIR" /var/www/html/var || true

# Caddy/FrankenPHP storage (TLS autosave, locks). Without this, non-root FrankenPHP logs errors on startup.
mkdir -p /data/caddy/locks /config/caddy
chown -R "$APP_USER:$APP_GROUP" /data/caddy /config/caddy || true

# 3) Run migrations as the application user.
if [ "${RUN_MIGRATIONS:-1}" = "1" ]; then
    echo "[entrypoint] Running database migrations..."
    # shellcheck disable=SC2086
    su-exec "$APP_USER:$APP_GROUP" php /var/www/html/bin/console \
        doctrine:migrations:migrate --no-interaction --allow-no-migration || {
        echo "[entrypoint] First migration attempt failed, retrying in 5s..." >&2
        sleep 5
        su-exec "$APP_USER:$APP_GROUP" php /var/www/html/bin/console \
            doctrine:migrations:migrate --no-interaction --allow-no-migration
    }
fi

# 4) Warm up the production cache (idempotent).
if [ "${APP_ENV:-prod}" = "prod" ] && [ "${SKIP_CACHE_WARMUP:-0}" != "1" ]; then
    su-exec "$APP_USER:$APP_GROUP" php /var/www/html/bin/console cache:warmup --env=prod --no-debug || true
fi

# 5) Start FrankenPHP as the application user through tini (PID 1) for proper
#    signal handling.
echo "[entrypoint] Starting FrankenPHP as $APP_USER:$APP_GROUP..."
exec tini -- su-exec "$APP_USER:$APP_GROUP" "$@"
