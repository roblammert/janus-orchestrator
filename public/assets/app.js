function api(url, method = 'GET', body = null) {
  return window.JanusUI.api(url, method, body);
}

function statusClassFromValue(statusRaw) {
  return String(statusRaw || '').toLowerCase().replace(/_/g, '-');
}

function statusPillMarkup(statusRaw) {
  const status = String(statusRaw || 'UNKNOWN');
  return `<span class="status-pill status-${statusClassFromValue(status)}">${status}</span>`;
}

function setPollIndicator(id, text, kind = 'idle') {
  const node = document.getElementById(id);
  if (!node) return;
  node.textContent = text;
  node.classList.remove('status-info', 'status-warning', 'status-danger');
  if (kind === 'busy') node.classList.add('status-info');
  if (kind === 'warn') node.classList.add('status-warning');
  if (kind === 'error') node.classList.add('status-danger');
}

function setLoadingState(node, isLoading) {
  if (!node) return;
  node.classList.toggle('skeleton-loading', isLoading);
}

function setEmptyState(id, visible, text) {
  const node = document.getElementById(id);
  if (!node) return;
  node.hidden = !visible;
  if (text) node.textContent = text;
}

function asCsvCell(value) {
  const text = String(value ?? '');
  if (/[",\n]/.test(text)) {
    return `"${text.replace(/"/g, '""')}"`;
  }
  return text;
}

function downloadCsv(filename, headers, rows) {
  const csv = [headers, ...rows].map((row) => row.map(asCsvCell).join(',')).join('\n');
  const blob = new Blob([csv], { type: 'text/csv;charset=utf-8' });
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url;
  a.download = filename;
  document.body.appendChild(a);
  a.click();
  a.remove();
  URL.revokeObjectURL(url);
}

function applyVirtualizedRows(tbody, allRows, stateKey) {
  if (!tbody) return;
  const pageSize = 150;
  if (!window.__janusVirtual) window.__janusVirtual = {};
  if (!window.__janusVirtual[stateKey]) window.__janusVirtual[stateKey] = { visible: pageSize };
  const state = window.__janusVirtual[stateKey];

  allRows.forEach((row, index) => {
    row.hidden = index >= state.visible;
  });

  let loadMoreBtn = tbody.parentElement?.parentElement?.querySelector(`[data-load-more="${stateKey}"]`);
  const hasMore = allRows.length > state.visible;
  if (!loadMoreBtn && hasMore) {
    loadMoreBtn = document.createElement('button');
    loadMoreBtn.type = 'button';
    loadMoreBtn.dataset.loadMore = stateKey;
    loadMoreBtn.textContent = 'Load More';
    loadMoreBtn.addEventListener('click', () => {
      state.visible += pageSize;
      applyVirtualizedRows(tbody, allRows, stateKey);
    });
    tbody.parentElement?.parentElement?.appendChild(loadMoreBtn);
  }

  if (loadMoreBtn) {
    loadMoreBtn.hidden = !hasMore;
  }
}

function renderLineChart(svg, values, stroke) {
  if (!svg) return;
  const width = 320;
  const height = 120;
  const pad = 10;
  const safeValues = values.length > 0 ? values : [0];
  const max = Math.max(...safeValues, 1);

  const points = safeValues.map((value, index) => {
    const x = pad + ((width - 2 * pad) * (safeValues.length <= 1 ? 0 : index / (safeValues.length - 1)));
    const y = height - pad - ((height - 2 * pad) * (value / max));
    return `${x},${y}`;
  }).join(' ');

  svg.innerHTML = `
    <polyline points="${points}" fill="none" stroke="${stroke}" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"></polyline>
    <line x1="${pad}" y1="${height - pad}" x2="${width - pad}" y2="${height - pad}" stroke="var(--color-border)" stroke-width="1"></line>
  `;
}

function formatNowTime() {
  const d = new Date();
  return d.toLocaleTimeString();
}

function withBusyIndicator(button, indicatorId, pendingText, doneText, fn) {
  return async () => {
    if (button) button.disabled = true;
    setPollIndicator(indicatorId, pendingText, 'busy');
    try {
      await fn();
      setPollIndicator(indicatorId, `${doneText} ${formatNowTime()}`, 'idle');
    } catch (error) {
      setPollIndicator(indicatorId, `Error: ${error.message}`, 'error');
      throw error;
    } finally {
      if (button) button.disabled = false;
    }
  };
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
      modal.removeEventListener('click', onBackdrop);
      document.removeEventListener('keydown', onKeydown);
    };

    const onConfirm = () => {
      cleanup();
      resolve(true);
    };

    const onCancel = () => {
      cleanup();
      resolve(false);
    };

    const onBackdrop = (event) => {
      if (event.target === modal) {
        onCancel();
      }
    };

    const onKeydown = (event) => {
      if (event.key === 'Escape') {
        onCancel();
      }
    };

    confirmBtn.addEventListener('click', onConfirm);
    cancelBtn.addEventListener('click', onCancel);
    modal.addEventListener('click', onBackdrop);
    document.addEventListener('keydown', onKeydown);
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
      modal.removeEventListener('click', onBackdrop);
      document.removeEventListener('keydown', onKeydown);
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

    const onBackdrop = (event) => {
      if (event.target === modal) {
        onCancel();
      }
    };

    const onKeydown = (event) => {
      if (event.key === 'Escape') {
        onCancel();
      }
    };

    confirmBtn.addEventListener('click', onConfirm);
    cancelBtn.addEventListener('click', onCancel);
    modal.addEventListener('click', onBackdrop);
    document.addEventListener('keydown', onKeydown);
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
        definition,
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
    issues,
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
  const refreshBtn = document.getElementById('workflow-refresh-btn');
  const exportBtn = document.getElementById('workflow-export-csv-btn');
  if (!table || !searchInput || !sortSelect || !title || !summary || !versionList || !viewer) return;

  const tbody = table.querySelector('tbody');
  if (!tbody) return;

  let rows = Array.from(tbody.querySelectorAll('tr'));
  let selectedWorkflowName = null;

  function parseRowData(row) {
    return {
      name: String(row.dataset.workflowName || ''),
      latestVersion: Number(row.dataset.latestVersion || 0),
      versionsCount: Number(row.dataset.versionsCount || 0),
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
    applyVirtualizedRows(tbody, filtered, 'workflows');
    setEmptyState('workflow-empty-state', filtered.length === 0);

    if (filtered.length > 0 && !selectedWorkflowName) {
      selectWorkflow(filtered[0].dataset.workflowName || '');
    }
  }

  function bindRowClickHandlers() {
    rows.forEach((row) => {
      row.addEventListener('click', () => {
        selectWorkflow(String(row.dataset.workflowName || ''));
      });
    });
  }

  function renderVersionList(versions) {
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
      renderVersionList(versions);
    } catch (error) {
      summary.textContent = '';
      viewer.textContent = error.message;
      versionList.innerHTML = '<li>Failed to load versions.</li>';
    }
  }

  async function refreshWorkflows() {
    setLoadingState(table, true);
    const page = await api('/api/workflows?page=1&page_size=500');
    tbody.innerHTML = '';
    page.forEach((workflow) => {
      const tr = document.createElement('tr');
      tr.dataset.workflowName = String(workflow.name || '');
      tr.dataset.latestVersion = String(workflow.latest_version || 0);
      tr.dataset.versionsCount = String(workflow.versions_count || 0);
      tr.innerHTML = `
        <td>${workflow.name}</td>
        <td>${workflow.latest_version}</td>
        <td>${workflow.versions_count}</td>
        <td><a href="/workflows/${encodeURIComponent(workflow.name)}">Legacy view</a></td>
      `;
      tbody.appendChild(tr);
    });
    rows = Array.from(tbody.querySelectorAll('tr'));
    bindRowClickHandlers();
    applyFilterAndSort();
    setLoadingState(table, false);
  }

  bindRowClickHandlers();
  searchInput.addEventListener('input', applyFilterAndSort);
  sortSelect.addEventListener('change', applyFilterAndSort);

  if (refreshBtn) {
    refreshBtn.addEventListener('click', withBusyIndicator(refreshBtn, 'workflow-poll-indicator', 'Refreshing...', 'Updated', refreshWorkflows));
  }

  if (exportBtn) {
    exportBtn.addEventListener('click', () => {
      const visible = rows.filter((row) => row.style.display !== 'none');
      const csvRows = visible.map((row) => [
        row.dataset.workflowName || '',
        row.dataset.latestVersion || '',
        row.dataset.versionsCount || '',
      ]);
      downloadCsv('workflows.csv', ['name', 'latest_version', 'versions_count'], csvRows);
    });
  }

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
          input,
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
  const refreshBtn = document.getElementById('observability-refresh-btn');

  const throughputTrend = [];
  const failureTrend = [];
  const latencyTrend = [];

  function pushTrend(arr, value) {
    arr.push(Number(value || 0));
    while (arr.length > 24) arr.shift();
  }

  function renderDiagnostics() {
    const diag = window.JanusUI.getDiagnostics();
    const apiNode = document.getElementById('diag-last-api');
    const reqNode = document.getElementById('diag-request-id');
    const latencyNode = document.getElementById('diag-latency');
    const updatedNode = document.getElementById('diag-updated-at');
    if (apiNode) apiNode.textContent = diag.lastApi;
    if (reqNode) reqNode.textContent = diag.requestId;
    if (latencyNode) latencyNode.textContent = diag.latencyMs == null ? 'n/a' : `${diag.latencyMs}ms`;
    if (updatedNode) updatedNode.textContent = diag.updatedAt ? new Date(diag.updatedAt).toLocaleString() : 'n/a';
  }

  async function loadMetrics() {
    setLoadingState(executionNode, true);
    setLoadingState(taskNode, true);
    try {
      const payload = await api('/api/metrics/overview');
      const executionCounts = payload.execution_counts || [];
      const taskCounts = payload.task_counts || [];
      if (executionNode) {
        executionNode.textContent = executionCounts.map((item) => `${item.status}: ${item.count}`).join('\n') || 'No execution data';
      }
      if (taskNode) {
        taskNode.textContent = taskCounts.map((item) => `${item.status}: ${item.count}`).join('\n') || 'No task data';
      }
      if (avgNode) {
        avgNode.textContent = String(payload.avg_task_duration_seconds ?? 'n/a');
      }

      const throughput = executionCounts.reduce((acc, item) => acc + Number(item.count || 0), 0);
      const failed = executionCounts
        .filter((item) => ['FAILED', 'TIMED_OUT', 'CANCELLED'].includes(String(item.status || '').toUpperCase()))
        .reduce((acc, item) => acc + Number(item.count || 0), 0);
      const retryPressure = taskCounts
        .filter((item) => ['FAILED', 'FAILED_PERMANENTLY', 'READY'].includes(String(item.status || '').toUpperCase()))
        .reduce((acc, item) => acc + Number(item.count || 0), 0);

      pushTrend(throughputTrend, throughput);
      pushTrend(failureTrend, failed + retryPressure);
      pushTrend(latencyTrend, Number(payload.avg_task_duration_seconds || 0));

      renderLineChart(document.getElementById('obs-trend-throughput'), throughputTrend, 'var(--color-info)');
      renderLineChart(document.getElementById('obs-trend-failure'), failureTrend, 'var(--color-danger)');
      renderLineChart(document.getElementById('obs-trend-latency'), latencyTrend, 'var(--color-warning)');
    } catch (error) {
      if (executionNode) executionNode.textContent = error.message;
      if (taskNode) taskNode.textContent = error.message;
      if (avgNode) avgNode.textContent = 'n/a';
    } finally {
      setLoadingState(executionNode, false);
      setLoadingState(taskNode, false);
    }
  }

  async function loadHealth() {
    if (!healthTable) return;

    setLoadingState(healthTable, true);
    try {
      const health = await api('/api/health/services');
      const mapping = [
        ['Web', health.web],
        ['API', health.api],
        ['DB', health.db],
        ['FastAPI', health.fastapi],
        ['Scheduler', health.scheduler],
        ['Worker', health.worker],
      ];

      const body = healthTable.querySelector('tbody');
      if (!body) return;
      body.innerHTML = '';

      mapping.forEach(([name, value]) => {
        const tr = document.createElement('tr');
        const ok = value && value.ok === true;
        const unknown = value && value.ok === null;
        const statusText = unknown ? 'Unknown' : ok ? 'Healthy' : 'Degraded';
        const statusClass = unknown ? 'status-muted' : ok ? 'status-success' : 'status-danger';
        tr.innerHTML = `<td>${name}</td><td><span class="status-pill ${statusClass}">${statusText}</span></td><td>${JSON.stringify(value?.details || {})}</td>`;
        body.appendChild(tr);
      });
    } catch (error) {
      const body = healthTable.querySelector('tbody');
      if (!body) return;
      body.innerHTML = `<tr><td colspan="3">${error.message}</td></tr>`;
    } finally {
      setLoadingState(healthTable, false);
    }
  }

  async function refreshAll() {
    await Promise.all([loadMetrics(), loadHealth()]);
    renderDiagnostics();
  }

  if (refreshBtn) {
    refreshBtn.addEventListener('click', withBusyIndicator(refreshBtn, 'observability-poll-indicator', 'Refreshing...', 'Updated', refreshAll));
  }

  document.addEventListener('janus:diagnostics', renderDiagnostics);
  refreshAll();
  setInterval(() => {
    setPollIndicator('observability-poll-indicator', 'Polling...', 'busy');
    refreshAll().then(() => {
      setPollIndicator('observability-poll-indicator', `Updated ${formatNowTime()}`, 'idle');
    }).catch(() => {
      setPollIndicator('observability-poll-indicator', 'Polling error', 'error');
    });
  }, 15000);
}

