from __future__ import annotations

import json
import signal
import time
from typing import Any

from mysql.connector import MySQLConnection

from .config import settings
from .db import Db
from .executors import EXECUTOR_REGISTRY
from .log_store import append_task_log
from .secret_store import resolve_config_secrets

_SHUTDOWN = False


def _handle_signal(signum, frame):
    del signum, frame
    global _SHUTDOWN
    _SHUTDOWN = True


signal.signal(signal.SIGINT, _handle_signal)
signal.signal(signal.SIGTERM, _handle_signal)


def _execution_cancelled(conn: MySQLConnection, execution_id: int) -> bool:
    cur = conn.cursor(dictionary=True)
    try:
        cur.execute("SELECT status FROM executions WHERE id = %s", (execution_id,))
        row = cur.fetchone()
        if not row:
            return True
        return row["status"] in {"CANCELLED", "TIMED_OUT"}
    finally:
        cur.close()


def _heartbeat(conn: MySQLConnection, task_id: int) -> None:
    cur = conn.cursor()
    try:
        cur.execute("UPDATE tasks SET last_heartbeat_at = NOW() WHERE id = %s", (task_id,))
        conn.commit()
    finally:
        cur.close()


def _claim_next_task(conn: MySQLConnection) -> dict[str, Any] | None:
    cur = conn.cursor(dictionary=True)
    try:
        cur.execute("START TRANSACTION")
        cur.execute(
            """
            SELECT q.id AS queue_id, q.task_id, q.priority,
                   t.execution_id, t.workflow_id, t.workflow_node_id, t.node_key,
                   t.idempotency_key, t.attempts, t.max_attempts,
                   wn.type AS node_type, wn.config_json, wn.timeout_seconds
            FROM task_queue q
            INNER JOIN tasks t ON t.id = q.task_id
            INNER JOIN workflow_nodes wn ON wn.id = t.workflow_node_id
            WHERE q.claimed_at IS NULL
              AND q.available_at <= NOW()
              AND q.scheduled_at <= NOW()
              AND t.status = 'READY'
            ORDER BY q.priority DESC, q.scheduled_at ASC, q.id ASC
            LIMIT 1
            FOR UPDATE SKIP LOCKED
            """
        )
        row = cur.fetchone()
        if not row:
            cur.execute("COMMIT")
            return None

        cur.execute(
            """
            UPDATE task_queue
            SET claimed_by_worker_id = %s,
                claimed_at = NOW()
            WHERE id = %s
            """,
            (settings.worker_id, row["queue_id"]),
        )

        cur.execute(
            """
            UPDATE tasks
            SET status = 'RUNNING',
                started_at = COALESCE(started_at, NOW()),
                last_heartbeat_at = NOW(),
                claimed_by_worker_id = %s,
                attempts = attempts + 1
            WHERE id = %s
            """,
            (settings.worker_id, row["task_id"]),
        )

        cur.execute(
            """
            INSERT INTO state_transitions (entity_type, entity_id, from_state, to_state, metadata_json)
            VALUES ('task', %s, 'READY', 'RUNNING', JSON_OBJECT('worker_id', %s))
            """,
            (row["task_id"], settings.worker_id),
        )
        cur.execute("COMMIT")

        return row
    except Exception:
        cur.execute("ROLLBACK")
        raise
    finally:
        cur.close()


def _finalize_success(conn: MySQLConnection, row: dict[str, Any], output: dict[str, Any]) -> None:
    cur = conn.cursor()
    try:
        cur.execute(
            """
            UPDATE tasks
            SET status = 'COMPLETED',
                finished_at = NOW(),
                output_json = %s,
                last_error = NULL,
                claimed_by_worker_id = NULL
            WHERE id = %s
            """,
            (json.dumps(output), row["task_id"]),
        )
        cur.execute("DELETE FROM task_queue WHERE id = %s", (row["queue_id"],))
        cur.execute(
            """
            INSERT INTO state_transitions (entity_type, entity_id, from_state, to_state, metadata_json)
            VALUES ('task', %s, 'RUNNING', 'COMPLETED', JSON_OBJECT('worker_id', %s))
            """,
            (row["task_id"], settings.worker_id),
        )
        conn.commit()
    finally:
        cur.close()


