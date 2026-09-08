/** Dashboard filters, safe DOM rendering, chart lifecycle, and snippet generation. */
(() => {
    'use strict';
    const config = JSON.parse(document.getElementById('app-config').textContent);
    const byId = id => document.getElementById(id);
    const domain = byId('domain');
    const period = byId('period');
    const start = byId('start');
    const end = byId('end');
    const number = new Intl.NumberFormat('en-US');
    let controller = null;
    let requestNumber = 0;
    let chart = null;

    // Calendar arithmetic stays independent of the browser's timezone and DST.
    function shiftDate(iso, days) {
        const date = new Date(`${iso}T12:00:00Z`);
        date.setUTCDate(date.getUTCDate() + days);
        return date.toISOString().slice(0, 10);
    }
    function setPeriod() {
        end.value = config.today;
        if (period.value === 'month') {
            end.value = shiftDate(`${config.today.slice(0, 7)}-01`, -1);
            start.value = `${end.value.slice(0, 7)}-01`;
        } else {
            start.value = shiftDate(config.today, -(period.value === 'today' ? 0 : Number(period.value) - 1));
        }
    }
    function tableRows(id, rows, total) {
        const body = byId(id);
        body.replaceChildren();
        if (!rows.length) {
            const cell = document.createElement('td');
            cell.colSpan = 3;
            cell.className = 'table-empty';
            cell.textContent = 'No data for this period';
            const row = document.createElement('tr');
            row.append(cell); body.append(row); return;
        }
        for (const item of rows) {
            const row = document.createElement('tr');
            const label = document.createElement('td');
            label.className = 'item-label';
            // Untrusted URLs and user-agent-derived labels are always text, never HTML.
            label.textContent = item.label;
            label.title = item.label;
            const views = document.createElement('td');
            views.className = 'text-end tabular'; views.textContent = number.format(item.views);
            const share = document.createElement('td');
            share.className = 'text-end subtle tabular';
            share.textContent = `${total ? (item.views / total * 100).toFixed(1) : '0.0'}%`;
            row.append(label, views, share); body.append(row);
        }
    }
    function renderDaily(series) {
        const body = byId('daily-body'); body.replaceChildren();
        for (const point of series) {
            const row = document.createElement('tr');
            for (const value of [point.date, number.format(point.pageviews), number.format(point.visitors)]) {
                const cell = document.createElement('td'); cell.textContent = value; row.append(cell);
            }
            body.append(row);
        }
    }
    function renderChart(series) {
        if (typeof window.Chart !== 'function') {
            byId('chart-warning').hidden = false;
            byId('traffic-chart').hidden = true;
            return;
        }
        const labels = series.map(point => point.date);
        const datasets = [
            {label: 'Pageviews', data: series.map(point => point.pageviews), borderColor: '#087f75',
                backgroundColor: 'rgba(8,127,117,0.07)', fill: true, borderWidth: 2.5, tension: 0.25, pointRadius: series.length === 1 ? 4 : 0, pointHitRadius: 12},
            {label: 'Visitors', data: series.map(point => point.visitors), borderColor: '#6478b9',
                borderDash: [5, 4], borderWidth: 2, tension: 0.25, pointRadius: series.length === 1 ? 4 : 0, pointHitRadius: 12}
        ];
        if (chart) { chart.data = {labels, datasets}; chart.update(); return; }
        chart = new window.Chart(byId('traffic-chart'), {
            type: 'line', data: {labels, datasets},
            options: {responsive: true, maintainAspectRatio: false,
                animation: !window.matchMedia('(prefers-reduced-motion: reduce)').matches,
                interaction: {mode: 'index', intersect: false},
                plugins: {legend: {display: false}},
                scales: {x: {grid: {display: false}, ticks: {maxTicksLimit: 8, maxRotation: 0}},
                    y: {beginAtZero: true, ticks: {precision: 0}, grid: {color: '#edf0f4'}}}}
        });
    }
    function clearResults() {
        byId('pageviews').textContent = '—'; byId('visitors').textContent = '—';
        byId('daily-body').replaceChildren();
        byId('range-label').textContent = 'Daily pageviews and visitors';
        ['pages', 'referrers', 'devices', 'browsers'].forEach(key => byId(`${key}-body`).replaceChildren());
        byId('empty-state').hidden = true;
        if (chart) { chart.destroy(); chart = null; }
    }
    async function loadStats() {
        const currentRequest = ++requestNumber;
        if (controller) controller.abort();
        controller = null;
        byId('error').hidden = true;
        clearResults();
        byId('results').setAttribute('aria-busy', 'false');
        byId('status').textContent = '';
        if (!start.value || !end.value || start.value > end.value || end.value > config.today ||
            (Date.parse(end.value) - Date.parse(start.value)) / 86400000 >= config.maxDays) {
            byId('error').textContent = `Choose a valid range of up to ${config.maxDays} days, ending today or earlier.`;
            byId('error').hidden = false; return;
        }
        controller = new AbortController();
        const localController = controller;
        let timedOut = false;
        const timeout = window.setTimeout(() => { timedOut = true; localController.abort(); }, 15000);
        byId('results').setAttribute('aria-busy', 'true');
        byId('status').textContent = `Loading ${domain.value}…`;
        try {
            const query = new URLSearchParams({domain: domain.value, start: start.value, end: end.value});
            const response = await fetch(`stats.php?${query}`, {credentials: 'same-origin',
                headers: {'Accept': 'application/json'}, cache: 'no-store', signal: localController.signal});
            let data;
            try { data = await response.json(); } catch (error) { throw new Error('The server returned an invalid response. Please try again.'); }
            if (response.status === 401) { window.location.assign('index.php'); return; }
            if (!response.ok) throw new Error(data.error || 'Unable to load analytics.');
            if (currentRequest !== requestNumber) return;
            byId('pageviews').textContent = number.format(data.totals.pageviews);
            byId('visitors').textContent = number.format(data.totals.visitors);
            byId('range-label').textContent = `${data.start} – ${data.end} · ${data.timezone}`;
            byId('empty-state').hidden = data.totals.pageviews !== 0;
            for (const key of ['pages', 'referrers', 'devices', 'browsers']) {
                tableRows(`${key}-body`, data.tables[key], data.totals.pageviews);
            }
            renderDaily(data.series); renderChart(data.series);
            byId('status').textContent = `${data.domain} · Updated ${new Date().toLocaleTimeString('en-GB', {hour: '2-digit', minute: '2-digit'})}`;
        } catch (error) {
            if (currentRequest !== requestNumber || (error.name === 'AbortError' && !timedOut)) return;
            byId('error').textContent = timedOut ? 'The request timed out. Please refresh to try again.' : error.message;
            byId('error').hidden = false; byId('status').textContent = 'Analytics could not be loaded.';
        } finally {
            window.clearTimeout(timeout);
            if (currentRequest === requestNumber) byId('results').setAttribute('aria-busy', 'false');
        }
    }
    function generateSnippet() {
        const allowed = config.allowedDomains.includes(domain.value);
        const base = config.appUrl ? `${config.appUrl}/` : new URL('./', window.location.href).href;
        const source = new URL('assets/tracker.js', base).href;
        const safeSource = source.replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;');
        byId('snippet').value = allowed ? `<script async src="${safeSource}"></script>` : '';
        byId('copy-snippet').disabled = !allowed;
        byId('snippet-notice').textContent = allowed ? `Ready for ${domain.value}. The current page URL determines the website automatically.` :
            `${domain.value} has historical data but is no longer allowed. Add it to settings.php to resume tracking.`;
        byId('copy-status').textContent = '';
    }
    byId('copy-snippet').addEventListener('click', async () => {
        const snippet = byId('snippet');
        try {
            await navigator.clipboard.writeText(snippet.value);
            byId('copy-status').textContent = 'Snippet copied.';
        } catch (error) {
            snippet.focus(); snippet.select();
            byId('copy-status').textContent = 'Press Ctrl+C (or Command+C) to copy the selected snippet.';
        }
    });
    byId('filters').addEventListener('submit', event => { event.preventDefault(); loadStats(); });
    domain.addEventListener('change', () => { generateSnippet(); loadStats(); });
    period.addEventListener('change', () => {
        if (period.value === 'custom') { start.focus(); return; }
        setPeriod(); loadStats();
    });
    [start, end].forEach(input => input.addEventListener('change', () => { period.value = 'custom'; loadStats(); }));
    setPeriod(); generateSnippet(); loadStats();
})();
