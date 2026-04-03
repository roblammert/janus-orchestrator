from __future__ import annotations

import os
from dataclasses import dataclass


@dataclass(frozen=True)
class Settings:
    db_host: str = os.getenv("DB_HOST", "127.0.0.1")
    db_port: int = int(os.getenv("DB_PORT", "3306"))
    db_name: str = os.getenv("DB_NAME", "janus_orchestrator")
    db_user: str = os.getenv("DB_USER", "janus")
    db_password: str = os.getenv("DB_PASSWORD", "janus")

    worker_id: str = os.getenv("WORKER_ID", "worker-1")
    worker_poll_seconds: float = float(os.getenv("WORKER_POLL_SECONDS", "1.0"))
    scheduler_poll_seconds: float = float(os.getenv("SCHEDULER_POLL_SECONDS", "2.0"))

    heartbeat_interval_seconds: int = int(os.getenv("TASK_HEARTBEAT_INTERVAL_SECONDS", "5"))
    stale_task_seconds: int = int(os.getenv("TASK_STALE_SECONDS", "30"))
    retry_backoff_seconds: int = int(os.getenv("TASK_RETRY_BACKOFF_SECONDS", "10"))


settings = Settings()
