<section>
    <h2>Workflows</h2>
    <p>Create immutable workflow versions using the JSON payload form.</p>

    <table>
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
            <tr>
                <td><?= htmlspecialchars($workflow['name']) ?></td>
                <td><?= (int)$workflow['latest_version'] ?></td>
                <td><?= (int)$workflow['versions_count'] ?></td>
                <td><a href="/workflows/<?= urlencode($workflow['name']) ?>">View versions</a></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</section>

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
