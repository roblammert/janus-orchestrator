<?php

declare(strict_types=1);

namespace Janus;

use PDO;

final class TaskService
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function retryTask(int $taskId): void
    {
        $this->pdo->beginTransaction();
        try {
            $task = $this->pdo->prepare('SELECT * FROM tasks WHERE id = :id FOR UPDATE');
            $task->execute(['id' => $taskId]);
            $row = $task->fetch();
            if (!$row) {
                throw new \InvalidArgumentException('Task not found');
            }

            if ((int)$row['attempts'] >= (int)$row['max_attempts']) {
                throw new \RuntimeException('Task exceeded max_attempts; manual retry not allowed without reset');
            }

            $update = $this->pdo->prepare(
                'UPDATE tasks
                 SET status = \"READY\", scheduled_at = NOW(), next_attempt_at = NULL, last_error = NULL, finished_at = NULL
                 WHERE id = :id'
            );
            $update->execute(['id' => $taskId]);

            $enqueue = $this->pdo->prepare(
                'INSERT INTO task_queue (task_id, priority, scheduled_at, available_at)
                 VALUES (:task_id, :priority, NOW(), NOW())
                 ON DUPLICATE KEY UPDATE
                    priority = VALUES(priority),
                    scheduled_at = VALUES(scheduled_at),
                    available_at = VALUES(available_at),
                    claimed_by_worker_id = NULL,
                    claimed_at = NULL'
            );
            $enqueue->execute([
                'task_id' => $taskId,
                'priority' => (int)$row['priority'],
            ]);

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function skipTask(int $taskId, string $reason = 'Skipped manually'): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE tasks
             SET status = \"SKIPPED\", finished_at = NOW(), last_error = :reason
             WHERE id = :id AND status IN (\"PENDING\", \"READY\", \"FAILED\")'
        );
        $stmt->execute([
            'id' => $taskId,
            'reason' => $reason,
        ]);

        $cleanup = $this->pdo->prepare('DELETE FROM task_queue WHERE task_id = :task_id');
        $cleanup->execute(['task_id' => $taskId]);
    }

    public function completeTaskManually(int $taskId, array $output): void
    {
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare(
                'UPDATE tasks
                 SET status = \"COMPLETED\", finished_at = NOW(), output_json = :output_json, last_error = NULL
                 WHERE id = :id AND status IN (\"PENDING\", \"READY\", \"FAILED\")'
            );
            $stmt->execute([
                'id' => $taskId,
                'output_json' => json_encode($output, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);

            $cleanup = $this->pdo->prepare('DELETE FROM task_queue WHERE task_id = :task_id');
            $cleanup->execute(['task_id' => $taskId]);

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function listTaskLogs(int $taskId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, level, message, metadata_json, created_at
             FROM task_logs
             WHERE task_id = :task_id
             ORDER BY id ASC'
        );
        $stmt->execute(['task_id' => $taskId]);
        $rows = $stmt->fetchAll();

        foreach ($rows as &$row) {
            $row['metadata_json'] = json_decode((string)$row['metadata_json'], true);
        }

        return $rows;
    }

    public function listDeadLetters(): array
    {
        $stmt = $this->pdo->query(
            'SELECT t.id, t.execution_id, e.workflow_name, e.workflow_version, t.node_key, t.attempts, t.max_attempts,
                    t.last_error, t.finished_at, t.updated_at
             FROM tasks t
             INNER JOIN executions e ON e.id = t.execution_id
             WHERE t.status = "FAILED_PERMANENTLY"
             ORDER BY t.updated_at DESC, t.id DESC'
        );

        return $stmt->fetchAll();
    }

    public function annotateTask(int $taskId, string $note, ?int $actorUserId = null): void
    {
        if (trim($note) === '') {
            throw new \InvalidArgumentException('Note is required');
        }

        $exists = $this->pdo->prepare('SELECT id FROM tasks WHERE id = :id LIMIT 1');
        $exists->execute(['id' => $taskId]);
        if (!$exists->fetch()) {
            throw new \InvalidArgumentException('Task not found');
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO audit_events (actor_user_id, event_type, entity_type, entity_id, details_json)
             VALUES (:actor_user_id, :event_type, :entity_type, :entity_id, :details_json)'
        );
        $stmt->execute([
            'actor_user_id' => $actorUserId,
            'event_type' => 'dead_letter_note',
            'entity_type' => 'task',
            'entity_id' => $taskId,
            'details_json' => json_encode(['note' => $note], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    }
}
