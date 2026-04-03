function api(url, method = 'GET', body = null) {
  return window.JanusUI.api(url, method, body);
}

function confirmAction(message) {
  return new Promise((resolve) => {
    const modal = document.getElementById('confirm-modal');
    const messageNode = document.getElementById('confirm-modal-message');
    const confirmBtn = document.getElementById('confirm-modal-confirm');
    const cancelBtn = document.getElementById('confirm-modal-cancel');

    if (!modal || !messageNode || !confirmBtn || !cancelBtn) {
      resolve(false);
      return;
    }

    messageNode.textContent = message;
    modal.hidden = false;

    const cleanup = () => {
      modal.hidden = true;
      confirmBtn.removeEventListener('click', onConfirm);
      cancelBtn.removeEventListener('click', onCancel);
    };

    const onConfirm = () => {
      cleanup();
      resolve(true);
    };

    const onCancel = () => {
      cleanup();
      resolve(false);
    };

    confirmBtn.addEventListener('click', onConfirm);
    cancelBtn.addEventListener('click', onCancel);
  });
}

function collectExecutionInput() {
  return new Promise((resolve, reject) => {
    const modal = document.getElementById('execution-start-modal');
    const input = document.getElementById('execution-start-input');
    const confirmBtn = document.getElementById('execution-start-confirm');
    const cancelBtn = document.getElementById('execution-start-cancel');

    if (!modal || !input || !confirmBtn || !cancelBtn) {
      reject(new Error('Execution modal unavailable'));
      return;
    }

    input.value = '{}';
    modal.hidden = false;

    const cleanup = () => {
      modal.hidden = true;
      confirmBtn.removeEventListener('click', onConfirm);
      cancelBtn.removeEventListener('click', onCancel);
    };

    const onConfirm = () => {
      try {
        const parsed = JSON.parse(input.value || '{}');
        cleanup();
        resolve(parsed);
      } catch (_) {
        window.JanusUI.showToast('Execution input must be valid JSON', 'error');
      }
    };

    const onCancel = () => {
      cleanup();
      reject(new Error('Execution start cancelled'));
    };

    confirmBtn.addEventListener('click', onConfirm);
    cancelBtn.addEventListener('click', onCancel);
  });
}

function bindCreateWorkflow() {
  const form = document.getElementById('create-workflow-form');
  if (!form) return;

  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    const fd = new FormData(form);

    try {
      const definition = JSON.parse(fd.get('definition'));
      await api('/api/workflows', 'POST', {
        name: fd.get('name'),
        description: fd.get('description'),
        definition
      });
      window.JanusUI.showToast('Workflow version created', 'success');
      window.location.reload();
    } catch (error) {
      window.JanusUI.showToast(error.message, 'error');
    }
  });
}

function workflowDefinitionSummary(definition) {
  const nodes = Array.isArray(definition?.nodes) ? definition.nodes : [];
  const edges = Array.isArray(definition?.edges) ? definition.edges : [];
  const issues = [];

  if (nodes.length === 0) {
    issues.push('No nodes found');
  }

  const keySet = new Set();
  nodes.forEach((node) => {
    const key = String(node?.key || '').trim();
    if (key === '') {
      issues.push('Node with missing key');
      return;
    }
    if (keySet.has(key)) {
      issues.push(`Duplicate node key: ${key}`);
    }
    keySet.add(key);
  });

  edges.forEach((edge) => {
    const from = String(edge?.from || '');
    const to = String(edge?.to || '');
    if (!keySet.has(from)) {
      issues.push(`Edge from unknown node: ${from}`);
    }
    if (!keySet.has(to)) {
      issues.push(`Edge to unknown node: ${to}`);
    }
  });

  return {
    nodes: nodes.length,
    edges: edges.length,
    timeoutSeconds: Number(definition?.timeout_seconds || 0),
    issues
  };
}

