<section id="observability-workspace">
    <header class="page-heading">
        <h2>Observability</h2>
        <p>Operational overview of workflow throughput, task states, duration, and service health.</p>
    </header>

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
</section>
