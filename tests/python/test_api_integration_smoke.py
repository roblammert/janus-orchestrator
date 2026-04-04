import json
import os
import re
import time
import uuid
import http.cookiejar
import urllib.error
import urllib.parse
import urllib.request


BASE_URL = os.getenv("JANUS_BASE_URL", "http://127.0.0.1:8811")
API_TIMEOUT_SECONDS = float(os.getenv("JANUS_API_TIMEOUT_SECONDS", "10"))
AUTH_USERNAME = os.getenv("JANUS_AUTH_USERNAME", "admin")
AUTH_PASSWORD = os.getenv("JANUS_AUTH_PASSWORD", "admin123")


_COOKIE_JAR = http.cookiejar.CookieJar()
_OPENER = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(_COOKIE_JAR))
_CSRF_TOKEN = ""


def _extract_csrf_token(html: str) -> str:
    match = re.search(r'name="csrf_token"\s+value="([^"]+)"', html)
    if not match:
        raise AssertionError('Could not find csrf_token on login page')
    return match.group(1)


def _extract_shell_csrf_token(html: str) -> str:
    match = re.search(r'<meta\s+name="csrf-token"\s+content="([^"]+)"\s*/?>', html)
    if not match:
        raise AssertionError('Could not find csrf-token meta tag on authenticated shell page')
    return match.group(1)


def _ensure_authenticated() -> None:
    global _CSRF_TOKEN

    with _OPENER.open(f"{BASE_URL}/login", timeout=API_TIMEOUT_SECONDS) as response:
        login_html = response.read().decode("utf-8", errors="replace")

    _CSRF_TOKEN = _extract_csrf_token(login_html)

    encoded = urllib.parse.urlencode({"username": AUTH_USERNAME, "password": AUTH_PASSWORD, "csrf_token": _CSRF_TOKEN}).encode("utf-8")
    req = urllib.request.Request(
        url=f"{BASE_URL}/login",
        data=encoded,
        headers={"Content-Type": "application/x-www-form-urlencoded"},
        method="POST",
    )
    with _OPENER.open(req, timeout=API_TIMEOUT_SECONDS):
        pass

    with _OPENER.open(f"{BASE_URL}/", timeout=API_TIMEOUT_SECONDS) as response:
        shell_html = response.read().decode("utf-8", errors="replace")

    _CSRF_TOKEN = _extract_shell_csrf_token(shell_html)


def _request_json(method: str, path: str, payload: dict | None = None) -> tuple[int, dict | list]:
    url = f"{BASE_URL}{path}"
    headers = {"Content-Type": "application/json"}
    if method.upper() in {"POST", "PUT", "PATCH", "DELETE"}:
        if not _CSRF_TOKEN:
            raise AssertionError('Missing CSRF token. Authenticate before state-changing requests.')
        headers["X-CSRF-Token"] = _CSRF_TOKEN
    data = None
    if payload is not None:
        data = json.dumps(payload).encode("utf-8")

    req = urllib.request.Request(url=url, data=data, headers=headers, method=method)
    try:
        with _OPENER.open(req, timeout=API_TIMEOUT_SECONDS) as response:
            raw = response.read().decode("utf-8")
            body = json.loads(raw) if raw else {}
            return response.status, body
    except urllib.error.HTTPError as exc:
        raw = exc.read().decode("utf-8", errors="replace")
        try:
            body = json.loads(raw) if raw else {}
        except json.JSONDecodeError:
            body = {"raw": raw}
        raise AssertionError(f"API request failed: {method} {path} status={exc.code} body={body}") from exc
    except urllib.error.URLError as exc:
        raise AssertionError(
            f"Could not reach API at {BASE_URL}. "
            f"Start the app first and set JANUS_BASE_URL if needed. Details: {exc}"
        ) from exc


def _request_html(path: str) -> tuple[int, str]:
    url = f"{BASE_URL}{path}"
    req = urllib.request.Request(url=url, method="GET")
    try:
        with _OPENER.open(req, timeout=API_TIMEOUT_SECONDS) as response:
            html = response.read().decode("utf-8", errors="replace")
            return response.status, html
    except urllib.error.HTTPError as exc:
        raw = exc.read().decode("utf-8", errors="replace")
        raise AssertionError(f"UI request failed: GET {path} status={exc.code} body={raw[:400]}") from exc


def _unique_workflow_name(prefix: str) -> str:
    return f"{prefix}_{int(time.time())}_{uuid.uuid4().hex[:8]}"


