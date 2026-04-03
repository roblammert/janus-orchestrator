<?php if (!empty($error)): ?>
    <p class="error-banner"><?= htmlspecialchars((string)$error) ?></p>
<?php endif; ?>
<form method="post" action="/login" class="stack-form">
    <label>
        Username
        <input type="text" name="username" required autocomplete="username" />
    </label>
    <label>
        Password
        <input type="password" name="password" required autocomplete="current-password" />
    </label>
    <button type="submit">Login</button>
</form>
