from __future__ import annotations

from fastapi import FastAPI

from .db import Db

app = FastAPI(title="Janus Worker Service")


@app.get("/health")
def health() -> dict:
    db = Db()
    conn = db.connect()
    cur = conn.cursor()
    try:
        cur.execute("SELECT 1")
        cur.fetchone()
    finally:
        cur.close()
        conn.close()

    return {
        "status": "ok",
        "service": "janus-worker",
    }


@app.get("/metrics/overview")
def metrics_overview() -> dict:
    db = Db()
    conn = db.connect()
    cur = conn.cursor(dictionary=True)
    try:
        cur.execute("SELECT * FROM v_execution_counts_by_status")
        execution_counts = cur.fetchall()

        cur.execute("SELECT * FROM v_task_counts_by_status")
        task_counts = cur.fetchall()

        cur.execute("SELECT * FROM v_avg_task_duration_seconds")
        avg = cur.fetchone() or {"avg_duration_seconds": 0}
    finally:
        cur.close()
        conn.close()

    return {
        "execution_counts": execution_counts,
        "task_counts": task_counts,
        "avg_task_duration_seconds": float(avg["avg_duration_seconds"]),
    }
