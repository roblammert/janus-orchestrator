# Janus Orchestrator

Single-container workflow orchestration engine using PHP + JavaScript + Python FastAPI + MySQL, with MySQL-backed queue semantics and production-grade control patterns.

## Repository Layout

- `01-Docs/`
	- Architecture and implementation document.
	- Runbook and release/rollback checklist.
	- Workflow definition JSON schema.
- `app/`
	- `php/` backend API and server-rendered UI.
	- `python/` worker, scheduler, executors, FastAPI service.
- `docker/`
	- Dockerfile, Nginx config, Supervisor config, entrypoint.
- `public/`
	- Frontend entrypoint and browser assets.
- `scripts/`
	- DB init and demo scripts.
- `sql/`
	- Schema, observability views, seed data.
- `tests/`
	- Test scaffolding and Python tests.

## Components

- PHP UI/API (`public/index.php` + `app/php/src/*`)
	- Local username/password login and session guard for UI + API.
	- Shared shell layout with sidebar/header/footer status line.
	- Theme preferences for all users and admin font-pair selection.
	- Observability dashboard for metrics and service health.
	- Trend charts for throughput, failure/retry pressure, and latency.
	- UI diagnostics panel showing last API, request ID, and latency.
	- Workflow version creation and listing.
	- Execution start/list/detail.
	- Manual controls: retry, skip, manual-complete, cancel execution.
	- CSV export for workflows, executions, execution tasks, task logs, and dead letters.
	- Keyboard shortcuts for operator workflows (`/`, `R`, `E`, `?`).
	- Polling indicators, empty/loading states, and incremental list/log rendering behavior.
	- Metrics endpoint reading SQL views.
- Python scheduler (`janus_worker.main_scheduler`)
	- Dependency resolution (PENDING -> READY).
	- Retry promotion (FAILED -> READY when allowed).
	- Timeout handling and stale task recovery.
- Python worker (`janus_worker.main_worker`)
	- Transactional queue claim with `FOR UPDATE SKIP LOCKED`.
	- Executor dispatch (HTTP, SCRIPT, FILE_WRITER).
	- Heartbeats, retries, dead-letter state, task logs.
- Python FastAPI service (`janus_worker.main_service`)
	- `/health`
	- `/metrics/overview`
- MySQL
	- Source of truth for timestamps and all workflow state.
	- Queue table (`task_queue`) with claim/release semantics.

## Status Models

- Execution: `PENDING`, `RUNNING`, `COMPLETED`, `FAILED`, `CANCELLED`, `TIMED_OUT`
- Task: `PENDING`, `READY`, `RUNNING`, `COMPLETED`, `FAILED`, `FAILED_PERMANENTLY`, `SKIPPED`

## SQL Artifacts

- `sql/001_schema.sql`: tables and indexes.
- `sql/002_views.sql`: observability views.
- `sql/003_seed_example.sql`: demo workflow seed.
- `sql/004_phase1_auth.sql`: Phase 1 auth and audit tables.

## API Endpoints

Security policy for state-changing API calls:

- Requests using `POST`, `PUT`, `PATCH`, or `DELETE` must include `X-CSRF-Token`.
- Token value is issued by the authenticated shell in a page meta tag.
- RBAC model:
	- `VIEWER`: read-only endpoints and pages.
	- `OPERATOR`: execution/task intervention endpoints except force-complete.
	- `ADMIN`: full access including workflow creation, force-complete, and audit pages.

All API responses now use a standard envelope:

- Success: `{ "success": true, "data": ..., "meta": { ... } }`
- Error: `{ "success": false, "error": { "code", "message", "details" }, "meta": { "request_id" } }`

