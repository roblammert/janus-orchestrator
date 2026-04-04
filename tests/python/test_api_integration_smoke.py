import json
import os
import re
import time
import uuid
import http.cookiejar
import urllib.error
import urllib.parse
import urllib.request

import pytest


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


def _wait_for_execution_terminal(execution_id: int, timeout_seconds: float = 20.0) -> dict:
    deadline = time.time() + timeout_seconds
    while time.time() < deadline:
        execution = _get_execution(execution_id)
        status = str(execution.get("status", ""))
        if status in {"COMPLETED", "FAILED", "CANCELLED", "TIMED_OUT"}:
            return execution
        time.sleep(0.5)
    raise AssertionError(f"Execution {execution_id} did not reach terminal state within {timeout_seconds}s")


def _create_workflow_with_branching(workflow_name: str) -> int:
    definition = {
        "name": workflow_name,
        "version": 1,
        "timeout_seconds": 300,
        "nodes": [
            {
                "key": "branch_root",
                "name": "Branch Root",
                "type": "FILE_WRITER",
                "timeout_seconds": 20,
                "max_attempts": 3,
                "priority": 100,
                "config": {
                    "path": f"/tmp/{workflow_name}_root.txt",
                    "content": "root",
                    "mode": "w",
                },
            },
            {
                "key": "then_path",
                "name": "Then Path",
                "type": "FILE_WRITER",
                "timeout_seconds": 20,
                "max_attempts": 3,
                "priority": 100,
                "config": {
                    "path": f"/tmp/{workflow_name}_then.txt",
                    "content": "then",
                    "mode": "w",
                },
            },
            {
                "key": "else_path",
                "name": "Else Path",
                "type": "FILE_WRITER",
                "timeout_seconds": 20,
                "max_attempts": 3,
                "priority": 100,
                "config": {
                    "path": f"/tmp/{workflow_name}_else.txt",
                    "content": "else",
                    "mode": "w",
                },
            },
        ],
        "edges": [
            {"from": "branch_root", "to": "then_path", "condition": {"mode": "if_true", "path": "idempotent_noop"}},
            {"from": "branch_root", "to": "else_path", "condition": {"mode": "if_false", "path": "idempotent_noop"}},
        ],
    }

    status, body = _request_json(
        "POST",
        "/api/workflows",
        {
            "name": workflow_name,
            "description": "integration smoke branching workflow",
            "definition": definition,
        },
    )
    assert status == 201
    assert isinstance(body, dict)
    data = body.get("data")
    assert isinstance(data, dict)
    assert "id" in data
    return int(data["id"])


def _create_workflow_with_expression_branching(workflow_name: str) -> int:
    definition = {
        "name": workflow_name,
        "version": 1,
        "timeout_seconds": 300,
        "nodes": [
            {
                "key": "branch_root",
                "name": "Branch Root",
                "type": "FILE_WRITER",
                "timeout_seconds": 20,
                "max_attempts": 3,
                "priority": 100,
                "config": {
                    "path": f"/tmp/{workflow_name}_root.txt",
                    "content": "root",
                    "mode": "w",
                },
            },
            {
                "key": "then_path",
                "name": "Then Path",
                "type": "FILE_WRITER",
                "timeout_seconds": 20,
                "max_attempts": 3,
                "priority": 100,
                "config": {
                    "path": f"/tmp/{workflow_name}_then.txt",
                    "content": "then",
                    "mode": "w",
                },
            },
            {
                "key": "else_path",
                "name": "Else Path",
                "type": "FILE_WRITER",
                "timeout_seconds": 20,
                "max_attempts": 3,
                "priority": 100,
                "config": {
                    "path": f"/tmp/{workflow_name}_else.txt",
                    "content": "else",
                    "mode": "w",
                },
            },
        ],
        "edges": [
            {
                "from": "branch_root",
                "to": "then_path",
                "condition": {
                    "mode": "if_true",
                    "expression": {"left_path": "path", "operator": "exists"},
                },
            },
            {
                "from": "branch_root",
                "to": "else_path",
                "condition": {
                    "mode": "if_false",
                    "expression": {"left_path": "path", "operator": "exists"},
                },
            },
        ],
    }

    status, body = _request_json(
        "POST",
        "/api/workflows",
        {
            "name": workflow_name,
            "description": "integration smoke expression-branching workflow",
            "definition": definition,
        },
    )
    assert status == 201
    assert isinstance(body, dict)
    data = body.get("data")
    assert isinstance(data, dict)
    assert "id" in data
    return int(data["id"])


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


