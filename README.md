# Janus Orchestrator

Single-container workflow orchestration engine using PHP + JavaScript + Python FastAPI + MySQL, with MySQL-backed queue semantics and production-grade control patterns.

## Repository Layout

- `01-Docs/`
	- Architecture and implementation document.
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
	- Workflow version creation and listing.
	- Execution start/list/detail.
	- Manual controls: retry, skip, manual-complete, cancel execution.
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

- `POST /api/workflows` create immutable workflow version
- `GET /api/workflows` list workflows by name with latest version
- `GET /api/workflows/{id}` get workflow version by id
- `GET /api/workflows/by-name/{name}` list versions for one workflow name
- `POST /api/executions` start execution for workflow id
- `GET /api/executions` list executions
- `GET /api/executions/{id}` execution details (tasks included)
- `POST /api/executions/{id}/cancel` cancel execution
- `POST /api/tasks/{id}/retry` manual retry
- `POST /api/tasks/{id}/skip` manual skip
- `POST /api/tasks/{id}/complete` manual complete with output
- `GET /api/tasks/{id}/logs` per-task logs
- `GET /api/metrics/overview` metrics from SQL views
- `GET /api/health/services` service health summary (web/api/db/fastapi/scheduler/worker)

UI routes:

- `GET /login` login page
- `POST /login` authenticate session
- `GET /logout` end session
- `GET /settings` theme preferences and admin font selection
- `GET /observability` metrics dashboard and service health panel
- `GET /dead-letters` dead-letter queue triage workspace

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