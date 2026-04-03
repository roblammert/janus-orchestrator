<?php

declare(strict_types=1);

namespace Janus;

final class Config
{
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
}