function bindWorkflowsWorkspace() {
  const workspace = document.getElementById('workflow-workspace');
  if (!workspace) return;

  const table = document.getElementById('workflow-list-table');
  const searchInput = document.getElementById('workflow-search');
  const sortSelect = document.getElementById('workflow-sort');
  const title = document.getElementById('workflow-detail-title');
  const summary = document.getElementById('workflow-validation-summary');
  const versionList = document.getElementById('workflow-version-list');
  const viewer = document.getElementById('workflow-definition-viewer');
  if (!table || !searchInput || !sortSelect || !title || !summary || !versionList || !viewer) return;

  const tbody = table.querySelector('tbody');
  if (!tbody) return;

  const rows = Array.from(tbody.querySelectorAll('tr'));
  let selectedWorkflowName = null;

  function parseRowData(row) {
    return {
      name: String(row.dataset.workflowName || ''),
      latestVersion: Number(row.dataset.latestVersion || 0),
      versionsCount: Number(row.dataset.versionsCount || 0)
    };
  }

  function applyFilterAndSort() {
    const query = searchInput.value.trim().toLowerCase();
    const mode = sortSelect.value;

    const filtered = rows.filter((row) => {
      const name = String(row.dataset.workflowName || '').toLowerCase();
      const show = query === '' || name.includes(query);
      row.style.display = show ? '' : 'none';
      return show;
    });

    filtered.sort((a, b) => {
      const da = parseRowData(a);
      const db = parseRowData(b);

      if (mode === 'name-desc') return db.name.localeCompare(da.name);
      if (mode === 'version-desc') return db.latestVersion - da.latestVersion;
      if (mode === 'count-desc') return db.versionsCount - da.versionsCount;
      return da.name.localeCompare(db.name);
    });

    filtered.forEach((row) => tbody.appendChild(row));

    if (filtered.length > 0 && !selectedWorkflowName) {
      selectWorkflow(filtered[0].dataset.workflowName || '');
    }
  }

  function renderVersionList(name, versions) {
    versionList.innerHTML = '';

    if (!Array.isArray(versions) || versions.length === 0) {
      versionList.innerHTML = '<li>No versions found.</li>';
      viewer.textContent = 'No definition available.';
      summary.textContent = '';
      return;
    }

    versions.forEach((version, index) => {
      const li = document.createElement('li');
      const button = document.createElement('button');
      button.type = 'button';
      button.className = 'version-pill';
      button.textContent = `v${version.version} (id ${version.id})`;
      button.addEventListener('click', () => {
        const definition = version.definition_json || {};
        const stats = workflowDefinitionSummary(definition);
        const issueText = stats.issues.length > 0 ? `Issues: ${stats.issues.join('; ')}` : 'Validation: no structural issues';
        summary.textContent = `Nodes: ${stats.nodes} | Edges: ${stats.edges} | Timeout: ${stats.timeoutSeconds || 'n/a'}s | ${issueText}`;
        viewer.textContent = JSON.stringify(definition, null, 2);

        versionList.querySelectorAll('.version-pill').forEach((node) => node.classList.remove('is-active'));
        button.classList.add('is-active');
      });
      li.appendChild(button);
      versionList.appendChild(li);

      if (index === 0) {
        button.click();
      }
    });
  }

  async function selectWorkflow(name) {
    if (!name) return;
    selectedWorkflowName = name;
    title.textContent = `Workflow: ${name}`;
    summary.textContent = 'Loading versions...';
    viewer.textContent = 'Loading definition...';

    rows.forEach((row) => {
      row.classList.toggle('is-selected', row.dataset.workflowName === name);
    });

    try {
      const versions = await api(`/api/workflows/by-name/${encodeURIComponent(name)}`);
      renderVersionList(name, versions);
    } catch (error) {
      summary.textContent = '';
      viewer.textContent = error.message;
      versionList.innerHTML = '<li>Failed to load versions.</li>';
    }
  }

  rows.forEach((row) => {
    row.addEventListener('click', () => {
      selectWorkflow(String(row.dataset.workflowName || ''));
    });
  });

  searchInput.addEventListener('input', applyFilterAndSort);
  sortSelect.addEventListener('change', applyFilterAndSort);
  applyFilterAndSort();
}

