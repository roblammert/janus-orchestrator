import json
import socket
import sys
import threading
from http.server import BaseHTTPRequestHandler, HTTPServer
from pathlib import Path


ROOT = Path(__file__).resolve().parents[2]
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


def test_executor_registry_contains_all_supported_node_types() -> None:
    assert set(EXECUTOR_REGISTRY.keys()) == {"HTTP", "SCRIPT", "FILE_WRITER"}


def test_script_executor_runs_shell_command() -> None:
    run_script = EXECUTOR_REGISTRY["SCRIPT"]
    result = run_script({"command": "echo script_ok", "shell": True}, "idem-script", 10)

    assert int(result["return_code"]) == 0
    assert "script_ok" in str(result["stdout"])


def test_file_writer_executor_writes_and_is_idempotent(tmp_path: Path) -> None:
    run_file_writer = EXECUTOR_REGISTRY["FILE_WRITER"]
    target = tmp_path / "executor_matrix.txt"

    first = run_file_writer({"path": str(target), "content": "hello", "mode": "w"}, "idem-file", 10)
    second = run_file_writer({"path": str(target), "content": "hello", "mode": "w"}, "idem-file", 10)

    assert target.read_text(encoding="utf-8") == "hello"
    assert bool(first["idempotent_noop"]) is False
    assert int(first["bytes_written"]) == 5
    assert bool(second["idempotent_noop"]) is True
    assert int(second["bytes_written"]) == 0


def test_http_executor_supports_get_and_post() -> None:
    run_http = EXECUTOR_REGISTRY["HTTP"]
    server, base_url = _start_http_server()
    try:
        get_result = run_http(
            {
                "method": "GET",
                "url": f"{base_url}/ping",
                "headers": {"Accept": "application/json"},
            },
            "idem-http-get",
            10,
        )

        post_result = run_http(
            {
                "method": "POST",
                "url": f"{base_url}/echo",
                "headers": {"Content-Type": "application/json"},
                "body": {"message": "hello"},
            },
            "idem-http-post",
            10,
        )
    finally:
        server.shutdown()
        server.server_close()

    get_body = json.loads(str(get_result["body"]))
    post_body = json.loads(str(post_result["body"]))

    assert int(get_result["status_code"]) == 200
    assert get_body["method"] == "GET"
    assert bool(get_body["ok"]) is True

    assert int(post_result["status_code"]) == 200
    assert post_body["method"] == "POST"
    assert bool(post_body["ok"]) is True
    assert post_body["payload"] == {"message": "hello"}
