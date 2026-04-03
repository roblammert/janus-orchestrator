from __future__ import annotations

import shlex
import subprocess
import time
from typing import Callable


def run(
    config: dict,
    _: str,
    timeout_seconds: int,
    heartbeat_callback: Callable[[], None] | None = None,
    cancellation_check: Callable[[], bool] | None = None,
) -> dict:
    command = str(config["command"])
    shell = bool(config.get("shell", False))

    args = command if shell else shlex.split(command)

    proc = subprocess.Popen(
        args,
        shell=shell,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        text=True,
    )

    start = time.monotonic()
    while proc.poll() is None:
        elapsed = time.monotonic() - start
        if elapsed > timeout_seconds:
            proc.kill()
            raise TimeoutError(f"Script timeout after {timeout_seconds}s")

        if cancellation_check and cancellation_check():
            proc.kill()
            raise RuntimeError("Task cancelled during script execution")

        if heartbeat_callback:
            heartbeat_callback()

        time.sleep(1)

    stdout, stderr = proc.communicate()
    if proc.returncode != 0:
        raise RuntimeError(f"Script failed (code={proc.returncode}): {stderr.strip()}")

    return {
        "return_code": proc.returncode,
        "stdout": stdout,
        "stderr": stderr,
    }
