<?php

declare(strict_types=1);

namespace Janus;

use PDO;

final class AuthService
{
    private const ROLE_LEVELS = [
        'VIEWER' => 10,
        'OPERATOR' => 20,
        'ADMIN' => 30,
    ];

    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
        $this->startSession();
        $this->ensureAuthTables();
        $this->ensureBootstrapAdmin();
    }

    public function currentUser(): ?array
    {
        $userId = (int) ($_SESSION['user_id'] ?? 0);
        if ($userId <= 0) {
            return null;
        }

        $stmt = $this->pdo->prepare(
            'SELECT id, username, role, is_active FROM users WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $userId]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!is_array($user) || (int) $user['is_active'] !== 1) {
            $this->logout();
            return null;
        }

        $createdAt = (int) ($_SESSION['created_at'] ?? 0);
        $lastSeenAt = (int) ($_SESSION['last_seen_at'] ?? 0);
        $now = time();

        if ($createdAt <= 0 || $lastSeenAt <= 0) {
            $this->logout();
            return null;
        }

        if (($now - $createdAt) > Config::sessionAbsoluteTtlSeconds()) {
            $this->logout();
            return null;
        }

        if (($now - $lastSeenAt) > Config::sessionIdleTimeoutSeconds()) {
            $this->logout();
            return null;
        }

        $_SESSION['last_seen_at'] = $now;

        return [
            'id' => (int) $user['id'],
            'username' => (string) $user['username'],
            'role' => (string) $user['role'],
        ];
    }

    public function requireAuthenticatedPage(): void
    {
        if ($this->currentUser() !== null) {
            return;
        }

        header('Location: /login');
        exit;
    }

    public function requireAuthenticatedApi(): void
    {
        if ($this->currentUser() !== null) {
            return;
        }

        Http::error('Authentication required', 'AUTH_REQUIRED', 401);
        exit;
    }

    public function requireRoleApi(string ...$allowedRoles): void
    {
        $user = $this->currentUser();
        if ($user === null) {
            Http::error('Authentication required', 'AUTH_REQUIRED', 401);
            exit;
        }

        if ($this->userHasAnyRole($user, $allowedRoles)) {
            return;
        }

        Http::error('Insufficient role permissions', 'FORBIDDEN', 403);
        exit;
    }

    public function requireRolePage(string ...$allowedRoles): void
    {
        $user = $this->currentUser();
        if ($user === null) {
            header('Location: /login');
            exit;
        }

        if ($this->userHasAnyRole($user, $allowedRoles)) {
            return;
        }

        header('Location: /');
        exit;
    }

    public function csrfToken(): string
    {
        $token = $_SESSION['csrf_token'] ?? null;
        if (is_string($token) && $token !== '') {
            return $token;
        }

        $token = bin2hex(random_bytes(32));
        $_SESSION['csrf_token'] = $token;
        return $token;
    }

    public function requireCsrfForStateChange(bool $isApiRequest): void
    {
        $expected = $this->csrfToken();

        $provided = '';
        if ($isApiRequest) {
            $header = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
            $provided = is_string($header) ? trim($header) : '';
        } else {
            $provided = trim((string)($_POST['csrf_token'] ?? ''));
        }

        if ($provided !== '' && hash_equals($expected, $provided)) {
            return;
        }

        if ($isApiRequest) {
            Http::error('Invalid CSRF token', 'CSRF_FAILED', 419);
            exit;
        }

        Http::json(['error' => 'Invalid CSRF token'], 419);
        exit;
    }

    public function login(string $username, string $password): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, username, role, password_hash, is_active FROM users WHERE username = :username LIMIT 1'
        );
        $stmt->execute(['username' => $username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!is_array($user) || (int) $user['is_active'] !== 1) {
            return false;
        }

        $hash = (string) ($user['password_hash'] ?? '');
        if ($hash === '' || !password_verify($password, $hash)) {
            return false;
        }

        session_regenerate_id(true);
        $_SESSION['user_id'] = (int) $user['id'];
        $_SESSION['created_at'] = time();
        $_SESSION['last_seen_at'] = time();
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

        return true;
    }

    public function logout(): void
    {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }

    private function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        session_name(Config::sessionCookieName());
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'domain' => '',
            'secure' => Config::sessionCookieSecure(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        session_start();

        if (!isset($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token']) || $_SESSION['csrf_token'] === '') {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
    }

    private function userHasAnyRole(array $user, array $allowedRoles): bool
    {
        $userRole = strtoupper((string)($user['role'] ?? 'VIEWER'));
        $userLevel = self::ROLE_LEVELS[$userRole] ?? 0;

        foreach ($allowedRoles as $role) {
            $normalized = strtoupper(trim($role));
            $required = self::ROLE_LEVELS[$normalized] ?? PHP_INT_MAX;
            if ($userLevel >= $required) {
                return true;
            }
        }

        return false;
    }

    private function ensureBootstrapAdmin(): void
    {
        $count = (int) $this->pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
        if ($count > 0) {
            return;
        }

        $username = Config::bootstrapAdminUsername();
        $password = Config::bootstrapAdminPassword();
        if ($username === null || $password === null) {
            return;
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO users (username, password_hash, role, is_active) VALUES (:username, :password_hash, :role, 1)'
        );
        $stmt->execute([
            'username' => $username,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'role' => 'ADMIN',
        ]);
    }

    private function ensureAuthTables(): void
    {
        $this->pdo->exec(
            "CREATE TABLE IF NOT EXISTS users (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                username VARCHAR(190) NOT NULL,
                password_hash VARCHAR(255) NOT NULL,
                role ENUM('VIEWER','OPERATOR','ADMIN') NOT NULL DEFAULT 'ADMIN',
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_users_username (username),
                KEY idx_users_role_active (role, is_active)
            ) ENGINE=InnoDB"
        );

        $this->pdo->exec(
            "CREATE TABLE IF NOT EXISTS audit_events (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                actor_user_id BIGINT UNSIGNED NULL,
                event_type VARCHAR(120) NOT NULL,
                entity_type VARCHAR(64) NULL,
                entity_id BIGINT UNSIGNED NULL,
                details_json JSON NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT fk_audit_actor_user FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE SET NULL,
                KEY idx_audit_event_time (event_type, created_at),
                KEY idx_audit_entity (entity_type, entity_id, created_at)
            ) ENGINE=InnoDB"
        );
    }
}