function bindExecutionsWorkspace() {
  const workspace = document.getElementById('executions-workspace');
  if (!workspace) return;

  const table = document.getElementById('executions-list-table');
  const statusFilter = document.getElementById('executions-status-filter');
  const timeFilter = document.getElementById('executions-time-filter');
  const sortSelect = document.getElementById('executions-sort');
  const refreshBtn = document.getElementById('executions-refresh-btn');
  const exportBtn = document.getElementById('executions-export-csv-btn');
  if (!table || !statusFilter || !timeFilter || !sortSelect) return;

  const tbody = table.querySelector('tbody');
  if (!tbody) return;

  let rows = Array.from(tbody.querySelectorAll('tr'));

  function withinRange(startedAtRaw, rangeMode) {
    if (!startedAtRaw || rangeMode === 'all') return true;

    const startedAt = new Date(startedAtRaw.replace(' ', 'T') + 'Z');
    if (Number.isNaN(startedAt.getTime())) return true;

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

      if (sortMode === 'oldest') return idA - idB;
      return idB - idA;
    });

    filtered.forEach((row) => tbody.appendChild(row));
    applyVirtualizedRows(tbody, filtered, 'executions');
    setEmptyState('executions-empty-state', filtered.length === 0);
  }

  async function refreshExecutions() {
    setLoadingState(table, true);
    const query = new URLSearchParams({
      page: '1',
      page_size: '500',
      sort: 'id_desc',
    });
    const list = await api(`/api/executions?${query.toString()}`);
    tbody.innerHTML = '';

    list.forEach((execution) => {
      const tr = document.createElement('tr');
      tr.dataset.executionId = String(execution.id || 0);
      tr.dataset.status = String(execution.status || '');
      tr.dataset.startedAt = String(execution.started_at || '');
      tr.innerHTML = `
        <td>${execution.id}</td>
        <td>${execution.workflow_name}</td>
        <td>${execution.workflow_version}</td>
        <td>${statusPillMarkup(execution.status)}</td>
        <td>${execution.started_at || ''}</td>
        <td>${execution.finished_at || ''}</td>
        <td><a href="/executions/${execution.id}">View</a></td>
      `;
      tbody.appendChild(tr);
    });

    rows = Array.from(tbody.querySelectorAll('tr'));
    bindExecutionCancelButtons();
    applyFilters();
    setLoadingState(table, false);
  }

  statusFilter.addEventListener('change', applyFilters);
  timeFilter.addEventListener('change', applyFilters);
  sortSelect.addEventListener('change', applyFilters);

  if (refreshBtn) {
    refreshBtn.addEventListener('click', withBusyIndicator(refreshBtn, 'executions-poll-indicator', 'Refreshing...', 'Updated', refreshExecutions));
  }

  if (exportBtn) {
    exportBtn.addEventListener('click', () => {
      const visible = rows.filter((row) => row.style.display !== 'none');
      const csvRows = visible.map((row) => {
        const cells = row.querySelectorAll('td');
        return [
          cells[0]?.textContent?.trim() || '',
          cells[1]?.textContent?.trim() || '',
          cells[2]?.textContent?.trim() || '',
          cells[3]?.textContent?.trim() || '',
          cells[4]?.textContent?.trim() || '',
          cells[5]?.textContent?.trim() || '',
        ];
      });
      downloadCsv('executions.csv', ['id', 'workflow', 'version', 'status', 'started_at', 'finished_at'], csvRows);
    });
  }

  applyFilters();
  setInterval(() => {
    if (!document.hidden) {
      setPollIndicator('executions-poll-indicator', 'Polling...', 'busy');
      refreshExecutions().then(() => {
        setPollIndicator('executions-poll-indicator', `Updated ${formatNowTime()}`);
      }).catch(() => {
        setPollIndicator('executions-poll-indicator', 'Polling error', 'error');
      });
    }
  }, 20000);
}

