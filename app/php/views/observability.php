<section id="observability-workspace">
    <header class="page-heading">
        <h2>Observability</h2>
        <p>Operational overview of workflow throughput, task states, duration, and service health.</p>
    </header>

    <div class="dead-letter-toolbar">
        <button id="observability-refresh-btn" type="button">Refresh</button>
        <span id="observability-poll-indicator" class="poll-indicator">Idle</span>
    </div>

    <div class="observability-cards" id="observability-summary-cards">
        <article>
            <h3>Execution Counts</h3>
            <pre id="obs-execution-counts">Loading...</pre>
        </article>
        <article>
            <h3>Task Counts</h3>
            <pre id="obs-task-counts">Loading...</pre>
        </article>
        <article>
            <h3>Average Task Duration (s)</h3>
            <div id="obs-avg-duration" class="metric-value">Loading...</div>
        </article>
    </div>

    <section>
        <h3>Trends</h3>
        <div class="observability-cards">
            <article>
                <h4>Execution Throughput</h4>
                <svg id="obs-trend-throughput" class="trend-chart" viewBox="0 0 320 120" preserveAspectRatio="none"></svg>
            </article>
            <article>
                <h4>Failure + Retry Pressure</h4>
                <svg id="obs-trend-failure" class="trend-chart" viewBox="0 0 320 120" preserveAspectRatio="none"></svg>
            </article>
            <article>
                <h4>Latency Trend</h4>
                <svg id="obs-trend-latency" class="trend-chart" viewBox="0 0 320 120" preserveAspectRatio="none"></svg>
            </article>
        </div>
    </section>

    <section>
        <h3>Service Health</h3>
        <div class="table-scroll">
            <table id="obs-health-table">
                <thead>
                <tr>
                    <th>Service</th>
                    <th>Status</th>
                    <th>Details</th>
                </tr>
                </thead>
                <tbody>
                <tr><td>Web</td><td>Loading...</td><td></td></tr>
                <tr><td>API</td><td>Loading...</td><td></td></tr>
                <tr><td>DB</td><td>Loading...</td><td></td></tr>
                <tr><td>FastAPI</td><td>Loading...</td><td></td></tr>
                <tr><td>Scheduler</td><td>Loading...</td><td></td></tr>
                <tr><td>Worker</td><td>Loading...</td><td></td></tr>
                </tbody>
            </table>
        </div>
    </section>

    <section>
        <h3>UI Diagnostics</h3>
        <div id="ui-diagnostics-panel" class="diagnostics-panel">
            <div><strong>Last API:</strong> <span id="diag-last-api">n/a</span></div>
            <div><strong>Request ID:</strong> <span id="diag-request-id">n/a</span></div>
            <div><strong>Latency:</strong> <span id="diag-latency">n/a</span></div>
            <div><strong>Updated:</strong> <span id="diag-updated-at">n/a</span></div>
        </div>
    </section>
</section>
