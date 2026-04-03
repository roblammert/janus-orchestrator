#!/usr/bin/env sh
set -e

ROOT_DIR="$(CDPATH= cd -- "$(dirname "$0")/.." && pwd)"
PID_DIR="$ROOT_DIR/.run"
LOG_DIR="$ROOT_DIR/.logs"

if [ -f "$ROOT_DIR/.env" ]; then
  set -a
  . "$ROOT_DIR/.env"
  set +a
fi

WEB_PORT="${WEB_PORT:-8811}"
FASTAPI_PORT="${FASTAPI_PORT:-8812}"

if [ -z "${PYTHON_BIN:-}" ]; then
  if [ -x "$ROOT_DIR/.venv/bin/python" ]; then
    PYTHON_BIN="$ROOT_DIR/.venv/bin/python"
  else
    PYTHON_BIN="python3"
  fi
fi

mkdir -p "$PID_DIR" "$LOG_DIR"

find_pid_by_pattern() {
  pattern="$1"
  pgrep -f "$pattern" | head -n 1 || true
}

is_http_up() {
  url="$1"
  curl -fsS --max-time 2 "$url" >/dev/null 2>&1
}

PHP_PATTERN="php -S 0.0.0.0:${WEB_PORT} -t ${ROOT_DIR}/public ${ROOT_DIR}/public/index.php"
FASTAPI_PATTERN="uvicorn janus_worker.main_service:app --host 0.0.0.0 --port ${FASTAPI_PORT}"

php_pid="$(find_pid_by_pattern "$PHP_PATTERN")"
if [ -n "$php_pid" ] && kill -0 "$php_pid" >/dev/null 2>&1 && is_http_up "http://127.0.0.1:${WEB_PORT}/"; then
  echo "$php_pid" > "$PID_DIR/php.pid"
  echo "php is already running (pid=$php_pid)"
else
  if [ -n "$php_pid" ] && kill -0 "$php_pid" >/dev/null 2>&1; then
    kill "$php_pid" >/dev/null 2>&1 || true
    sleep 1
  fi
  nohup php -S "0.0.0.0:${WEB_PORT}" -t "$ROOT_DIR/public" "$ROOT_DIR/public/index.php" > "$LOG_DIR/php.log" 2>&1 &
  php_pid=$!
  echo "$php_pid" > "$PID_DIR/php.pid"
  echo "Started php (pid=$php_pid)"
fi

fastapi_pid="$(find_pid_by_pattern "$FASTAPI_PATTERN")"
if [ -n "$fastapi_pid" ] && kill -0 "$fastapi_pid" >/dev/null 2>&1 && is_http_up "http://127.0.0.1:${FASTAPI_PORT}/health"; then
  echo "$fastapi_pid" > "$PID_DIR/fastapi.pid"
  echo "fastapi is already running (pid=$fastapi_pid)"
else
  if [ -n "$fastapi_pid" ] && kill -0 "$fastapi_pid" >/dev/null 2>&1; then
    kill "$fastapi_pid" >/dev/null 2>&1 || true
    sleep 1
  fi
  nohup env PYTHONPATH="$ROOT_DIR/app/python" "$PYTHON_BIN" -m uvicorn janus_worker.main_service:app --host 0.0.0.0 --port "$FASTAPI_PORT" > "$LOG_DIR/fastapi.log" 2>&1 &
  fastapi_pid=$!
  echo "$fastapi_pid" > "$PID_DIR/fastapi.pid"
  echo "Started fastapi (pid=$fastapi_pid)"
fi

printf "\nServices started:\n"
printf -- "- PHP:    http://127.0.0.1:%s\n" "$WEB_PORT"
printf -- "- FastAPI: http://127.0.0.1:%s\n" "$FASTAPI_PORT"
printf -- "- Logs:   %s\n" "$LOG_DIR"
