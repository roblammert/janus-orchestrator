async function api(url, method = 'GET', body = null) {
  const response = await fetch(url, {
    method,
    headers: {
      'Content-Type': 'application/json'
    },
    body: body ? JSON.stringify(body) : null
  });

  const text = await response.text();
  let data = {};
  try {
    data = text ? JSON.parse(text) : {};
  } catch (_) {
    data = { raw: text };
  }

  if (!response.ok) {
    throw new Error(data.error || `Request failed (${response.status})`);
  }

  return data;
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
      window.location.reload();
    } catch (error) {
      alert(error.message);
    }
  });
}

function bindStartExecution() {
  document.querySelectorAll('.start-execution-btn').forEach((button) => {
    button.addEventListener('click', async () => {
      const workflowId = Number(button.dataset.workflowId);
      const inputText = prompt('Execution input JSON', '{}');
      if (inputText === null) return;

      try {
        const input = JSON.parse(inputText);
        const result = await api('/api/executions', 'POST', {
          workflow_id: workflowId,
          input
        });
        window.location.href = `/executions/${result.execution_id}`;
      } catch (error) {
        alert(error.message);
      }
    });
  });
}

function bindExecutionCancelButtons() {
  document.querySelectorAll('.cancel-execution-btn').forEach((button) => {
    button.addEventListener('click', async () => {
      const executionId = Number(button.dataset.executionId);
      try {
        await api(`/api/executions/${executionId}/cancel`, 'POST');
        window.location.reload();
      } catch (error) {
        alert(error.message);
      }
    });
  });
}

function bindTaskButtons() {
  document.querySelectorAll('.task-retry-btn').forEach((button) => {
    button.addEventListener('click', async () => {
      try {
        await api(`/api/tasks/${Number(button.dataset.taskId)}/retry`, 'POST');
        window.location.reload();
      } catch (error) {
        alert(error.message);
      }
    });
  });

  document.querySelectorAll('.task-skip-btn').forEach((button) => {
    button.addEventListener('click', async () => {
      const reason = prompt('Skip reason', 'Skipped manually');
      if (reason === null) return;
      try {
        await api(`/api/tasks/${Number(button.dataset.taskId)}/skip`, 'POST', { reason });
        window.location.reload();
      } catch (error) {
        alert(error.message);
      }
    });
  });

  document.querySelectorAll('.task-complete-btn').forEach((button) => {
    button.addEventListener('click', async () => {
      const outputRaw = prompt('Manual output JSON', '{"manual":true}');
      if (outputRaw === null) return;
      try {
        const output = JSON.parse(outputRaw);
        await api(`/api/tasks/${Number(button.dataset.taskId)}/complete`, 'POST', { output });
        window.location.reload();
      } catch (error) {
        alert(error.message);
      }
    });
  });

  document.querySelectorAll('.task-logs-btn').forEach((button) => {
    button.addEventListener('click', async () => {
      const taskId = Number(button.dataset.taskId);
      const viewer = document.getElementById('task-log-viewer');
      if (!viewer) return;

      try {
        const logs = await api(`/api/tasks/${taskId}/logs`);
        viewer.textContent = logs
          .map((log) => `${log.created_at} [${log.level}] ${log.message}`)
          .join('\n');
      } catch (error) {
        viewer.textContent = error.message;
      }
    });
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
  bindCreateWorkflow();
  bindStartExecution();
  bindExecutionCancelButtons();
  bindTaskButtons();

  if (document.getElementById('execution-tasks-table')) {
    setInterval(refreshExecutionTasks, 3000);
  }
}

document.addEventListener('DOMContentLoaded', init);
