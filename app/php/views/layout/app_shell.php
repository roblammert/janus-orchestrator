<?php
/** @var array<string,mixed> $pageMeta */
$isPublic = (bool)($pageMeta['isPublic'] ?? false);
$title = (string)($pageMeta['title'] ?? 'Janus Orchestrator');
$environment = (string)($pageMeta['environment'] ?? 'local');
$version = (string)($pageMeta['version'] ?? '0.1.0');
$navItems = is_array($pageMeta['navItems'] ?? null) ? $pageMeta['navItems'] : [];
$currentPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$user = is_array($pageMeta['user'] ?? null) ? $pageMeta['user'] : null;
?>
<!doctype html>
<html lang="en" data-theme="light">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title><?= htmlspecialchars($title) ?> - Janus Orchestrator</title>
    <link rel="stylesheet" href="/assets/base.css" />
    <link rel="stylesheet" href="/assets/layout.css" />
    <link rel="stylesheet" href="/assets/components.css" />
    <link rel="stylesheet" href="/assets/pages.css" />
</head>
<body>
<?php if ($isPublic): ?>
    <main class="auth-main">
        <section class="auth-card">
            <h1>Janus Orchestrator</h1>
            <?php include $templatePath; ?>
        </section>
    </main>
<?php else: ?>
    <div class="app-shell">
        <aside class="app-sidebar">
            <div class="brand">Janus</div>
            <nav>
                <?php foreach ($navItems as $item): ?>
                    <?php
                    $href = (string)($item['href'] ?? '#');
                    $active = $currentPath === $href;
                    ?>
                    <a class="nav-link<?= $active ? ' is-active' : '' ?>" href="<?= htmlspecialchars($href) ?>">
                        <?= htmlspecialchars((string)($item['label'] ?? '')) ?>
                    </a>
                <?php endforeach; ?>
            </nav>
            <div class="env-badge"><?= htmlspecialchars(strtoupper($environment)) ?></div>
        </aside>

        <div class="app-main">
            <header class="app-header">
                <h1><?= htmlspecialchars($title) ?></h1>
                <div class="header-controls">
                    <button id="theme-toggle-btn" type="button">Toggle Theme</button>
                    <?php if ($user !== null): ?>
                        <?php if (($user['role'] ?? '') === 'ADMIN'): ?>
                            <label for="font-pair-selector">Font</label>
                            <select id="font-pair-selector" aria-label="Select font pair">
                                <option value="plex">Plex Sans + Plex Mono</option>
                                <option value="source">Source Sans 3 + Source Code Pro</option>
                                <option value="nunito">Nunito Sans + JetBrains Mono</option>
                            </select>
                        <?php endif; ?>
                        <span class="user-pill"><?= htmlspecialchars((string)$user['username']) ?> (<?= htmlspecialchars((string)$user['role']) ?>)</span>
                    <?php endif; ?>
                    <a class="link-button" href="/logout">Logout</a>
                </div>
            </header>

            <main class="content-area">
                <?php include $templatePath; ?>
            </main>

            <footer class="app-footer">
                <span id="footer-service-status">Services: checking...</span>
                <span id="footer-latency">Latency: n/a</span>
                <span>Version: <?= htmlspecialchars($version) ?></span>
            </footer>
        </div>
    </div>
    <div id="toast-region" class="toast-region" aria-live="polite"></div>
<?php endif; ?>
<script src="/assets/site.js"></script>
<script src="/assets/app.js"></script>
</body>
</html>
