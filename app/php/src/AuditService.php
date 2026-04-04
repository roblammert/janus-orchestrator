<?php

declare(strict_types=1);

namespace Janus;

use PDO;

final class AuditService
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function listEventsPage(
        int $page = 1,
        int $pageSize = 50,
        string $eventType = '',
        int $actorUserId = 0
    ): array {
        $page = max(1, $page);
        $pageSize = min(200, max(1, $pageSize));
        $offset = ($page - 1) * $pageSize;

        $filters = [];
        $params = [];

        if ($eventType !== '') {
            $filters[] = 'a.event_type = :event_type';
            $params['event_type'] = $eventType;
        }

        if ($actorUserId > 0) {
            $filters[] = 'a.actor_user_id = :actor_user_id';
            $params['actor_user_id'] = $actorUserId;
        }

        $where = $filters === [] ? '' : ('WHERE ' . implode(' AND ', $filters));

        $countStmt = $this->pdo->prepare('SELECT COUNT(*) FROM audit_events a ' . $where);
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        $sql =
            'SELECT a.id, a.actor_user_id, u.username AS actor_username, a.event_type, a.entity_type,
                    a.entity_id, a.details_json, a.created_at
             FROM audit_events a
             LEFT JOIN users u ON u.id = a.actor_user_id
             ' . $where . '
             ORDER BY a.id DESC
             LIMIT :limit OFFSET :offset';

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue(':' . $key, $value, $key === 'actor_user_id' ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->bindValue(':limit', $pageSize, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $items = $stmt->fetchAll();
        foreach ($items as &$item) {
            $details = json_decode((string)($item['details_json'] ?? 'null'), true);
            $item['details_json'] = Redactor::redact(is_array($details) ? $details : []);
        }

        return [
            'items' => $items,
            'pagination' => [
                'page' => $page,
                'page_size' => $pageSize,
                'total' => $total,
                'has_next' => ($offset + $pageSize) < $total,
            ],
        ];
    }
}
