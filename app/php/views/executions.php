<section id="executions-workspace" data-can-operate="<?= in_array(strtoupper((string)($user['role'] ?? 'VIEWER')), ['OPERATOR', 'ADMIN'], true) ? '1' : '0' ?>">
    <?php $role = strtoupper((string)($user['role'] ?? 'VIEWER')); ?>
    <?php $canOperate = in_array($role, ['OPERATOR', 'ADMIN'], true); ?>

    <header class="page-heading">
        <h2>Executions</h2>
        <p>Monitor runtime health, triage active runs quickly, and inspect execution details without leaving this page.</p>
    </header>

    <section class="executions-summary-grid" aria-label="Execution summary">
        <article class="workflow-detail-panel executions-summary-card">
            <h3>Total</h3>
            <p id="executions-summary-total" class="metric-value">0</p>
            <small class="workflow-detail-meta">Rows loaded in this view</small>
        </article>
        <article class="workflow-detail-panel executions-summary-card">
            <h3>Active</h3>
            <p id="executions-summary-active" class="metric-value">0</p>
            <small class="workflow-detail-meta">Pending + running executions</small>
        </article>
        <article class="workflow-detail-panel executions-summary-card">
            <h3>Completed</h3>
            <p id="executions-summary-completed" class="metric-value">0</p>
            <small class="workflow-detail-meta">Finished successfully</small>
        </article>
        <article class="workflow-detail-panel executions-summary-card">
            <h3>Issues</h3>
            <p id="executions-summary-issues" class="metric-value">0</p>
            <small class="workflow-detail-meta">Failed + timed out</small>
        </article>
    </section>

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
        <label>
            Search Workflow
            <input id="executions-search-filter" type="search" placeholder="invoice, nightly_sync, smoke_..." />
        </label>
        <div class="toolbar-actions">
            <button id="executions-refresh-btn" type="button">Refresh</button>
            <button id="executions-export-csv-btn" type="button">Export CSV</button>
            <span id="executions-poll-indicator" class="poll-indicator">Idle</span>
        </div>
        <label class="executions-auto-refresh-toggle">
            <span>Auto Refresh</span>
            <input id="executions-auto-refresh" type="checkbox" checked />
        </label>
    </div>

    <div class="dead-letter-toolbar" role="group" aria-label="Quick status filters">
        <button type="button" class="execution-quick-filter is-active" data-status="ALL">All <span id="executions-count-all" class="status-pill">0</span></button>
        <button type="button" class="execution-quick-filter" data-status="RUNNING">Running <span id="executions-count-running" class="status-pill status-running">0</span></button>
        <button type="button" class="execution-quick-filter" data-status="PENDING">Pending <span id="executions-count-pending" class="status-pill status-pending">0</span></button>
        <button type="button" class="execution-quick-filter" data-status="COMPLETED">Completed <span id="executions-count-completed" class="status-pill status-completed">0</span></button>
        <button type="button" class="execution-quick-filter" data-status="FAILED">Failed <span id="executions-count-failed" class="status-pill status-failed">0</span></button>
        <button type="button" class="execution-quick-filter" data-status="TIMED_OUT">Timed Out <span id="executions-count-timeout" class="status-pill status-timed-out">0</span></button>
    </div>

    <p class="workflow-detail-meta executions-tips">Tips: Press <span class="kbd-chip">/</span> to focus search. Press <span class="kbd-chip">r</span> to refresh.</p>

    <div class="workflow-layout execution-layout execution-workspace-layout">
        <section class="workflow-list-panel">
            <div class="execution-table-heading">
                <h3>Execution Queue</h3>
                <p id="executions-result-summary" class="workflow-detail-meta">0 results</p>
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
                            data-finished-at="<?= htmlspecialchars((string)($execution['finished_at'] ?? '')) ?>"
                            data-workflow-name="<?= htmlspecialchars((string)$execution['workflow_name']) ?>"
                            data-workflow-version="<?= (int)$execution['workflow_version'] ?>"
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
                            <td class="task-actions-cell">
                                <div class="task-actions-stack">
                                    <a href="/executions/<?= (int)$execution['id'] ?>">View</a>
                                    <?php if ($canOperate && in_array($execution['status'], ['PENDING', 'RUNNING'], true)): ?>
                                        <button class="cancel-execution-btn" data-execution-id="<?= (int)$execution['id'] ?>">Cancel</button>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <p id="executions-empty-state" class="empty-state" hidden>No executions match the current filters.</p>
        </section>

        <aside class="workflow-detail-panel executions-inspector-panel" aria-live="polite">
            <div class="execution-table-heading">
                <h3 id="executions-inspector-title">Select an execution</h3>
                <span id="executions-last-updated" class="poll-indicator">Not loaded</span>
            </div>
            <p id="executions-inspector-subtitle" class="workflow-detail-meta">Choose a row to inspect runtime details and task distribution.</p>

            <div class="execution-meta-grid">
                <article class="execution-meta-card">
                    <h3>Status</h3>
                    <p id="executions-inspector-status">-</p>
                </article>
                <article class="execution-meta-card">
                    <h3>Started</h3>
                    <p id="executions-inspector-started">-</p>
                </article>
                <article class="execution-meta-card">
                    <h3>Finished</h3>
                    <p id="executions-inspector-finished">-</p>
                </article>
                <article class="execution-meta-card">
                    <h3>Duration</h3>
                    <p id="executions-inspector-duration">-</p>
                </article>
            </div>

            <h4>Task Breakdown</h4>
            <div id="executions-inspector-task-breakdown" class="diagnostics-panel">
                <span>Total tasks: -</span>
                <span>Completed: -</span>
                <span>Running: -</span>
                <span>Failed: -</span>
                <span>Skipped: -</span>
            </div>

            <h4>Recent Tasks</h4>
            <div id="executions-inspector-task-list" class="builder-validation">No execution selected.</div>

            <div class="execution-actions">
                <a id="executions-inspector-open-link" href="/executions" class="button-link">Open Detail Page</a>
                <?php if ($canOperate): ?>
                    <button id="executions-inspector-cancel-btn" type="button" hidden>Cancel Execution</button>
                <?php endif; ?>
            </div>
        </aside>
    </div>
</section>