function bindStartExecution() {
  document.querySelectorAll('.start-execution-btn').forEach((button) => {
    button.addEventListener('click', async () => {
      const workflowId = Number(button.dataset.workflowId);
      try {
        const input = await collectExecutionInput();
        const result = await api('/api/executions', 'POST', {
          workflow_id: workflowId,
          input
        });
        window.JanusUI.showToast('Execution started', 'success');
        window.location.href = `/executions/${result.execution_id}`;
      } catch (error) {
        window.JanusUI.showToast(error.message, 'error');
      }
    });
  });
}

function bindObservabilityWorkspace() {
  const workspace = document.getElementById('observability-workspace');
  if (!workspace) return;

  const executionNode = document.getElementById('obs-execution-counts');
  const taskNode = document.getElementById('obs-task-counts');
  const avgNode = document.getElementById('obs-avg-duration');
  const healthTable = document.getElementById('obs-health-table');

  async function loadMetrics() {
    try {
      const payload = await api('/api/metrics/overview');
      if (executionNode) {
        executionNode.textContent = (payload.execution_counts || [])
          .map((item) => `${item.status}: ${item.count}`)
          .join('\n') || 'No execution data';
      }
      if (taskNode) {
        taskNode.textContent = (payload.task_counts || [])
          .map((item) => `${item.status}: ${item.count}`)
          .join('\n') || 'No task data';
      }
      if (avgNode) {
        avgNode.textContent = String(payload.avg_task_duration_seconds ?? 'n/a');
      }
    } catch (error) {
      if (executionNode) executionNode.textContent = error.message;
      if (taskNode) taskNode.textContent = error.message;
      if (avgNode) avgNode.textContent = 'n/a';
    }
  }

  async function loadHealth() {
    if (!healthTable) return;

    try {
      const health = await api('/api/health/services');
      const mapping = [
        ['Web', health.web],
        ['API', health.api],
        ['DB', health.db],
        ['FastAPI', health.fastapi],
        ['Scheduler', health.scheduler],
        ['Worker', health.worker]
      ];

      const body = healthTable.querySelector('tbody');
      if (!body) return;
      body.innerHTML = '';

      mapping.forEach(([name, value]) => {
        const tr = document.createElement('tr');
        const ok = value && value.ok === true;
        const unknown = value && value.ok === null;
        tr.innerHTML = `<td>${name}</td><td>${unknown ? 'Unknown' : ok ? 'Healthy' : 'Degraded'}</td><td>${JSON.stringify(value?.details || {})}</td>`;
        body.appendChild(tr);
      });
    } catch (error) {
      const body = healthTable.querySelector('tbody');
      if (!body) return;
      body.innerHTML = `<tr><td colspan="3">${error.message}</td></tr>`;
    }
  }

  loadMetrics();
  loadHealth();
}

