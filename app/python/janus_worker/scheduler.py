from __future__ import annotations

import time

from .config import settings
from .db import Db


def _mark_timeout_executions(conn) -> None:
    cur = conn.cursor()
    try:
        cur.execute(
            """
            UPDATE executions
            SET status = 'TIMED_OUT',
                finished_at = NOW(),
                cancelled_at = NOW(),
                error_text = 'Workflow timeout exceeded'
            WHERE status IN ('PENDING', 'RUNNING')
              AND timeout_at IS NOT NULL
              AND timeout_at <= NOW()
            """
        )

        cur.execute(
            """
            UPDATE tasks t
            INNER JOIN executions e ON e.id = t.execution_id
            SET t.status = 'SKIPPED',
                t.finished_at = NOW(),
                t.last_error = 'Skipped due to workflow timeout'
            WHERE e.status = 'TIMED_OUT'
              AND t.status IN ('PENDING', 'READY', 'RUNNING', 'FAILED')
            """
        )

        cur.execute(
            """
            DELETE q FROM task_queue q
            INNER JOIN tasks t ON t.id = q.task_id
            INNER JOIN executions e ON e.id = t.execution_id
            WHERE e.status IN ('TIMED_OUT', 'CANCELLED')
            """
        )
        conn.commit()
    finally:
        cur.close()


def _recover_stale_running_tasks(conn) -> None:
    cur = conn.cursor(dictionary=True)
    try:
        cur.execute(
            """
            SELECT id, attempts, max_attempts, priority
            FROM tasks
            WHERE status = 'RUNNING'
              AND last_heartbeat_at < DATE_SUB(NOW(), INTERVAL %s SECOND)
            """,
            (settings.stale_task_seconds,),
        )
        rows = cur.fetchall()

        for row in rows:
            task_id = int(row["id"])
            attempts = int(row["attempts"])
            max_attempts = int(row["max_attempts"])

            if attempts >= max_attempts:
                cur.execute(
                    """
                    UPDATE tasks
                    SET status = 'FAILED_PERMANENTLY',
                        finished_at = NOW(),
                        last_error = 'Task stale and exhausted retries',
                        claimed_by_worker_id = NULL
                    WHERE id = %s
                    """,
                    (task_id,),
                )
                cur.execute("DELETE FROM task_queue WHERE task_id = %s", (task_id,))
            else:
                cur.execute(
                    """
                    UPDATE tasks
                    SET status = 'READY',
                        claimed_by_worker_id = NULL,
                        scheduled_at = NOW(),
                        next_attempt_at = NOW(),
                        last_error = 'Recovered stale running task'
                    WHERE id = %s
                    """,
                    (task_id,),
                )
                cur.execute(
                    """
                    INSERT INTO task_queue (task_id, priority, scheduled_at, available_at)
                    VALUES (%s, %s, NOW(), NOW())
                    ON DUPLICATE KEY UPDATE
                        priority = VALUES(priority),
                        scheduled_at = VALUES(scheduled_at),
                        available_at = VALUES(available_at),
                        claimed_by_worker_id = NULL,
                        claimed_at = NULL
                    """,
                    (task_id, int(row["priority"])),
                )

        conn.commit()
    finally:
        cur.close()


def _promote_failed_to_ready_for_retry(conn) -> None:
    cur = conn.cursor(dictionary=True)
    try:
        cur.execute(
            """
            SELECT id, priority
            FROM tasks
            WHERE status = 'FAILED'
              AND attempts < max_attempts
              AND (next_attempt_at IS NULL OR next_attempt_at <= NOW())
            """
        )
        rows = cur.fetchall()

        for row in rows:
            task_id = int(row["id"])
            cur.execute(
                """
                UPDATE tasks
                SET status = 'READY',
                    scheduled_at = NOW(),
                    claimed_by_worker_id = NULL
                WHERE id = %s
                """,
                (task_id,),
            )
            cur.execute(
                """
                INSERT INTO task_queue (task_id, priority, scheduled_at, available_at)
                VALUES (%s, %s, NOW(), NOW())
                ON DUPLICATE KEY UPDATE
                    priority = VALUES(priority),
                    scheduled_at = VALUES(scheduled_at),
                    available_at = VALUES(available_at),
                    claimed_by_worker_id = NULL,
                    claimed_at = NULL
                """,
                (task_id, int(row["priority"])),
            )

        conn.commit()
    finally:
        cur.close()


def _promote_dependency_ready_tasks(conn) -> None:
    cur = conn.cursor(dictionary=True)
    try:
        cur.execute(
            """
            SELECT t.id, t.priority
            FROM tasks t
            INNER JOIN executions e ON e.id = t.execution_id
            WHERE t.status = 'PENDING'
              AND e.status IN ('PENDING', 'RUNNING')
              AND NOT EXISTS (
                  SELECT 1
                  FROM workflow_edges we
                  INNER JOIN workflow_nodes pred_node ON pred_node.id = we.from_node_id
                  INNER JOIN tasks pred_task
                      ON pred_task.execution_id = t.execution_id
                     AND pred_task.workflow_node_id = pred_node.id
                  WHERE we.to_node_id = t.workflow_node_id
                    AND pred_task.status NOT IN ('COMPLETED', 'SKIPPED')
              )
            """
        )
        rows = cur.fetchall()

        for row in rows:
            task_id = int(row["id"])
            cur.execute(
                """
                UPDATE tasks
                SET status = 'READY',
                    scheduled_at = NOW()
                WHERE id = %s
                """,
                (task_id,),
            )
            cur.execute(
                """
                INSERT INTO task_queue (task_id, priority, scheduled_at, available_at)
                VALUES (%s, %s, NOW(), NOW())
                ON DUPLICATE KEY UPDATE
                    priority = VALUES(priority),
                    scheduled_at = VALUES(scheduled_at),
                    available_at = VALUES(available_at)
                """,
                (task_id, int(row["priority"])),
            )

        conn.commit()
    finally:
        cur.close()


def run_scheduler() -> None:
    db = Db()
    while True:
        conn = db.connect()
        try:
            _mark_timeout_executions(conn)
            _recover_stale_running_tasks(conn)
            _promote_failed_to_ready_for_retry(conn)
            _promote_dependency_ready_tasks(conn)
        finally:
            conn.close()

        time.sleep(settings.scheduler_poll_seconds)
