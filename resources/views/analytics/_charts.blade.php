{{--
    The chart renderers the Analytics page and the dashboard share, fed by the
    analytics.data payload (App\Services\Reports\CrmAnalytics). Each takes the
    selector of an empty box and draws (or redraws) one ApexChart in it.
    Pushed to the scripts stack, so include it before the page's own script.
--}}
@once
@push('styles')
<style>
    /* Card header with a title line and a muted subtitle under it (the design
       system's .card-header is a flex row, which would put them side by side). */
    .an-head { flex-direction: column; align-items: flex-start; gap: 2px; }
    .an-head .an-title { font-weight: 600; font-size: var(--fui-text-base); }
    .an-head .an-sub { font-size: 12px; color: var(--bs-secondary-color); font-weight: 400; }
    .an-head.an-head-row { flex-direction: row; align-items: flex-start; justify-content: space-between; flex-wrap: wrap; gap: 8px; }
    /* Funnel: one row per step, the bar centred so the shape narrows. */
    .an-funnel { display: flex; flex-direction: column; gap: 8px; }
    .an-funnel-row { display: grid; grid-template-columns: 150px minmax(0, 1fr) 170px; gap: 12px; align-items: center; }
    .an-funnel-row .lbl { font-size: 13px; font-weight: 500; }
    .an-funnel-row .track { height: 32px; display: flex; justify-content: center; background: var(--bs-tertiary-bg); border-radius: 6px; }
    .an-funnel-row .fill { height: 32px; min-width: 44px; border-radius: 6px; display: flex; align-items: center; justify-content: center; color: #fff; font-size: 13px; font-weight: 600; }
    .an-funnel-row .meta { font-size: 12px; color: var(--bs-secondary-color); text-align: right; white-space: nowrap; }
    .an-funnel-row .meta strong { color: var(--bs-body-color); }
    @media (max-width: 575px) { .an-funnel-row { grid-template-columns: 1fr; gap: 4px; } .an-funnel-row .meta { text-align: left; } }
    .an-kpi .rate { font-size: 12px; color: var(--bs-secondary-color); }
    .an-agents td, .an-agents th { white-space: nowrap; }
    .an-agents .bar { height: 6px; border-radius: 3px; background: var(--bs-tertiary-bg); overflow: hidden; min-width: 70px; }
    .an-agents .bar > i { display: block; height: 100%; background: #0d9488; border-radius: 3px; }
    .an-avatar { width: 26px; height: 26px; border-radius: 999px; display: inline-flex; align-items: center; justify-content: center; font-size: 11px; font-weight: 600; background: var(--bs-primary-bg-subtle); color: var(--bs-primary-text-emphasis); }
</style>
@endpush
@push('scripts')
<script>
window.CrmCharts = (function () {
    const charts = {};
    const esc = v => $('<div>').text(v == null ? '' : v).html();
    const num = n => Number(n || 0).toLocaleString('en-IN');

    function render(id, options) {
        if (charts[id]) { charts[id].updateOptions(options, true, true); return; }
        charts[id] = new ApexCharts(document.querySelector(id), options);
        charts[id].render();
    }

    function empty(id, text) {
        if (charts[id]) { charts[id].destroy(); delete charts[id]; }
        $(id).html(`<div class="d-flex align-items-center justify-content-center text-muted small" style="min-height:180px"><span><i class="bi bi-inbox me-1"></i>${esc(text)}</span></div>`);
    }

    function kpis(id, kpis, range) {
        const icons = { leads: 'bi-people', contacted: 'bi-telephone', booked: 'bi-calendar-check', visited: 'bi-door-open', won: 'bi-trophy', lost: 'bi-x-circle' };
        const tones = { leads: 'primary', contacted: 'info', booked: 'warning', visited: 'success', won: 'success', lost: 'danger' };
        $(id).html(kpis.map(k => {
            let delta = '';
            if (k.change !== null) {
                const good = k.inverse ? k.change <= 0 : k.change >= 0;
                const pct = Math.abs(k.change) >= 1000 ? '>999' : Math.abs(k.change);
                delta = `<small class="${good ? 'text-success' : 'text-danger'} text-nowrap" title="Compared with the ${range.days} day${range.days > 1 ? 's' : ''} before this range"><i class="bi bi-arrow-${k.change >= 0 ? 'up' : 'down'}"></i> ${pct}% <span class="text-muted fw-normal">vs previous period</span></small>`;
            }
            return `<div class="col-6 col-md-4 col-xl-2">
                <div class="card stat-card shadow-sm h-100 an-kpi"><div class="card-body p-3">
                    <div class="d-flex align-items-center gap-2 mb-2">
                        <div class="icon-box bg-${tones[k.key]}-subtle text-${tones[k.key]} m-0"><i class="bi ${icons[k.key]}"></i></div>
                        <small class="text-muted">${esc(k.label)}</small>
                    </div>
                    <div class="h4 mb-0">${num(k.value)}</div>
                    <div class="rate">${k.rate !== undefined ? `${k.rate}% of leads` : '&nbsp;'}</div>
                    ${delta ? `<div class="mt-1">${delta}</div>` : ''}
                </div></div></div>`;
        }).join(''));
    }

    // The funnel is plain HTML rows: a bar per step, centred so the shape
    // narrows, with the count on the bar and the rates beside it. Readable at
    // any size, which the library's funnel bars were not.
    function funnel(id, steps) {
        if (!steps.length || !steps[0].count) return empty(id, 'No leads created in this range.');
        const colors = ['#2c7be5', '#2c7be5', '#0d9488', '#0d9488', '#dc6803', '#039855'];
        const top = Math.max(steps[0].count, 1);
        $(id).html(`<div class="an-funnel">${steps.map((s, i) => `
            <div class="an-funnel-row">
                <div class="lbl">${esc(s.label)}</div>
                <div class="track"><div class="fill" style="width:${Math.max(s.count / top * 100, 7)}%;background:${colors[i % colors.length]}">${num(s.count)}</div></div>
                <div class="meta"><strong>${s.rate}%</strong> of leads${i > 0 ? ` · ${s.step_rate}% of previous` : ''}</div>
            </div>`).join('')}</div>`);
    }

    function donut(id, items, unit) {
        items = items.filter(i => i.count > 0);
        if (!items.length) return empty(id, `No ${unit} in this range.`);
        const palette = FUI.colors;
        render(id, {
            chart: { type: 'donut', height: 300 },
            series: items.map(i => i.count),
            labels: items.map(i => i.label),
            colors: items.map((i, n) => i.color || palette[n % palette.length]),
            legend: { position: 'bottom', fontSize: '12px' },
            dataLabels: { enabled: false },
            plotOptions: { pie: { donut: { size: '68%', labels: { show: true, total: { show: true, label: unit, formatter: w => num(w.globals.seriesTotals.reduce((a, b) => a + b, 0)) } } } } },
            stroke: { width: 2, colors: ['#fff'] },
            tooltip: { y: { formatter: (v, o) => `${num(v)} · ${Math.round(v / o.globals.seriesTotals.reduce((a, b) => a + b, 0) * 100)}%` } },
        });
    }

    // Whole-number axis with evenly spaced ticks (1, 2, 5, 10, 20, 50…), so a
    // chart of ones does not show 0 0 0 1 1 1 and a max of 12 does not give 2 5 7 10.
    function countAxis(max) {
        const rough = Math.max(max * 1.1, 1) / 5;
        const pow = Math.pow(10, Math.floor(Math.log10(rough)));
        const step = Math.max([1, 2, 5, 10].map(m => m * pow).find(s => s >= rough), 1);
        const top = step * Math.ceil(Math.max(max * 1.1, 1) / step);
        return { min: 0, max: top, tickAmount: Math.round(top / step), labels: { formatter: v => Math.round(v) } };
    }

    function trend(id, trend) {
        if (!trend.series.leads.some(v => v > 0) && !trend.series.booked.some(v => v > 0)) return empty(id, 'No leads created in this range.');
        const max = Math.max(...trend.series.leads, ...trend.series.booked, ...trend.series.visited, 1);
        const unit = { day: 'day', week: 'week', month: 'month' }[trend.unit] || 'day';
        render(id, {
            chart: { type: 'line', height: 300, toolbar: { show: false } },
            series: [
                { name: 'Leads',   data: trend.series.leads },
                { name: 'Booked',  data: trend.series.booked },
                { name: 'Visited', data: trend.series.visited },
            ],
            xaxis: { categories: trend.labels, tickAmount: Math.min(trend.labels.length, 12), labels: { rotate: 0, hideOverlappingLabels: true }, tooltip: { enabled: false } },
            yaxis: countAxis(max),
            stroke: { width: [3, 2, 2], curve: 'smooth' },
            colors: ['#2c7be5', '#0d9488', '#dc6803'],
            markers: { size: trend.labels.length > 31 ? 0 : 4, hover: { size: 6 } },
            legend: { position: 'top', horizontalAlign: 'right' },
            dataLabels: { enabled: false },
            tooltip: { shared: true, intersect: false, x: { formatter: v => (unit === 'day' ? '' : unit === 'week' ? 'Week of ' : '') + v } },
            grid: { borderColor: '#eef2f6' },
        });
    }

    function bars(id, rows, name, colors, emptyText) {
        if (!rows.length) return empty(id, emptyText);
        const max = Math.max(...rows.map(r => r.count), 1);
        render(id, {
            chart: { type: 'bar', height: Math.max(260, rows.length * 34 + 50), toolbar: { show: false } },
            series: [{ name, data: rows.map(r => r.count) }],
            xaxis: { categories: rows.map(r => r.label), ...countAxis(max) },
            yaxis: { labels: { maxWidth: 160, style: { fontSize: '12px' } } },
            plotOptions: { bar: { horizontal: true, borderRadius: 4, barHeight: '62%', distributed: colors.length > 1, dataLabels: { position: 'top' } } },
            colors,
            legend: { show: false },
            // Counts sit just past the end of each bar, in ink, so a short bar still reads.
            dataLabels: { enabled: true, formatter: v => num(v), textAnchor: 'start', offsetX: 6, style: { colors: ['#1f2a37'], fontWeight: 600, fontSize: '12px' } },
            grid: { borderColor: '#eef2f6', xaxis: { lines: { show: true } }, yaxis: { lines: { show: false } } },
            tooltip: { y: { formatter: v => num(v) } },
        });
    }

    function heat(id, days) {
        if (!days.some(d => d.hours.some(v => v > 0))) return empty(id, 'No leads created in this range.');
        const hour = h => h === 0 ? '12am' : h < 12 ? h + 'am' : h === 12 ? '12pm' : (h - 12) + 'pm';
        render(id, {
            chart: { type: 'heatmap', height: 300, toolbar: { show: false } },
            // Sunday last, so the week reads top to bottom as Mon → Sun.
            series: days.slice().reverse().map(d => ({ name: d.day, data: d.hours.map((v, h) => ({ x: hour(h), y: v })) })),
            plotOptions: { heatmap: { radius: 3, shadeIntensity: .6, colorScale: { ranges: [{ from: 0, to: 0, color: '#eef2f6', name: 'none' }] } } },
            colors: ['#2c7be5'],
            dataLabels: { enabled: false },
            xaxis: { labels: { rotate: 0, hideOverlappingLabels: true, style: { fontSize: '11px' } }, tooltip: { enabled: false } },
            legend: { show: false },
            tooltip: { y: { formatter: v => `${num(v)} lead${v === 1 ? '' : 's'}`, title: { formatter: () => '' } } },
            stroke: { width: 2, colors: ['#fff'] },
        });
    }

    function centers(id, rows) {
        if (!rows.length) return empty(id, 'No leads in this range.');
        render(id, {
            chart: { type: 'bar', height: 280, toolbar: { show: false } },
            series: [
                { name: 'Leads',   data: rows.map(r => r.leads) },
                { name: 'Booked',  data: rows.map(r => r.booked) },
                { name: 'Visited', data: rows.map(r => r.visited) },
                { name: 'Won',     data: rows.map(r => r.won) },
            ],
            xaxis: { categories: rows.map(r => r.label) },
            yaxis: countAxis(Math.max(...rows.map(r => r.leads), 1)),
            plotOptions: { bar: { borderRadius: 3, columnWidth: rows.length > 3 ? '70%' : '45%' } },
            colors: ['#2c7be5', '#0d9488', '#dc6803', '#039855'],
            dataLabels: { enabled: false },
            legend: { position: 'top', horizontalAlign: 'right' },
            grid: { borderColor: '#eef2f6' },
            tooltip: { shared: true, intersect: false },
        });
    }

    function agents(id, rows) {
        if (!rows.length) return $(id).html('<tr><td colspan="9" class="text-center text-muted py-4">No leads were assigned to an agent in this range.</td></tr>');
        const max = Math.max(...rows.map(r => r.visit_rate), 1);
        $(id).html(rows.map(r => `
            <tr>
                <td class="text-muted">${r.rank}</td>
                <td><span class="an-avatar me-2">${esc(r.name.split(' ').map(w => w[0]).join('').slice(0, 2).toUpperCase())}</span>${esc(r.name)}</td>
                <td class="text-end">${num(r.leads)}</td>
                <td class="text-end">${num(r.dials)}</td>
                <td class="text-end">${num(r.connected)}</td>
                <td class="text-end">${num(r.appointments)}</td>
                <td class="text-end fw-semibold">${num(r.visited)}</td>
                <td class="text-end">${num(r.bookings)}</td>
                <td><div class="d-flex align-items-center gap-2"><div class="bar flex-grow-1"><i style="width:${Math.round(r.visit_rate / max * 100)}%"></i></div><span class="text-end" style="width:44px">${r.visit_rate}%</span></div></td>
            </tr>`).join(''));
    }

    return { kpis, funnel, donut, trend, bars, heat, centers, agents, empty, num, esc };
})();
</script>
@endpush
@endonce
