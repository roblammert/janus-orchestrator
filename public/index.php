<?php

declare(strict_types=1);

use Janus\Database;
use Janus\ExecutionService;
use Janus\Http;
use Janus\TaskService;
use Janus\SystemService;
use Janus\View;
use Janus\WorkflowService;
use Janus\AuthService;
use Janus\AppShell;

require_once __DIR__ . '/../app/php/src/bootstrap.php';

$db = new Database();
$pdo = $db->pdo();
$workflowService = new WorkflowService($pdo);
$executionService = new ExecutionService($pdo);
$taskService = new TaskService($pdo);
$systemService = new SystemService($pdo);
$authService = new AuthService($pdo);

$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';

try {
    $publicPaths = ['/login'];
    $isApiPath = str_starts_with($path, '/api/');

    if ($method === 'GET' && $path === '/logout') {
        $authService->logout();
        header('Location: /login');
        exit;
    }

    if ($method === 'GET' && $path === '/login') {
        if ($authService->currentUser() !== null) {
            header('Location: /');
            exit;
        }

        View::render('login', [], ['title' => 'Login', 'isPublic' => true]);
        exit;
    }

    if ($method === 'POST' && $path === '/login') {
        $username = trim((string)($_POST['username'] ?? ''));
        $password = (string)($_POST['password'] ?? '');

        if ($authService->login($username, $password)) {
            header('Location: /');
            exit;
        }

        View::render('login', ['error' => 'Invalid credentials'], ['title' => 'Login', 'isPublic' => true]);
        exit;
    }

    if (!in_array($path, $publicPaths, true)) {
        if ($isApiPath) {
            $authService->requireAuthenticatedApi();
        } else {
            $authService->requireAuthenticatedPage();
        }
    }

    $user = $authService->currentUser();

    if ($method === 'GET' && $path === '/') {
        View::render('workflows', ['workflows' => $workflowService->listWorkflows()], AppShell::meta('Workflows', $user));
        exit;
    }

    if ($method === 'GET' && $path === '/executions') {
        View::render('executions', ['executions' => $executionService->listExecutions()], AppShell::meta('Executions', $user));
        exit;
    }

    if ($method === 'GET' && $path === '/settings') {
        View::render('settings', ['user' => $user], AppShell::meta('Settings', $user));
        exit;
    }

    if ($method === 'GET' && $path === '/dead-letters') {
        View::render('dead_letters', ['deadLetters' => $taskService->listDeadLetters()], AppShell::meta('Dead Letters', $user));
        exit;
    }

    if ($method === 'GET' && $path === '/observability') {
        View::render('observability', [], AppShell::meta('Observability', $user));
        exit;
    }

    if ($method === 'GET' && preg_match('#^/workflows/([^/]+)$#', $path, $m) === 1) {
        $name = urldecode($m[1]);
        View::render(
            'workflow_detail',
            ['name' => $name, 'versions' => $workflowService->listWorkflowVersions($name)],
            AppShell::meta('Workflow Detail', $user)
        );
        exit;
    }

    if ($method === 'GET' && preg_match('#^/executions/(\d+)$#', $path, $m) === 1) {
        $executionId = (int)$m[1];
        $execution = $executionService->getExecutionDetails($executionId);
        if ($execution === null) {
            Http::notFound();
            exit;
        }

        View::render('execution_detail', ['execution' => $execution], AppShell::meta('Execution Detail', $user));
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

    if ($method === 'GET' && $path === '/api/dead-letters') {
        Http::json($taskService->listDeadLetters());
        exit;
    }

    if ($method === 'POST' && preg_match('#^/api/tasks/(\d+)/annotate$#', $path, $m) === 1) {
        $body = Http::bodyJson();
        $note = (string)($body['note'] ?? '');
        $actorUserId = is_array($user) ? (int)($user['id'] ?? 0) : 0;
        $taskService->annotateTask((int)$m[1], $note, $actorUserId > 0 ? $actorUserId : null);
        Http::json(['ok' => true]);
        exit;
    }

    if ($method === 'GET' && $path === '/api/metrics/overview') {
        Http::json($executionService->metricsOverview());
        exit;
    }

    if ($method === 'GET' && $path === '/api/health/services') {
        Http::json($systemService->healthSummary());
        exit;
    }

    Http::notFound();
} catch (Throwable $e) {
    Http::json([
        'error' => $e->getMessage(),
    ], 400);
}
