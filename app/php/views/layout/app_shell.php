<?php
/** @var array<string,mixed> $pageMeta */
$isPublic = (bool)($pageMeta['isPublic'] ?? false);
$title = (string)($pageMeta['title'] ?? 'Janus Orchestrator');
$environment = (string)($pageMeta['environment'] ?? 'local');
$version = (string)($pageMeta['version'] ?? '0.1.0');
$navItems = is_array($pageMeta['navItems'] ?? null) ? $pageMeta['navItems'] : [];
$currentPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$user = is_array($pageMeta['user'] ?? null) ? $pageMeta['user'] : null;
$csrfToken = (string)($pageMeta['csrfToken'] ?? '');
?>
<!doctype html>
<html lang="en" data-theme="light" data-font-pair="plex">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <?php if (!$isPublic && $csrfToken !== ''): ?>
        <meta name="csrf-token" content="<?= htmlspecialchars($csrfToken) ?>" />
    <?php endif; ?>
    <title><?= htmlspecialchars($title) ?> - Janus Orchestrator</title>
        <script>
            (function () {
                var themeKey = 'janus.theme';
                var fontKey = 'janus.fontPair';
                var root = document.documentElement;

                try {
                    var savedTheme = localStorage.getItem(themeKey);
                    var savedFont = localStorage.getItem(fontKey);
                    var theme = savedTheme === 'dark' ? 'dark' : 'light';
                    var font = savedFont === 'source' || savedFont === 'nunito' ? savedFont : 'plex';

                    root.setAttribute('data-theme', theme);
                    root.setAttribute('data-font-pair', font);
                } catch (_) {
                    root.setAttribute('data-theme', 'light');
                    root.setAttribute('data-font-pair', 'plex');
                }
            })();
        </script>
        <style>
            html[data-theme="light"],
            html[data-theme="light"] body {
                background: #edf3f9;
                color: #0f2238;
            }

            html[data-theme="dark"],
            html[data-theme="dark"] body {
                background: #0a1320;
                color: #e8f1fb;
            }
        </style>
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Mono:wght@400;500&family=IBM+Plex+Sans:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500&family=Nunito+Sans:wght@400;600;700&family=Source+Code+Pro:wght@400;500&family=Source+Sans+3:wght@400;600;700&display=swap" rel="stylesheet" />
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
            <nav class="app-nav" aria-label="Primary">
                <?php foreach ($navItems as $item): ?>
                    <?php
                    $href = (string)($item['href'] ?? '#');
                    $active = $currentPath === $href;
                    $isSubpage = (bool)($item['subpage'] ?? false);
                    $navClass = 'nav-link';
                    if ($active) {
                        $navClass .= ' is-active';
                    }
                    if ($isSubpage) {
                        $navClass .= ' nav-link-subpage';
                    }
                    ?>
                    <a class="<?= htmlspecialchars($navClass) ?>" href="<?= htmlspecialchars($href) ?>">
                        <?= htmlspecialchars((string)($item['label'] ?? '')) ?>
                    </a>
                <?php endforeach; ?>
            </nav>
            <div class="env-badge"><?= htmlspecialchars(strtoupper($environment)) ?></div>
        </aside>

        <div class="app-main">
            <header class="app-header">
                <h1 class="page-title"><?= htmlspecialchars($title) ?></h1>
                <div class="header-controls">
                    <button id="theme-toggle-btn" type="button" aria-label="Toggle theme">Theme: Light</button>
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
                    <form method="post" action="/logout" class="inline-form">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>" />
                        <button type="submit" class="link-button">Logout</button>
                    </form>
                </div>
            </header>

            <main class="content-area">
                <div class="content-inner">
                    <?php include $templatePath; ?>
                </div>
            </main>

            <footer class="app-footer">
                <span id="footer-service-status">Services: checking...</span>
                <span id="footer-latency">Latency: n/a</span>
                <span>Version: <?= htmlspecialchars($version) ?></span>
            </footer>
        </div>
    </div>
    <div id="toast-region" class="toast-region" aria-live="polite"></div>
    <div id="confirm-modal" class="confirm-modal" hidden>
        <div class="confirm-modal-card" role="dialog" aria-modal="true" aria-labelledby="confirm-modal-title">
            <h2 id="confirm-modal-title">Confirm Action</h2>
            <p id="confirm-modal-message">Are you sure?</p>
            <div class="confirm-modal-actions">
                <button id="confirm-modal-cancel" type="button">Cancel</button>
                <button id="confirm-modal-confirm" type="button">Confirm</button>
            </div>
        </div>
    </div>
    <div id="execution-start-modal" class="confirm-modal" hidden>
        <div class="confirm-modal-card" role="dialog" aria-modal="true" aria-labelledby="execution-start-title">
            <h2 id="execution-start-title">Start Execution</h2>
            <p>Provide execution input JSON for this workflow.</p>
            <label>
                Input JSON
                <textarea id="execution-start-input" rows="8">{}</textarea>
            </label>
            <div class="confirm-modal-actions">
                <button id="execution-start-cancel" type="button">Cancel</button>
                <button id="execution-start-confirm" type="button">Start</button>
            </div>
        </div>
    </div>
<?php endif; ?>
<script src="/assets/site.js"></script>
<script src="/assets/app.js"></script>
</body>
</html>
