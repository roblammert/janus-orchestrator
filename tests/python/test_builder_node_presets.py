import json
import re
import socket
import sys
import threading
from http.server import BaseHTTPRequestHandler, HTTPServer
from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]
APP_JS = ROOT / "public" / "assets" / "app.js"
PYTHON_SRC = ROOT / "app" / "python"
if str(PYTHON_SRC) not in sys.path:
    sys.path.insert(0, str(PYTHON_SRC))

from janus_worker.executors import EXECUTOR_REGISTRY


class _TestHandler(BaseHTTPRequestHandler):
    def do_GET(self):  # noqa: N802
        self.send_response(200)
        self.send_header("Content-Type", "application/json")
        self.end_headers()
        self.wfile.write(json.dumps({"ok": True, "method": "GET"}).encode("utf-8"))

    def do_POST(self):  # noqa: N802
        body_len = int(self.headers.get("Content-Length", "0"))
        raw = self.rfile.read(body_len).decode("utf-8") if body_len > 0 else "{}"
        payload = json.loads(raw)
        self.send_response(200)
        self.send_header("Content-Type", "application/json")
        self.end_headers()
        self.wfile.write(json.dumps({"ok": True, "method": "POST", "payload": payload}).encode("utf-8"))

    def log_message(self, fmt, *args):
        del fmt, args


def _start_http_server() -> tuple[HTTPServer, str]:
    with socket.socket(socket.AF_INET, socket.SOCK_STREAM) as s:
        s.bind(("127.0.0.1", 0))
        host, port = s.getsockname()

    server = HTTPServer((host, port), _TestHandler)
    thread = threading.Thread(target=server.serve_forever, daemon=True)
    thread.start()
    return server, f"http://{host}:{port}"


def _extract_node_presets() -> dict[str, str]:
    text = APP_JS.read_text(encoding="utf-8")
    match = re.search(r"const NODE_PRESETS = \{(.*?)\n\s*\};\n\n\s*const CONDITION_TEMPLATES", text, flags=re.DOTALL)
    if not match:
        raise AssertionError("Could not locate NODE_PRESETS in app.js")

    block = match.group(1)
    preset_pattern = re.compile(r"\n\s*([a-z_]+):\s*\{\s*\n\s*type:\s*'([A-Z_]+)'", flags=re.MULTILINE)
    presets = {name: node_type for name, node_type in preset_pattern.findall(block)}
    return presets


def _runtime_config_for_preset(name: str, base_url: str, tmp_path: Path) -> dict:
    if name in {"http_request", "webhook_call", "graphql_query", "email_notification", "slack_notification"}:
        if name == "http_request":
            return {"method": "GET", "url": f"{base_url}/http_request", "headers": {"Accept": "application/json"}}
        return {
            "method": "POST",
            "url": f"{base_url}/{name}",
            "headers": {"Content-Type": "application/json"},
            "body": {"preset": name},
        }

    if name == "file_writer":
        return {
            "path": str(tmp_path / "preset_file_writer.txt"),
            "content": "preset_file_writer",
            "mode": "w",
        }

    script_commands = {
        "sql_query": "echo sql_query_ok",
        "python_script": f'"{sys.executable}" -c \'print("python_script_ok")\'',
        "shell_command": "echo shell_command_ok",
        "delay_timer": "echo delay_timer_ok",
        "approval_gate": "echo '{\"approved\": true}'",
        "json_transform": "echo '{\"result\": {\"approved\": true}}'",
    }
    if name in script_commands:
        return {"command": script_commands[name], "shell": True}

    raise AssertionError(f"Unknown preset: {name}")


def test_builder_has_expected_12_node_presets() -> None:
    presets = _extract_node_presets()
    expected = {
        "http_request": "HTTP",
        "webhook_call": "HTTP",
        "graphql_query": "HTTP",
        "sql_query": "SCRIPT",
        "python_script": "SCRIPT",
        "shell_command": "SCRIPT",
        "file_writer": "FILE_WRITER",
        "delay_timer": "SCRIPT",
        "approval_gate": "SCRIPT",
        "json_transform": "SCRIPT",
        "email_notification": "HTTP",
        "slack_notification": "HTTP",
    }
    assert presets == expected


def test_all_12_builder_node_presets_execute(tmp_path: Path) -> None:
    presets = _extract_node_presets()
    server, base_url = _start_http_server()
    try:
        for name, node_type in presets.items():
            executor = EXECUTOR_REGISTRY[node_type]
            config = _runtime_config_for_preset(name, base_url, tmp_path)

            if node_type == "SCRIPT":
                result = executor(config, f"idem-{name}", 10)
                assert int(result["return_code"]) == 0
            elif node_type == "FILE_WRITER":
                first = executor(config, f"idem-{name}", 10)
                second = executor(config, f"idem-{name}", 10)
                assert bool(first["idempotent_noop"]) is False
                assert bool(second["idempotent_noop"]) is True
            else:
                result = executor(config, f"idem-{name}", 10)
                assert int(result["status_code"]) == 200
                body = json.loads(str(result["body"]))
                assert bool(body["ok"]) is True
    finally:
        server.shutdown()
        server.server_close()
