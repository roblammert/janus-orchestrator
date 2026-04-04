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

        $schedulerHealth = $this->processHealth('janus_worker.main_scheduler', 'scheduler');
        $workerHealth = $this->processHealth('janus_worker.main_worker', 'worker');

        return [
            'web' => ['ok' => true],
            'api' => ['ok' => true],
            'db' => ['ok' => $dbOk],
            'fastapi' => ['ok' => $fastapiOk, 'details' => $fastapiBody],
            'scheduler' => $schedulerHealth,
            'worker' => $workerHealth,
        ];
    }

    private function processHealth(string $needle, string $name): array
    {
        if (!function_exists('shell_exec')) {
            return ['ok' => null, 'details' => ['reason' => 'shell_exec unavailable']];
        }

        $command = 'pgrep -f ' . escapeshellarg($needle) . ' | head -n 1';
        $output = shell_exec($command);
        $pid = trim((string)$output);

        if ($pid !== '') {
            return [
                'ok' => true,
                'details' => [
                    'detected_by' => 'process_scan',
                    'process' => $name,
                    'pid' => $pid,
                ],
            ];
        }

        return [
            'ok' => false,
            'details' => [
                'detected_by' => 'process_scan',
                'process' => $name,
                'reason' => 'not_running',
            ],
        ];
    }
}