function bindExecutionsWorkspace() {
  const workspace = document.getElementById('executions-workspace');
  if (!workspace) return;

  const table = document.getElementById('executions-list-table');
  const statusFilter = document.getElementById('executions-status-filter');
  const timeFilter = document.getElementById('executions-time-filter');
  const sortSelect = document.getElementById('executions-sort');
  if (!table || !statusFilter || !timeFilter || !sortSelect) return;

  const tbody = table.querySelector('tbody');
  if (!tbody) return;

  const rows = Array.from(tbody.querySelectorAll('tr'));

  function withinRange(startedAtRaw, rangeMode) {
    if (!startedAtRaw || rangeMode === 'all') {
      return true;
    }

    const startedAt = new Date(startedAtRaw.replace(' ', 'T') + 'Z');
    if (Number.isNaN(startedAt.getTime())) {
      return true;
    }

    const now = Date.now();
    const ageMs = now - startedAt.getTime();
    if (rangeMode === '24h') return ageMs <= 24 * 3600 * 1000;
    if (rangeMode === '7d') return ageMs <= 7 * 24 * 3600 * 1000;
    if (rangeMode === '30d') return ageMs <= 30 * 24 * 3600 * 1000;
    return true;
  }

  function rowPriority(status, mode) {
    if (mode === 'running-first') {
      if (status === 'RUNNING') return 0;
      if (status === 'PENDING') return 1;
      return 2;
    }

    if (mode === 'error-first') {
      if (status === 'FAILED' || status === 'TIMED_OUT') return 0;
      return 1;
    }

    return 0;
  }

  function applyFilters() {
    const statusMode = statusFilter.value;
    const timeMode = timeFilter.value;
    const sortMode = sortSelect.value;

    const filtered = rows.filter((row) => {
      const status = String(row.dataset.status || '');
      const startedAt = String(row.dataset.startedAt || '');
      const statusOk = statusMode === 'ALL' || status === statusMode;
      const timeOk = withinRange(startedAt, timeMode);
      const show = statusOk && timeOk;
      row.style.display = show ? '' : 'none';
      return show;
    });

    filtered.sort((a, b) => {
      const statusA = String(a.dataset.status || '');
      const statusB = String(b.dataset.status || '');
      const idA = Number(a.dataset.executionId || 0);
      const idB = Number(b.dataset.executionId || 0);

      if (sortMode === 'running-first' || sortMode === 'error-first') {
        const p = rowPriority(statusA, sortMode) - rowPriority(statusB, sortMode);
        if (p !== 0) return p;
      }

      if (sortMode === 'oldest') {
        return idA - idB;
      }

      return idB - idA;
    });

    filtered.forEach((row) => tbody.appendChild(row));
  }

  statusFilter.addEventListener('change', applyFilters);
  timeFilter.addEventListener('change', applyFilters);
  sortSelect.addEventListener('change', applyFilters);
  applyFilters();
}

function bindExecutionCancelButtons() {
  document.querySelectorAll('.cancel-execution-btn').forEach((button) => {
    button.addEventListener('click', async () => {
      const executionId = Number(button.dataset.executionId);
      try {
        await api(`/api/executions/${executionId}/cancel`, 'POST');
        window.JanusUI.showToast('Execution cancelled', 'success');
        window.location.reload();
      } catch (error) {
        window.JanusUI.showToast(error.message, 'error');
      }
    });
  });
}