- `POST /api/workflows` create immutable workflow version
- `GET /api/workflows` list workflows (supports `search`, `sort`, `page`, `page_size`)
- `GET /api/workflows/{id}` get workflow version by id
- `GET /api/workflows/by-name/{name}` list versions for one workflow name
- `POST /api/executions` start execution for workflow id
- `GET /api/executions` list executions (supports `status`, `workflow`, `started_after`, `started_before`, `sort`, `page`, `page_size`)
- `GET /api/executions/{id}` execution details (tasks included)
- `GET /api/executions/{id}/dag` execution DAG summary with runtime node states
- `GET /api/executions/{id}/events` execution/task state transition deltas (supports `since_id`, `limit`)
- `POST /api/executions/{id}/cancel` cancel execution
- `GET /api/tasks` list tasks (supports `status`, `node_key`, `execution_id`, `sort`, `page`, `page_size`)
- `POST /api/tasks/{id}/retry` manual retry
- `POST /api/tasks/{id}/skip` manual skip
- `POST /api/tasks/{id}/complete` manual complete with output
- `GET /api/tasks/{id}/logs` per-task logs (supports `level`, `cursor`, `limit`)
- `GET /api/dead-letters` list dead-letter tasks
- `GET /api/dead-letters/{id}` get dead-letter task detail
- `GET /api/metrics/overview` metrics from SQL views
- `GET /api/health/services` service health summary (web/api/db/fastapi/scheduler/worker)
- `GET /api/meta/shell` app metadata and capability flags for UI shell
- `GET /api/audit-events` list intervention audit events (admin only)

UI routes:

- `GET /login` login page
- `POST /login` authenticate session
- `POST /logout` end session (CSRF protected)
- `GET /settings` theme preferences and admin font selection
- `GET /observability` metrics dashboard and service health panel
- `GET /dead-letters` dead-letter queue triage workspace
- `GET /audit` intervention audit workspace (admin only)

## Environment Variables

Use `.env.example` as source:

- DB: `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASSWORD`
- Worker/scheduler tuning:
	- `WORKER_ID`
	- `WORKER_POLL_SECONDS`
	- `SCHEDULER_POLL_SECONDS`
	- `TASK_HEARTBEAT_INTERVAL_SECONDS`
	- `TASK_STALE_SECONDS`
	- `TASK_RETRY_BACKOFF_SECONDS`
	- `WORKFLOW_DEFAULT_TIMEOUT_SECONDS`
- App/session:
	- `APP_ENV`
	- `APP_VERSION`
	- `SESSION_COOKIE_NAME`
	- `SESSION_ABSOLUTE_TTL_SECONDS`
	- `SESSION_IDLE_TIMEOUT_SECONDS`
	- `BOOTSTRAP_ADMIN_USERNAME`
	- `BOOTSTRAP_ADMIN_PASSWORD`
- Container startup:
	- `AUTO_INIT_DB`
	- `SEED_EXAMPLE`

Security mode:

- Deployment target is HTTP-only (no SSL).
- Session cookies are `HttpOnly` and non-secure by design in this environment.

## Build and Run (Docker)

Build image:

```bash
docker build -f docker/Dockerfile -t janus-orchestrator:local .
```

Run container:

```bash
docker run --rm -it \
	-p 8811:8811 \
	-p 8812:8812 \
	--env-file .env.example \
	janus-orchestrator:local
```

If MySQL is external, set `DB_HOST` and credentials appropriately. If `AUTO_INIT_DB=1`, startup runs schema/view initialization scripts.

## Local Development (Without Container)

- PHP UI/API:

```bash
./scripts/dev-run.sh
```

- Python worker service:

```bash
python3 -m venv .venv
. .venv/bin/activate
pip install -r app/python/requirements.txt
PYTHONPATH=app/python uvicorn janus_worker.main_service:app --host 0.0.0.0 --port 8812
```

- Python scheduler:

```bash
PYTHONPATH=app/python python -m janus_worker.main_scheduler
```

- Python worker:

```bash
PYTHONPATH=app/python python -m janus_worker.main_worker
```

## Workflow Definition Contract

See `01-Docs/workflow-definition.schema.json`.

Definition root fields:

- `name`
- `version`
- `timeout_seconds`
- `nodes[]` with `key`, `name`, `type`, `config`, optional timeout/attempts/priority
- `edges[]` with `from`, `to`, optional `condition`

## Notes on Safety and Idempotency

- HTTP executor injects `Idempotency-Key` header.
- File writer executor performs read-before-write and no-op if content already matches.
- Script executor enforces timeout and checks cancellation during execution.
- All state timestamps use MySQL `NOW()`.
- Dead-letter behavior is explicit with `FAILED_PERMANENTLY`.