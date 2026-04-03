<?php

declare(strict_types=1);

namespace Janus;

final class Config
{
    public static function appVersion(): string
    {
        return (string) (getenv('APP_VERSION') ?: '0.1.0');
    }

    public static function appEnvironment(): string
    {
        return (string) (getenv('APP_ENV') ?: 'local');
    }

    public static function dbDsn(): string
    {
        $host = getenv('DB_HOST') ?: '127.0.0.1';
        $port = getenv('DB_PORT') ?: '3306';
        $name = getenv('DB_NAME') ?: 'janus_orchestrator';

        return sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $host, $port, $name);
    }

    public static function dbUser(): string
    {
        return getenv('DB_USER') ?: 'janus';
    }

    public static function dbPassword(): string
    {
        return getenv('DB_PASSWORD') ?: 'janus';
    }

    public static function workflowDefaultTimeoutSeconds(): int
    {
        return (int) (getenv('WORKFLOW_DEFAULT_TIMEOUT_SECONDS') ?: 3600);
    }

    public static function sessionAbsoluteTtlSeconds(): int
    {
        return (int) (getenv('SESSION_ABSOLUTE_TTL_SECONDS') ?: 43200);
    }

    public static function sessionIdleTimeoutSeconds(): int
    {
        return (int) (getenv('SESSION_IDLE_TIMEOUT_SECONDS') ?: 3600);
    }

    public static function sessionCookieName(): string
    {
        return (string) (getenv('SESSION_COOKIE_NAME') ?: 'janus_session');
    }

    public static function sessionCookieSecure(): bool
    {
        // User decision: this deployment stays on HTTP only.
        return false;
    }

    public static function bootstrapAdminUsername(): ?string
    {
        $value = getenv('BOOTSTRAP_ADMIN_USERNAME');
        if (is_string($value) && $value !== '') {
            return $value;
        }

        return 'admin';
    }

    public static function bootstrapAdminPassword(): ?string
    {
        $value = getenv('BOOTSTRAP_ADMIN_PASSWORD');
        if (is_string($value) && $value !== '') {
            return $value;
        }

        return 'admin123';
    }
}
