#!/usr/bin/env sh
set -e

# Local helper to run PHP built-in server (development only).
WEB_PORT="${WEB_PORT:-8811}"
php -S "0.0.0.0:${WEB_PORT}" -t public public/index.php
