#!/usr/bin/env bash
#
# Starts a local PocketBase for the Gratitude Journal.
#
# Downloads the binary on first run, applies the migrations in pb_migrations/
# (which create the `entries` collection and its per-user access rules), then
# serves the API and admin UI on http://127.0.0.1:8090.
#
# Usage:
#   ./start.sh                 # serve on 127.0.0.1:8090
#   PB_PORT=9000 ./start.sh    # serve on a different port
#
set -euo pipefail

PB_VERSION="${PB_VERSION:-0.39.10}"
PB_PORT="${PB_PORT:-8090}"
PB_HOST="${PB_HOST:-127.0.0.1}"

cd "$(dirname "$0")"

# ── Resolve the release asset for this machine ──────────────────────────
case "$(uname -s)" in
  Linux)  os="linux" ;;
  Darwin) os="darwin" ;;
  *)      echo "Unsupported OS: $(uname -s). Download PocketBase manually from" \
               "https://github.com/pocketbase/pocketbase/releases" >&2; exit 1 ;;
esac

case "$(uname -m)" in
  x86_64|amd64)  arch="amd64" ;;
  arm64|aarch64) arch="arm64" ;;
  *)             echo "Unsupported architecture: $(uname -m)" >&2; exit 1 ;;
esac

# ── Fetch the binary if we don't already have the pinned version ────────
if [ ! -x ./pocketbase ] || ! ./pocketbase --version 2>/dev/null | grep -q "$PB_VERSION"; then
  zip="pocketbase_${PB_VERSION}_${os}_${arch}.zip"
  url="https://github.com/pocketbase/pocketbase/releases/download/v${PB_VERSION}/${zip}"

  echo "Downloading PocketBase ${PB_VERSION} (${os}/${arch})..."
  curl -fsSL -o "$zip" "$url"
  unzip -o -q "$zip" pocketbase
  rm -f "$zip"
  chmod +x ./pocketbase
fi

# ── First run: create the admin account ─────────────────────────────────
# PocketBase needs at least one superuser before the admin UI is usable.
if [ ! -d ./pb_data ]; then
  echo
  echo "First run — let's create your PocketBase admin account."
  echo "(This is the server admin, not a journal user. Journal users sign up in the app.)"
  echo
  ./pocketbase superuser create --dir ./pb_data --migrationsDir ./pb_migrations
fi

echo
echo "Journal API   http://${PB_HOST}:${PB_PORT}"
echo "Admin UI      http://${PB_HOST}:${PB_PORT}/_/"
echo

exec ./pocketbase serve \
  --http="${PB_HOST}:${PB_PORT}" \
  --dir ./pb_data \
  --migrationsDir ./pb_migrations
