<section id="dead-letters-workspace">
    <?php $role = strtoupper((string)($user['role'] ?? 'VIEWER')); ?>
    <?php $canOperate = in_array($role, ['OPERATOR', 'ADMIN'], true); ?>
    <header class="page-heading">
        <h2>Dead Letters</h2>
        <p>Inspect permanently failed tasks, annotate triage notes, and retry eligible tasks.</p>
    </header>

    <div class="workflow-layout">
        <div class="workflow-list-panel">
            <div class="dead-letter-toolbar">
                <button id="dead-letter-refresh-btn" type="button">Refresh</button>
                <?php if ($canOperate): ?>
                    <button id="dead-letter-bulk-retry-btn" type="button">Retry Selected</button>
                <?php endif; ?>
                <button id="dead-letter-export-csv-btn" type="button">Export CSV</button>
                <span id="dead-letter-poll-indicator" class="poll-indicator">Idle</span>
            </div>
            <div class="table-scroll">
                <table id="dead-letter-table">
                    <thead>
                    <tr>
                        <th><?php if ($canOperate): ?><input type="checkbox" id="dead-letter-select-all" /><?php endif; ?></th>
                        <th>Task ID</th>
                        <th>Execution</th>
                        <th>Workflow</th>
                        <th>Node</th>
                        <th>Status</th>
                        <th>Attempts</th>
                        <th>Error</th>
                        <th>Actions</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($deadLetters as $task): ?>
                        <tr
                            data-task-id="<?= (int)$task['id'] ?>"
                            data-execution-id="<?= (int)$task['execution_id'] ?>"
                            data-error="<?= htmlspecialchars((string)($task['last_error'] ?? '')) ?>"
                        >
                            <td><?php if ($canOperate): ?><input type="checkbox" class="dead-letter-select" /><?php endif; ?></td>
                            <td><?= (int)$task['id'] ?></td>
                            <td><a href="/executions/<?= (int)$task['execution_id'] ?>">#<?= (int)$task['execution_id'] ?></a></td>
                            <td><?= htmlspecialchars((string)$task['workflow_name']) ?> v<?= (int)$task['workflow_version'] ?></td>
                            <td><?= htmlspecialchars((string)$task['node_key']) ?></td>
                            <td><span class="status-pill status-failed-permanently">FAILED_PERMANENTLY</span></td>
                            <td><?= (int)$task['attempts'] ?>/<?= (int)$task['max_attempts'] ?></td>
                            <td><?= htmlspecialchars((string)$task['last_error']) ?></td>
                            <td>
                                <button class="dead-letter-view-btn" type="button" data-task-id="<?= (int)$task['id'] ?>">View</button>
                                <?php if ($canOperate): ?>
                                    <button class="dead-letter-retry-btn" type="button" data-task-id="<?= (int)$task['id'] ?>">Retry</button>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <p id="dead-letter-empty-state" class="empty-state" hidden>No dead-letter tasks currently match this view.</p>
        </div>

        <div class="workflow-detail-panel">
            <h3 id="dead-letter-detail-title">Select a dead-letter task</h3>
            <pre id="dead-letter-detail-viewer">Choose a row to inspect details.</pre>

            <label>
                Triage Note
                <textarea id="dead-letter-note" rows="5" placeholder="Capture investigation notes"></textarea>
            </label>
            <?php if ($canOperate): ?>
                <button id="dead-letter-note-btn" type="button">Save Note</button>
            <?php endif; ?>
        </div>
    </div>
</section>
