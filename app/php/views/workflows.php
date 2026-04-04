<section id="workflow-workspace">
    <header class="page-heading">
        <h2>Workflows</h2>
        <p>Create immutable workflow versions and inspect historical definitions from a single view.</p>
    </header>

    <div class="workflow-toolbar">
        <label>
            Search
            <input id="workflow-search" type="search" placeholder="Filter by workflow name" />
        </label>
        <label>
            Sort
            <select id="workflow-sort">
                <option value="name-asc">Name (A-Z)</option>
                <option value="name-desc">Name (Z-A)</option>
                <option value="version-desc">Latest Version (High-Low)</option>
                <option value="count-desc">Total Versions (High-Low)</option>
            </select>
        </label>
    </div>

    <div class="workflow-layout">
        <div class="workflow-list-panel">
            <div class="table-scroll">
                <table id="workflow-list-table">
                    <thead>
                    <tr>
                        <th>Name</th>
                        <th>Latest Version</th>
                        <th>Total Versions</th>
                        <th>Actions</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($workflows as $workflow): ?>
                        <tr
                            data-workflow-name="<?= htmlspecialchars((string)$workflow['name']) ?>"
                            data-latest-version="<?= (int)$workflow['latest_version'] ?>"
                            data-versions-count="<?= (int)$workflow['versions_count'] ?>"
                        >
                            <td><?= htmlspecialchars((string)$workflow['name']) ?></td>
                            <td><?= (int)$workflow['latest_version'] ?></td>
                            <td><?= (int)$workflow['versions_count'] ?></td>
                            <td><a href="/workflows/<?= urlencode((string)$workflow['name']) ?>">Legacy view</a></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="workflow-detail-panel">
            <h3 id="workflow-detail-title">Select a workflow</h3>
            <div class="workflow-detail-meta" id="workflow-validation-summary"></div>

            <h4>Version History</h4>
            <ul id="workflow-version-list" class="workflow-version-list">
                <li>Select a workflow to load versions.</li>
            </ul>

            <h4>Definition Viewer</h4>
            <pre id="workflow-definition-viewer">Select a version to inspect definition JSON.</pre>
        </div>
    </div>
</section>

<?php $role = strtoupper((string)($user['role'] ?? 'VIEWER')); ?>
<?php if ($role === 'ADMIN'): ?>
<section>
    <h2>Create Workflow Version</h2>
    <form id="create-workflow-form">
        <label>
            Workflow Name
            <input type="text" name="name" required />
        </label>
        <label>
            Description
            <input type="text" name="description" />
        </label>
        <label>
            Definition JSON
            <textarea name="definition" rows="20" required>{
  "name": "demo_http_script_file",
  "version": 1,
  "timeout_seconds": 600,
  "nodes": [
    {
      "key": "fetch_api",
      "name": "Fetch API",
      "type": "HTTP",
      "timeout_seconds": 20,
      "max_attempts": 3,
      "priority": 200,
      "config": {
        "method": "GET",
        "url": "https://example.org",
        "headers": {"Accept": "application/json"}
      }
    }
  ],
  "edges": []
}</textarea>
        </label>
        <button type="submit">Create Version</button>
    </form>
</section>
<?php endif; ?>
