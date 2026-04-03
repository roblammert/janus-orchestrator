#!/usr/bin/env sh
set -e

ROOT_DIR="$(CDPATH= cd -- "$(dirname "$0")/.." && pwd)"
if [ -d "/var/www/janus/sql" ]; then
  SQL_DIR="/var/www/janus/sql"
else
  SQL_DIR="$ROOT_DIR/sql"
fi

MYSQL_CMD="mysql -h ${DB_HOST:-127.0.0.1} -P ${DB_PORT:-3306} -u ${DB_USER:-janus} -p${DB_PASSWORD:-janus}"

$MYSQL_CMD < "$SQL_DIR/001_schema.sql"
$MYSQL_CMD < "$SQL_DIR/002_views.sql"

if [ "${SEED_EXAMPLE:-0}" = "1" ]; then
  $MYSQL_CMD < "$SQL_DIR/003_seed_example.sql"
fi

echo "Database initialized"