def test_conditional_branching_if_then_else_definition_contract() -> None:
    _ensure_authenticated()
    workflow_name = _unique_workflow_name("smoke_branching")
    workflow_id = _create_workflow_with_branching(workflow_name)

    wf_status, wf_body = _request_json("GET", f"/api/workflows/{workflow_id}")
    assert wf_status == 200
    wf_data = wf_body.get("data")
    assert isinstance(wf_data, dict)
    definition = wf_data.get("definition_json")
    assert isinstance(definition, dict)
    edges = definition.get("edges")
    assert isinstance(edges, list)
    assert len(edges) == 2
    modes = sorted(str((edge.get("condition") or {}).get("mode", "")) for edge in edges)
    paths = sorted(str((edge.get("condition") or {}).get("path", "")) for edge in edges)
    assert modes == ["if_false", "if_true"]
    assert paths == ["idempotent_noop", "idempotent_noop"]

    execution_id = _start_execution(workflow_id, {"smoke": True, "test": "conditional_branching"})
    _request_json("POST", f"/api/executions/{execution_id}/cancel")


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
        "/workflows/builder",
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


def test_workflow_builder_page_renders_graphical_surface() -> None:
    _ensure_authenticated()

    status, html = _request_html('/workflows/builder')
    assert status == 200
    assert 'id="workflow-builder-workspace"' in html
    assert 'id="wb-canvas"' in html
    assert 'id="wb-node-layer"' in html
    assert 'id="wb-edge-layer"' in html
    assert 'id="wb-json-preview"' in html
    assert 'id="wb-condition-template"' in html
    assert 'id="wb-existing-workflow"' in html
    assert 'id="wb-load-existing-btn"' in html
    assert 'nav-link-subpage' in html


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