function bindTaskButtons() {
  const executionWorkspace = document.getElementById('execution-workspace');
  const logViewer = document.getElementById('task-log-viewer');
  const logLevelFilter = document.getElementById('task-log-level-filter');
  const loadMoreBtn = document.getElementById('task-log-load-more');
  const timeline = document.getElementById('execution-timeline');
  const dagPanel = document.getElementById('execution-dag-panel');

  let loadedLogs = [];
  let visibleLogCount = 40;

  function renderLogs() {
    if (!logViewer) return;
    const selectedLevel = logLevelFilter ? logLevelFilter.value : 'ALL';
    const filtered = loadedLogs.filter((log) => selectedLevel === 'ALL' || String(log.level) === selectedLevel);
    const visible = filtered.slice(0, visibleLogCount);
    logViewer.textContent = visible
      .map((log) => `${log.created_at} [${log.level}] ${log.message}`)
      .join('\n');

    if (loadMoreBtn) {
      loadMoreBtn.disabled = visible.length >= filtered.length;
    }
  }

  function buildTimeline() {
    if (!timeline) return;
    const rows = Array.from(document.querySelectorAll('#execution-tasks-table tbody tr'));
    const events = [];
    rows.forEach((row) => {
      const nodeKey = String(row.dataset.nodeKey || '');
      const startedAt = String(row.dataset.startedAt || '');
      const finishedAt = String(row.dataset.finishedAt || '');
      const statusCell = row.querySelector('.task-status');
      const status = statusCell ? statusCell.textContent : '';

      if (startedAt) {
        events.push({ at: startedAt, text: `${nodeKey} started` });
      }
      if (finishedAt) {
        events.push({ at: finishedAt, text: `${nodeKey} finished (${status})` });
      }
    });

    events.sort((a, b) => String(a.at).localeCompare(String(b.at)));
    timeline.innerHTML = events.length === 0
      ? '<li>No timeline events yet.</li>'
      : events.map((event) => `<li>${event.at} - ${event.text}</li>`).join('');
  }

  function bindDagNodeSelection() {
    if (!dagPanel) return;
    dagPanel.querySelectorAll('.dag-node').forEach((node) => {
      node.addEventListener('click', () => {
        const nodeKey = String(node.dataset.nodeKey || '');
        document.querySelectorAll('#execution-tasks-table tbody tr').forEach((row) => {
          row.classList.toggle('is-selected', String(row.dataset.nodeKey || '') === nodeKey);
        });
      });
    });
  }

  if (executionWorkspace) {
    buildTimeline();
    bindDagNodeSelection();
  }

  if (logLevelFilter) {
    logLevelFilter.addEventListener('change', () => {
      visibleLogCount = 40;
      renderLogs();
    });
  }

  if (loadMoreBtn) {
    loadMoreBtn.addEventListener('click', () => {
      visibleLogCount += 40;
      renderLogs();
    });
  }

  document.querySelectorAll('.task-retry-btn').forEach((button) => {
    button.addEventListener('click', async () => {
      const ok = await confirmAction('Retry this task? This will place it back in the queue.');
      if (!ok) return;
      try {
        await api(`/api/tasks/${Number(button.dataset.taskId)}/retry`, 'POST');
        window.JanusUI.showToast('Task queued for retry', 'success');
        window.location.reload();
      } catch (error) {
        window.JanusUI.showToast(error.message, 'error');
      }
    });
  });

  document.querySelectorAll('.task-skip-btn').forEach((button) => {
    button.addEventListener('click', async () => {
      const ok = await confirmAction('Skip this task? This marks it as SKIPPED.');
      if (!ok) return;
      try {
        await api(`/api/tasks/${Number(button.dataset.taskId)}/skip`, 'POST', { reason: 'Skipped manually' });
        window.JanusUI.showToast('Task skipped', 'success');
        window.location.reload();
      } catch (error) {
        window.JanusUI.showToast(error.message, 'error');
      }
    });
  });

  document.querySelectorAll('.task-complete-btn').forEach((button) => {
    button.addEventListener('click', async () => {
      const ok = await confirmAction('Mark this task completed manually?');
      if (!ok) return;
      try {
        const output = { manual: true, source: 'ui' };
        await api(`/api/tasks/${Number(button.dataset.taskId)}/complete`, 'POST', { output });
        window.JanusUI.showToast('Task manually completed', 'success');
        window.location.reload();
      } catch (error) {
        window.JanusUI.showToast(error.message, 'error');
      }
    });
  });

  document.querySelectorAll('.task-logs-btn').forEach((button) => {
    button.addEventListener('click', async () => {
      const taskId = Number(button.dataset.taskId);
      if (!logViewer) return;

      try {
        loadedLogs = await api(`/api/tasks/${taskId}/logs`);
        visibleLogCount = 40;
        renderLogs();
      } catch (error) {
        logViewer.textContent = error.message;
      }
    });
  });
}