def _create_workflow_with_two_nodes(workflow_name: str) -> int:
    definition = {
        "name": workflow_name,
        "version": 1,
        "timeout_seconds": 300,
        "nodes": [
            {
                "key": "node_a",
                "name": "Node A",
                "type": "FILE_WRITER",
                "timeout_seconds": 20,
                "max_attempts": 3,
                "priority": 100,
                "config": {
                    "path": f"/tmp/{workflow_name}_a.txt",
                    "content": "A",
                    "mode": "w",
                },
            },
            {
                "key": "node_b",
                "name": "Node B",
                "type": "FILE_WRITER",
                "timeout_seconds": 20,
                "max_attempts": 3,
                "priority": 100,
                "config": {
                    "path": f"/tmp/{workflow_name}_b.txt",
                    "content": "B",
                    "mode": "w",
                },
            },
        ],
        "edges": [
            {"from": "node_a", "to": "node_b"},
        ],
    }

    status, body = _request_json(
        "POST",
        "/api/workflows",
        {
            "name": workflow_name,
            "description": "integration smoke workflow",
            "definition": definition,
        },
    )
    assert status == 201
    assert isinstance(body, dict)
    data = body.get("data")
    assert isinstance(data, dict)
    assert "id" in data
    return int(data["id"])


def _start_execution(workflow_id: int, input_payload: dict) -> int:
    status, body = _request_json(
        "POST",
        "/api/executions",
        {"workflow_id": workflow_id, "input": input_payload},
    )
    assert status == 201
    assert isinstance(body, dict)
    data = body.get("data")
    assert isinstance(data, dict)
    assert data.get("status") == "RUNNING"
    return int(data["execution_id"])


def _get_execution(execution_id: int) -> dict:
    status, body = _request_json("GET", f"/api/executions/{execution_id}")
    assert status == 200
    assert isinstance(body, dict)
    data = body.get("data")
    assert isinstance(data, dict)
    return data


def test_execution_lifecycle_cancel_flow() -> None:
    _ensure_authenticated()
    workflow_name = _unique_workflow_name("smoke_lifecycle")
    workflow_id = _create_workflow_with_two_nodes(workflow_name)

    execution_id = _start_execution(workflow_id, {"smoke": True, "test": "lifecycle_cancel"})

    execution_before_cancel = _get_execution(execution_id)
    assert execution_before_cancel["status"] == "RUNNING"
    assert len(execution_before_cancel["tasks"]) == 2

    status, body = _request_json("POST", f"/api/executions/{execution_id}/cancel")
    assert status == 200
    assert body.get("data") == {"ok": True}

    execution_after_cancel = _get_execution(execution_id)
    assert execution_after_cancel["status"] == "CANCELLED"

    task_statuses = [task["status"] for task in execution_after_cancel["tasks"]]
    assert all(status == "SKIPPED" for status in task_statuses)


def test_manual_task_controls_retry_skip_complete_and_logs() -> None:
    _ensure_authenticated()
    workflow_name = _unique_workflow_name("smoke_controls")
    workflow_id = _create_workflow_with_two_nodes(workflow_name)

    execution_1 = _start_execution(workflow_id, {"test": "manual_complete"})
    details_1 = _get_execution(execution_1)
    task_1_id = int(details_1["tasks"][0]["id"])

    status, body = _request_json(
        "POST",
        f"/api/tasks/{task_1_id}/complete",
        {"output": {"manual": True, "note": "smoke complete"}},
    )
    assert status == 200
    assert body.get("data") == {"ok": True}

    details_1_after = _get_execution(execution_1)
    task_1_after = next(task for task in details_1_after["tasks"] if int(task["id"]) == task_1_id)
    assert task_1_after["status"] == "COMPLETED"
    assert isinstance(task_1_after["output_json"], dict)
    assert task_1_after["output_json"].get("manual") is True

    logs_status, logs_body = _request_json("GET", f"/api/tasks/{task_1_id}/logs")
    assert logs_status == 200
    assert isinstance(logs_body, dict)
    assert isinstance(logs_body.get("data"), list)

    execution_2 = _start_execution(workflow_id, {"test": "manual_skip"})
    details_2 = _get_execution(execution_2)
    task_2_id = int(details_2["tasks"][0]["id"])

    status, body = _request_json(
        "POST",
        f"/api/tasks/{task_2_id}/skip",
        {"reason": "smoke skip"},
    )
    assert status == 200
    assert body.get("data") == {"ok": True}

    details_2_after = _get_execution(execution_2)
    task_2_after = next(task for task in details_2_after["tasks"] if int(task["id"]) == task_2_id)
    assert task_2_after["status"] == "SKIPPED"

    execution_3 = _start_execution(workflow_id, {"test": "manual_retry"})
    details_3 = _get_execution(execution_3)
    task_3_id = int(details_3["tasks"][0]["id"])

    status, body = _request_json("POST", f"/api/tasks/{task_3_id}/retry")
    assert status == 200
    assert body.get("data") == {"ok": True}

    details_3_after = _get_execution(execution_3)
    task_3_after = next(task for task in details_3_after["tasks"] if int(task["id"]) == task_3_id)
    assert task_3_after["status"] == "READY"

    # Cleanup to avoid leaving active runs.
    _request_json("POST", f"/api/executions/{execution_1}/cancel")
    _request_json("POST", f"/api/executions/{execution_2}/cancel")
    _request_json("POST", f"/api/executions/{execution_3}/cancel")