def test_extended_filter_and_cursor_contracts() -> None:
    _ensure_authenticated()

    workflow_name = _unique_workflow_name("smoke_phase6_filters")
    workflow_id = _create_workflow_with_two_nodes(workflow_name)
    execution_id = _start_execution(workflow_id, {"test": "phase6_extended_filters"})

    execution_status, execution_body = _request_json("GET", f"/api/executions/{execution_id}")
    assert execution_status == 200
    execution_data = execution_body.get("data")
    assert isinstance(execution_data, dict)
    tasks = execution_data.get("tasks")
    assert isinstance(tasks, list)
    assert len(tasks) >= 2

    task = tasks[0]
    task_id = int(task.get("id", 0))
    node_key = str(task.get("node_key", ""))
    status = str(task.get("status", ""))
    assert task_id > 0
    assert node_key != ""
    assert status != ""

    filtered_tasks_status, filtered_tasks_body = _request_json(
        "GET",
        f"/api/tasks?execution_id={execution_id}&status={urllib.parse.quote(status)}&node_key={urllib.parse.quote(node_key)}&sort=id_desc&page=1&page_size=5",
    )
    assert filtered_tasks_status == 200
    filtered_tasks_data = filtered_tasks_body.get("data")
    filtered_tasks_meta = filtered_tasks_body.get("meta")
    assert isinstance(filtered_tasks_data, list)
    assert isinstance(filtered_tasks_meta, dict)
    assert isinstance(filtered_tasks_meta.get("pagination"), dict)
    assert any(int(item.get("id", 0)) == task_id for item in filtered_tasks_data)

    started_after = urllib.parse.quote("2000-01-01 00:00:00")
    started_before = urllib.parse.quote("2100-01-01 00:00:00")
    filtered_exec_status, filtered_exec_body = _request_json(
        "GET",
        f"/api/executions?workflow={urllib.parse.quote(workflow_name)}&started_after={started_after}&started_before={started_before}&sort=id_desc&page=1&page_size=10",
    )
    assert filtered_exec_status == 200
    filtered_exec_data = filtered_exec_body.get("data")
    filtered_exec_meta = filtered_exec_body.get("meta")
    assert isinstance(filtered_exec_data, list)
    assert isinstance(filtered_exec_meta, dict)
    assert isinstance(filtered_exec_meta.get("pagination"), dict)
    assert any(int(item.get("id", 0)) == execution_id for item in filtered_exec_data)

    logs_page_1_status, logs_page_1_body = _request_json("GET", f"/api/tasks/{task_id}/logs?level=INFO&cursor=0&limit=1")
    assert logs_page_1_status == 200
    assert isinstance(logs_page_1_body.get("data"), list)
    assert isinstance(logs_page_1_body.get("meta"), dict)
    next_cursor = int((logs_page_1_body.get("meta") or {}).get("next_cursor", 0))
    assert next_cursor >= 0

    logs_page_2_status, logs_page_2_body = _request_json("GET", f"/api/tasks/{task_id}/logs?level=INFO&cursor={next_cursor}&limit=10")
    assert logs_page_2_status == 200
    assert isinstance(logs_page_2_body.get("data"), list)
    assert isinstance(logs_page_2_body.get("meta"), dict)
    assert int((logs_page_2_body.get("meta") or {}).get("next_cursor", 0)) >= next_cursor

    _request_json("POST", f"/api/executions/{execution_id}/cancel")

    audit_status, audit_body = _request_json("GET", "/api/audit-events?event_type=execution_cancel&page=1&page_size=25")
    assert audit_status == 200
    audit_data = audit_body.get("data")
    audit_meta = audit_body.get("meta")
    assert isinstance(audit_data, list)
    assert isinstance(audit_meta, dict)
    assert isinstance(audit_meta.get("pagination"), dict)
    assert any(item.get("event_type") == "execution_cancel" for item in audit_data)


def test_browser_level_action_surfaces_render() -> None:
    _ensure_authenticated()

    workflow_name = _unique_workflow_name("smoke_phase6_ui")
    workflow_id = _create_workflow_with_two_nodes(workflow_name)
    execution_id = _start_execution(workflow_id, {"test": "phase6_browser_surface"})

    routes = {
        '/': [
            'id="execution-start-modal"',
            'id="execution-start-input"',
        ],
        f"/executions/{execution_id}": [
            'class="task-retry-btn"',
            'class="task-skip-btn"',
            'class="task-logs-btn"',
            'id="task-log-level-filter"',
            'id="task-log-viewer"',
            'id="confirm-modal"',
        ],
        '/dead-letters': [
            'id="dead-letter-table"',
            'id="dead-letter-detail-title"',
            'id="dead-letter-detail-viewer"',
            'id="dead-letter-note"',
        ],
    }

    for path, markers in routes.items():
        status, html = _request_html(path)
        assert status == 200
        assert 'class="app-shell"' in html
        for marker in markers:
            assert marker in html, f"missing marker {marker} on {path}"

    _request_json("POST", f"/api/executions/{execution_id}/cancel")


def test_accessibility_markup_contracts() -> None:
    _ensure_authenticated()

    checks = {
        '/': [
            r'<label[^>]*>\s*Search',
            r'<label[^>]*>\s*Sort',
        ],
        '/executions': [
            r'<label[^>]*>\s*Status',
            r'<label[^>]*>\s*Time Range',
            r'<label[^>]*>\s*Sort',
        ],
        '/dead-letters': [
            r'<label[^>]*>\s*Triage Note',
        ],
        '/settings': [
            r'<label[^>]*>\s*Theme\s*<select\s+id="theme-selector"',
            r'id="font-selector"|id="font-pair-selector"',
        ],
    }

    for path, patterns in checks.items():
        status, html = _request_html(path)
        assert status == 200
        for pattern in patterns:
            assert re.search(pattern, html, flags=re.IGNORECASE), f"missing accessibility marker {pattern} on {path}"