function bindDeadLettersWorkspace() {
  const workspace = document.getElementById('dead-letters-workspace');
  if (!workspace) return;

  const table = document.getElementById('dead-letter-table');
  const selectAll = document.getElementById('dead-letter-select-all');
  const bulkRetryBtn = document.getElementById('dead-letter-bulk-retry-btn');
  const refreshBtn = document.getElementById('dead-letter-refresh-btn');
  const detailTitle = document.getElementById('dead-letter-detail-title');
  const detailViewer = document.getElementById('dead-letter-detail-viewer');
  const noteInput = document.getElementById('dead-letter-note');
  const noteBtn = document.getElementById('dead-letter-note-btn');
  if (!table || !detailTitle || !detailViewer || !noteInput || !noteBtn) return;

  let selectedTaskId = null;

  function selectedRows() {
    return Array.from(table.querySelectorAll('tbody tr')).filter((row) => {
      const checkbox = row.querySelector('.dead-letter-select');
      return checkbox && checkbox.checked;
    });
  }

  table.querySelectorAll('tbody tr').forEach((row) => {
    row.querySelector('.dead-letter-view-btn')?.addEventListener('click', () => {
      selectedTaskId = Number(row.dataset.taskId || 0);
      const error = String(row.dataset.error || '');
      detailTitle.textContent = `Dead Letter Task #${selectedTaskId}`;
      detailViewer.textContent = JSON.stringify({
        task_id: selectedTaskId,
        execution_id: Number(row.dataset.executionId || 0),
        last_error: error
      }, null, 2);
    });

    row.querySelector('.dead-letter-retry-btn')?.addEventListener('click', async () => {
      const taskId = Number(row.dataset.taskId || 0);
      const ok = await confirmAction(`Retry task #${taskId}?`);
      if (!ok) return;
      try {
        await api(`/api/tasks/${taskId}/retry`, 'POST');
        window.JanusUI.showToast(`Task #${taskId} queued for retry`, 'success');
        row.remove();
      } catch (error) {
        window.JanusUI.showToast(error.message, 'error');
      }
    });
  });

  if (selectAll) {
    selectAll.addEventListener('change', () => {
      table.querySelectorAll('.dead-letter-select').forEach((checkbox) => {
        checkbox.checked = selectAll.checked;
      });
    });
  }

  if (bulkRetryBtn) {
    bulkRetryBtn.addEventListener('click', async () => {
      const rows = selectedRows();
      if (rows.length === 0) {
        window.JanusUI.showToast('Select at least one task', 'error');
        return;
      }

      const ok = await confirmAction(`Retry ${rows.length} selected task(s)?`);
      if (!ok) return;

      for (const row of rows) {
        const taskId = Number(row.dataset.taskId || 0);
        try {
          await api(`/api/tasks/${taskId}/retry`, 'POST');
          row.remove();
        } catch (_) {
          // Keep row visible if retry fails.
        }
      }

      window.JanusUI.showToast('Bulk retry complete', 'success');
    });
  }

  if (refreshBtn) {
    refreshBtn.addEventListener('click', () => {
      window.location.reload();
    });
  }

  noteBtn.addEventListener('click', async () => {
    if (!selectedTaskId) {
      window.JanusUI.showToast('Select a task to annotate', 'error');
      return;
    }

    const note = noteInput.value.trim();
    if (note === '') {
      window.JanusUI.showToast('Note cannot be empty', 'error');
      return;
    }

    try {
      await api(`/api/tasks/${selectedTaskId}/annotate`, 'POST', { note });
      window.JanusUI.showToast('Triage note saved', 'success');
      noteInput.value = '';
    } catch (error) {
      window.JanusUI.showToast(error.message, 'error');
    }
  });
}

async function refreshExecutionTasks() {
  const table = document.getElementById('execution-tasks-table');
  if (!table) return;

  const executionId = Number(table.dataset.executionId);
  try {
    const execution = await api(`/api/executions/${executionId}`);
    const status = document.getElementById('execution-status');
    if (status) status.textContent = execution.status;

    execution.tasks.forEach((task) => {
      const row = table.querySelector(`tr[data-task-id="${task.id}"]`);
      if (!row) return;
      const statusCell = row.querySelector('.task-status');
      const errorCell = row.querySelector('.task-error');
      if (statusCell) statusCell.textContent = task.status;
      if (errorCell) errorCell.textContent = task.last_error || '';
    });
  } catch (_) {
    // Polling is best effort and should not spam alerts.
  }
}

function init() {
  bindWorkflowsWorkspace();
  bindExecutionsWorkspace();
  bindObservabilityWorkspace();
  bindCreateWorkflow();
  bindStartExecution();
  bindExecutionCancelButtons();
  bindTaskButtons();
  bindDeadLettersWorkspace();

  if (document.getElementById('execution-tasks-table')) {
    setInterval(refreshExecutionTasks, 3000);
  }
}

document.addEventListener('DOMContentLoaded', init);
