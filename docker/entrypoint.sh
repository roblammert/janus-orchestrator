#!/usr/bin/env sh
set -e

if [ "${AUTO_INIT_DB:-0}" = "1" ]; then
  /var/www/janus/scripts/wait-for-mysql.sh
  /var/www/janus/scripts/init_db.sh
fi

exec "$@"
