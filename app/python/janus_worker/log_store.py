from __future__ import annotations

from mysql.connector import MySQLConnection


def append_task_log(
    conn: MySQLConnection,
    task_id: int,
    level: str,
    message: str,
    metadata: dict | None = None,
) -> None:
    cursor = conn.cursor()
    try:
        cursor.execute(
            """
            INSERT INTO task_logs (task_id, level, message, metadata_json)
            VALUES (%s, %s, %s, %s)
            """,
            (task_id, level, message, None if metadata is None else str(metadata).replace("'", '"')),
        )
        conn.commit()
    finally:
        cursor.close()