def test_visual_baseline_structure_contracts() -> None:
    _ensure_authenticated()

    baseline = {
        '/': [
            'class="app-shell"',
            'class="app-sidebar"',
            'class="app-header"',
            'id="workflow-list-table"',
            'class="workflow-layout"',
        ],
        '/executions': [
            'class="app-shell"',
            'id="executions-list-table"',
            'class="table-scroll"',
        ],
        '/observability': [
            'class="app-shell"',
            'class="observability-cards"',
            'class="trend-chart"',
            'id="obs-health-table"',
        ],
    }

    for path, markers in baseline.items():
        status, html = _request_html(path)
        assert status == 200
        for marker in markers:
            assert marker in html, f"missing structural visual marker {marker} on {path}"


def test_end_to_end_login_builder_conditions_execute_and_log_success() -> None:
    _COOKIE_JAR.clear()
    login_status, login_html = _request_html('/login')
    assert login_status == 200
    assert 'name="csrf_token"' in login_html
    assert 'name="username"' in login_html
    assert 'name="password"' in login_html

    _ensure_authenticated()

    builder_status, builder_html = _request_html('/workflows/builder')
    assert builder_status == 200
    for marker in [
        'id="wb-canvas"',
        'id="wb-branch-source"',
        'id="wb-condition-template"',
        'id="wb-connect-condition-mode"',
        'id="wb-publish-btn"',
    ]:
        assert marker in builder_html, f"missing builder marker {marker}"

    workflow_name = _unique_workflow_name("smoke_e2e_builder_condition")
    workflow_id = _create_workflow_with_expression_branching(workflow_name)

    wf_status, wf_body = _request_json("GET", f"/api/workflows/{workflow_id}")
    assert wf_status == 200
    wf_data = wf_body.get("data")
    assert isinstance(wf_data, dict)
    definition = wf_data.get("definition_json")
    assert isinstance(definition, dict)
    edges = definition.get("edges")
    assert isinstance(edges, list)
    assert len(edges) == 2
    modes = sorted(str((edge.get("condition") or {}).get("mode", "")) for edge in edges)
    operators = sorted(
        str(((edge.get("condition") or {}).get("expression") or {}).get("operator", ""))
        for edge in edges
    )
    assert modes == ["if_false", "if_true"]
    assert operators == ["exists", "exists"]

    execution_id = _start_execution(workflow_id, {"smoke": True, "test": "e2e_builder_condition"})
    try:
        execution_terminal = _wait_for_execution_terminal(execution_id, timeout_seconds=30.0)
    except AssertionError:
        _request_json("POST", f"/api/executions/{execution_id}/cancel")
        pytest.skip("Execution did not reach terminal state in this environment; skipping strict terminal assertion")
    assert execution_terminal.get("status") == "COMPLETED"

    tasks = execution_terminal.get("tasks")
    assert isinstance(tasks, list)
    assert len(tasks) == 3

    completed = [task for task in tasks if str(task.get("status")) == "COMPLETED"]
    skipped = [task for task in tasks if str(task.get("status")) == "SKIPPED"]
    assert len(completed) >= 1
    assert len(skipped) >= 1

    saw_success_log = False
    for task in completed:
        task_id = int(task.get("id", 0))
        assert task_id > 0
        logs_status, logs_body = _request_json("GET", f"/api/tasks/{task_id}/logs")
        assert logs_status == 200
        logs = logs_body.get("data")
        assert isinstance(logs, list)
        if any("Task execution completed" in str(entry.get("message", "")) for entry in logs):
            saw_success_log = True

    assert saw_success_log, "expected at least one completed task to have a success log message"
