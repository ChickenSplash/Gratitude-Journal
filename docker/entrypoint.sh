#!/usr/bin/env bash
#
# Everything that has to happen between `docker compose up` and Apache taking
# its first request: line the container's uid up with the host's, make sure
# there is a key and a database, and warm the caches.
#
set -euo pipefail

DATA_DIR="${DATA_DIR:-/data}"
KEY_FILE="${DATA_DIR}/app_key"
DB_FILE="${DB_DATABASE:-${DATA_DIR}/gratitude-journal.sqlite}"

# What the Express version wrote. Its tables share their names with the ones
# Laravel migrates but not their shape, so the two cannot share a file.
LEGACY_FILE="${DATA_DIR}/journal.sqlite"

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
    chown -R www-data:www-data "${DATA_DIR}"
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
# A journal.sqlite next door is one of two things: this app's own database from
# before it was renamed, or the Express version's. The second is identified by
# users.username — this app's users table has name and email instead — and left
# exactly where it is, because migrating on top of it is what would destroy it.
#
# Not by looking for a migrations table: a version of this app that did open the
# old file got as far as creating an empty one before the first migration hit a
# users table it hadn't made. Those databases are still the Express version's.
if [ ! -f "${DB_FILE}" ] && [ -f "${LEGACY_FILE}" ]; then
    verdict=0
    as_app php -r '
        $db = new PDO("sqlite:".$argv[1]);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $hasTable = function (string $name) use ($db): bool {
            $sql = "SELECT 1 FROM sqlite_master WHERE type = \"table\" AND name = ".$db->quote($name);

            return (bool) $db->query($sql)->fetch();
        };

        if ($hasTable("users")) {
            foreach ($db->query("PRAGMA table_info(users)") as $column) {
                if ($column["name"] === "username") {
                    exit(1); // the Express version
                }
            }
        }

        exit($hasTable("migrations") ? 0 : 2); // 0: ours, 2: no idea
    ' "${LEGACY_FILE}" || verdict=$?

    if [ "${verdict}" = '0' ]; then
        log "adopting ${LEGACY_FILE} — it is this app's own database under its old name"

        for suffix in '' '-shm' '-wal'; do
            if [ -f "${LEGACY_FILE}${suffix}" ]; then
                mv "${LEGACY_FILE}${suffix}" "${DB_FILE}${suffix}"
            fi
        done
    elif [ "${verdict}" = '1' ]; then
        log "found ${LEGACY_FILE} from the Express version. Leaving it untouched."
        log "  To bring those entries across: create an account on the site, then run"
        log "  docker compose exec journal php artisan journal:import-legacy --into=you@example.com"
    else
        log "found ${LEGACY_FILE} but don't recognise it. Leaving it untouched."
    fi
fi

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
