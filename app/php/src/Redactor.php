<?php

declare(strict_types=1);

namespace Janus;

final class Redactor
{
    private const SENSITIVE_KEYS = [
        'password',
        'passphrase',
        'secret',
        'token',
        'api_key',
        'apikey',
        'authorization',
        'cookie',
        'set-cookie',
        'x-api-key',
        'access_key',
        'private_key',
    ];

    public static function redact(mixed $value): mixed
    {
        if (is_array($value)) {
            $output = [];
            foreach ($value as $key => $item) {
                if (is_string($key) && self::isSensitiveKey($key)) {
                    $output[$key] = '[REDACTED]';
                    continue;
                }

                $output[$key] = self::redact($item);
            }

            return $output;
        }

        if (is_string($value)) {
            return self::redactString($value);
        }

        return $value;
    }

    public static function redactString(string $text): string
    {
        $text = preg_replace('/(bearer\s+)[A-Za-z0-9\-\._~\+\/]+=*/i', '$1[REDACTED]', $text) ?? $text;
        $text = preg_replace('/((?:api[_-]?key|token|secret|password)\s*[:=]\s*)([^\s,;]+)/i', '$1[REDACTED]', $text) ?? $text;
        $text = preg_replace('/(authorization\s*[:=]\s*)([^\s,;]+)/i', '$1[REDACTED]', $text) ?? $text;
        return $text;
    }

    private static function isSensitiveKey(string $key): bool
    {
        $normalized = strtolower(str_replace(['-', ' '], '_', trim($key)));
        foreach (self::SENSITIVE_KEYS as $candidate) {
            if ($normalized === $candidate || str_contains($normalized, $candidate)) {
                return true;
            }
        }

        return false;
    }
}
