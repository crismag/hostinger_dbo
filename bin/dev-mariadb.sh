#!/usr/bin/env bash
#
# TEMPORARY dev helper — spin up a disposable MariaDB for the DBO gateway,
# load the schema, and write matching config. Not for production. Safe to delete.
#
#   bin/dev-mariadb.sh up      # start container, load schema, write config
#   bin/dev-mariadb.sh down    # stop and remove the container + volume
#   bin/dev-mariadb.sh shell   # open a mysql client against it
#
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

# Overridable via env; defaults match config/database.example.php.
CONTAINER="${CONTAINER:-dbo-mariadb}"
IMAGE="${IMAGE:-mariadb:11}"
DB_HOST="${DB_HOST:-127.0.0.1}"
DB_PORT="${DB_PORT:-3306}"
DB_DATABASE="${DB_DATABASE:-dbo_gateway}"
DB_USERNAME="${DB_USERNAME:-dbo}"
DB_PASSWORD="${DB_PASSWORD:-dbo_dev_pw}"
DB_ROOT_PASSWORD="${DB_ROOT_PASSWORD:-root_dev_pw}"

up() {
  if docker ps -a --format '{{.Names}}' | grep -qx "$CONTAINER"; then
    echo "Container '$CONTAINER' already exists. Run '$0 down' first to recreate."
  else
    echo "Starting $IMAGE as '$CONTAINER' on ${DB_HOST}:${DB_PORT}..."
    docker run -d --name "$CONTAINER" \
      -e MARIADB_ROOT_PASSWORD="$DB_ROOT_PASSWORD" \
      -e MARIADB_DATABASE="$DB_DATABASE" \
      -e MARIADB_USER="$DB_USERNAME" \
      -e MARIADB_PASSWORD="$DB_PASSWORD" \
      -p "${DB_PORT}:3306" \
      "$IMAGE" >/dev/null
  fi

  # Wait until the app user can actually authenticate (ping reports "alive"
  # before grants are applied, so check a real query against the database).
  echo -n "Waiting for MariaDB to accept connections"
  for _ in $(seq 1 60); do
    if docker exec "$CONTAINER" mariadb -u"$DB_USERNAME" -p"$DB_PASSWORD" "$DB_DATABASE" \
        -e 'SELECT 1' >/dev/null 2>&1; then
      echo " ready."; break
    fi
    echo -n "."; sleep 1
  done

  echo "Loading schema..."
  for sql in security_tables.sql example_objects.sql; do
    docker exec -i "$CONTAINER" mariadb -u"$DB_USERNAME" -p"$DB_PASSWORD" "$DB_DATABASE" < "$ROOT/schema/$sql"
    echo "  + $sql"
  done

  write_config
  echo
  echo "Done. DB '$DB_DATABASE' is up on ${DB_HOST}:${DB_PORT} (user: $DB_USERNAME)."
  echo "Wrote config/database.php and .env. Run tests with: php tests/hardening_smoke.php"
}

write_config() {
  if [ ! -f "$ROOT/config/database.php" ]; then
    cp "$ROOT/config/database.example.php" "$ROOT/config/database.php"
    echo "Wrote config/database.php (reads from .env)."
  fi
  cat > "$ROOT/.env" <<EOF
DB_HOST=$DB_HOST
DB_PORT=$DB_PORT
DB_DATABASE=$DB_DATABASE
DB_USERNAME=$DB_USERNAME
DB_PASSWORD=$DB_PASSWORD
DB_CHARSET=utf8mb4
EOF
}

down() {
  echo "Removing container '$CONTAINER'..."
  docker rm -f "$CONTAINER" >/dev/null 2>&1 || true
  echo "Done. (config/database.php and .env left in place — delete manually if desired.)"
}

shell() {
  exec docker exec -it "$CONTAINER" mariadb -u"$DB_USERNAME" -p"$DB_PASSWORD" "$DB_DATABASE"
}

case "${1:-up}" in
  up) up ;;
  down) down ;;
  shell) shell ;;
  *) echo "Usage: $0 {up|down|shell}" >&2; exit 1 ;;
esac
