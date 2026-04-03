<section>
    <h2>Executions</h2>
    <table>
        <thead>
        <tr>
            <th>ID</th>
            <th>Workflow</th>
            <th>Version</th>
            <th>Status</th>
            <th>Started</th>
            <th>Finished</th>
            <th>Actions</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($executions as $execution): ?>
            <tr>
                <td><?= (int)$execution['id'] ?></td>
                <td><?= htmlspecialchars($execution['workflow_name']) ?></td>
                <td><?= (int)$execution['workflow_version'] ?></td>
                <td><?= htmlspecialchars($execution['status']) ?></td>
                <td><?= htmlspecialchars((string)$execution['started_at']) ?></td>
                <td><?= htmlspecialchars((string)$execution['finished_at']) ?></td>
                <td>
                    <a href="/executions/<?= (int)$execution['id'] ?>">View</a>
                    <?php if (in_array($execution['status'], ['PENDING', 'RUNNING'], true)): ?>
                        <button class="cancel-execution-btn" data-execution-id="<?= (int)$execution['id'] ?>">Cancel</button>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</section>