def test_filter_pagination_and_audit_contracts() -> None:
    _ensure_authenticated()
    workflow_name = _unique_workflow_name("smoke_filters")
    workflow_id = _create_workflow_with_two_nodes(workflow_name)
    execution_id = _start_execution(workflow_id, {"test": "filters_and_pagination"})

    wf_status, wf_body = _request_json("GET", f"/api/workflows?search={urllib.parse.quote(workflow_name)}&sort=name_asc&page=1&page_size=5")
    assert wf_status == 200
    assert isinstance(wf_body, dict)
    wf_data = wf_body.get("data")
    wf_meta = wf_body.get("meta")
    assert isinstance(wf_data, list)
    assert isinstance(wf_meta, dict)
    assert isinstance(wf_meta.get("pagination"), dict)
    assert any(item.get("name") == workflow_name for item in wf_data)

    exec_status, exec_body = _request_json(
        "GET",
        f"/api/executions?workflow={urllib.parse.quote(workflow_name)}&status=RUNNING&sort=id_desc&page=1&page_size=10",
    )
    assert exec_status == 200
    exec_data = exec_body.get("data")
    exec_meta = exec_body.get("meta")
    assert isinstance(exec_data, list)
    assert isinstance(exec_meta, dict)
    assert isinstance(exec_meta.get("pagination"), dict)
    assert any(int(item.get("id", 0)) == execution_id for item in exec_data)

    tasks_status, tasks_body = _request_json("GET", f"/api/tasks?execution_id={execution_id}&sort=id_desc&page=1&page_size=20")
    assert tasks_status == 200
    tasks_data = tasks_body.get("data")
    tasks_meta = tasks_body.get("meta")
    assert isinstance(tasks_data, list)
    assert len(tasks_data) >= 2
    assert isinstance(tasks_meta, dict)
    assert isinstance(tasks_meta.get("pagination"), dict)

    events_status, events_body = _request_json("GET", f"/api/executions/{execution_id}/events?since_id=0&limit=50")
    assert events_status == 200
    assert isinstance(events_body.get("data"), list)
    assert isinstance(events_body.get("meta"), dict)
    assert "next_since_id" in (events_body.get("meta") or {})

    _request_json("POST", f"/api/executions/{execution_id}/cancel")

    audit_status, audit_body = _request_json("GET", "/api/audit-events?page=1&page_size=50")
    assert audit_status == 200
    audit_data = audit_body.get("data")
    audit_meta = audit_body.get("meta")
    assert isinstance(audit_data, list)
    assert isinstance(audit_meta, dict)
    assert isinstance(audit_meta.get("pagination"), dict)
    assert any(item.get("event_type") == "execution_cancel" for item in audit_data)


def test_authenticated_ui_routes_render_shell() -> None:
    _ensure_authenticated()

    pages = [
        "/",
        "/executions",
        "/dead-letters",
        "/observability",
        "/settings",
        "/audit",
    ]

    for path in pages:
        status, html = _request_html(path)
        assert status == 200
        assert '<meta name="csrf-token" content="' in html
        assert 'class="app-shell"' in html
        assert 'Janus Orchestrator' in html
        assert '/assets/app.js' in html


def test_phase5_ui_controls_render_on_key_pages() -> None:
    _ensure_authenticated()

    route_expectations = {
        '/': [
            'id="workflow-refresh-btn"',
            'id="workflow-export-csv-btn"',
            'id="workflow-poll-indicator"',
            'id="workflow-empty-state"',
            'id="workflow-definition-viewer"',
        ],
        '/executions': [
            'id="executions-refresh-btn"',
            'id="executions-export-csv-btn"',
            'id="executions-poll-indicator"',
            'id="executions-empty-state"',
        ],
        '/dead-letters': [
            'id="dead-letter-refresh-btn"',
            'id="dead-letter-export-csv-btn"',
            'id="dead-letter-poll-indicator"',
            'id="dead-letter-empty-state"',
            'id="dead-letter-detail-viewer"',
        ],
        '/observability': [
            'id="observability-refresh-btn"',
            'id="observability-poll-indicator"',
            'id="obs-trend-throughput"',
            'id="obs-trend-failure"',
            'id="obs-trend-latency"',
            'id="diag-last-api"',
            'id="diag-request-id"',
            'id="diag-latency"',
            'id="diag-updated-at"',
        ],
    }

    for path, expected_markers in route_expectations.items():
        status, html = _request_html(path)
        assert status == 200
        for marker in expected_markers:
            assert marker in html, f"missing marker {marker} on {path}"
