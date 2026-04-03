<?php

declare(strict_types=1);

namespace Janus;

use PDO;

final class WorkflowService
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function createWorkflowVersion(array $payload): array
    {
        $name = (string)($payload['name'] ?? '');
        $definition = $payload['definition'] ?? null;

        if ($name === '' || !is_array($definition)) {
            throw new \InvalidArgumentException('name and definition are required');
        }

        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare('SELECT COALESCE(MAX(version), 0) AS max_version FROM workflows WHERE name = :name');
            $stmt->execute(['name' => $name]);
            $row = $stmt->fetch();
            $nextVersion = ((int)$row['max_version']) + 1;

            $description = (string)($payload['description'] ?? '');
            $insertWorkflow = $this->pdo->prepare(
                'INSERT INTO workflows (name, version, description, definition_json, is_active) VALUES (:name, :version, :description, :definition_json, 1)'
            );
            $insertWorkflow->execute([
                'name' => $name,
                'version' => $nextVersion,
                'description' => $description === '' ? null : $description,
                'definition_json' => json_encode($definition, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
            $workflowId = (int)$this->pdo->lastInsertId();

            $nodeIdByKey = [];
            foreach (($definition['nodes'] ?? []) as $node) {
                $insertNode = $this->pdo->prepare(
                    'INSERT INTO workflow_nodes (workflow_id, node_key, name, type, config_json, timeout_seconds, max_attempts, priority)
                     VALUES (:workflow_id, :node_key, :name, :type, :config_json, :timeout_seconds, :max_attempts, :priority)'
                );
                $insertNode->execute([
                    'workflow_id' => $workflowId,
                    'node_key' => (string)$node['key'],
                    'name' => (string)($node['name'] ?? $node['key']),
                    'type' => (string)$node['type'],
                    'config_json' => json_encode(($node['config'] ?? []), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'timeout_seconds' => (int)($node['timeout_seconds'] ?? 60),
                    'max_attempts' => (int)($node['max_attempts'] ?? 3),
                    'priority' => (int)($node['priority'] ?? 100),
                ]);
                $nodeIdByKey[(string)$node['key']] = (int)$this->pdo->lastInsertId();
            }

            foreach (($definition['edges'] ?? []) as $edge) {
                $fromKey = (string)$edge['from'];
                $toKey = (string)$edge['to'];
                $insertEdge = $this->pdo->prepare(
                    'INSERT INTO workflow_edges (workflow_id, from_node_id, to_node_id, condition_json)
                     VALUES (:workflow_id, :from_node_id, :to_node_id, :condition_json)'
                );
                $insertEdge->execute([
                    'workflow_id' => $workflowId,
                    'from_node_id' => $nodeIdByKey[$fromKey],
                    'to_node_id' => $nodeIdByKey[$toKey],
                    'condition_json' => isset($edge['condition'])
                        ? json_encode($edge['condition'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                        : null,
                ]);
            }

            $this->pdo->commit();

            return [
                'id' => $workflowId,
                'name' => $name,
                'version' => $nextVersion,
            ];
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function listWorkflows(): array
    {
        $stmt = $this->pdo->query(
            'SELECT name, MAX(version) AS latest_version, COUNT(*) AS versions_count
             FROM workflows
             GROUP BY name
             ORDER BY name ASC'
        );

        return $stmt->fetchAll();
    }

    public function listWorkflowVersions(string $name): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, name, version, description, definition_json, created_at
             FROM workflows
             WHERE name = :name
             ORDER BY version DESC'
        );
        $stmt->execute(['name' => $name]);
        $rows = $stmt->fetchAll();

        foreach ($rows as &$row) {
            $row['definition_json'] = json_decode((string)$row['definition_json'], true);
        }

        return $rows;
    }

    public function getWorkflowById(int $workflowId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, name, version, description, definition_json, created_at
             FROM workflows
             WHERE id = :id'
        );
        $stmt->execute(['id' => $workflowId]);
        $row = $stmt->fetch();

        if (!$row) {
            return null;
        }

        $row['definition_json'] = json_decode((string)$row['definition_json'], true);
        return $row;
    }
}