function bindExecutionCancelButtons() {
  document.querySelectorAll('.cancel-execution-btn').forEach((button) => {
    button.addEventListener('click', async () => {
      const ok = await confirmAction('Cancel this execution? Impact: queued and running tasks will be stopped and marked as skipped.');
      if (!ok) return;

      const executionId = Number(button.dataset.executionId);
      try {
        await api(`/api/executions/${executionId}/cancel`, 'POST');
        window.JanusUI.showToast('Execution cancelled', 'success');

        const row = button.closest('tr');
        if (row) {
          row.dataset.status = 'CANCELLED';
          const statusCell = row.children[3];
          if (statusCell) {
            statusCell.innerHTML = statusPillMarkup('CANCELLED');
          }
          button.remove();
        }

        const executionStatus = document.getElementById('execution-status');
        if (executionStatus) {
          executionStatus.className = `status-pill status-${statusClassFromValue('CANCELLED')}`;
          executionStatus.textContent = 'CANCELLED';
        }
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
  const exportLogsBtn = document.getElementById('task-log-export-csv-btn');
  const exportTasksBtn = document.getElementById('execution-export-tasks-csv-btn');
  const refreshBtn = document.getElementById('execution-refresh-btn');
  const timeline = document.getElementById('execution-timeline');
  const dagPanel = document.getElementById('execution-dag-panel');
  const taskLogState = document.getElementById('task-log-state');

  let loadedLogs = [];
  let visibleLogCount = 40;

  function renderLogsIncremental(lines) {
    if (!logViewer) return;
    logViewer.textContent = '';
    let cursor = 0;
    const chunkSize = 150;

    function tick() {
      const slice = lines.slice(cursor, cursor + chunkSize);
      if (slice.length > 0) {
        logViewer.textContent += (cursor === 0 ? '' : '\n') + slice.join('\n');
        cursor += slice.length;
      }
      if (cursor < lines.length) {
        window.requestAnimationFrame(tick);
      }
    }

    window.requestAnimationFrame(tick);
  }

  function renderLogs() {
    if (!logViewer) return;
    const selectedLevel = logLevelFilter ? logLevelFilter.value : 'ALL';
    const filtered = loadedLogs.filter((log) => selectedLevel === 'ALL' || String(log.level) === selectedLevel);
    const visible = filtered.slice(0, visibleLogCount);

    const lines = visible.map((log) => `${log.created_at} [${log.level}] ${log.message}`);
    if (lines.length === 0) {
      logViewer.textContent = 'No logs for this filter yet.';
      if (taskLogState) taskLogState.textContent = 'No log rows for current level filter.';
    } else {
      renderLogsIncremental(lines);
      if (taskLogState) taskLogState.textContent = `Showing ${visible.length} of ${filtered.length} logs`;
    }

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

      if (startedAt) events.push({ at: startedAt, text: `${nodeKey} started` });
      if (finishedAt) events.push({ at: finishedAt, text: `${nodeKey} finished (${status})` });
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

  async function refreshExecutionWorkspace() {
    setPollIndicator('execution-poll-indicator', 'Live updates: refreshing...', 'busy');
    await refreshExecutionTasks();
    buildTimeline();
    setPollIndicator('execution-poll-indicator', `Live updates: refreshed ${formatNowTime()}`);
  }

  if (executionWorkspace) {
    buildTimeline();
    bindDagNodeSelection();
    setEmptyState('execution-tasks-empty-state', document.querySelectorAll('#execution-tasks-table tbody tr').length === 0);
  }

  if (refreshBtn) {
    refreshBtn.addEventListener('click', refreshExecutionWorkspace);
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

  if (exportLogsBtn) {
    exportLogsBtn.addEventListener('click', () => {
      const selectedLevel = logLevelFilter ? logLevelFilter.value : 'ALL';
      const filtered = loadedLogs.filter((log) => selectedLevel === 'ALL' || String(log.level) === selectedLevel);
      const rows = filtered.map((log) => [log.created_at, log.level, log.message]);
      downloadCsv('task-logs.csv', ['created_at', 'level', 'message'], rows);
    });
  }

  if (exportTasksBtn) {
    exportTasksBtn.addEventListener('click', () => {
      const rows = Array.from(document.querySelectorAll('#execution-tasks-table tbody tr')).map((row) => {
        const cells = row.querySelectorAll('td');
        return [
          cells[0]?.textContent?.trim() || '',
          cells[1]?.textContent?.trim() || '',
          cells[2]?.textContent?.trim() || '',
          cells[3]?.textContent?.trim() || '',
          cells[4]?.textContent?.trim() || '',
          cells[5]?.textContent?.trim() || '',
        ];
      });
      downloadCsv('execution-tasks.csv', ['task_id', 'node_key', 'type', 'status', 'attempts', 'error'], rows);
    });
  }

  document.querySelectorAll('.task-retry-btn').forEach((button) => {
    button.addEventListener('click', async () => {
      const ok = await confirmAction('Retry this task? Impact: it will re-enter the queue and may execute external side effects again.');
      if (!ok) return;
      try {
        await api(`/api/tasks/${Number(button.dataset.taskId)}/retry`, 'POST');
        window.JanusUI.showToast('Task queued for retry', 'success');
        refreshExecutionWorkspace();
      } catch (error) {
        window.JanusUI.showToast(error.message, 'error');
      }
    });
  });

  document.querySelectorAll('.task-skip-btn').forEach((button) => {
    button.addEventListener('click', async () => {
      const ok = await confirmAction('Skip this task? Impact: downstream tasks may still run with missing expected input.');
      if (!ok) return;
      try {
        await api(`/api/tasks/${Number(button.dataset.taskId)}/skip`, 'POST', { reason: 'Skipped manually' });
        window.JanusUI.showToast('Task skipped', 'success');
        refreshExecutionWorkspace();
      } catch (error) {
        window.JanusUI.showToast(error.message, 'error');
      }
    });
  });

  document.querySelectorAll('.task-complete-btn').forEach((button) => {
    button.addEventListener('click', async () => {
      const ok = await confirmAction('Force-complete this task? Impact: this bypasses normal execution and records a manual override.');
      if (!ok) return;
      try {
        const output = { manual: true, source: 'ui' };
        await api(`/api/tasks/${Number(button.dataset.taskId)}/complete`, 'POST', { output });
        window.JanusUI.showToast('Task manually completed', 'success');
        refreshExecutionWorkspace();
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
        if (taskLogState) taskLogState.textContent = `Loading task #${taskId} logs...`;
        setLoadingState(logViewer, true);
        loadedLogs = await api(`/api/tasks/${taskId}/logs`);
        visibleLogCount = 40;
        renderLogs();
      } catch (error) {
        logViewer.textContent = error.message;
        if (taskLogState) taskLogState.textContent = 'Failed to load logs.';
      } finally {
        setLoadingState(logViewer, false);
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
  const exportBtn = document.getElementById('dead-letter-export-csv-btn');
  const detailTitle = document.getElementById('dead-letter-detail-title');
  const detailViewer = document.getElementById('dead-letter-detail-viewer');
  const noteInput = document.getElementById('dead-letter-note');
  const noteBtn = document.getElementById('dead-letter-note-btn');
  if (!table || !detailTitle || !detailViewer || !noteInput) return;

  let selectedTaskId = null;

  function selectedRows() {
    return Array.from(table.querySelectorAll('tbody tr')).filter((row) => {
      const checkbox = row.querySelector('.dead-letter-select');
      return checkbox && checkbox.checked;
    });
  }

  function bindRowActions() {
    const rows = Array.from(table.querySelectorAll('tbody tr'));
    applyVirtualizedRows(table.querySelector('tbody'), rows, 'deadletters');
    setEmptyState('dead-letter-empty-state', rows.length === 0);

    rows.forEach((row) => {
      row.querySelector('.dead-letter-view-btn')?.addEventListener('click', () => {
        selectedTaskId = Number(row.dataset.taskId || 0);
        const error = String(row.dataset.error || '');
        detailTitle.textContent = `Dead Letter Task #${selectedTaskId}`;
        detailViewer.textContent = JSON.stringify({
          task_id: selectedTaskId,
          execution_id: Number(row.dataset.executionId || 0),
          last_error: error,
        }, null, 2);
      });

      row.querySelector('.dead-letter-retry-btn')?.addEventListener('click', async () => {
        const taskId = Number(row.dataset.taskId || 0);
        const ok = await confirmAction(`Retry task #${taskId}? Impact: this may re-run failed operations against external systems.`);
        if (!ok) return;
        try {
          await api(`/api/tasks/${taskId}/retry`, 'POST');
          window.JanusUI.showToast(`Task #${taskId} queued for retry`, 'success');
          row.remove();
          setEmptyState('dead-letter-empty-state', table.querySelectorAll('tbody tr').length === 0);
        } catch (error) {
          window.JanusUI.showToast(error.message, 'error');
        }
      });
    });
  }

  async function refreshDeadLetters() {
    setLoadingState(table, true);
    const rows = await api('/api/dead-letters');
    const tbody = table.querySelector('tbody');
    if (!tbody) return;
    tbody.innerHTML = '';
    rows.forEach((task) => {
      const tr = document.createElement('tr');
      tr.dataset.taskId = String(task.id || 0);
      tr.dataset.executionId = String(task.execution_id || 0);
      tr.dataset.error = String(task.last_error || '');
      const canOperate = Boolean(document.getElementById('dead-letter-bulk-retry-btn'));
      tr.innerHTML = `
        <td>${canOperate ? '<input type="checkbox" class="dead-letter-select" />' : ''}</td>
        <td>${task.id}</td>
        <td><a href="/executions/${task.execution_id}">#${task.execution_id}</a></td>
        <td>${task.workflow_name} v${task.workflow_version}</td>
        <td>${task.node_key}</td>
        <td><span class="status-pill status-failed-permanently">FAILED_PERMANENTLY</span></td>
        <td>${task.attempts}/${task.max_attempts}</td>
        <td>${task.last_error || ''}</td>
        <td>
          <button class="dead-letter-view-btn" type="button" data-task-id="${task.id}">View</button>
          ${canOperate ? `<button class="dead-letter-retry-btn" type="button" data-task-id="${task.id}">Retry</button>` : ''}
        </td>
      `;
      tbody.appendChild(tr);
    });
    bindRowActions();
    setLoadingState(table, false);
  }

  bindRowActions();

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

      const ok = await confirmAction(`Retry ${rows.length} selected task(s)? Impact: each selected task may re-trigger external side effects.`);
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
      setEmptyState('dead-letter-empty-state', table.querySelectorAll('tbody tr').length === 0);
    });
  }

  if (refreshBtn) {
    refreshBtn.addEventListener('click', withBusyIndicator(refreshBtn, 'dead-letter-poll-indicator', 'Refreshing...', 'Updated', refreshDeadLetters));
  }

  if (exportBtn) {
    exportBtn.addEventListener('click', () => {
      const rows = Array.from(table.querySelectorAll('tbody tr')).map((row) => {
        const tds = row.querySelectorAll('td');
        return [
          tds[1]?.textContent?.trim() || '',
          tds[2]?.textContent?.trim() || '',
          tds[3]?.textContent?.trim() || '',
          tds[4]?.textContent?.trim() || '',
          tds[5]?.textContent?.trim() || '',
          tds[6]?.textContent?.trim() || '',
          tds[7]?.textContent?.trim() || '',
        ];
      });
      downloadCsv('dead-letters.csv', ['task_id', 'execution', 'workflow', 'node', 'status', 'attempts', 'error'], rows);
    });
  }

  if (noteBtn) {
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

  setInterval(() => {
    if (!document.hidden) {
      refreshDeadLetters().then(() => {
        setPollIndicator('dead-letter-poll-indicator', `Updated ${formatNowTime()}`);
      }).catch(() => {
        setPollIndicator('dead-letter-poll-indicator', 'Polling error', 'error');
      });
    }
  }, 25000);
}

async function refreshExecutionTasks() {
  const table = document.getElementById('execution-tasks-table');
  if (!table) return;

  const executionId = Number(table.dataset.executionId);
  try {
    const execution = await api(`/api/executions/${executionId}`);
    const status = document.getElementById('execution-status');
    if (status) {
      status.className = `status-pill status-${statusClassFromValue(execution.status)}`;
      status.textContent = String(execution.status || 'UNKNOWN');
    }

    execution.tasks.forEach((task) => {
      const row = table.querySelector(`tr[data-task-id="${task.id}"]`);
      if (!row) return;
      const statusCell = row.querySelector('.task-status');
      const errorCell = row.querySelector('.task-error');
      if (statusCell) statusCell.innerHTML = statusPillMarkup(task.status);
      if (errorCell) errorCell.textContent = task.last_error || '';
    });
  } catch (_) {
    // Polling is best effort and should not spam alerts.
  }
}

function bindKeyboardShortcuts() {
  document.addEventListener('keydown', (event) => {
    const target = event.target;
    const tag = target && target.tagName ? target.tagName.toLowerCase() : '';
    if (tag === 'input' || tag === 'textarea' || tag === 'select' || event.ctrlKey || event.metaKey) {
      return;
    }

    if (event.key === '/') {
      const search = document.getElementById('workflow-search');
      if (search) {
        event.preventDefault();
        search.focus();
      }
    }

    if (event.key.toLowerCase() === 'r') {
      const refreshId = [
        'workflow-refresh-btn',
        'executions-refresh-btn',
        'execution-refresh-btn',
        'dead-letter-refresh-btn',
        'observability-refresh-btn',
      ].find((id) => document.getElementById(id));
      if (refreshId) {
        document.getElementById(refreshId).click();
      }
    }

    if (event.key.toLowerCase() === 'e') {
      const exportId = [
        'workflow-export-csv-btn',
        'executions-export-csv-btn',
        'execution-export-tasks-csv-btn',
        'dead-letter-export-csv-btn',
      ].find((id) => document.getElementById(id));
      if (exportId) {
        document.getElementById(exportId).click();
      }
    }

    if (event.key === '?') {
      window.JanusUI.showToast('Shortcuts: / focus search, R refresh, E export CSV', 'info');
    }
  });
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
  bindKeyboardShortcuts();

  if (document.getElementById('execution-tasks-table')) {
    setInterval(() => {
      if (!document.hidden) {
        setPollIndicator('execution-poll-indicator', 'Live updates: polling...', 'busy');
        refreshExecutionTasks().then(() => {
          setPollIndicator('execution-poll-indicator', `Live updates: updated ${formatNowTime()}`);
        }).catch(() => {
          setPollIndicator('execution-poll-indicator', 'Live updates: degraded', 'error');
        });
      }
    }, 3000);
  }
}

document.addEventListener('DOMContentLoaded', init);
