# Janus Orchestrator Runbook and Release Checklist

## Purpose
Operational runbook for local/docker workflows and release readiness checks for the web UI + API stack.

## Development Runbook

### Local mode
1. Initialize Python environment:

```bash
python3 -m venv .venv
. .venv/bin/activate
pip install -r app/python/requirements.txt
pip install pytest
```

2. Start Janus web/API shell:

```bash
./scripts/dev-run.sh
```

3. Optionally run FastAPI service/scheduler/worker manually (if not already managed by your runtime):

```bash
PYTHONPATH=app/python uvicorn janus_worker.main_service:app --host 0.0.0.0 --port 8812
PYTHONPATH=app/python python -m janus_worker.main_scheduler
PYTHONPATH=app/python python -m janus_worker.main_worker
```

4. Smoke test before code review:

```bash
.venv/bin/python -m pytest -q
```

### Docker mode
1. Build image:

```bash
docker build -f docker/Dockerfile -t janus-orchestrator:local .
```

2. Run container:

```bash
docker run --rm -it \
  -p 8811:8811 \
  -p 8812:8812 \
  --env-file .env.example \
  janus-orchestrator:local
```

3. Validate web/API health:
- Web UI: `http://127.0.0.1:8811`
- FastAPI health: `http://127.0.0.1:8812/health`
- Service health summary: `GET /api/health/services`

## Verification Workflow

### Required test command
```bash
.venv/bin/python -m pytest -q
```

### Coverage now included
- API lifecycle and manual controls.
- Filter/pagination contracts for workflows, executions, tasks, and audit events.
- Task logs cursor/level contract checks.
- Browser-level rendered UI action-surface checks for:
  - start execution modal surface,
  - task controls and task-log controls,
  - dead-letter triage controls.
- Accessibility markup checks (labels and key accessible control markers).
- Visual baseline structure checks for shell and key pages.

## Release Checklist
- [ ] Pull latest default branch and resolve drift.
- [ ] Apply DB schema/view scripts required by current release.
- [ ] Confirm `.env` variables for target environment.
- [ ] Run `.venv/bin/python -m pytest -q` and require green result.
- [ ] Manual quick check in browser:
  - login/logout,
  - start execution,
  - task retry/skip/complete controls,
  - dead-letter page and note save,
  - observability metrics/trends/diagnostics panel.
- [ ] Confirm audit trail entries appear for manual interventions.
- [ ] Confirm role boundaries (viewer/operator/admin) on control buttons and API endpoints.
- [ ] Record release notes summary (risk, known limitations, rollback trigger).

## Rollback Checklist
- [ ] Stop current deployment workload (container/processes).
- [ ] Redeploy previous known-good image/build.
- [ ] Reapply previous known-good env values if changed.
- [ ] Run smoke tests against rollback target.
- [ ] Validate critical user journeys in UI.
- [ ] Confirm DB compatibility and no destructive forward-only migration side effects.
- [ ] Post rollback incident note with trigger, timeline, and remediation actions.

## Current Known Limits
- Browser-level smoke and visual checks are currently contract/structure based (rendered markup assertions), not screenshot-diff based.
- A full screenshot visual regression stack can be layered later with Playwright or similar tooling when desired.
