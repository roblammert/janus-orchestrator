<?php

declare(strict_types=1);

namespace Janus;

final class Http
{
    public static function json(mixed $payload, int $statusCode = 200): void
    {
        http_response_code($statusCode);
        header('Content-Type: application/json');
        echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public static function bodyJson(): array
    {
        $raw = file_get_contents('php://input');
        if ($raw === false || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new \InvalidArgumentException('Request body must be a JSON object');
        }

        return $decoded;
    }

    public static function notFound(): void
    {
        self::json(['error' => 'Not found'], 404);
    }
}