def _finalize_failure(conn: MySQLConnection, row: dict[str, Any], error_text: str) -> None:
    cur = conn.cursor(dictionary=True)
    try:
        cur.execute("SELECT attempts, max_attempts FROM tasks WHERE id = %s", (row["task_id"],))
        task = cur.fetchone()
        if not task:
            conn.commit()
            return

        attempts = int(task["attempts"])
        max_attempts = int(task["max_attempts"])

        if attempts >= max_attempts:
            status = "FAILED_PERMANENTLY"
            queue_sql = "DELETE FROM task_queue WHERE id = %s"
            queue_params = (row["queue_id"],)
            next_attempt_at = None
        else:
            status = "FAILED"
            delay = settings.retry_backoff_seconds * (2 ** max(0, attempts - 1))
            queue_sql = """
                UPDATE task_queue
                SET claimed_by_worker_id = NULL,
                    claimed_at = NULL,
                    available_at = DATE_ADD(NOW(), INTERVAL %s SECOND)
                WHERE id = %s
            """
            queue_params = (delay, row["queue_id"])
            next_attempt_at = delay

        cur.execute(
            """
            UPDATE tasks
            SET status = %s,
                finished_at = NOW(),
                last_error = %s,
                next_attempt_at = IF(%s IS NULL, NULL, DATE_ADD(NOW(), INTERVAL %s SECOND)),
                claimed_by_worker_id = NULL
            WHERE id = %s
            """,
            (status, error_text[:65535], next_attempt_at, next_attempt_at, row["task_id"]),
        )

        cur.execute(queue_sql, queue_params)
        cur.execute(
            """
            INSERT INTO state_transitions (entity_type, entity_id, from_state, to_state, metadata_json)
            VALUES ('task', %s, 'RUNNING', %s, JSON_OBJECT('worker_id', %s, 'error', %s))
            """,
            (row["task_id"], status, settings.worker_id, error_text[:1024]),
        )
        conn.commit()
    finally:
        cur.close()


def _update_execution_statuses(conn: MySQLConnection) -> None:
    cur = conn.cursor(dictionary=True)
    try:
        cur.execute(
            """
            SELECT e.id,
                   SUM(CASE WHEN t.status = 'FAILED_PERMANENTLY' THEN 1 ELSE 0 END) AS failed_permanent,
                   SUM(CASE WHEN t.status = 'FAILED' THEN 1 ELSE 0 END) AS failed,
                   SUM(CASE WHEN t.status IN ('PENDING', 'READY', 'RUNNING') THEN 1 ELSE 0 END) AS unfinished,
                   SUM(CASE WHEN t.status = 'COMPLETED' THEN 1 ELSE 0 END) AS completed,
                   COUNT(*) AS total
            FROM executions e
            INNER JOIN tasks t ON t.execution_id = e.id
            WHERE e.status IN ('PENDING', 'RUNNING')
            GROUP BY e.id
            """
        )
        rows = cur.fetchall()

        for row in rows:
            execution_id = row["id"]
            target = None
            if int(row["failed_permanent"]) > 0:
                target = "FAILED"
            elif int(row["failed"]) > 0 and int(row["unfinished"]) == 0:
                target = "FAILED"
            elif int(row["completed"]) == int(row["total"]):
                target = "COMPLETED"

            if target:
                cur.execute(
                    """
                    UPDATE executions
                    SET status = %s,
                        finished_at = NOW()
                    WHERE id = %s
                    """,
                    (target, execution_id),
                )

        conn.commit()
    finally:
        cur.close()


def run_worker() -> None:
    db = Db()
    while not _SHUTDOWN:
        conn = db.connect()
        try:
            row = _claim_next_task(conn)
            if not row:
                time.sleep(settings.worker_poll_seconds)
                continue

            task_id = int(row["task_id"])
            execution_id = int(row["execution_id"])
            append_task_log(conn, task_id, "INFO", "Task claimed by worker", {"worker_id": settings.worker_id})

            if _execution_cancelled(conn, execution_id):
                _finalize_failure(conn, row, "Execution already cancelled or timed out before task start")
                append_task_log(conn, task_id, "WARN", "Task aborted because execution was cancelled")
                continue

            node_type = str(row["node_type"])
            fn = EXECUTOR_REGISTRY.get(node_type)
            if fn is None:
                raise RuntimeError(f"Unsupported executor: {node_type}")

            config = json.loads(row["config_json"])
            safe_config = resolve_config_secrets(conn, config)
            timeout_seconds = int(row["timeout_seconds"])

            append_task_log(conn, task_id, "INFO", "Task execution started", {"executor": node_type})

            if node_type == "SCRIPT":
                output = fn(
                    safe_config,
                    str(row["idempotency_key"]),
                    timeout_seconds,
                    heartbeat_callback=lambda: _heartbeat(conn, task_id),
                    cancellation_check=lambda: _execution_cancelled(conn, execution_id),
                )
            else:
                _heartbeat(conn, task_id)
                output = fn(safe_config, str(row["idempotency_key"]), timeout_seconds)

            _finalize_success(conn, row, output)
            append_task_log(conn, task_id, "INFO", "Task execution completed")
            _update_execution_statuses(conn)
        except Exception as exc:
            if "row" in locals() and row:
                _finalize_failure(conn, row, str(exc))
                append_task_log(conn, int(row["task_id"]), "ERROR", str(exc))
                _update_execution_statuses(conn)
        finally:
            conn.close()

    # Graceful worker exit by supervisor restart policy.

