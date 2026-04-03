<?php

declare(strict_types=1);

namespace Janus;

use PDO;

final class SystemService
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function healthSummary(): array
    {
        $dbOk = false;
        try {
            $this->pdo->query('SELECT 1')->fetchColumn();
            $dbOk = true;
        } catch (\Throwable) {
            $dbOk = false;
        }

        $fastapiUrl = 'http://127.0.0.1:' . (string)(getenv('FASTAPI_PORT') ?: '8812') . '/health';
        $fastapiOk = false;
        $fastapiBody = null;

        try {
            $ctx = stream_context_create(['http' => ['timeout' => 2]]);
            $raw = @file_get_contents($fastapiUrl, false, $ctx);
            if (is_string($raw) && $raw !== '') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $fastapiBody = $decoded;
                }
                $fastapiOk = true;
            }
        } catch (\Throwable) {
            $fastapiOk = false;
        }

        return [
            'web' => ['ok' => true],
            'api' => ['ok' => true],
            'db' => ['ok' => $dbOk],
            'fastapi' => ['ok' => $fastapiOk, 'details' => $fastapiBody],
            'scheduler' => ['ok' => null],
            'worker' => ['ok' => null],
        ];
    }
}
