USE janus_orchestrator;

INSERT INTO workflows (name, version, description, definition_json)
VALUES (
    'demo_http_script_file',
    1,
    'Demo workflow with HTTP, script, and file-writer executors',
    JSON_OBJECT(
        'name', 'demo_http_script_file',
        'version', 1,
        'timeout_seconds', 600,
        'nodes', JSON_ARRAY(
            JSON_OBJECT(
                'key', 'fetch_api',
                'name', 'Fetch API',
                'type', 'HTTP',
                'timeout_seconds', 20,
                'max_attempts', 3,
                'priority', 200,
                'config', JSON_OBJECT(
                    'method', 'GET',
                    'url', 'https://example.org',
                    'headers', JSON_OBJECT('Accept', 'application/json')
                )
            ),
            JSON_OBJECT(
                'key', 'run_script',
                'name', 'Run Script',
                'type', 'SCRIPT',
                'timeout_seconds', 30,
                'max_attempts', 2,
                'priority', 150,
                'config', JSON_OBJECT(
                    'command', 'python3 /var/www/janus/scripts/demo_task.py',
                    'shell', true
                )
            ),
            JSON_OBJECT(
                'key', 'write_output',
                'name', 'Write Output',
                'type', 'FILE_WRITER',
                'timeout_seconds', 10,
                'max_attempts', 3,
                'priority', 100,
                'config', JSON_OBJECT(
                    'path', '/tmp/janus-demo-output.txt',
                    'content', 'Workflow finished',
                    'mode', 'w'
                )
            )
        ),
        'edges', JSON_ARRAY(
            JSON_OBJECT('from', 'fetch_api', 'to', 'run_script'),
            JSON_OBJECT('from', 'run_script', 'to', 'write_output')
        )
    )
);
