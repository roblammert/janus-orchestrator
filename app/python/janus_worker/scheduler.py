from __future__ import annotations

import json
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
            SELECT t.id, t.priority, t.execution_id, t.workflow_node_id
            FROM tasks t
            INNER JOIN executions e ON e.id = t.execution_id
            WHERE t.status = 'PENDING'
              AND e.status IN ('PENDING', 'RUNNING')
            """
        )
        rows = cur.fetchall()

        for row in rows:
            task_id = int(row["id"])
            execution_id = int(row["execution_id"])
            workflow_node_id = int(row["workflow_node_id"])

            edge_cur = conn.cursor(dictionary=True)
            edge_cur.execute(
                """
                SELECT we.condition_json, pred_task.status AS pred_status, pred_task.output_json AS pred_output
                FROM workflow_edges we
                INNER JOIN tasks pred_task
                    ON pred_task.execution_id = %s
                   AND pred_task.workflow_node_id = we.from_node_id
                WHERE we.to_node_id = %s
                """,
                (execution_id, workflow_node_id),
            )
            incoming = edge_cur.fetchall()
            edge_cur.close()

            all_resolved = True
            active_edges = 0

            for incoming_edge in incoming:
                pred_status = str(incoming_edge.get("pred_status") or "")
                if pred_status not in {"COMPLETED", "SKIPPED"}:
                    all_resolved = False
                    continue

                if _edge_condition_matches(pred_status, incoming_edge.get("condition_json"), incoming_edge.get("pred_output")):
                    active_edges += 1

            if not all_resolved:
                continue

            # Root nodes (no incoming edges) are always eligible.
            if incoming and active_edges == 0:
                cur.execute(
                    """
                    UPDATE tasks
                    SET status = 'SKIPPED',
                        finished_at = NOW(),
                        last_error = 'Skipped: no incoming branch condition matched'
                    WHERE id = %s AND status = 'PENDING'
                    """,
                    (task_id,),
                )
                cur.execute(
                    """
                    INSERT INTO state_transitions (entity_type, entity_id, from_state, to_state, metadata_json)
                    VALUES ('task', %s, 'PENDING', 'SKIPPED', JSON_OBJECT('reason', 'branch_not_selected'))
                    """,
                    (task_id,),
                )
                continue

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


def _decode_json_field(value):
    if value is None:
        return None
    if isinstance(value, dict):
        return value
    if isinstance(value, (bytes, bytearray)):
        value = value.decode("utf-8", errors="ignore")
    if isinstance(value, str):
        text = value.strip()
        if text == "":
            return None
        try:
            return json.loads(text)
        except Exception:
            return None
    return None


def _read_path(payload, path: str):
    current = payload
    for part in [p for p in path.split('.') if p]:
        if not isinstance(current, dict) or part not in current:
            return None
        current = current[part]
    return current


def _as_number(value):
    try:
        return float(value)
    except Exception:
        return None


def _evaluate_expression(pred_output, expression) -> bool:
    left_path = str(expression.get("left_path") or "").strip()
    operator = str(expression.get("operator") or "truthy").strip()
    right_value = expression.get("right_value")

    if left_path == "":
        return False

    left_value = _read_path(pred_output, left_path)

    if operator == "truthy":
        return bool(left_value)
    if operator == "exists":
        return left_value is not None
    if operator == "empty":
        if left_value is None:
            return True
        if isinstance(left_value, (str, list, dict, tuple, set)):
            return len(left_value) == 0
        return False
    if operator == "equals":
        return left_value == right_value
    if operator == "not_equals":
        return left_value != right_value
    if operator == "contains":
        if isinstance(left_value, str):
            return str(right_value) in left_value
        if isinstance(left_value, list):
            return right_value in left_value
        if isinstance(left_value, dict):
            return str(right_value) in left_value
        return False
    if operator in {"gt", "gte", "lt", "lte"}:
        left_num = _as_number(left_value)
        right_num = _as_number(right_value)
        if left_num is None or right_num is None:
            return False
        if operator == "gt":
            return left_num > right_num
        if operator == "gte":
            return left_num >= right_num
        if operator == "lt":
            return left_num < right_num
        return left_num <= right_num

    return False


def _edge_condition_matches(pred_status: str, condition_json, pred_output_json) -> bool:
    if pred_status != "COMPLETED":
        return False

    condition = _decode_json_field(condition_json)
    if condition is None:
        return True

    mode = str(condition.get("mode") or "").strip().lower()
    if mode not in {"if_true", "if_false"}:
        return False

    pred_output = _decode_json_field(pred_output_json)
    expression = condition.get("expression")
    if isinstance(expression, dict):
        result = _evaluate_expression(pred_output, expression)
    else:
        # Backward compatibility for older definitions that used a single path.
        path = str(condition.get("path") or "").strip()
        if path == "":
            return False
        result = bool(_read_path(pred_output, path))

    return result if mode == "if_true" else (not result)


def _sync_execution_statuses(conn) -> None:
    cur = conn.cursor(dictionary=True)
    try:
        cur.execute(
            """
            SELECT e.id,
                   SUM(CASE WHEN t.status = 'FAILED_PERMANENTLY' THEN 1 ELSE 0 END) AS failed_permanent,
                   SUM(CASE WHEN t.status = 'FAILED' THEN 1 ELSE 0 END) AS failed,
                   SUM(CASE WHEN t.status IN ('PENDING', 'READY', 'RUNNING') THEN 1 ELSE 0 END) AS unfinished,
                   SUM(CASE WHEN t.status = 'COMPLETED' THEN 1 ELSE 0 END) AS completed,
                   SUM(CASE WHEN t.status = 'SKIPPED' THEN 1 ELSE 0 END) AS skipped,
                   COUNT(*) AS total
            FROM executions e
            INNER JOIN tasks t ON t.execution_id = e.id
            WHERE e.status IN ('PENDING', 'RUNNING')
            GROUP BY e.id
            """
        )
        rows = cur.fetchall()

        for row in rows:
            target = None
            execution_id = int(row["id"])
            if int(row["failed_permanent"]) > 0:
                target = "FAILED"
            elif int(row["failed"]) > 0 and int(row["unfinished"]) == 0:
                target = "FAILED"
            elif int(row["completed"]) + int(row["skipped"]) == int(row["total"]):
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


def run_scheduler() -> None:
    db = Db()
    while True:
        conn = db.connect()
        try:
            _mark_timeout_executions(conn)
            _recover_stale_running_tasks(conn)
            _promote_failed_to_ready_for_retry(conn)
            _promote_dependency_ready_tasks(conn)
            _sync_execution_statuses(conn)
        finally:
            conn.close()

        time.sleep(settings.scheduler_poll_seconds)
