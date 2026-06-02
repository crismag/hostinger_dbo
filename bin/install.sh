#!/usr/bin/env bash
#
# DBO REST Gateway installer — system preflight + wizard launcher.
#
# Interactive (over SSH):
#   bin/install.sh
#
# Full automation (non-interactive): provide DB_* env and INSTALL_NONINTERACTIVE=1
#   INSTALL_NONINTERACTIVE=1 \
#   DB_HOST=127.0.0.1 DB_PORT=3306 DB_DATABASE=dbo_gateway \
#   DB_USERNAME=dbo DB_PASSWORD=secret \
#   INSTALL_CREATE_DATABASE=1 INSTALL_WITH_EXAMPLES=1 \
#   INSTALL_CLIENT_ID=primary-client INSTALL_CLIENT_ACTIONS=select,insert \
#   INSTALL_REQUIRE_HTTPS=1 \
#   bin/install.sh
#
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

echo "==> System preflight"

# --- PHP present and recent enough ---------------------------------------
PHP_BIN="${PHP_BIN:-php}"
if ! command -v "$PHP_BIN" >/dev/null 2>&1; then
  echo "    [XX] php not found on PATH. Set PHP_BIN=/path/to/php and retry." >&2
  exit 1
fi
PHP_VER="$("$PHP_BIN" -r 'echo PHP_VERSION;')"
if ! "$PHP_BIN" -r 'exit(PHP_VERSION_ID >= 80100 ? 0 : 1);'; then
  echo "    [XX] PHP >= 8.1 required (found $PHP_VER)." >&2
  exit 1
fi
echo "    [ok] php $PHP_VER ($(command -v "$PHP_BIN"))"

# --- Required extensions --------------------------------------------------
missing=()
for ext in pdo_mysql mbstring json; do
  if ! "$PHP_BIN" -m | grep -qix "$ext"; then
    missing+=("$ext")
  fi
done
if [ "${#missing[@]}" -gt 0 ]; then
  echo "    [XX] Missing PHP extensions: ${missing[*]}" >&2
  echo "         Install them (e.g. apt install php-mysql php-mbstring) and retry." >&2
  exit 1
fi
echo "    [ok] extensions: pdo_mysql, mbstring, json"

# --- Optional: a mysql/mariadb client (handy but not required) -----------
if command -v mariadb >/dev/null 2>&1 || command -v mysql >/dev/null 2>&1; then
  echo "    [ok] mysql/mariadb client present"
else
  echo "    [--] no mysql/mariadb client (not required; PHP PDO is used directly)"
fi

# --- Writable config dir --------------------------------------------------
if [ -d "$ROOT/config" ]; then
  TARGET="$ROOT/config"
else
  TARGET="$ROOT"
fi
if [ ! -w "$TARGET" ]; then
  echo "    [XX] $TARGET is not writable by $(id -un). Fix ownership/permissions and retry." >&2
  exit 1
fi
echo "    [ok] config directory writable"

echo
echo "==> Launching wizard"
exec "$PHP_BIN" "$ROOT/bin/install.php" "$@"
