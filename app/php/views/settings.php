<section>
    <h2>User Preferences</h2>
    <p>Preferences are saved in your browser profile.</p>
    <form id="ui-preferences-form" class="stack-form">
        <label>
            Theme
            <select id="theme-selector" name="theme">
                <option value="light">Light</option>
                <option value="dark">Dark</option>
            </select>
        </label>

        <?php if (($user['role'] ?? '') === 'ADMIN'): ?>
            <label>
                Font Pair
                <select id="font-selector" name="font_pair">
                    <option value="plex">Plex Sans + Plex Mono</option>
                    <option value="source">Source Sans 3 + Source Code Pro</option>
                    <option value="nunito">Nunito Sans + JetBrains Mono</option>
                </select>
            </label>
        <?php endif; ?>

        <button type="submit">Save Preferences</button>
    </form>
</section>

<section>
    <h2>Session Policy</h2>
    <ul>
        <li>Absolute TTL: 12 hours</li>
        <li>Idle timeout: 60 minutes</li>
        <li>Cookie mode: HttpOnly over HTTP (no SSL)</li>
    </ul>
</section>
