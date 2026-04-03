<?php

declare(strict_types=1);

use Janus\Database;
use Janus\ExecutionService;
use Janus\Http;
use Janus\TaskService;
use Janus\View;
use Janus\WorkflowService;

require_once __DIR__ . '/../app/php/src/bootstrap.php';

$db = new Database();
$pdo = $db->pdo();
$workflowService = new WorkflowService($pdo);
$executionService = new ExecutionService($pdo);
$taskService = new TaskService($pdo);

$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';

try {
    if ($method === 'GET' && $path === '/') {
        View::render('workflows', ['workflows' => $workflowService->listWorkflows()]);
        exit;
    }

    if ($method === 'GET' && $path === '/executions') {
        View::render('executions', ['executions' => $executionService->listExecutions()]);
        exit;
    }

    if ($method === 'GET' && preg_match('#^/workflows/([^/]+)$#', $path, $m) === 1) {
        $name = urldecode($m[1]);
        View::render('workflow_detail', ['name' => $name, 'versions' => $workflowService->listWorkflowVersions($name)]);
        exit;
    }

    if ($method === 'GET' && preg_match('#^/executions/(\d+)$#', $path, $m) === 1) {
        $executionId = (int)$m[1];
        $execution = $executionService->getExecutionDetails($executionId);
        if ($execution === null) {
            Http::notFound();
            exit;
        }

        View::render('execution_detail', ['execution' => $execution]);
        exit;
    }

    if ($method === 'GET' && $path === '/api/workflows') {
        Http::json($workflowService->listWorkflows());
        exit;
    }

    if ($method === 'POST' && $path === '/api/workflows') {
        $body = Http::bodyJson();
        $result = $workflowService->createWorkflowVersion($body);
        Http::json($result, 201);
        exit;
    }

    if ($method === 'GET' && preg_match('#^/api/workflows/(\d+)$#', $path, $m) === 1) {
        $workflow = $workflowService->getWorkflowById((int)$m[1]);
        if ($workflow === null) {
            Http::notFound();
            exit;
        }

        Http::json($workflow);
        exit;
    }

    if ($method === 'GET' && preg_match('#^/api/workflows/by-name/(.+)$#', $path, $m) === 1) {
        Http::json($workflowService->listWorkflowVersions(urldecode($m[1])));
        exit;
    }

    if ($method === 'POST' && $path === '/api/executions') {
        $body = Http::bodyJson();
        $workflowId = (int)($body['workflow_id'] ?? 0);
        $input = is_array($body['input'] ?? null) ? $body['input'] : [];
        $result = $executionService->startExecution($workflowId, $input);
        Http::json($result, 201);
        exit;
    }

    if ($method === 'GET' && $path === '/api/executions') {
        Http::json($executionService->listExecutions());
        exit;
    }

    if ($method === 'GET' && preg_match('#^/api/executions/(\d+)$#', $path, $m) === 1) {
        $execution = $executionService->getExecutionDetails((int)$m[1]);
        if ($execution === null) {
            Http::notFound();
            exit;
        }

        Http::json($execution);
        exit;
    }

    if ($method === 'POST' && preg_match('#^/api/executions/(\d+)/cancel$#', $path, $m) === 1) {
        $executionService->cancelExecution((int)$m[1]);
        Http::json(['ok' => true]);
        exit;
    }

    if ($method === 'POST' && preg_match('#^/api/tasks/(\d+)/retry$#', $path, $m) === 1) {
        $taskService->retryTask((int)$m[1]);
        Http::json(['ok' => true]);
        exit;
    }

    if ($method === 'POST' && preg_match('#^/api/tasks/(\d+)/skip$#', $path, $m) === 1) {
        $body = Http::bodyJson();
        $taskService->skipTask((int)$m[1], (string)($body['reason'] ?? 'Skipped manually'));
        Http::json(['ok' => true]);
        exit;
    }

    if ($method === 'POST' && preg_match('#^/api/tasks/(\d+)/complete$#', $path, $m) === 1) {
        $body = Http::bodyJson();
        $output = is_array($body['output'] ?? null) ? $body['output'] : [];
        $taskService->completeTaskManually((int)$m[1], $output);
        Http::json(['ok' => true]);
        exit;
    }

    if ($method === 'GET' && preg_match('#^/api/tasks/(\d+)/logs$#', $path, $m) === 1) {
        Http::json($taskService->listTaskLogs((int)$m[1]));
        exit;
    }

    if ($method === 'GET' && $path === '/api/metrics/overview') {
        Http::json($executionService->metricsOverview());
        exit;
    }

    Http::notFound();
} catch (Throwable $e) {
    Http::json([
        'error' => $e->getMessage(),
    ], 400);
}
