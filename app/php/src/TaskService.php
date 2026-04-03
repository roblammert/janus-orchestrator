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
        $result = $this->listTaskLogsPage($taskId);
        return $result['items'];
    }

    public function listTaskLogsPage(
        int $taskId,
        string $level = '',
        int $cursor = 0,
        int $limit = 200
    ): array {
        $limit = min(500, max(1, $limit));
        $sql =
            'SELECT id, level, message, metadata_json, created_at
             FROM task_logs
             WHERE task_id = :task_id AND id > :cursor';
        if ($level !== '') {
            $sql .= ' AND level = :level';
        }
        $sql .= ' ORDER BY id ASC LIMIT :limit';

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':task_id', $taskId, PDO::PARAM_INT);
        $stmt->bindValue(':cursor', max(0, $cursor), PDO::PARAM_INT);
        if ($level !== '') {
            $stmt->bindValue(':level', $level);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll();

        foreach ($rows as &$row) {
            $row['metadata_json'] = json_decode((string)$row['metadata_json'], true);
        }

        $nextCursor = $cursor;
        if ($rows !== []) {
            $last = end($rows);
            $nextCursor = (int)($last['id'] ?? $cursor);
        }

        return [
            'items' => $rows,
            'next_cursor' => $nextCursor,
        ];
    }

    public function listTasksPage(
        int $page = 1,
        int $pageSize = 50,
        string $status = '',
        string $nodeKey = '',
        int $executionId = 0,
        string $sort = 'id_desc'
    ): array {
        $page = max(1, $page);
        $pageSize = min(200, max(1, $pageSize));
        $offset = ($page - 1) * $pageSize;

        $filters = [];
        $params = [];

        if ($status !== '') {
            $filters[] = 't.status = :status';
            $params['status'] = $status;
        }

        if ($nodeKey !== '') {
            $filters[] = 't.node_key LIKE :node_key';
            $params['node_key'] = '%' . $nodeKey . '%';
        }

        if ($executionId > 0) {
            $filters[] = 't.execution_id = :execution_id';
            $params['execution_id'] = $executionId;
        }

        $where = $filters === [] ? '' : ('WHERE ' . implode(' AND ', $filters));
        $orderBy = match ($sort) {
            'id_asc' => 't.id ASC',
            'scheduled_asc' => 't.scheduled_at ASC, t.id ASC',
            'scheduled_desc' => 't.scheduled_at DESC, t.id DESC',
            default => 't.id DESC',
        };

        $countStmt = $this->pdo->prepare('SELECT COUNT(*) FROM tasks t ' . $where);
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        $sql =
            'SELECT t.id, t.execution_id, t.node_key, t.status, t.attempts, t.max_attempts, t.scheduled_at,
                    t.started_at, t.finished_at, t.last_error, t.updated_at
             FROM tasks t ' . $where . '
             ORDER BY ' . $orderBy . '
             LIMIT :limit OFFSET :offset';
        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $paramType = $key === 'execution_id' ? PDO::PARAM_INT : PDO::PARAM_STR;
            $stmt->bindValue(':' . $key, $value, $paramType);
        }
        $stmt->bindValue(':limit', $pageSize, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return [
            'items' => $stmt->fetchAll(),
            'pagination' => [
                'page' => $page,
                'page_size' => $pageSize,
                'total' => $total,
                'has_next' => ($offset + $pageSize) < $total,
            ],
        ];

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
