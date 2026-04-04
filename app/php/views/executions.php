<section id="executions-workspace">
    <?php $role = strtoupper((string)($user['role'] ?? 'VIEWER')); ?>
    <?php $canOperate = in_array($role, ['OPERATOR', 'ADMIN'], true); ?>
    <header class="page-heading">
        <h2>Executions</h2>
        <p>Review runtime activity, apply quick filters, and jump into live execution details.</p>
    </header>
    <div class="workflow-toolbar">
        <label>
            Status
            <select id="executions-status-filter">
                <option value="ALL">All</option>
                <option value="PENDING">Pending</option>
                <option value="RUNNING">Running</option>
                <option value="COMPLETED">Completed</option>
                <option value="FAILED">Failed</option>
                <option value="CANCELLED">Cancelled</option>
                <option value="TIMED_OUT">Timed Out</option>
            </select>
        </label>
        <label>
            Time Range
            <select id="executions-time-filter">
                <option value="all">All time</option>
                <option value="24h">Last 24h</option>
                <option value="7d">Last 7 days</option>
                <option value="30d">Last 30 days</option>
            </select>
        </label>
        <label>
            Sort
            <select id="executions-sort">
                <option value="newest">Newest first</option>
                <option value="oldest">Oldest first</option>
                <option value="running-first">Running first</option>
                <option value="error-first">Failed/Timed Out first</option>
            </select>
        </label>
        <div class="toolbar-actions">
            <button id="executions-refresh-btn" type="button">Refresh</button>
            <button id="executions-export-csv-btn" type="button">Export CSV</button>
            <span id="executions-poll-indicator" class="poll-indicator">Idle</span>
        </div>
    </div>

    <div class="table-scroll">
        <table id="executions-list-table">
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
                <tr
                    data-execution-id="<?= (int)$execution['id'] ?>"
                    data-status="<?= htmlspecialchars((string)$execution['status']) ?>"
                    data-started-at="<?= htmlspecialchars((string)($execution['started_at'] ?? '')) ?>"
                >
                    <td><?= (int)$execution['id'] ?></td>
                    <td><?= htmlspecialchars((string)$execution['workflow_name']) ?></td>
                    <td><?= (int)$execution['workflow_version'] ?></td>
                    <td>
                        <?php $statusClass = strtolower(str_replace('_', '-', (string)$execution['status'])); ?>
                        <span class="status-pill status-<?= htmlspecialchars($statusClass) ?>"><?= htmlspecialchars((string)$execution['status']) ?></span>
                    </td>
                    <td><?= htmlspecialchars((string)$execution['started_at']) ?></td>
                    <td><?= htmlspecialchars((string)$execution['finished_at']) ?></td>
                    <td>
                        <a href="/executions/<?= (int)$execution['id'] ?>">View</a>
                        <?php if ($canOperate && in_array($execution['status'], ['PENDING', 'RUNNING'], true)): ?>
                            <button class="cancel-execution-btn" data-execution-id="<?= (int)$execution['id'] ?>">Cancel</button>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <p id="executions-empty-state" class="empty-state" hidden>No executions match the current filters.</p>
</section>
