CREATE DATABASE IF NOT EXISTS janus_orchestrator CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE janus_orchestrator;

CREATE TABLE IF NOT EXISTS workflows (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(190) NOT NULL,
    version INT UNSIGNED NOT NULL,
    description VARCHAR(500) NULL,
    definition_json JSON NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_workflows_name_version (name, version),
    KEY idx_workflows_name (name),
    KEY idx_workflows_active (is_active)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS workflow_nodes (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    workflow_id BIGINT UNSIGNED NOT NULL,
    node_key VARCHAR(190) NOT NULL,
    name VARCHAR(190) NOT NULL,
    type ENUM('HTTP','SCRIPT','FILE_WRITER') NOT NULL,
    config_json JSON NOT NULL,
    timeout_seconds INT UNSIGNED NOT NULL DEFAULT 60,
    max_attempts INT UNSIGNED NOT NULL DEFAULT 3,
    priority INT NOT NULL DEFAULT 100,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_nodes_workflow FOREIGN KEY (workflow_id) REFERENCES workflows(id) ON DELETE CASCADE,
    UNIQUE KEY uq_nodes_workflow_node_key (workflow_id, node_key),
    KEY idx_nodes_workflow (workflow_id),
    KEY idx_nodes_type (type)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS workflow_edges (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    workflow_id BIGINT UNSIGNED NOT NULL,
    from_node_id BIGINT UNSIGNED NOT NULL,
    to_node_id BIGINT UNSIGNED NOT NULL,
    condition_json JSON NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_edges_workflow FOREIGN KEY (workflow_id) REFERENCES workflows(id) ON DELETE CASCADE,
    CONSTRAINT fk_edges_from_node FOREIGN KEY (from_node_id) REFERENCES workflow_nodes(id) ON DELETE CASCADE,
    CONSTRAINT fk_edges_to_node FOREIGN KEY (to_node_id) REFERENCES workflow_nodes(id) ON DELETE CASCADE,
    UNIQUE KEY uq_edges_unique (workflow_id, from_node_id, to_node_id),
    KEY idx_edges_workflow_to (workflow_id, to_node_id),
    KEY idx_edges_workflow_from (workflow_id, from_node_id),
    CONSTRAINT chk_edges_not_self CHECK (from_node_id <> to_node_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS executions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    workflow_id BIGINT UNSIGNED NOT NULL,
    workflow_name VARCHAR(190) NOT NULL,
    workflow_version INT UNSIGNED NOT NULL,
    status ENUM('PENDING','RUNNING','COMPLETED','FAILED','CANCELLED','TIMED_OUT') NOT NULL DEFAULT 'PENDING',
    input_json JSON NULL,
    output_json JSON NULL,
    error_text TEXT NULL,
    timeout_at DATETIME NULL,
    cancelled_at DATETIME NULL,
    started_at DATETIME NULL,
    finished_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_executions_workflow FOREIGN KEY (workflow_id) REFERENCES workflows(id),
    KEY idx_executions_status (status),
    KEY idx_executions_workflow (workflow_id),
    KEY idx_executions_timeout (timeout_at),
    KEY idx_executions_created (created_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS tasks (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    execution_id BIGINT UNSIGNED NOT NULL,
    workflow_id BIGINT UNSIGNED NOT NULL,
    workflow_node_id BIGINT UNSIGNED NOT NULL,
    node_key VARCHAR(190) NOT NULL,
    status ENUM('PENDING','READY','RUNNING','COMPLETED','FAILED','FAILED_PERMANENTLY','SKIPPED') NOT NULL DEFAULT 'PENDING',
    attempts INT UNSIGNED NOT NULL DEFAULT 0,
    max_attempts INT UNSIGNED NOT NULL DEFAULT 3,
    priority INT NOT NULL DEFAULT 100,
    scheduled_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    next_attempt_at DATETIME NULL,
    started_at DATETIME NULL,
    finished_at DATETIME NULL,
    last_heartbeat_at DATETIME NULL,
    idempotency_key VARCHAR(255) NOT NULL,
    claimed_by_worker_id VARCHAR(128) NULL,
    last_error TEXT NULL,
    output_json JSON NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_tasks_execution FOREIGN KEY (execution_id) REFERENCES executions(id) ON DELETE CASCADE,
    CONSTRAINT fk_tasks_workflow FOREIGN KEY (workflow_id) REFERENCES workflows(id) ON DELETE CASCADE,
    CONSTRAINT fk_tasks_node FOREIGN KEY (workflow_node_id) REFERENCES workflow_nodes(id) ON DELETE CASCADE,
    UNIQUE KEY uq_tasks_execution_node (execution_id, workflow_node_id),
    KEY idx_tasks_execution_status (execution_id, status),
    KEY idx_tasks_status_schedule (status, scheduled_at),
    KEY idx_tasks_heartbeat (status, last_heartbeat_at),
    KEY idx_tasks_next_attempt (status, next_attempt_at),
    KEY idx_tasks_claimed_worker (claimed_by_worker_id),
    KEY idx_tasks_node_key (node_key),
    KEY idx_tasks_idempotency (idempotency_key)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS task_queue (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    task_id BIGINT UNSIGNED NOT NULL,
    priority INT NOT NULL DEFAULT 100,
    scheduled_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    claimed_by_worker_id VARCHAR(128) NULL,
    claimed_at DATETIME NULL,
    available_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_queue_task FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
    UNIQUE KEY uq_queue_task (task_id),
    KEY idx_queue_claim (claimed_by_worker_id, claimed_at),
    KEY idx_queue_fetch (claimed_at, available_at, scheduled_at, priority),
    KEY idx_queue_scheduled (scheduled_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS task_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    task_id BIGINT UNSIGNED NOT NULL,
    level ENUM('DEBUG','INFO','WARN','ERROR') NOT NULL,
    message TEXT NOT NULL,
    metadata_json JSON NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_task_logs_task FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
    KEY idx_task_logs_task_time (task_id, created_at),
    KEY idx_task_logs_level (level)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS secrets (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(190) NOT NULL,
    value_encrypted TEXT NOT NULL,
    is_encrypted TINYINT(1) NOT NULL DEFAULT 0,
    description VARCHAR(500) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_secrets_name (name)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS state_transitions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    entity_type ENUM('execution','task') NOT NULL,
    entity_id BIGINT UNSIGNED NOT NULL,
    from_state VARCHAR(64) NULL,
    to_state VARCHAR(64) NOT NULL,
    metadata_json JSON NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_state_transitions_entity (entity_type, entity_id, created_at),
    KEY idx_state_transitions_to_state (to_state)
) ENGINE=InnoDB;
