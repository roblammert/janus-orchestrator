#!/usr/bin/env sh
set -e

HOST="${DB_HOST:-127.0.0.1}"
PORT="${DB_PORT:-3306}"

until nc -z "$HOST" "$PORT"; do
  echo "Waiting for MySQL at $HOST:$PORT"
  sleep 1
done

echo "MySQL is reachable"
