#!/usr/bin/env bash
#
# Everything that has to happen between `docker compose up` and Apache taking
# its first request: line the container's uid up with the host's, make sure
# there is a key and a database, and warm the caches.
#
set -euo pipefail

DATA_DIR="${DATA_DIR:-/data}"
KEY_FILE="${DATA_DIR}/app_key"
DB_FILE="${DB_DATABASE:-${DATA_DIR}/journal.sqlite}"

log() { printf '[entrypoint] %s\n' "$*"; }

# ─── Ownership ─────────────────────────────────────────────────────
# ./data is bind-mounted from the host, so files Apache writes need to come out
# owned by whoever runs the container rather than by uid 33. Moving www-data
# rather than running Apache as a stranger keeps Apache's own directories — pid,
# lock, logs — writable.
if [ "$(id -u)" = '0' ]; then
    PUID="${PUID:-1000}"
    PGID="${PGID:-1000}"

    if [ "$(id -g www-data)" != "${PGID}" ]; then
        log "moving www-data to gid ${PGID}"
        groupmod -o -g "${PGID}" www-data
    fi

    if [ "$(id -u www-data)" != "${PUID}" ]; then
        log "moving www-data to uid ${PUID}"
        usermod -o -u "${PUID}" www-data
    fi

    mkdir -p "${DATA_DIR}"
    chown www-data:www-data "${DATA_DIR}"
    chown -R www-data:www-data storage bootstrap/cache

    for dir in /var/run/apache2 /var/lock/apache2 /var/log/apache2; do
        [ -d "${dir}" ] && chown -R www-data:www-data "${dir}"
    done

    as_app() { runuser -u www-data -- "$@"; }
else
    log "not running as root — leaving ownership alone"
    as_app() { "$@"; }
fi

# ─── Application key ───────────────────────────────────────────────
# Generated once and kept beside the database, so a first `docker compose up`
# needs no preparation and a restart doesn't invalidate every session cookie.
# Setting APP_KEY in the environment overrides all of this.
if [ -z "${APP_KEY:-}" ]; then
    if [ ! -f "${KEY_FILE}" ]; then
        log "no application key yet — generating one in ${KEY_FILE}"
        as_app php -r '
            $file = $argv[1];
            $old = umask(0077);
            file_put_contents($file, "base64:".base64_encode(random_bytes(32)));
            umask($old);
        ' "${KEY_FILE}"
    fi

    APP_KEY="$(cat "${KEY_FILE}")"
    export APP_KEY
fi

# ─── Database ──────────────────────────────────────────────────────
if [ ! -f "${DB_FILE}" ]; then
    log "creating ${DB_FILE}"
    as_app touch "${DB_FILE}"
fi

# ─── Caches and schema ─────────────────────────────────────────────
# Config is cached here rather than at build time because it bakes in the
# environment, which isn't known until the container starts. Routes are left
# uncached on purpose: there are a dozen of them, and two are registered by a
# service provider rather than the routes file.
as_app php artisan config:clear >/dev/null
as_app php artisan config:cache
as_app php artisan view:cache
as_app php artisan migrate --force

log "ready on port 3000"

exec "$@"
