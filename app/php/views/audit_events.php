<section id="audit-events-workspace">
    <header class="page-heading">
        <h2>Audit Events</h2>
        <p>Track operator and admin interventions across executions and tasks.</p>
    </header>

    <div class="table-scroll">
        <table id="audit-events-table">
            <thead>
            <tr>
                <th>ID</th>
                <th>When</th>
                <th>Actor</th>
                <th>Event</th>
                <th>Entity</th>
                <th>Details</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($events as $event): ?>
                <tr>
                    <td><?= (int)($event['id'] ?? 0) ?></td>
                    <td><?= htmlspecialchars((string)($event['created_at'] ?? '')) ?></td>
                    <td>
                        <?php if (!empty($event['actor_username'])): ?>
                            <?= htmlspecialchars((string)$event['actor_username']) ?> (#<?= (int)($event['actor_user_id'] ?? 0) ?>)
                        <?php else: ?>
                            system
                        <?php endif; ?>
                    </td>
                    <td><span class="status-pill status-info"><?= htmlspecialchars((string)($event['event_type'] ?? '')) ?></span></td>
                    <td><?= htmlspecialchars((string)($event['entity_type'] ?? '')) ?> #<?= (int)($event['entity_id'] ?? 0) ?></td>
                    <td><pre><?= htmlspecialchars(json_encode($event['details_json'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) ?></pre></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
