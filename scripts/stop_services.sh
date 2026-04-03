#!/usr/bin/env sh
set -e

ROOT_DIR="$(CDPATH= cd -- "$(dirname "$0")/.." && pwd)"
PID_DIR="$ROOT_DIR/.run"
if [ -f "$ROOT_DIR/.env" ]; then
  set -a
  . "$ROOT_DIR/.env"
  set +a
fi

WEB_PORT="${WEB_PORT:-8811}"
FASTAPI_PORT="${FASTAPI_PORT:-8812}"

stop_service() {
  service_name="$1"
  pid_file="$2"
  pattern="$3"
  stopped="0"

  if [ -f "$pid_file" ]; then
    pid="$(cat "$pid_file")"
    if kill -0 "$pid" >/dev/null 2>&1; then
      kill "$pid" >/dev/null 2>&1 || true
      sleep 1
      if kill -0 "$pid" >/dev/null 2>&1; then
        kill -9 "$pid" >/dev/null 2>&1 || true
      fi
      echo "Stopped $service_name (pid=$pid)"
      stopped="1"
    fi
    rm -f "$pid_file"
  fi

  extra_pids="$(pgrep -f "$pattern" || true)"
  if [ -n "$extra_pids" ]; then
    for extra_pid in $extra_pids; do
      if kill -0 "$extra_pid" >/dev/null 2>&1; then
        kill "$extra_pid" >/dev/null 2>&1 || true
        sleep 1
        if kill -0 "$extra_pid" >/dev/null 2>&1; then
          kill -9 "$extra_pid" >/dev/null 2>&1 || true
        fi
        echo "Stopped $service_name (pid=$extra_pid, matched pattern)"
        stopped="1"
      fi
    done
  fi

  if [ "$stopped" = "0" ]; then
    echo "$service_name is not running"
  fi
}

stop_service \
  "php" \
  "$PID_DIR/php.pid" \
  "php -S 0.0.0.0:${WEB_PORT} -t ${ROOT_DIR}/public ${ROOT_DIR}/public/index.php"

stop_service \
  "fastapi" \
  "$PID_DIR/fastapi.pid" \
  "uvicorn janus_worker.main_service:app --host 0.0.0.0 --port ${FASTAPI_PORT}"
