# Janus Orchestrator Phase 0 UI Specification

Date: 2026-04-03
Status: Approved for Phase 1 implementation
Scope: Design foundation and information architecture only (no code changes required)

## 1. Information Architecture (Authenticated App)
Primary navigation:
- Workflows
- Executions
- Dead Letters
- Observability
- Settings

Global shell regions:
- Sidebar: primary navigation + compact environment badge
- Header: page title, contextual actions, quick search, user menu
- Content: page body
- Footer: service status, request latency hint, app version

Secondary navigation model:
- Workflows: list, versions, definition view
- Executions: list, detail
- Dead Letters: queue list, task detail drawer
- Observability: overview cards, trend area (future)
- Settings: account, theme, system info

## 2. Design Tokens
Use CSS custom properties in a shared token file and map all component styles through these tokens.

Spacing scale:
- --space-1: 4px
- --space-2: 8px
- --space-3: 12px
- --space-4: 16px
- --space-5: 24px
- --space-6: 32px

Radius and elevation:
- --radius-sm: 6px
- --radius-md: 10px
- --radius-lg: 14px
- --shadow-1: subtle card shadow
- --shadow-2: elevated panel shadow

Typography:
- UI font stack: "IBM Plex Sans", "Segoe UI", sans-serif
- Mono font stack: "IBM Plex Mono", "Cascadia Code", monospace
- Scale:
  - --text-xs: 12px
  - --text-sm: 14px
  - --text-md: 16px
  - --text-lg: 20px
  - --text-xl: 24px

Semantic color roles (light and dark token sets):
- --color-bg
- --color-surface
- --color-border
- --color-text
- --color-text-muted
- --color-accent
- --color-success
- --color-warning
- --color-danger
- --color-info

Motion:
- --motion-fast: 120ms
- --motion-base: 180ms
- --motion-slow: 260ms
- Easing: cubic-bezier(0.2, 0.0, 0.2, 1)

## 3. Status System
Execution status badges:
- PENDING: neutral
- RUNNING: info
- COMPLETED: success
- FAILED: danger
- CANCELLED: muted
- TIMED_OUT: warning

Task status badges:
- PENDING: neutral
- READY: info-soft
- RUNNING: info
- COMPLETED: success
- FAILED: danger
- FAILED_PERMANENTLY: danger-strong
- SKIPPED: muted

Rules:
- Never encode state with color alone; include text label and icon.
- Keep one canonical status-to-style mapping in a shared JS/PHP config.

## 4. Interaction System
Core components:
- Buttons: primary, secondary, ghost, danger
- Form controls: input, select, textarea, checkbox, segmented filter
- Feedback: toast, inline form error, modal confirmation
- Data display: table, card, key-value list, badge
- Overlay: slide-over drawer for task/log detail

Behavior standards:
- Destructive actions require explicit confirmation modal.
- Long operations show non-blocking progress state.
- API errors render concise message + request identifier.
- Keyboard focus returns to triggering control when modal closes.

## 5. Responsive Breakpoints and Shell Behavior
Breakpoints:
- Mobile: <= 767px
- Tablet: 768px to 1199px
- Desktop: >= 1200px

Shell behavior:
- Mobile: off-canvas sidebar with overlay
- Tablet: collapsed icon sidebar by default, expandable
- Desktop: persistent full sidebar

Tables and dense views:
- Mobile: stacked row cards for dense tables
- Tablet/Desktop: full table with sticky header for long lists

## 6. Accessibility Standards
Minimum requirements:
- WCAG 2.1 AA contrast targets
- Visible focus ring on all interactive controls
- Keyboard-only operability for all actions
- Labels for all form fields and icon-only controls
- ARIA live region for toast notifications
- Skip link to main content

Testing baseline for each page:
- Tab order sanity check
- Focus trap in modal dialogs
- Screen reader label audit for buttons/inputs

## 7. Wireframes (Low-Fidelity)

### 7.1 Workflows List + Version Detail
```
+--------------------------------------------------------------+
| Header: Workflows                      [Search] [New Version]|
+--------------+-----------------------------------------------+
| Sidebar      | Filters: name/status                           |
| nav          +-----------------------------------------------+
|              | Table: workflow name | latest version | updated|
|              +-----------------------------------------------+
|              | Detail Panel: selected workflow versions       |
|              | - v3 (active)                                  |
|              | - v2                                            |
|              | Definition preview + validation summary         |
+--------------+-----------------------------------------------+
| Footer: API status | Worker status | App version              |
+--------------------------------------------------------------+
```

### 7.2 Executions List
```
+--------------------------------------------------------------+
| Header: Executions               [Status] [Time Range] [Sort]|
+--------------+-----------------------------------------------+
| Sidebar      | Summary cards: Running | Failed | Timed out    |
| nav          +-----------------------------------------------+
|              | Table: exec_id | workflow | status | started_at |
|              | Row click -> Execution detail                  |
+--------------+-----------------------------------------------+
| Footer status                                                |
+--------------------------------------------------------------+
```

### 7.3 Execution Detail + Task Controls
```
+--------------------------------------------------------------+
| Header: Execution #1234      [Cancel] [Refresh] [Open Logs]  |
+--------------+-----------------------------------------------+
| Sidebar      | DAG Panel (interactive): nodes + edge states   |
| nav          +-----------------------------------------------+
|              | Timeline: task events                          |
|              +-----------------------------------------------+
|              | Task Table: node | status | attempts | actions |
|              | actions: Retry / Skip / Manual Complete        |
|              +-----------------------------------------------+
|              | Slide-over drawer: task logs (filter by level) |
+--------------+-----------------------------------------------+
| Footer status                                                |
+--------------------------------------------------------------+
```

### 7.4 Dead Letters + Retry Workspace
```
+--------------------------------------------------------------+
| Header: Dead Letters                     [Filter] [Bulk Action]|
+--------------+-----------------------------------------------+
| Sidebar      | Table: task_id | node | error_code | failed_at |
| nav          +-----------------------------------------------+
|              | Detail panel: payload snapshot, attempts, logs |
|              | Actions: Retry eligible / Annotate / Export    |
+--------------+-----------------------------------------------+
| Footer status                                                |
+--------------------------------------------------------------+
```

### 7.5 Observability Dashboard
```
+--------------------------------------------------------------+
| Header: Observability                   [Window] [Auto-Refresh]|
+--------------+-----------------------------------------------+
| Sidebar      | Cards: executions by state                     |
| nav          | Cards: tasks by state                          |
|              | Card: avg task duration                        |
|              | Health row: web/api/scheduler/worker/db        |
|              | (Trend chart area reserved for Phase 5)        |
+--------------+-----------------------------------------------+
| Footer status                                                |
+--------------------------------------------------------------+
```

## 8. Non-Functional UX Constraints
- Browser target: latest evergreen browsers only.
- Theme target: light and dark both delivered in Phase 1.
- Realtime target: SSE in Phase 3 with polling fallback.
- Security note: HttpOnly session cookies with secure flag disabled in current non-SSL environment.

## 9. Phase 1 Entry Criteria
Phase 1 can start when:
- Shell layout contract is accepted.
- Token names are accepted.
- Status mapping is accepted.
- Interaction and accessibility standards are accepted.
- Wireframes are accepted as implementation baseline.

## 10. Phase 1 Exit Validation Anchors (Preview)
- Login/logout and guarded routes working.
- All migrated pages use shared shell.
- Theme toggle works in light/dark and persists.
- Footer status reports service availability.
- No page relies on browser prompt dialogs for critical actions.
