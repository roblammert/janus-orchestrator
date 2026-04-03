<section>
    <h2>Workflow: <?= htmlspecialchars($name) ?></h2>

    <?php foreach ($versions as $version): ?>
        <article>
            <h3>Version <?= (int)$version['version'] ?> (ID <?= (int)$version['id'] ?>)</h3>
            <p><?= htmlspecialchars((string)($version['description'] ?? '')) ?></p>
            <button class="start-execution-btn" data-workflow-id="<?= (int)$version['id'] ?>">Start Execution</button>
            <pre><?= htmlspecialchars(json_encode($version['definition_json'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) ?></pre>
        </article>
    <?php endforeach; ?>
</section>
