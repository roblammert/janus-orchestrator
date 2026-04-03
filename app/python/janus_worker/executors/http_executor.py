from __future__ import annotations

import json
import urllib.request


def run(config: dict, idempotency_key: str, timeout_seconds: int) -> dict:
    method = str(config.get("method", "GET")).upper()
    if method not in {"GET", "POST"}:
        raise RuntimeError(f"Unsupported HTTP method: {method}")

    url = str(config["url"])
    headers = dict(config.get("headers", {}))
    headers["Idempotency-Key"] = idempotency_key

    data = None
    if method == "POST":
        body = config.get("body", {})
        data = json.dumps(body).encode("utf-8")
        headers.setdefault("Content-Type", "application/json")

    req = urllib.request.Request(url=url, data=data, method=method)
    for k, v in headers.items():
        req.add_header(k, str(v))

    with urllib.request.urlopen(req, timeout=timeout_seconds) as response:
        content = response.read().decode("utf-8", errors="replace")
        return {
            "status_code": response.status,
            "headers": dict(response.headers.items()),
            "body": content,
        }
