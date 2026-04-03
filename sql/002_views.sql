USE janus_orchestrator;

CREATE OR REPLACE VIEW v_execution_counts_by_status AS
SELECT
    status,
    COUNT(*) AS total
FROM executions
GROUP BY status;

CREATE OR REPLACE VIEW v_task_counts_by_status AS
SELECT
    status,
    COUNT(*) AS total
FROM tasks
GROUP BY status;

CREATE OR REPLACE VIEW v_avg_task_duration_seconds AS
SELECT
    COALESCE(AVG(TIMESTAMPDIFF(SECOND, started_at, finished_at)), 0) AS avg_duration_seconds
FROM tasks
WHERE started_at IS NOT NULL
  AND finished_at IS NOT NULL;

CREATE OR REPLACE VIEW v_failed_permanent_tasks AS
SELECT
    t.id AS task_id,
    t.execution_id,
    t.node_key,
    t.attempts,
    t.max_attempts,
    t.last_error,
    t.updated_at
FROM tasks t
WHERE t.status = 'FAILED_PERMANENTLY';
