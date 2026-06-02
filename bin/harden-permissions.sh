#!/usr/bin/env bash
#
# Apply secure file/directory permissions for the DBO REST gateway.
# Safe to run repeatedly. Run as the file owner (the user PHP executes as).
#
#   bin/harden-permissions.sh
#
# Defaults assume the common shared-hosting model: PHP runs as the file owner,
# so secrets are owner-only (config dir 0700, config files 0600). When the web
# server runs as a SEPARATE user, relax via env and chgrp the files to that user:
#
#   CONFIG_DIR_MODE=750 CONFIG_FILE_MODE=640 bin/harden-permissions.sh
#
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

CONFIG_DIR_MODE="${CONFIG_DIR_MODE:-700}"
CONFIG_FILE_MODE="${CONFIG_FILE_MODE:-600}"

chmod_if() {
  local mode="$1" path="$2"
  if [ -e "$path" ]; then
    if chmod "$mode" "$path" 2>/dev/null; then
      printf '    [ok] %-5s %s\n' "$mode" "$path"
    else
      printf '    [!!] %-5s %s (chmod failed — are you the owner?)\n' "$mode" "$path"
    fi
  fi
}

echo "==> Hardening permissions under $ROOT"

# Secrets: config directory + generated config files.
chmod_if "$CONFIG_DIR_MODE" "$ROOT/config"
chmod_if "$CONFIG_FILE_MODE" "$ROOT/config/database.php"
chmod_if "$CONFIG_FILE_MODE" "$ROOT/config/security.php"

# Runtime storage (rate-limit state, install lock): owner-only.
mkdir -p "$ROOT/storage/ratelimit"
chmod_if 700 "$ROOT/storage"
chmod_if 700 "$ROOT/storage/ratelimit"
chmod_if 600 "$ROOT/storage/.install-lock"

# Scripts: executable by owner only.
if [ -d "$ROOT/bin" ]; then
  find "$ROOT/bin" -maxdepth 1 -type f -print0 | while IFS= read -r -d '' f; do
    chmod_if 750 "$f"
  done
fi

# Web root: traversable + readable, never group/other writable.
chmod_if 755 "$ROOT/public"
chmod_if 644 "$ROOT/public/index.php"
[ -e "$ROOT/public/.htaccess" ] && chmod_if 644 "$ROOT/public/.htaccess"

# Source + schema: read-only to the world is fine (they live outside the docroot).
if [ -d "$ROOT/src" ]; then
  find "$ROOT/src" -type d -exec chmod 755 {} + 2>/dev/null || true
  find "$ROOT/src" -type f -exec chmod 644 {} + 2>/dev/null || true
fi

echo "==> Done. Verify the web server can read config/ (PHP must run as the owner,"
echo "    or relax with CONFIG_DIR_MODE/CONFIG_FILE_MODE + chgrp to the server user)."
