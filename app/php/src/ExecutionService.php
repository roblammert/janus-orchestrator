<?php

declare(strict_types=1);

namespace Janus;

use PDO;

final class ExecutionService
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function startExecution(int $workflowId, array $input): array
    {
        $workflowStmt = $this->pdo->prepare('SELECT * FROM workflows WHERE id = :id');
        $workflowStmt->execute(['id' => $workflowId]);
        $workflow = $workflowStmt->fetch();
        if (!$workflow) {
            throw new \InvalidArgumentException('Workflow not found');
        }

        $definition = json_decode((string)$workflow['definition_json'], true);
        $timeoutSeconds = (int)($definition['timeout_seconds'] ?? Config::workflowDefaultTimeoutSeconds());

        $this->pdo->beginTransaction();
        try {
            $executionStmt = $this->pdo->prepare(
                'INSERT INTO executions (workflow_id, workflow_name, workflow_version, status, input_json, started_at, timeout_at)
                 VALUES (:workflow_id, :workflow_name, :workflow_version, :status, :input_json, NOW(), DATE_ADD(NOW(), INTERVAL :timeout SECOND))'
            );
            $executionStmt->bindValue(':workflow_id', $workflowId, PDO::PARAM_INT);
            $executionStmt->bindValue(':workflow_name', $workflow['name']);
            $executionStmt->bindValue(':workflow_version', (int)$workflow['version'], PDO::PARAM_INT);
            $executionStmt->bindValue(':status', 'RUNNING');
            $executionStmt->bindValue(':input_json', json_encode($input, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            $executionStmt->bindValue(':timeout', $timeoutSeconds, PDO::PARAM_INT);
            $executionStmt->execute();

            $executionId = (int)$this->pdo->lastInsertId();

            $nodeStmt = $this->pdo->prepare('SELECT * FROM workflow_nodes WHERE workflow_id = :workflow_id');
            $nodeStmt->execute(['workflow_id' => $workflowId]);
            $nodes = $nodeStmt->fetchAll();

            $taskStmt = $this->pdo->prepare(
                'INSERT INTO tasks (
                    execution_id, workflow_id, workflow_node_id, node_key, status, attempts, max_attempts,
                    priority, scheduled_at, idempotency_key
                 ) VALUES (
                    :execution_id, :workflow_id, :workflow_node_id, :node_key, :status, 0, :max_attempts,
                    :priority, NOW(), :idempotency_key
                 )'
            );

            foreach ($nodes as $node) {
                $taskStmt->execute([
                    'execution_id' => $executionId,
                    'workflow_id' => $workflowId,
                    'workflow_node_id' => (int)$node['id'],
                    'node_key' => $node['node_key'],
                    'status' => 'PENDING',
                    'max_attempts' => (int)$node['max_attempts'],
                    'priority' => (int)$node['priority'],
                    'idempotency_key' => hash('sha256', $executionId . ':' . $node['node_key']),
                ]);
            }

            $transitionStmt = $this->pdo->prepare(
                'INSERT INTO state_transitions (entity_type, entity_id, from_state, to_state, metadata_json)
                 VALUES (\'execution\', :entity_id, NULL, :to_state, :metadata_json)'
            );
            $transitionStmt->execute([
                'entity_id' => $executionId,
                'to_state' => 'RUNNING',
                'metadata_json' => json_encode(['reason' => 'execution_started']),
            ]);

            $this->pdo->commit();

            return ['execution_id' => $executionId, 'status' => 'RUNNING'];
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function listExecutions(): array
    {
        $result = $this->listExecutionsPage();
        return $result['items'];
    }

    public function listExecutionsPage(
        int $page = 1,
        int $pageSize = 50,
        string $status = '',
        string $workflow = '',
        string $startedAfter = '',
        string $startedBefore = '',
        string $sort = 'id_desc'
    ): array {
        $page = max(1, $page);
        $pageSize = min(200, max(1, $pageSize));
        $offset = ($page - 1) * $pageSize;

        $filters = [];
        $params = [];

        if ($status !== '') {
            $filters[] = 'status = :status';
            $params['status'] = $status;
        }

        if ($workflow !== '') {
            $filters[] = 'workflow_name LIKE :workflow';
            $params['workflow'] = '%' . $workflow . '%';
        }

        if ($startedAfter !== '') {
            $filters[] = 'started_at >= :started_after';
            $params['started_after'] = $startedAfter;
        }

        if ($startedBefore !== '') {
            $filters[] = 'started_at <= :started_before';
            $params['started_before'] = $startedBefore;
        }

        $where = $filters === [] ? '' : ('WHERE ' . implode(' AND ', $filters));
        $orderBy = match ($sort) {
            'id_asc' => 'id ASC',
            'started_asc' => 'started_at ASC, id ASC',
            'started_desc' => 'started_at DESC, id DESC',
            default => 'id DESC',
        };

        $countStmt = $this->pdo->prepare('SELECT COUNT(*) FROM executions ' . $where);
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        $sql =
            'SELECT id, workflow_name, workflow_version, status, created_at, started_at, finished_at
             FROM executions ' . $where . '
             ORDER BY ' . $orderBy . '
             LIMIT :limit OFFSET :offset';
        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue(':' . $key, $value);
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
    }

    public function getExecutionDetails(int $executionId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM executions WHERE id = :id');
        $stmt->execute(['id' => $executionId]);
        $execution = $stmt->fetch();

        if (!$execution) {
            return null;
        }

        $execution['input_json'] = json_decode((string)$execution['input_json'], true);
        $execution['output_json'] = json_decode((string)$execution['output_json'], true);

        $tasksStmt = $this->pdo->prepare(
            'SELECT t.id, t.node_key, wn.name AS node_name, wn.type AS node_type, t.status, t.attempts, t.max_attempts,
                    t.scheduled_at, t.started_at, t.finished_at, t.last_error, t.output_json
             FROM tasks t
             INNER JOIN workflow_nodes wn ON wn.id = t.workflow_node_id
             WHERE t.execution_id = :execution_id
             ORDER BY t.id ASC'
        );
        $tasksStmt->execute(['execution_id' => $executionId]);
        $tasks = $tasksStmt->fetchAll();

        foreach ($tasks as &$task) {
            $task['output_json'] = json_decode((string)$task['output_json'], true);
        }

        $statusByNodeKey = [];
        foreach ($tasks as $task) {
            $statusByNodeKey[(string)$task['node_key']] = (string)$task['status'];
        }

        $nodesStmt = $this->pdo->prepare(
            'SELECT node_key, name, type
             FROM workflow_nodes
             WHERE workflow_id = :workflow_id
             ORDER BY id ASC'
        );
        $nodesStmt->execute(['workflow_id' => (int)$execution['workflow_id']]);
        $dagNodes = $nodesStmt->fetchAll();

        foreach ($dagNodes as &$node) {
            $node['status'] = $statusByNodeKey[(string)$node['node_key']] ?? 'PENDING';
        }

        $edgesStmt = $this->pdo->prepare(
            'SELECT f.node_key AS from_node_key, t.node_key AS to_node_key
             FROM workflow_edges e
             INNER JOIN workflow_nodes f ON f.id = e.from_node_id
             INNER JOIN workflow_nodes t ON t.id = e.to_node_id
             WHERE e.workflow_id = :workflow_id
             ORDER BY e.id ASC'
        );
        $edgesStmt->execute(['workflow_id' => (int)$execution['workflow_id']]);
        $dagEdges = $edgesStmt->fetchAll();

        $execution['tasks'] = $tasks;
        $execution['dag'] = [
            'nodes' => $dagNodes,
            'edges' => $dagEdges,
        ];
        return $execution;
    }

    public function executionDagSummary(int $executionId): ?array
    {
        $execution = $this->getExecutionDetails($executionId);
        if ($execution === null) {
            return null;
        }

        return [
            'execution_id' => (int)$execution['id'],
            'workflow_name' => (string)$execution['workflow_name'],
            'workflow_version' => (int)$execution['workflow_version'],
            'nodes' => $execution['dag']['nodes'] ?? [],
            'edges' => $execution['dag']['edges'] ?? [],
        ];
    }

    public function executionEventsDelta(int $executionId, int $sinceId = 0, int $limit = 200): array
    {
        $limit = min(500, max(1, $limit));

        $stmt = $this->pdo->prepare(
            'SELECT id, entity_type, entity_id, from_state, to_state, metadata_json, created_at
             FROM state_transitions
             WHERE id > :since_id
               AND (
                                        (entity_type = "execution" AND entity_id = :execution_id_1)
                    OR (
                        entity_type = "task"
                                                AND entity_id IN (SELECT id FROM tasks WHERE execution_id = :execution_id_2)
                    )
               )
             ORDER BY id ASC
             LIMIT :limit'
        );
        $stmt->bindValue(':since_id', max(0, $sinceId), PDO::PARAM_INT);
                $stmt->bindValue(':execution_id_1', $executionId, PDO::PARAM_INT);
                $stmt->bindValue(':execution_id_2', $executionId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        $items = $stmt->fetchAll();
        foreach ($items as &$item) {
            $item['metadata_json'] = json_decode((string)$item['metadata_json'], true);
        }

        $nextSinceId = $sinceId;
        if ($items !== []) {
            $last = end($items);
            $nextSinceId = (int)($last['id'] ?? $sinceId);
        }

        return [
            'items' => $items,
            'next_since_id' => $nextSinceId,
        ];
    }

    public function cancelExecution(int $executionId): void
    {
        $this->pdo->beginTransaction();
        try {
            $updateExecution = $this->pdo->prepare(
                'UPDATE executions
                 SET status = \"CANCELLED\", cancelled_at = NOW(), finished_at = NOW()
                 WHERE id = :id AND status IN (\"PENDING\", \"RUNNING\")'
            );
            $updateExecution->execute(['id' => $executionId]);

            $updateTasks = $this->pdo->prepare(
                'UPDATE tasks
                 SET status = \"SKIPPED\", finished_at = NOW(), last_error = \"Execution cancelled manually\"
                 WHERE execution_id = :execution_id AND status IN (\"PENDING\", \"READY\", \"RUNNING\", \"FAILED\")'
            );
            $updateTasks->execute(['execution_id' => $executionId]);

            $cleanupQueue = $this->pdo->prepare(
                'DELETE q FROM task_queue q
                 INNER JOIN tasks t ON t.id = q.task_id
                 WHERE t.execution_id = :execution_id'
            );
            $cleanupQueue->execute(['execution_id' => $executionId]);

            $transitionStmt = $this->pdo->prepare(
                'INSERT INTO state_transitions (entity_type, entity_id, from_state, to_state, metadata_json)
                 VALUES (\'execution\', :entity_id, NULL, :to_state, :metadata_json)'
            );
            $transitionStmt->execute([
                'entity_id' => $executionId,
                'to_state' => 'CANCELLED',
                'metadata_json' => json_encode(['reason' => 'manual_cancel']),
            ]);

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function metricsOverview(): array
    {
        $executions = $this->pdo->query('SELECT * FROM v_execution_counts_by_status')->fetchAll();
        $tasks = $this->pdo->query('SELECT * FROM v_task_counts_by_status')->fetchAll();
        $avgRow = $this->pdo->query('SELECT * FROM v_avg_task_duration_seconds')->fetch();

        return [
            'execution_counts' => $executions,
            'task_counts' => $tasks,
            'avg_task_duration_seconds' => (float)$avgRow['avg_duration_seconds'],
        ];
    }
}
