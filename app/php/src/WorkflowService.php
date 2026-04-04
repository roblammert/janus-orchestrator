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
        $fieldErrors = [];

        if ($name === '') {
            $fieldErrors[] = ['field' => 'name', 'message' => 'name is required'];
        }
        if (!is_array($definition)) {
            $fieldErrors[] = ['field' => 'definition', 'message' => 'definition must be a JSON object'];
        }

        if (is_array($definition)) {
            $fieldErrors = array_merge($fieldErrors, $this->validateDefinition($definition));
        }

        if ($fieldErrors !== []) {
            throw new ValidationException('Invalid workflow payload', $fieldErrors);
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
        $result = $this->listWorkflowsPage();
        return $result['items'];
    }

    public function listWorkflowsPage(
        string $search = '',
        string $sort = 'name_asc',
        int $page = 1,
        int $pageSize = 50
    ): array {
        $page = max(1, $page);
        $pageSize = min(200, max(1, $pageSize));
        $offset = ($page - 1) * $pageSize;

        $where = '';
        $params = [];
        if ($search !== '') {
            $where = 'WHERE name LIKE :search';
            $params['search'] = '%' . $search . '%';
        }

        $orderBy = match ($sort) {
            'name_desc' => 'name DESC',
            'latest_version_desc' => 'latest_version DESC, name ASC',
            'versions_count_desc' => 'versions_count DESC, name ASC',
            default => 'name ASC',
        };

        $countSql = 'SELECT COUNT(*) FROM (SELECT name FROM workflows ' . $where . ' GROUP BY name) x';
        $countStmt = $this->pdo->prepare($countSql);
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        $sql =
            'SELECT name, MAX(version) AS latest_version, COUNT(*) AS versions_count
             FROM workflows ' . $where . '
             GROUP BY name
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

    private function validateDefinition(array $definition): array
    {
        $errors = [];
        $nodes = $definition['nodes'] ?? null;
        if (!is_array($nodes) || $nodes === []) {
            $errors[] = ['field' => 'definition.nodes', 'message' => 'nodes must be a non-empty array'];
            return $errors;
        }

        $keys = [];
        foreach ($nodes as $idx => $node) {
            if (!is_array($node)) {
                $errors[] = ['field' => "definition.nodes[$idx]", 'message' => 'node must be an object'];
                continue;
            }

            $key = trim((string)($node['key'] ?? ''));
            $type = trim((string)($node['type'] ?? ''));
            if ($key === '') {
                $errors[] = ['field' => "definition.nodes[$idx].key", 'message' => 'key is required'];
            } elseif (isset($keys[$key])) {
                $errors[] = ['field' => "definition.nodes[$idx].key", 'message' => 'duplicate key'];
            } else {
                $keys[$key] = true;
            }

            if (!in_array($type, ['HTTP', 'SCRIPT', 'FILE_WRITER'], true)) {
                $errors[] = ['field' => "definition.nodes[$idx].type", 'message' => 'type must be HTTP, SCRIPT, or FILE_WRITER'];
            }
        }

        $edges = $definition['edges'] ?? [];
        if (!is_array($edges)) {
            $errors[] = ['field' => 'definition.edges', 'message' => 'edges must be an array'];
            return $errors;
        }

        foreach ($edges as $idx => $edge) {
            if (!is_array($edge)) {
                $errors[] = ['field' => "definition.edges[$idx]", 'message' => 'edge must be an object'];
                continue;
            }
            $from = (string)($edge['from'] ?? '');
            $to = (string)($edge['to'] ?? '');
            if ($from === '' || $to === '') {
                $errors[] = ['field' => "definition.edges[$idx]", 'message' => 'from and to are required'];
            } elseif (!isset($keys[$from]) || !isset($keys[$to])) {
                $errors[] = ['field' => "definition.edges[$idx]", 'message' => 'edge references unknown node key'];
            }

            if (array_key_exists('condition', $edge)) {
                if (!is_array($edge['condition'])) {
                    $errors[] = ['field' => "definition.edges[$idx].condition", 'message' => 'condition must be an object'];
                } else {
                    $mode = trim((string)($edge['condition']['mode'] ?? ''));
                    if (!in_array($mode, ['if_true', 'if_false'], true)) {
                        $errors[] = ['field' => "definition.edges[$idx].condition.mode", 'message' => 'condition.mode must be if_true or if_false'];
                    }

                    $expression = $edge['condition']['expression'] ?? null;
                    if (is_array($expression)) {
                        $leftPath = trim((string)($expression['left_path'] ?? ''));
                        $operator = trim((string)($expression['operator'] ?? ''));
                        $validOperators = ['truthy', 'equals', 'not_equals', 'contains', 'gt', 'gte', 'lt', 'lte', 'exists', 'empty'];
                        if ($leftPath === '') {
                            $errors[] = ['field' => "definition.edges[$idx].condition.expression.left_path", 'message' => 'left_path is required'];
                        }
                        if (!in_array($operator, $validOperators, true)) {
                            $errors[] = ['field' => "definition.edges[$idx].condition.expression.operator", 'message' => 'invalid operator'];
                        }
                        $operatorsRequiringValue = ['equals', 'not_equals', 'contains', 'gt', 'gte', 'lt', 'lte'];
                        if (in_array($operator, $operatorsRequiringValue, true) && !array_key_exists('right_value', $expression)) {
                            $errors[] = ['field' => "definition.edges[$idx].condition.expression.right_value", 'message' => 'right_value is required for selected operator'];
                        }
                    } else {
                        $path = trim((string)($edge['condition']['path'] ?? ''));
                        if ($path === '') {
                            $errors[] = ['field' => "definition.edges[$idx].condition.expression.left_path", 'message' => 'condition expression is required'];
                        }
                    }
                }
            }
        }

        return $errors;
    }
}
