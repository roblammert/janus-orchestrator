# Janus Orchestrator - Architecture and Implementation

## 1. High-Level Architecture

Janus Orchestrator runs as multiple cooperating processes in one Docker container:

- Nginx + PHP-FPM process pair serves:
  - HTML pages and JavaScript UI.
  - JSON API for workflow CRUD, execution control, and observability.
- Python FastAPI process serves internal worker health and metrics endpoints.
- Python scheduler process periodically:
  - Promotes dependency-satisfied tasks from PENDING to READY.
  - Enqueues READY tasks to MySQL-backed queue.
  - Applies timeout and stale-task recovery policies.
- Python worker process:
  - Atomically claims queue rows using transactional locking.
  - Executes tasks via executor registry (HTTP, script, file writer).
  - Updates task and execution states.
  - Writes task logs and heartbeats.
- MySQL is both persistent state store and queue backend.

Rationale:
- Single-container requirement is preserved.
- Queue correctness is ensured with row-level locking and transactions.
- Time consistency is enforced by using MySQL `NOW()` for all state timestamps.

## 2. Runtime Flow

1. User creates immutable workflow version with nodes and edges.
2. User starts execution for one workflow version.
3. API creates execution row and one task row per node (initially PENDING).
4. Scheduler determines dependency-ready tasks and enqueues them.
5. Worker claims one queue item transactionally and marks task RUNNING.
6. Worker executes task type and writes logs + heartbeat updates.
7. Worker marks task COMPLETED or FAILED/FAILED_PERMANENTLY and removes queue row.
8. Scheduler/worker updates execution aggregate status to COMPLETED, FAILED, CANCELLED, or TIMED_OUT.

## 3. State Models

### Execution states
- PENDING
- RUNNING
- COMPLETED
- FAILED
- CANCELLED
- TIMED_OUT

### Task states
- PENDING
- READY
- RUNNING
- COMPLETED
- FAILED
- FAILED_PERMANENTLY
- SKIPPED

## 4. Idempotency Model

- Every task has deterministic `idempotency_key`:
  - `sha256(execution_id + ':' + node_name)` by default.
- Executor behavior:
  - HTTP: sends `Idempotency-Key` header; retries safe only if endpoint supports idempotency.
  - Script: enforce optional `idempotent_check_file` or command-level check-before-write pattern.
  - File writer: writes only if target content differs (read-before-write).
- Task reruns are acceptable; side effects should be guarded by executor logic.

## 5. Timeout and Heartbeat Model

- Workflow timeout:
  - `executions.timeout_at` set on start from workflow timeout config.
  - Scheduler marks overdue executions as TIMED_OUT and marks unfinished tasks SKIPPED.
- Task timeout:
  - Worker enforces per-task timeout from node config.
- Heartbeat:
  - RUNNING tasks periodically update `last_heartbeat_at`.
  - Scheduler detects stale RUNNING tasks and retries or permanently fails.

## 6. Dead Letter Policy

- On task failure, if `attempts >= max_attempts`, task transitions to FAILED_PERMANENTLY.
- FAILED_PERMANENTLY tasks are not auto-enqueued.
- UI/API expose these tasks for manual inspection and manual control.

## 7. Manual Controls

API actions:
- Retry task (resets to READY and re-enqueues when allowed).
- Skip task (marks SKIPPED).
- Mark task completed manually (COMPLETED + manual output JSON).
- Cancel execution (sets execution CANCELLED and marks unfinished tasks SKIPPED).

## 8. Secrets Handling (MVP)

- `secrets` table stores values in `value_encrypted` with metadata fields.
- Runtime config can include tokens like `${secret:NAME}`.
- Worker resolves secret tokens before executor invocation.
- Resolved secret values are never written to `task_logs`.

## 9. Observability

- SQL views expose:
  - execution counts by state.
  - task counts by state.
  - average task duration in seconds.
- PHP API and FastAPI endpoints return these metrics.

## 10. Process Model

Supervisor manages:
- `nginx`
- `php-fpm`
- `fastapi-service`
- `scheduler`
- `worker`

All are restartable and isolated by process name for troubleshooting.

## 11. UI Reliability and Operations UX (Phase 5)

- Server-rendered pages are progressively enhanced with `public/assets/app.js` and global utilities in `public/assets/site.js`.
- Reliability-focused UX patterns now include:
  - Polling indicators and explicit refresh actions for operations pages.
  - Empty/loading/skeleton states for data surfaces.
  - Confirmation flows with impact language for manual controls.
  - CSV export for workflows, executions, tasks, logs, and dead letters.
  - Keyboard shortcuts for common operator actions.
- Frontend performance guardrails:
  - Incremental rendering for large log payloads.
  - Virtualized/contained row rendering strategies for larger tables.
- Observability UX additions:
  - Trend charts (throughput, failure/retry pressure, latency).
  - UI diagnostics panel wired to request ID and API latency metadata.
