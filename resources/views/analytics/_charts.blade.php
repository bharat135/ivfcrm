{{--
    The chart renderers the Analytics page and the dashboard share, fed by the
    analytics.data payload (App\Services\Reports\CrmAnalytics). Each takes the
    selector of an empty box and draws (or redraws) one ApexChart in it.
    Pushed to the scripts stack, so include it before the page's own script.
--}}
@once
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
                delta = `<small class="${good ? 'text-success' : 'text-danger'}"><i class="bi bi-arrow-${k.change >= 0 ? 'up' : 'down'}"></i> ${Math.abs(k.change)}%</small> <small class="text-muted">vs previous ${range.days} day${range.days > 1 ? 's' : ''}</small>`;
            }
            return `<div class="col-6 col-md-4 col-xl-2">
                <div class="card stat-card shadow-sm h-100"><div class="card-body p-3">
                    <div class="icon-box bg-${tones[k.key]}-subtle text-${tones[k.key]} mb-2"><i class="bi ${icons[k.key]}"></i></div>
                    <div class="h4 mb-0">${num(k.value)}${k.rate !== undefined ? ` <span class="small text-muted fw-normal">${k.rate}% of leads</span>` : ''}</div>
                    <small class="text-muted d-block">${esc(k.label)}</small>
                    ${delta}
                </div></div></div>`;
        }).join(''));
    }

    function funnel(id, steps, ratesId) {
        if (!steps.length || !steps[0].count) { if (ratesId) $(ratesId).empty(); return empty(id, 'No leads created in this range.'); }
        render(id, {
            chart: { type: 'bar', height: 300, toolbar: { show: false } },
            series: [{ name: 'Leads', data: steps.map(s => s.count) }],
            xaxis: { categories: steps.map(s => s.label) },
            plotOptions: { bar: { horizontal: true, isFunnel: true, borderRadius: 4, distributed: true } },
            colors: ['#2c7be5', '#2c7be5', '#0d9488', '#0d9488', '#dc6803', '#039855'],
            dataLabels: { enabled: true, formatter: (v, o) => `${steps[o.dataPointIndex].label}: ${num(v)}`, style: { colors: ['#fff'], fontWeight: 600 } },
            legend: { show: false },
            tooltip: { y: { formatter: (v, o) => `${num(v)} · ${steps[o.dataPointIndex].rate}% of leads · ${steps[o.dataPointIndex].step_rate}% of previous step` } },
        });
        if (ratesId) $(ratesId).html(steps.slice(1).map(s => `<span><strong>${esc(s.label)}</strong> ${s.step_rate}% of previous · ${num(s.dropped)} dropped</span>`).join(''));
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
            tooltip: { y: { formatter: v => num(v) } },
        });
    }

    function trend(id, trend) {
        if (!trend.series.leads.some(v => v > 0) && !trend.series.booked.some(v => v > 0)) return empty(id, 'No leads created in this range.');
        render(id, {
            chart: { type: 'line', height: 300, toolbar: { show: false } },
            series: [
                { name: 'Leads',   data: trend.series.leads },
                { name: 'Booked',  data: trend.series.booked },
                { name: 'Visited', data: trend.series.visited },
            ],
            xaxis: { categories: trend.labels, tickAmount: Math.min(trend.labels.length, 12), labels: { rotate: 0 } },
            yaxis: { labels: { formatter: v => Math.round(v) }, forceNiceScale: true, min: 0 },
            stroke: { width: [3, 2, 2], curve: 'smooth' },
            colors: ['#2c7be5', '#0d9488', '#dc6803'],
            markers: { size: trend.labels.length > 31 ? 0 : 3 },
            legend: { position: 'top' },
            dataLabels: { enabled: false },
        });
    }

    function bars(id, rows, name, colors, emptyText) {
        if (!rows.length) return empty(id, emptyText);
        render(id, {
            chart: { type: 'bar', height: Math.max(280, rows.length * 34 + 60), toolbar: { show: false } },
            series: [{ name, data: rows.map(r => r.count) }],
            xaxis: { categories: rows.map(r => r.label), labels: { formatter: v => Math.round(v) } },
            plotOptions: { bar: { horizontal: true, borderRadius: 4, barHeight: '60%', distributed: colors.length > 1 } },
            colors,
            legend: { show: false },
            dataLabels: { enabled: true, formatter: v => num(v), offsetX: 24, style: { colors: ['#1f2a37'] } },
            grid: { xaxis: { lines: { show: true } }, yaxis: { lines: { show: false } } },
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
            xaxis: { labels: { rotate: 0, hideOverlappingLabels: true } },
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
            yaxis: { labels: { formatter: v => Math.round(v) }, forceNiceScale: true },
            plotOptions: { bar: { borderRadius: 4, columnWidth: '55%' } },
            colors: ['#2c7be5', '#0d9488', '#dc6803', '#039855'],
            dataLabels: { enabled: false },
            legend: { position: 'top' },
        });
    }

    function agents(id, rows) {
        if (!rows.length) return $(id).html('<tr><td colspan="10" class="text-center text-muted py-4">No leads were assigned to an agent in this range.</td></tr>');
        const max = Math.max(...rows.map(r => r.visit_rate), 1);
        $(id).html(rows.map(r => `
            <tr>
                <td class="text-muted">${r.rank}</td>
                <td><span class="an-avatar me-2">${esc(r.name.split(' ').map(w => w[0]).join('').slice(0, 2).toUpperCase())}</span>${esc(r.name)}</td>
                <td class="text-end">${num(r.leads)}</td>
                <td class="text-end">${num(r.contacted)}</td>
                <td class="text-end">${num(r.dials)}</td>
                <td class="text-end">${num(r.connected)}</td>
                <td class="text-end">${num(r.booked)}</td>
                <td class="text-end fw-semibold">${num(r.visited)}</td>
                <td class="text-end">${num(r.won)}</td>
                <td><div class="d-flex align-items-center gap-2"><div class="bar flex-grow-1"><i style="width:${Math.round(r.visit_rate / max * 100)}%"></i></div><span style="width:42px;text-align:right">${r.visit_rate}%</span></div></td>
            </tr>`).join(''));
    }

    return { kpis, funnel, donut, trend, bars, heat, centers, agents, empty, num, esc };
})();
</script>
@endpush
@endonce
