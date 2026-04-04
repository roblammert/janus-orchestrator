<section id="workflow-builder-workspace">
    <?php $role = strtoupper((string)($user['role'] ?? 'VIEWER')); ?>
    <?php $canPublish = $role === 'ADMIN'; ?>
    <header class="page-heading">
        <h2>Workflow Builder</h2>
        <p>Design workflow graphs visually, validate structure, and publish immutable versions without manual JSON editing.</p>
    </header>

    <div class="builder-toolbar">
        <label>
            Workflow Name
            <input id="wb-workflow-name" type="text" placeholder="invoice_processing" />
        </label>
        <label>
            Description
            <input id="wb-workflow-description" type="text" placeholder="Process invoice intake and posting" />
        </label>
        <label>
            Version
            <input id="wb-workflow-version" type="number" min="1" step="1" value="1" />
        </label>
        <label>
            Timeout (seconds)
            <input id="wb-workflow-timeout" type="number" min="1" step="1" value="600" />
        </label>
        <div class="builder-toolbar-actions">
            <button id="wb-validate-btn" type="button">Validate</button>
            <button id="wb-auto-layout-btn" type="button">Auto Layout</button>
            <button id="wb-export-json-btn" type="button">Export JSON</button>
            <button id="wb-import-json-btn" type="button">Import JSON</button>
            <?php if ($canPublish): ?>
                <button id="wb-publish-btn" type="button">Publish Version</button>
            <?php endif; ?>
        </div>
    </div>

    <div class="builder-load-existing">
        <label>
            Existing Workflow
            <select id="wb-existing-workflow"></select>
        </label>
        <label>
            Version
            <select id="wb-existing-version"></select>
        </label>
        <div class="builder-toolbar-actions">
            <button id="wb-load-existing-btn" type="button">Load Into Builder</button>
            <span id="wb-editing-note" class="poll-indicator">New workflow draft</span>
        </div>
    </div>

    <div class="workflow-builder-grid">
        <aside class="workflow-builder-card builder-sidebar-left">
            <h3>Node Library</h3>
            <p class="builder-muted">Click a template to add a node. Use quick branch actions to add Then/Else paths in one click.</p>
            <div class="builder-node-library">
                <button class="wb-add-node-btn" data-node-preset="http_request" type="button">+ HTTP Request</button>
                <button class="wb-add-node-btn" data-node-preset="webhook_call" type="button">+ Webhook Call</button>
                <button class="wb-add-node-btn" data-node-preset="graphql_query" type="button">+ GraphQL Query</button>
                <button class="wb-add-node-btn" data-node-preset="sql_query" type="button">+ SQL Query</button>
                <button class="wb-add-node-btn" data-node-preset="python_script" type="button">+ Python Script</button>
                <button class="wb-add-node-btn" data-node-preset="shell_command" type="button">+ Shell Command</button>
                <button class="wb-add-node-btn" data-node-preset="file_writer" type="button">+ File Writer</button>
                <button class="wb-add-node-btn" data-node-preset="delay_timer" type="button">+ Delay Timer</button>
                <button class="wb-add-node-btn" data-node-preset="approval_gate" type="button">+ Approval Gate</button>
                <button class="wb-add-node-btn" data-node-preset="json_transform" type="button">+ JSON Transform</button>
                <button class="wb-add-node-btn" data-node-preset="email_notification" type="button">+ Email Notification</button>
                <button class="wb-add-node-btn" data-node-preset="slack_notification" type="button">+ Slack Notification</button>
            </div>

            <h4>Quick Conditions</h4>
            <p class="builder-muted">Build a condition statement once, then add Then/Else branches with one click.</p>
            <div class="builder-connect-row">
                <label>
                    Source Node
                    <select id="wb-branch-source"></select>
                </label>
                <label>
                    Branch Node Template
                    <select id="wb-branch-template">
                        <option value="http_request">HTTP Request</option>
                        <option value="webhook_call">Webhook Call</option>
                        <option value="graphql_query">GraphQL Query</option>
                        <option value="sql_query">SQL Query</option>
                        <option value="python_script">Python Script</option>
                        <option value="shell_command">Shell Command</option>
                        <option value="file_writer">File Writer</option>
                        <option value="delay_timer">Delay Timer</option>
                        <option value="approval_gate">Approval Gate</option>
                        <option value="json_transform">JSON Transform</option>
                        <option value="email_notification">Email Notification</option>
                        <option value="slack_notification">Slack Notification</option>
                    </select>
                </label>
            </div>
            <div class="builder-condition-editor">
                <label>
                    Condition Template
                    <select id="wb-condition-template">
                        <option value="custom">Custom condition</option>
                        <option value="approved_true">Approved is true</option>
                        <option value="approved_false">Approved is false</option>
                        <option value="status_success">Status equals success</option>
                        <option value="status_failed">Status equals failed</option>
                        <option value="amount_gt_1000">Amount greater than 1000</option>
                        <option value="amount_lte_1000">Amount less/equal 1000</option>
                        <option value="has_error">Error message exists</option>
                        <option value="result_empty">Result is empty</option>
                    </select>
                </label>
                <div class="builder-connect-row">
                    <label>
                        When Output Field
                        <input id="wb-condition-left-path" type="text" placeholder="result.approved" />
                    </label>
                    <label>
                        Operator
                        <select id="wb-condition-operator">
                            <option value="truthy">is true</option>
                            <option value="equals">equals</option>
                            <option value="not_equals">does not equal</option>
                            <option value="contains">contains</option>
                            <option value="gt">is greater than</option>
                            <option value="gte">is greater or equal</option>
                            <option value="lt">is less than</option>
                            <option value="lte">is less or equal</option>
                            <option value="exists">exists</option>
                            <option value="empty">is empty</option>
                        </select>
                    </label>
                </div>
                <label id="wb-condition-value-wrap">
                    Compare Value
                    <input id="wb-condition-right-value" type="text" placeholder="true | 100 | approved" />
                </label>
                <p class="builder-muted">Value parsing supports booleans, numbers, null, JSON, or plain text.</p>
                <div id="wb-condition-preview" class="builder-validation">Condition preview will appear here.</div>
            </div>
            <div class="builder-toolbar-actions">
                <button id="wb-add-then-branch-btn" type="button">+ Add Then Branch</button>
                <button id="wb-add-else-branch-btn" type="button">+ Add Else Branch</button>
            </div>

            <h4>Connections</h4>
            <p class="builder-muted">Advanced edge editor for manual graph tuning.</p>
            <div class="builder-connect-row">
                <label>
                    From
                    <select id="wb-connect-from"></select>
                </label>
                <label>
                    To
                    <select id="wb-connect-to"></select>
                </label>
            </div>
            <div class="builder-connect-row">
                <label>
                    Branch Mode
                    <select id="wb-connect-condition-mode">
                        <option value="always">Always</option>
                        <option value="if_true">If True</option>
                        <option value="if_false">If False (Else)</option>
                    </select>
                </label>
                <label>
                    Statement Source
                    <input type="text" value="Uses the condition statement above" readonly />
                </label>
            </div>
            <button id="wb-connect-btn" type="button">Add Edge</button>
            <div class="builder-edge-edit-row">
                <span id="wb-edge-edit-state" class="builder-muted">Adding new edge</span>
                <button id="wb-edge-clear-selection-btn" type="button">Clear Selection</button>
            </div>

            <h4>Nodes</h4>
            <div class="table-scroll">
                <table id="wb-node-table">
                    <thead>
                    <tr>
                        <th>Key</th>
                        <th>Type</th>
                        <th>Action</th>
                    </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>

            <h4>Edges</h4>
            <div class="table-scroll">
                <table id="wb-edge-table">
                    <thead>
                    <tr>
                        <th>From</th>
                        <th>To</th>
                        <th>Condition</th>
                        <th>Action</th>
                    </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
        </aside>

        <main class="workflow-builder-card builder-canvas-panel">
            <div class="builder-canvas-header">
                <h3>Graph Canvas</h3>
                <span id="wb-canvas-state" class="poll-indicator">Ready</span>
            </div>
            <p class="builder-muted">Drag nodes, click to select, and build directed dependencies.</p>

            <div id="wb-canvas" class="builder-canvas" tabindex="0" aria-label="Workflow graph canvas">
                <svg id="wb-edge-layer" class="builder-edge-layer" viewBox="0 0 1600 900" preserveAspectRatio="none">
                    <defs>
                        <marker id="wb-arrow" markerWidth="10" markerHeight="8" refX="9" refY="4" orient="auto" markerUnits="strokeWidth">
                            <path d="M0,0 L10,4 L0,8 Z" fill="var(--color-accent)"></path>
                        </marker>
                    </defs>
                </svg>
                <div id="wb-node-layer" class="builder-node-layer"></div>
            </div>
        </main>

        <aside class="workflow-builder-card builder-sidebar-right">
            <h3>Node Inspector</h3>
            <p id="wb-selected-node-label" class="builder-muted">Select a node to edit properties.</p>
            <div class="stack-form">
                <label>
                    Key
                    <input id="wb-node-key" type="text" />
                </label>
                <label>
                    Name
                    <input id="wb-node-name" type="text" />
                </label>
                <label>
                    Type
                    <select id="wb-node-type">
                        <option value="HTTP">HTTP</option>
                        <option value="SCRIPT">SCRIPT</option>
                        <option value="FILE_WRITER">FILE_WRITER</option>
                    </select>
                </label>
                <label>
                    Timeout (seconds)
                    <input id="wb-node-timeout" type="number" min="1" step="1" value="30" />
                </label>
                <label>
                    Max Attempts
                    <input id="wb-node-attempts" type="number" min="1" step="1" value="3" />
                </label>
                <label>
                    Priority
                    <input id="wb-node-priority" type="number" min="1" step="1" value="100" />
                </label>
                <label>
                    Config JSON
                    <textarea id="wb-node-config" rows="8">{}</textarea>
                </label>
            </div>

            <h4>Validation</h4>
            <div id="wb-validation-output" class="builder-validation">No validation run yet.</div>

            <h4>Import JSON</h4>
            <textarea id="wb-import-json-input" rows="10" placeholder="Paste workflow JSON here"></textarea>
            <button id="wb-apply-import-btn" type="button">Apply Imported JSON</button>

            <h4>Generated Definition</h4>
            <pre id="wb-json-preview">{}</pre>
        </aside>
    </div>
</section>
