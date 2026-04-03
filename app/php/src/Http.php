<?php

declare(strict_types=1);

namespace Janus;

final class Http
{
    public static function requestId(): string
    {
        $header = $_SERVER['HTTP_X_REQUEST_ID'] ?? '';
        $header = is_string($header) ? trim($header) : '';
        if ($header !== '') {
            return $header;
        }

        return bin2hex(random_bytes(8));
    }

    public static function json(mixed $payload, int $statusCode = 200): void
    {
        http_response_code($statusCode);
        header('Content-Type: application/json');
        header('X-Request-Id: ' . self::requestId());
        echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public static function success(mixed $data, int $statusCode = 200, array $meta = []): void
    {
        self::json([
            'success' => true,
            'data' => $data,
            'meta' => array_merge($meta, ['request_id' => self::requestId()]),
        ], $statusCode);
    }

    public static function error(string $message, string $code, int $statusCode, array $details = []): void
    {
        self::json([
            'success' => false,
            'error' => [
                'code' => $code,
                'message' => $message,
                'details' => $details,
            ],
            'meta' => [
                'request_id' => self::requestId(),
            ],
        ], $statusCode);
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
        self::error('Not found', 'NOT_FOUND', 404);
    }
}
