# Janus Orchestrator Web UI Development Plan

## Purpose
Build a professional, enterprise-style interface for Janus Orchestrator while keeping implementation practical for a personal project.

This plan uses the current Janus architecture and takes visual and structural inspiration from rjweb, especially the shared shell approach in AppShell.

Reference inspiration (read-only):
- /home/roblammert/git-repos/rjweb/app/Support/AppShell.php
- /home/roblammert/git-repos/rjweb/public/assets/css/polish/layout.css
- /home/roblammert/git-repos/rjweb/public/assets/css/polish/components.css

## Current Baseline
- Janus has a functional PHP UI and API endpoints but the UI is currently minimal.
- Frontend behavior is mostly prompt-driven and table-based.
- API supports core workflow/execution/task operations but lacks enterprise-level consistency for pagination, filtering, validation errors, and role-aware controls.
- No authenticated app shell, notification center, or operational UX patterns yet.

## Product UX Goals
- Shared shell with fixed sidebar, top header, and persistent footer status line.
- Clean navigation model: Workflows, Executions, Dead Letters, Observability, Settings.
- Rich operational views for running workflows and task interventions.
- Consistent interactive patterns: toasts, confirmations, status badges, filters, drawers/modals.
- Accessibility and keyboard support for all controls.
- Visual polish with design tokens and responsive behavior.

## Technical Goals
- Keep PHP + vanilla JS architecture.
- Keep pages server-rendered with progressive enhancement.
- Move repeated layout logic into shared PHP support class.
- Introduce consistent API response envelope and error model.
- Add frontend and API smoke tests for key user journeys.

## Confirmed Planning Decisions (2026-04-03)
- Authentication: local username/password now (same style as rjweb project).
- Theme: light + dark in Phase 1.
- Fonts: keep default IBM Plex pair and allow admins to select two additional pairs in Settings.
- Realtime: SSE targeted in Phase 3.
- DAG UX: interactive node-edge panel with drill-in from first execution detail release.
- RBAC timing: begin in Phase 4.
- Browser support: latest evergreen browsers only.
- Observability visuals: summary cards first, trend charts later.
- Security transport: HTTP only (no SSL) for this deployment.

## Phased Checklist

### Phase 0 - Design Foundation and Information Architecture
- [x] Define target IA for authenticated app areas.
- [x] Define visual tokens (spacing, typography, colors, elevations, states).
- [x] Define status system (task and execution badges, semantic colors, iconography).
- [x] Define interaction system (buttons, form controls, tables, cards, modal, toast).
- [x] Define responsive breakpoints and behavior for sidebar collapse.
- [x] Define accessibility standards (focus ring, contrast, keyboard navigation, labels).
- [x] Produce wireframes for 5 key pages:
  - Workflows list and version detail
  - Execution list
  - Execution detail and task controls
  - Dead letters and retry workspace
  - Metrics and health dashboard

Definition of done:
- UI specification documented in 01-Docs.
- Phase 0 artifact: 01-Docs/AD-Phase0-UI-Specification.md.
- No implementation yet, only agreed UX and component rules.

### Phase 1 - Shared AppShell and Frontend Architecture
- [x] Create shared shell renderer class in app/php support layer (Janus equivalent of AppShell).
- [x] Add shell layout with:
  - Sidebar navigation
  - Header with page title and environment context
  - Footer with status line and app version
- [x] Refactor existing views to consume shared shell.
- [x] Implement local auth/session baseline now:
  - Login/logout pages and guarded routes
  - Password hashing and verification
  - Initial admin bootstrap flow
- [x] Split CSS into layered files:
  - base.css (resets/tokens)
  - layout.css (shell, grid)
  - components.css (forms, tables, badges, cards, modals, toasts)
  - pages.css (page-specific overrides)
- [x] Add shared site.js for global UX utilities:
  - footer status updates
  - toast notifications
  - standardized API error rendering
- [x] Add theme strategy (light and dark, both complete in Phase 1).
- [x] Add font strategy with admin-selectable font pairs in Settings.

Definition of done:
- All existing pages render through one shell.
- Existing functionality unchanged.
- Visual consistency improved.
- Local login/session flow is active for UI/API access.
- Phase 1 artifacts delivered in code and docs.

### Phase 2 - Core UI Workflows (Professional Operations UX)
- [x] Rebuild Workflows page:
  - Search and sort
  - Version history panel
  - Definition viewer with formatting and validation summary
- [x] Rebuild Executions list:
  - Status filters
  - Time range filters
  - Sort by newest/running/error
- [x] Rebuild Execution detail:
  - Interactive DAG node-edge panel with drill-in
  - Task timeline and status badges
  - Inline task controls with guarded confirmations
  - Logs panel with level filter and lazy load
- [x] Add Dead Letter page:
  - FAILED_PERMANENTLY task queue
  - Bulk triage actions (view, retry where valid, annotate)
- [x] Add Observability page:
  - Execution counts by state
  - Task counts by state
  - Average task duration
  - Service health indicators

Definition of done:
- Operator can run and control workflows without prompts.
- No blocking operations depend on browser prompt dialogs.

### Phase 3 - API Hardening and Contract Completion (UI-Driven)
- [ ] Standardize response envelope and error format across all endpoints.
- [ ] Add request validation error structure with field-level details.
- [ ] Add pagination parameters and metadata for list endpoints.
- [ ] Add filtering and sorting support:
  - executions by status/time/workflow
  - tasks by status/node/execution
  - task logs by level/time cursor
