<?php

declare(strict_types=1);

namespace Janus;

final class ValidationException extends ApiException
{
    public function __construct(string $message, array $fieldErrors = [])
    {
        parent::__construct($message, 'VALIDATION_ERROR', 422, ['field_errors' => $fieldErrors]);
    }
}
