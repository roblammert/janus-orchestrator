<?php

declare(strict_types=1);

namespace Janus;

use RuntimeException;

class ApiException extends RuntimeException
{
    private string $errorCode;
    private int $statusCode;
    private array $details;

    public function __construct(string $message, string $errorCode, int $statusCode = 400, array $details = [])
    {
        parent::__construct($message);
        $this->errorCode = $errorCode;
        $this->statusCode = $statusCode;
        $this->details = $details;
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }

    public function details(): array
    {
        return $this->details;
    }
}
