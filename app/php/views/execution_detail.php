<section id="execution-workspace" data-execution-id="<?= (int)$execution['id'] ?>">
    <?php $role = strtoupper((string)($user['role'] ?? 'VIEWER')); ?>
    <?php $canOperate = in_array($role, ['OPERATOR', 'ADMIN'], true); ?>
    <?php $canForceComplete = $role === 'ADMIN'; ?>
    <header class="page-heading execution-heading">
        <h2>Execution #<?= (int)$execution['id'] ?></h2>
        <p>Workflow: <?= htmlspecialchars((string)$execution['workflow_name']) ?> v<?= (int)$execution['workflow_version'] ?></p>
    </header>
    <?php $executionStatusClass = strtolower(str_replace('_', '-', (string)$execution['status'])); ?>
    <div class="execution-meta-grid">
        <article class="execution-meta-card">
            <h3>Status</h3>
            <p><strong id="execution-status" class="status-pill status-<?= htmlspecialchars($executionStatusClass) ?>"><?= htmlspecialchars((string)$execution['status']) ?></strong></p>
        </article>
        <article class="execution-meta-card">
            <h3>Started</h3>
            <p><?= htmlspecialchars((string)$execution['started_at']) ?></p>
        </article>
        <article class="execution-meta-card">
            <h3>Finished</h3>
            <p><?= htmlspecialchars((string)$execution['finished_at']) ?></p>
        </article>
    </div>
    <?php if ($canOperate): ?>
        <div class="execution-actions">
            <button class="cancel-execution-btn" data-execution-id="<?= (int)$execution['id'] ?>">Cancel Execution</button>
        </div>
    <?php endif; ?>

    <div class="workflow-layout execution-layout">
        <div class="workflow-list-panel">
            <h3>Task Graph Summary</h3>
            <div id="execution-dag-panel" class="dag-panel">
                <?php foreach (($execution['dag']['nodes'] ?? []) as $node): ?>
                    <button
                        type="button"
                        class="dag-node"
                        data-node-key="<?= htmlspecialchars((string)$node['node_key']) ?>"
                        data-node-status="<?= htmlspecialchars((string)$node['status']) ?>"
                    >
                        <span><?= htmlspecialchars((string)$node['name']) ?></span>
                        <small><?= htmlspecialchars((string)$node['node_key']) ?> (<?= htmlspecialchars((string)$node['status']) ?>)</small>
                    </button>
                <?php endforeach; ?>
            </div>
            <div class="dag-edges" id="execution-dag-edges">
                <h4>Edges</h4>
                <ul>
                    <?php foreach (($execution['dag']['edges'] ?? []) as $edge): ?>
                        <li>
                            <?= htmlspecialchars((string)$edge['from_node_key']) ?> -> <?= htmlspecialchars((string)$edge['to_node_key']) ?>
                        </li>
                    <?php endforeach; ?>
                    <?php if (empty($execution['dag']['edges'])): ?>
                        <li>No dependency edges.</li>
                    <?php endif; ?>
                </ul>
            </div>

            <h3>Task Timeline</h3>
            <ul id="execution-timeline" class="timeline-list"></ul>
        </div>

        <div class="workflow-detail-panel">
            <h3>Tasks</h3>
            <div class="table-scroll">
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
                        <tr
                            data-task-id="<?= (int)$task['id'] ?>"
                            data-node-key="<?= htmlspecialchars((string)$task['node_key']) ?>"
                            data-started-at="<?= htmlspecialchars((string)($task['started_at'] ?? '')) ?>"
                            data-finished-at="<?= htmlspecialchars((string)($task['finished_at'] ?? '')) ?>"
                        >
                            <td><?= (int)$task['id'] ?></td>
                            <td><?= htmlspecialchars((string)$task['node_key']) ?></td>
                            <td><?= htmlspecialchars((string)$task['node_type']) ?></td>
                            <?php $taskStatusClass = strtolower(str_replace('_', '-', (string)$task['status'])); ?>
                            <td class="task-status"><span class="status-pill status-<?= htmlspecialchars($taskStatusClass) ?>"><?= htmlspecialchars((string)$task['status']) ?></span></td>
                            <td><?= (int)$task['attempts'] ?>/<?= (int)$task['max_attempts'] ?></td>
                            <td class="task-error"><?= htmlspecialchars((string)$task['last_error']) ?></td>
                            <td class="task-actions-cell">
                                <div class="task-actions-stack">
                                    <?php if ($canOperate): ?>
                                        <button class="task-retry-btn" type="button" data-task-id="<?= (int)$task['id'] ?>">Retry</button>
                                        <button class="task-skip-btn" type="button" data-task-id="<?= (int)$task['id'] ?>">Skip</button>
                                    <?php endif; ?>
                                    <?php if ($canForceComplete): ?>
                                        <button class="task-complete-btn" type="button" data-task-id="<?= (int)$task['id'] ?>">Complete</button>
                                    <?php endif; ?>
                                    <button class="task-logs-btn" type="button" data-task-id="<?= (int)$task['id'] ?>">Logs</button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <h3>Task Logs</h3>
            <div class="dead-letter-toolbar">
                <label>
                    Log Level
                    <select id="task-log-level-filter">
                        <option value="ALL">All</option>
                        <option value="DEBUG">Debug</option>
                        <option value="INFO">Info</option>
                        <option value="WARN">Warn</option>
                        <option value="ERROR">Error</option>
                    </select>
                </label>
                <button id="task-log-load-more" type="button">Load More</button>
            </div>
            <pre id="task-log-viewer">Select a task and click Logs.</pre>
        </div>
    </div>
</section>
