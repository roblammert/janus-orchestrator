from __future__ import annotations

from pathlib import Path


def run(config: dict, _: str, timeout_seconds: int) -> dict:
    del timeout_seconds

    path = Path(str(config["path"]))
    mode = str(config.get("mode", "w"))
    content = str(config.get("content", ""))

    path.parent.mkdir(parents=True, exist_ok=True)

    if path.exists() and "w" in mode:
        current = path.read_text(encoding="utf-8")
        if current == content:
            return {
                "path": str(path),
                "bytes_written": 0,
                "idempotent_noop": True,
            }

    with path.open(mode, encoding="utf-8") as f:
        written = f.write(content)

    return {
        "path": str(path),
        "bytes_written": written,
        "idempotent_noop": False,
    }
