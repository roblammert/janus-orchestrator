```text
You are GPT-5.3-codex, an expert software architect and senior full‑stack engineer.

Your task: Design and generate a complete orchestration engine project, named Janus Orchestrator, with high rigor, minimal drift, and no speculative features beyond what is explicitly requested.

================================
CONTEXT AND GOALS
================================
I want to build a workflow orchestration engine that:

- Coordinates and executes multi-step workflows composed of tasks.
- Uses only technologies I know: PHP, JavaScript, Python, FastAPI, and MySQL.
- Runs inside a single Docker container (multiple processes are fine).
- Uses MySQL as both the persistence layer and the queue (no Redis, no RabbitMQ, no Kafka).
- Is designed with production-grade patterns: idempotency, retries, heartbeats, dead letters, observability, and versioning.

You must:
- Be explicit, deterministic, and avoid hand-wavy descriptions.
- Avoid hallucinating external services, libraries, or infrastructure not requested.
- Prefer standard libraries and minimal, well-justified dependencies.
- Provide concrete file structures, schemas, and code skeletons that can be implemented directly.

================================
TECH STACK AND CONSTRAINTS
================================
Languages and components:
- Backend API/UI: PHP (with basic routing or a lightweight framework if absolutely necessary).
- Frontend: JavaScript (vanilla or minimal framework) for a simple web UI.
- Worker service: Python with FastAPI (for internal APIs/health) and background worker loop.
- Database: MySQL (single instance).
- Containerization: Single Docker container running:
  - PHP-FPM + web server (e.g., Nginx or Apache).
  - Python + FastAPI worker service.
  - Supervisor or similar process manager to run multiple processes.

Constraints:
- No external message queues (use MySQL tables as the queue).
- No external cache systems (no Redis, Memcached, etc.).
- No speculative cloud services or managed platforms.
- Keep dependencies minimal and explicit.

================================
CORE CONCEPTS
================================
The orchestration engine must support:

- **Workflow**: A versioned definition of a directed acyclic graph (DAG) of tasks.
- **Task**: A node in the workflow that performs a specific action via an executor.
- **Execution**: A concrete run of a workflow with specific input and resulting state.
- **Executor**: Implementation of a task type (e.g., HTTP call, script, file write).
- **Scheduler**: Component that determines which tasks are ready to run and enqueues them.
- **Worker**: Component that pulls tasks from the queue, executes them, and updates state.

================================
MVP FEATURE SET (MUST IMPLEMENT)
================================
Design and generate code and schemas to support at least:

1. **Workflow Definitions**
   - Store workflows in MySQL with:
     - Versioning (immutable workflow versions).
     - Nodes (tasks) and edges (dependencies).
     - JSON-based configuration for each node.
   - A simple JSON schema for workflow definitions.

2. **Executions**
   - Start a workflow execution with input payload.
   - Track execution state:
     - PENDING, RUNNING, COMPLETED, FAILED, CANCELLED, TIMED_OUT.
   - Track per-task instances:
     - PENDING, READY, RUNNING, COMPLETED, FAILED, FAILED_PERMANENTLY, SKIPPED.

3. **Task Types (Executors)**
   Implement at least these executors:
   - HTTP executor:
     - Supports GET/POST.
     - Configurable URL, headers, body.
   - Script executor:
     - Runs a Python script or shell command in a subprocess.
     - Enforces timeouts.
   - File writer executor:
     - Writes content to a file path inside the container (for demo purposes).

4. **DB-Backed Queue**
   - A `tasks_queue` concept implemented via MySQL table(s).
   - Workers claim tasks using safe row-level locking.
   - Use patterns like `SELECT ... FOR UPDATE` or equivalent to avoid double-claiming.
   - Support `priority`, `scheduled_at`, and `attempts`.

5. **Scheduler**
   - Periodic loop (e.g., cron-like or background process) that:
     - Finds tasks whose dependencies are satisfied.
     - Marks them READY.
     - Enqueues them into the DB-backed queue.
   - Ensures no double-scheduling via transactions/locking.

6. **Worker**
   - Python worker that:
     - Polls the queue table.
     - Atomically claims a task.
     - Loads task definition and execution context.
     - Runs the appropriate executor.
     - Updates task status, output, and logs.
   - Implements:
     - Idempotency key handling where applicable.
     - Heartbeats for long-running tasks.
     - Respect for cancellation flags.

7. **Logging**
   - Per-task logs stored in a dedicated table.
   - Store:
     - Task ID, timestamps, log level, message, optional structured metadata.
   - Avoid unbounded growth by designing for future retention policies (even if not fully implemented).

8. **Basic Web UI (PHP + JS)**
   - Pages to:
     - List workflows.
     - View a workflow’s versions.
     - Start an execution.
     - List executions.
     - View execution details (tasks, statuses, timestamps).
     - View per-task logs.
   - Simple, functional HTML/JS; no need for heavy styling.

9. **Single Docker Container**
   - Dockerfile that:
     - Installs PHP, web server, Python, FastAPI, and required dependencies.
     - Copies code for PHP app, Python worker, and configuration.
   - Supervisor (or similar) configuration to:
     - Run web server + PHP-FPM.
     - Run Python FastAPI service.
     - Run scheduler loop (could be a Python or PHP script).
     - Run worker process(es).

================================
NON-FUNCTIONAL REQUIREMENTS AND CAVEATS
================================
You must explicitly design for and address:

1. **Idempotency**
   - Tasks may run more than once.
   - Provide patterns for:
     - Idempotency keys.
     - Safe external calls (e.g., check-before-write).
   - Document how each executor should be made idempotent or at least safe.

2. **Heartbeats and Stale Task Recovery**
   - Tasks that run longer than a threshold must update a `last_heartbeat_at`.
   - Scheduler or a recovery process must:
     - Detect stale RUNNING tasks.
     - Move them back to READY or mark them FAILED, depending on policy.

3. **Dead Letter Concept**
   - After `max_attempts`, tasks should move to a permanent failure state:
     - `FAILED_PERMANENTLY`.
   - These tasks should not be retried automatically.
   - They should be visible in the UI for manual inspection.

4. **Manual Controls**
   - Provide API endpoints and UI actions for:
     - Retrying a task.
     - Skipping a task.
     - Marking a task as completed with manual output.
     - Cancelling an entire workflow execution.

5. **Workflow Versioning**
   - Workflows are immutable once created.
   - Executions reference a specific workflow version.
   - Changing a workflow creates a new version.

6. **Parallelism and Dependencies**
   - Model workflows as DAGs.
   - Track dependencies so that:
     - A task only becomes READY when all its prerequisites are COMPLETED (or SKIPPED, depending on policy).
   - Support parallel branches and joining nodes.

7. **Time Source Consistency**
   - Use MySQL’s time (e.g., `NOW()`) as the canonical time source for:
     - `created_at`, `updated_at`, `scheduled_at`, etc.
   - Avoid mixing multiple unsynchronized time sources.

8. **Workflow and Task Timeouts**
   - Workflow-level timeout:
     - If exceeded, mark execution as TIMED_OUT and cancel remaining tasks.
   - Task-level timeout:
     - Enforced by worker (e.g., subprocess timeout for scripts, HTTP timeout).

9. **Cancellation**
   - Ability to cancel an execution.
   - Workers must check for cancellation before starting a task and ideally during long-running tasks (via heartbeats or periodic checks).

10. **Secrets Handling (Basic)**
   - A simple secrets table:
     - Encrypted values or at least clearly marked as sensitive.
   - Mechanism to inject secrets into task configs at runtime without logging them.

11. **Worker Health and Graceful Shutdown**
   - FastAPI service exposes:
     - `/health` endpoint.
   - Workers should:
     - Handle SIGTERM/SIGINT gracefully.
     - Finish current task or mark it as stale appropriately.

12. **Observability (MVP Level)**
   - Basic metrics:
     - Number of executions by status.
     - Number of tasks by status.
     - Average task duration.
   - These can be exposed via simple API endpoints or SQL views.

================================
DATA MODEL REQUIREMENTS
================================
Design a MySQL schema including (but not limited to) the following tables:

- `workflows`
  - id, name, version, definition_json, created_at, etc.

- `workflow_nodes`
  - id, workflow_id, name, type, config_json, etc.

- `workflow_edges`
  - id, workflow_id, from_node_id, to_node_id, condition_json, etc.

- `executions`
  - id, workflow_id, workflow_version, status, input_json, output_json, started_at, finished_at, timeout_at, cancelled_at, etc.

- `tasks`
  - id, execution_id, node_id, status, attempts, max_attempts, last_error, scheduled_at, started_at, finished_at, last_heartbeat_at, idempotency_key, output_json, etc.

- `task_queue`
  - id, task_id, priority, scheduled_at, claimed_by_worker_id, claimed_at, etc.
  - Or integrate queue semantics into `tasks` with clear patterns.

- `task_logs`
  - id, task_id, level, message, metadata_json, created_at, etc.

- `secrets`
  - id, name, value_encrypted_or_placeholder, created_at, updated_at, etc.

- `state_transitions` (optional but preferred)
  - id, entity_type, entity_id, from_state, to_state, timestamp, metadata_json, etc.

You may refine or extend this schema, but keep it coherent and minimal.

================================
DELIVERABLES
================================
Produce the following, with as much concrete detail and code as possible:

1. **High-Level Architecture Overview**
   - Components and how they interact:
     - PHP UI/API.
     - Python FastAPI worker service.
     - Scheduler loop.
     - MySQL.
     - Process manager (e.g., Supervisor).

2. **MySQL Schema**
   - `CREATE TABLE` statements for all core tables.
   - Include indexes and constraints relevant to performance and correctness.

3. **Backend PHP Application**
   - Directory structure.
   - Routing structure.
   - Key endpoints:
     - Create workflow.
     - List workflows.
     - Get workflow details.
     - Start execution.
     - List executions.
     - Get execution details.
     - Control endpoints (retry task, skip task, cancel execution, etc.).
   - Example controller/handler code skeletons.

4. **Frontend (PHP + JS)**
   - Simple pages:
     - Workflows list.
     - Workflow detail (including versions).
     - Execution list.
     - Execution detail with task list and logs.
   - Minimal JS for:
     - Triggering actions (start execution, retry task, etc.).
     - Polling for status updates (optional but preferred).

5. **Python Worker + FastAPI Service**
   - Directory structure.
   - FastAPI app with:
     - `/health` endpoint.
   - Worker loop:
     - Poll queue.
     - Claim task with safe locking.
     - Execute via executor registry.
     - Update task state, logs, heartbeats.
   - Executor implementations:
     - HTTP executor.
     - Script executor.
     - File writer executor.
   - Patterns for:
     - Idempotency.
     - Timeouts.
     - Heartbeats.
     - Handling stale tasks.

6. **Scheduler Implementation**
   - Could be a Python or PHP script.
   - Logic to:
     - Find tasks whose dependencies are satisfied.
     - Mark them READY.
     - Enqueue them.
     - Respect workflow and task timeouts.
     - Reclaim stale tasks.

7. **Dockerfile and Supervisor Configuration**
   - Dockerfile that:
     - Installs PHP, web server, Python, and dependencies.
     - Copies application code.
   - Supervisor (or similar) config that:
     - Runs web server + PHP-FPM.
     - Runs Python FastAPI service.
     - Runs scheduler loop.
     - Runs worker process(es).

8. **Configuration and Environment**
   - How environment variables are used (DB connection, secrets, etc.).
   - How to run migrations or initialize the DB.

================================
STYLE AND OUTPUT REQUIREMENTS
================================
- Be explicit and concrete.
- Avoid vague phrases like “and so on”, “etc.” without prior concrete examples.
- Do not invent external services or tools.
- When you make design choices, briefly justify them.
- Provide code that is syntactically correct and structurally coherent, even if some parts are stubs or simplified.
- Favor clarity and correctness over brevity.

Begin by outlining the architecture and schema, then proceed into code structure and key implementation skeletons.
```