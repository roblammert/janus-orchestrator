# Tests

## Python tests

Run from repository root:

```bash
python3 -m venv .venv
. .venv/bin/activate
pip install pytest
pytest tests/python -q
```

## API integration smoke tests

These tests call the live API and validate:

- Execution lifecycle (start and cancel)
- Manual task controls (retry, skip, complete)
- Task logs endpoint
- Filter/pagination metadata contracts on list endpoints
- Audit events endpoint contract
- Authenticated UI route shell rendering smoke checks
- Phase 5 UI control rendering checks (refresh/export/poll indicators, trend chart and diagnostics placeholders)

Prerequisites:

1. Janus API is running (default `http://127.0.0.1:8811`).
2. Your external MySQL server is reachable by the running app.
3. Bootstrap/admin login credentials are available (default `admin` / `admin123`).

Run:

```bash
python3 -m venv .venv
. .venv/bin/activate
pip install pytest
JANUS_BASE_URL=http://127.0.0.1:8811 .venv/bin/python -m pytest tests/python/test_api_integration_smoke.py -q
```

Optional:

- `JANUS_API_TIMEOUT_SECONDS` (default `10`)
- `JANUS_AUTH_USERNAME` (default `admin`)
- `JANUS_AUTH_PASSWORD` (default `admin123`)

## API smoke checks (manual)

State-changing API requests require `X-CSRF-Token` from an authenticated session.

1. Create workflow with `POST /api/workflows`.
2. Start execution with `POST /api/executions`.
3. Verify task transitions with `GET /api/executions/{id}`.
4. Verify manual controls for retry/skip/complete/cancel.
