<section>
    <h2>Execution #<?= (int)$execution['id'] ?></h2>
    <p>Workflow: <?= htmlspecialchars($execution['workflow_name']) ?> v<?= (int)$execution['workflow_version'] ?></p>
    <p>Status: <strong id="execution-status"><?= htmlspecialchars($execution['status']) ?></strong></p>
    <p>Started: <?= htmlspecialchars((string)$execution['started_at']) ?> | Finished: <?= htmlspecialchars((string)$execution['finished_at']) ?></p>
    <button class="cancel-execution-btn" data-execution-id="<?= (int)$execution['id'] ?>">Cancel Execution</button>

    <h3>Tasks</h3>
    <table id="execution-tasks-table" data-execution-id="<?= (int)$execution['id'] ?>">
        <thead>
        <tr>
            <th>ID</th>
            <th>Node</th>
            <th>Type</th>
            <th>Status</th>
            <th>Attempts</th>
            <th>Error</th>
            <th>Actions</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($execution['tasks'] as $task): ?>
            <tr data-task-id="<?= (int)$task['id'] ?>">
                <td><?= (int)$task['id'] ?></td>
                <td><?= htmlspecialchars($task['node_key']) ?></td>
                <td><?= htmlspecialchars($task['node_type']) ?></td>
                <td class="task-status"><?= htmlspecialchars($task['status']) ?></td>
                <td><?= (int)$task['attempts'] ?>/<?= (int)$task['max_attempts'] ?></td>
                <td class="task-error"><?= htmlspecialchars((string)$task['last_error']) ?></td>
                <td>
                    <button class="task-retry-btn" data-task-id="<?= (int)$task['id'] ?>">Retry</button>
                    <button class="task-skip-btn" data-task-id="<?= (int)$task['id'] ?>">Skip</button>
                    <button class="task-complete-btn" data-task-id="<?= (int)$task['id'] ?>">Complete</button>
                    <button class="task-logs-btn" data-task-id="<?= (int)$task['id'] ?>">Logs</button>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <h3>Task Logs</h3>
    <pre id="task-log-viewer">Select a task and click Logs.</pre>
</section>
