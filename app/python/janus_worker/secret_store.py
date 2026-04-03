from __future__ import annotations

import re
from copy import deepcopy

from mysql.connector import MySQLConnection

SECRET_TOKEN = re.compile(r"^\$\{secret:([a-zA-Z0-9_\-\.]+)\}$")


def _resolve_secret(conn: MySQLConnection, name: str) -> str:
    cur = conn.cursor(dictionary=True)
    try:
        cur.execute("SELECT value_encrypted FROM secrets WHERE name = %s", (name,))
        row = cur.fetchone()
        if not row:
            raise RuntimeError(f"Secret '{name}' not found")
        return row["value_encrypted"]
    finally:
        cur.close()


def resolve_config_secrets(conn: MySQLConnection, config: dict) -> dict:
    safe = deepcopy(config)

    def walk(value):
        if isinstance(value, dict):
            return {k: walk(v) for k, v in value.items()}
        if isinstance(value, list):
            return [walk(v) for v in value]
        if isinstance(value, str):
            m = SECRET_TOKEN.match(value)
            if m:
                return _resolve_secret(conn, m.group(1))
        return value

    return walk(safe)
