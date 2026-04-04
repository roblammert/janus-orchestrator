<?php

declare(strict_types=1);

use Janus\Database;
use Janus\ExecutionService;
use Janus\Http;
use Janus\TaskService;
use Janus\SystemService;
use Janus\AuditService;
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
$auditService = new AuditService($pdo);
$authService = new AuthService($pdo);

$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';

if (PHP_SAPI === 'cli-server') {
    $staticFile = __DIR__ . $path;
    if ($path !== '/' && is_file($staticFile)) {
        return false;
    }
}

try {
    $publicPaths = ['/login'];
    $isApiPath = str_starts_with($path, '/api/');

    if ($method === 'POST' && $path === '/logout') {
        $authService->requireCsrfForStateChange(false);
        $authService->logout();
        header('Location: /login');
        exit;
    }

    if ($method === 'GET' && $path === '/login') {
        if ($authService->currentUser() !== null) {
            header('Location: /');
            exit;
        }

        View::render('login', ['csrfToken' => $authService->csrfToken()], ['title' => 'Login', 'isPublic' => true, 'csrfToken' => $authService->csrfToken()]);
        exit;
    }

    if ($method === 'POST' && $path === '/login') {
        $authService->requireCsrfForStateChange(false);
        $username = trim((string)($_POST['username'] ?? ''));
        $password = (string)($_POST['password'] ?? '');

        if ($authService->login($username, $password)) {
            header('Location: /');
            exit;
        }

        View::render('login', ['error' => 'Invalid credentials', 'csrfToken' => $authService->csrfToken()], ['title' => 'Login', 'isPublic' => true, 'csrfToken' => $authService->csrfToken()]);
        exit;
    }

    if (!in_array($path, $publicPaths, true)) {
        if ($isApiPath) {
            $authService->requireAuthenticatedApi();
        } else {
            $authService->requireAuthenticatedPage();
        }
    }

    if ($isApiPath && in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
        $authService->requireCsrfForStateChange(true);
    }

    $user = $authService->currentUser();
    $actorUserId = is_array($user) ? (int)($user['id'] ?? 0) : 0;
    $shellExtra = ['csrfToken' => $authService->csrfToken()];

    if ($method === 'GET' && $path === '/') {
        View::render('workflows', ['workflows' => $workflowService->listWorkflows(), 'user' => $user], AppShell::meta('Workflows', $user, $shellExtra));
        exit;
    }

    if ($method === 'GET' && $path === '/executions') {
        View::render('executions', ['executions' => $executionService->listExecutions(), 'user' => $user], AppShell::meta('Executions', $user, $shellExtra));
        exit;
    }

    if ($method === 'GET' && $path === '/settings') {
        View::render('settings', ['user' => $user], AppShell::meta('Settings', $user, $shellExtra));
        exit;
    }

    if ($method === 'GET' && $path === '/dead-letters') {
        View::render('dead_letters', ['deadLetters' => $taskService->listDeadLetters(), 'user' => $user], AppShell::meta('Dead Letters', $user, $shellExtra));
        exit;
    }

    if ($method === 'GET' && $path === '/observability') {
        View::render('observability', [], AppShell::meta('Observability', $user, $shellExtra));
        exit;
    }

    if ($method === 'GET' && $path === '/audit') {
        $authService->requireRolePage('ADMIN');
        $events = $auditService->listEventsPage(1, 100);
        View::render('audit_events', ['events' => $events['items']], AppShell::meta('Audit', $user, $shellExtra));
        exit;
    }

    if ($method === 'GET' && preg_match('#^/workflows/([^/]+)$#', $path, $m) === 1) {
        $name = urldecode($m[1]);
        View::render(
            'workflow_detail',
            ['name' => $name, 'versions' => $workflowService->listWorkflowVersions($name)],
            AppShell::meta('Workflow Detail', $user, $shellExtra)
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

        View::render('execution_detail', ['execution' => $execution, 'user' => $user], AppShell::meta('Execution Detail', $user, $shellExtra));
        exit;
    }

    if ($method === 'GET' && $path === '/api/workflows') {
        $search = trim((string)($_GET['search'] ?? ''));
        $sort = trim((string)($_GET['sort'] ?? 'name_asc'));
        $page = max(1, (int)($_GET['page'] ?? 1));
        $pageSize = max(1, (int)($_GET['page_size'] ?? 50));
        $result = $workflowService->listWorkflowsPage($search, $sort, $page, $pageSize);
        Http::success($result['items'], 200, ['pagination' => $result['pagination']]);
        exit;
    }

    if ($method === 'POST' && $path === '/api/workflows') {
        $authService->requireRoleApi('ADMIN');
        $body = Http::bodyJson();
        $result = $workflowService->createWorkflowVersion($body);
        Http::success($result, 201);
        exit;
    }

    if ($method === 'GET' && preg_match('#^/api/workflows/(\d+)$#', $path, $m) === 1) {
        $workflow = $workflowService->getWorkflowById((int)$m[1]);
        if ($workflow === null) {
            Http::notFound();
            exit;
        }

        Http::success($workflow);
        exit;
    }

    if ($method === 'GET' && preg_match('#^/api/workflows/by-name/(.+)$#', $path, $m) === 1) {
        Http::success($workflowService->listWorkflowVersions(urldecode($m[1])));
        exit;
    }

    if ($method === 'POST' && $path === '/api/executions') {
        $authService->requireRoleApi('OPERATOR', 'ADMIN');
        $body = Http::bodyJson();
        $workflowId = (int)($body['workflow_id'] ?? 0);
        $input = is_array($body['input'] ?? null) ? $body['input'] : [];
        if ($workflowId <= 0) {
            throw new Janus\ValidationException('Invalid execution payload', [
                ['field' => 'workflow_id', 'message' => 'workflow_id must be a positive integer'],
            ]);
        }

        $result = $executionService->startExecution($workflowId, $input);
        Http::success($result, 201);
        exit;
    }

    if ($method === 'GET' && $path === '/api/executions') {
        $page = max(1, (int)($_GET['page'] ?? 1));
        $pageSize = max(1, (int)($_GET['page_size'] ?? 50));
        $status = trim((string)($_GET['status'] ?? ''));
        $workflow = trim((string)($_GET['workflow'] ?? ''));
        $startedAfter = trim((string)($_GET['started_after'] ?? ''));
        $startedBefore = trim((string)($_GET['started_before'] ?? ''));
        $sort = trim((string)($_GET['sort'] ?? 'id_desc'));
        $result = $executionService->listExecutionsPage($page, $pageSize, $status, $workflow, $startedAfter, $startedBefore, $sort);
        Http::success($result['items'], 200, ['pagination' => $result['pagination']]);
        exit;
    }

    if ($method === 'GET' && preg_match('#^/api/executions/(\d+)$#', $path, $m) === 1) {
        $execution = $executionService->getExecutionDetails((int)$m[1]);
        if ($execution === null) {
            Http::notFound();
            exit;
        }

        Http::success($execution);
        exit;
    }

    if ($method === 'GET' && preg_match('#^/api/executions/(\d+)/dag$#', $path, $m) === 1) {
        $dag = $executionService->executionDagSummary((int)$m[1]);
        if ($dag === null) {
            Http::notFound();
            exit;
        }

        Http::success($dag);
        exit;
    }

    if ($method === 'GET' && preg_match('#^/api/executions/(\d+)/events$#', $path, $m) === 1) {
        $sinceId = max(0, (int)($_GET['since_id'] ?? 0));
        $limit = max(1, (int)($_GET['limit'] ?? 200));
        $events = $executionService->executionEventsDelta((int)$m[1], $sinceId, $limit);
        Http::success($events['items'], 200, ['next_since_id' => $events['next_since_id']]);
        exit;
    }

    if ($method === 'POST' && preg_match('#^/api/executions/(\d+)/cancel$#', $path, $m) === 1) {
        $authService->requireRoleApi('OPERATOR', 'ADMIN');
        $executionService->cancelExecution((int)$m[1], $actorUserId > 0 ? $actorUserId : null);
        Http::success(['ok' => true]);
        exit;
    }

    if ($method === 'GET' && $path === '/api/tasks') {
        $page = max(1, (int)($_GET['page'] ?? 1));
        $pageSize = max(1, (int)($_GET['page_size'] ?? 50));
        $status = trim((string)($_GET['status'] ?? ''));
        $nodeKey = trim((string)($_GET['node_key'] ?? ''));
        $executionId = max(0, (int)($_GET['execution_id'] ?? 0));
        $sort = trim((string)($_GET['sort'] ?? 'id_desc'));
        $result = $taskService->listTasksPage($page, $pageSize, $status, $nodeKey, $executionId, $sort);
        Http::success($result['items'], 200, ['pagination' => $result['pagination']]);
        exit;
    }

    if ($method === 'POST' && preg_match('#^/api/tasks/(\d+)/retry$#', $path, $m) === 1) {
        $authService->requireRoleApi('OPERATOR', 'ADMIN');
        $taskService->retryTask((int)$m[1], $actorUserId > 0 ? $actorUserId : null);
        Http::success(['ok' => true]);
        exit;
    }

    if ($method === 'POST' && preg_match('#^/api/tasks/(\d+)/skip$#', $path, $m) === 1) {
        $authService->requireRoleApi('OPERATOR', 'ADMIN');
        $body = Http::bodyJson();
        $taskService->skipTask((int)$m[1], (string)($body['reason'] ?? 'Skipped manually'), $actorUserId > 0 ? $actorUserId : null);
        Http::success(['ok' => true]);
        exit;
    }

    if ($method === 'POST' && preg_match('#^/api/tasks/(\d+)/complete$#', $path, $m) === 1) {
        $authService->requireRoleApi('ADMIN');
        $body = Http::bodyJson();
        $output = is_array($body['output'] ?? null) ? $body['output'] : [];
        $taskService->completeTaskManually((int)$m[1], $output, $actorUserId > 0 ? $actorUserId : null);
        Http::success(['ok' => true]);
        exit;
    }

    if ($method === 'GET' && preg_match('#^/api/tasks/(\d+)/logs$#', $path, $m) === 1) {
        $level = trim((string)($_GET['level'] ?? ''));
        $cursor = max(0, (int)($_GET['cursor'] ?? 0));
        $limit = max(1, (int)($_GET['limit'] ?? 200));
        $result = $taskService->listTaskLogsPage((int)$m[1], $level, $cursor, $limit);
        Http::success($result['items'], 200, ['next_cursor' => $result['next_cursor']]);
        exit;
    }

    if ($method === 'GET' && $path === '/api/dead-letters') {
        Http::success($taskService->listDeadLetters());
        exit;
    }

    if ($method === 'GET' && preg_match('#^/api/dead-letters/(\d+)$#', $path, $m) === 1) {
        $taskId = (int)$m[1];
        $item = null;
        foreach ($taskService->listDeadLetters() as $row) {
            if ((int)($row['id'] ?? 0) === $taskId) {
                $item = $row;
                break;
            }
        }
        if ($item === null) {
            Http::notFound();
            exit;
        }

        Http::success($item);
        exit;
    }

    if ($method === 'POST' && preg_match('#^/api/tasks/(\d+)/annotate$#', $path, $m) === 1) {
        $authService->requireRoleApi('OPERATOR', 'ADMIN');
        $body = Http::bodyJson();
        $note = (string)($body['note'] ?? '');
        $taskService->annotateTask((int)$m[1], $note, $actorUserId > 0 ? $actorUserId : null);
        Http::success(['ok' => true]);
        exit;
    }

    if ($method === 'GET' && $path === '/api/metrics/overview') {
        Http::success($executionService->metricsOverview());
        exit;
    }

    if ($method === 'GET' && $path === '/api/health/services') {
        Http::success($systemService->healthSummary());
        exit;
    }

    if ($method === 'GET' && $path === '/api/meta/shell') {
        Http::success([
            'app_version' => Janus\Config::appVersion(),
            'environment' => Janus\Config::appEnvironment(),
            'role' => is_array($user) ? (string)($user['role'] ?? 'VIEWER') : 'VIEWER',
            'capabilities' => [
                'dead_letters' => true,
                'observability' => true,
                'execution_dag' => true,
                'execution_events' => true,
                'task_logs_cursor' => true,
                'theme_dark' => true,
            ],
        ]);
        exit;
    }

    if ($method === 'GET' && $path === '/api/audit-events') {
        $authService->requireRoleApi('ADMIN');
        $eventType = trim((string)($_GET['event_type'] ?? ''));
        $actorFilter = max(0, (int)($_GET['actor_user_id'] ?? 0));
        $page = max(1, (int)($_GET['page'] ?? 1));
        $pageSize = max(1, (int)($_GET['page_size'] ?? 50));
        $result = $auditService->listEventsPage($page, $pageSize, $eventType, $actorFilter);
        Http::success($result['items'], 200, ['pagination' => $result['pagination']]);
        exit;
    }

    Http::notFound();
} catch (Janus\ValidationException $e) {
    Http::error($e->getMessage(), $e->errorCode(), $e->statusCode(), $e->details());
} catch (Janus\ApiException $e) {
    Http::error($e->getMessage(), $e->errorCode(), $e->statusCode(), $e->details());
} catch (InvalidArgumentException $e) {
    Http::error($e->getMessage(), 'INVALID_ARGUMENT', 400);
} catch (RuntimeException $e) {
    Http::error($e->getMessage(), 'RUNTIME_ERROR', 409);
} catch (Throwable $e) {
    Http::error('Internal server error', 'INTERNAL_ERROR', 500);
}