- [ ] Add endpoint for dead-letter listing and details.
- [ ] Add endpoint for workflow DAG summary per execution (nodes/edges + runtime state).
- [ ] Add endpoint for execution event stream or poll-optimized deltas.
- [ ] Add endpoint for app shell metadata:
  - app version
  - environment label
  - capabilities flags

Definition of done:
- UI pages no longer rely on ad hoc shape assumptions.
- API contracts are stable and testable.

### Phase 4 - Security and Trustworthy Operations UX
- [ ] Harden and expand authentication/session model for UI/API access.
- [ ] Add CSRF protection for state-changing operations.
- [ ] Add role model:
  - Viewer (read-only)
  - Operator (execute/control)
  - Admin (workflow definition and secrets management)
- [ ] Redact sensitive fields in UI logs and API responses.
- [ ] Add audit trail views for manual task interventions.
- [ ] Add confirmation dialogs with explicit impact statements for destructive actions.

Definition of done:
- No anonymous write operations.
- Manual controls fully auditable.

### Phase 5 - Enterprise Polish and Reliability
- [ ] Add empty/loading/error/skeleton states across all pages.
- [ ] Add optimistic refresh controls and polling indicators.
- [ ] Add keyboard shortcuts for common operator actions.
- [ ] Add CSV export for executions/tasks/logs filters.
- [ ] Add client-side performance improvements:
  - table virtualization for large lists
  - incremental rendering for logs
- [ ] Add observability-friendly UI diagnostics panel (request IDs, API latency).
- [ ] Add trend charts after summary cards are stable:
  - Execution throughput trend
  - Failure/retry trend
  - Latency trend by task type

Definition of done:
- UI feels production-ready for day-to-day operations.
- Usability is strong for both small and large datasets.

### Phase 6 - Testing, Documentation, and Release Readiness
- [ ] Expand API integration smoke tests to include all new filter/pagination endpoints.
- [ ] Add browser-level smoke tests for:
  - start execution
  - task control actions
  - dead-letter triage
- [ ] Add accessibility checks (focus order, labels, contrast hotspots).
- [ ] Add visual regression checks for shell and key pages.
- [ ] Update runbook and development docs for local and docker modes.
- [ ] Add release checklist and rollback checklist.

Definition of done:
- Repeatable verification workflow exists.
- UI/API changes are safely releasable.

## API Gaps to Include in the Plan Backlog

### High priority
- Consistent response envelope and typed error codes.
- Pagination and filtering on list endpoints.
- Dead-letter specific endpoints.
- Execution delta endpoint for efficient live updates.
- Validation endpoint for workflow JSON before create.

### Medium priority
- DAG runtime summary endpoint.
- Bulk task operations endpoint with partial-success reporting.
- Per-task log cursor pagination.
- Metrics endpoint expansion for trend windows.

### Lower priority
- Saved filters and user preferences endpoint.
- Export endpoints.
- Feature-flag capability endpoint.

## Suggested UI Component Set (MVP)
- Shell: sidebar, header, footer status.
- Data display: status cards, table, badge, key-value list.
- Interaction: primary/secondary buttons, icon button, segmented filter, modal confirm, toast.
- Forms: text, select, textarea, JSON editor panel, validation summary.
- Operational widgets: log viewer, timeline row, retry panel, dead-letter action panel.

## Suggested Delivery Approach
- Work vertical slices by page, not by technology layer.
- For each slice:
  1. Contract the endpoint shape.
  2. Build UI shell-integrated page.
  3. Add smoke tests.
  4. Record docs updates.

## Professional-Grade Recommendations for a Personal Project
- Keep dependency footprint low, but invest in structure and naming consistency.
- Treat API contracts as first-class and versioned.
- Add request IDs to server logs and surface in UI error panels.
- Use deterministic state machine transitions and expose transition reasons.
- Add visible environment banner (local/dev/prod-like) to avoid operator mistakes.
- Prioritize excellent empty/loading/error states; this strongly affects perceived quality.
- Build and maintain a small design token system early to avoid visual drift.

## Remaining Decisions Needed Before Implementation
1. Auth bootstrap path (required):
How should the first admin account be created?
- Option A: one-time env-based bootstrap credentials (recommended)
- Option B: CLI bootstrap command
- Option C: setup wizard page (disabled after first run)
  **Decision:** Option A

2. Session security policy (required):
What are the session TTL and idle timeout targets?
- Recommended baseline: absolute TTL 12h, idle timeout 30m, secure+httponly cookies
  **Decision:** absolute TTL 12h, idle timeout 60m, httponly cookies, secure flag disabled (no SSL)

3. Password policy and reset scope (required):
Do you want only admin-created users for v1, or self-service password reset by email/token?
- Recommended baseline: admin-managed users only in v1
  **Decision:** admin-created users only for v1

4. SSE fallback behavior (required):
If SSE disconnects, should UI auto-fallback to polling?
- Recommended baseline: fallback polling every 5s with reconnect backoff
  **Decision:** yes, use fallback polling every 5s with reconnect backoff

5. Role boundary details (required):
Should Operator role be allowed to force-complete/force-fail tasks, or only retry/cancel?
- Recommended baseline: Operator can retry/cancel; force actions are Admin only
  **Decision:** Operator can retry/cancel; force actions are Admin only

6. Audit retention window (required):
How long should intervention audit logs be retained in DB?
- Recommended baseline: 90 days online, optional archival later
  **Decision:** 90 days online, optional archival later

7. Chart rollout trigger (planning suggestion):
What objective should trigger adding trend charts in Phase 5?
- Suggested trigger: after summary cards prove useful in 2 weeks of regular usage
  **Decision:** to be determined (defer this decision during implementation planning)