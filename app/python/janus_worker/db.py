from __future__ import annotations

from contextlib import contextmanager

import mysql.connector
from mysql.connector import MySQLConnection

from .config import settings


class Db:
    def connect(self) -> MySQLConnection:
        return mysql.connector.connect(
            host=settings.db_host,
            port=settings.db_port,
            database=settings.db_name,
            user=settings.db_user,
            password=settings.db_password,
            autocommit=False,
        )


@contextmanager
def tx_cursor(conn: MySQLConnection, dictionary: bool = True):
    cursor = conn.cursor(dictionary=dictionary)
    try:
        yield cursor
        conn.commit()
    except Exception:
        conn.rollback()
        raise
    finally:
        cursor.close()
