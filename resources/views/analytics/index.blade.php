@extends('layouts.app')

@section('title', 'Analytics')

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">Dashboard</a></li>
    <li class="breadcrumb-item">Reports</li>
    <li class="breadcrumb-item active">Analytics</li>
@endsection

@push('styles')
<style>
    .an-kpi .h4 { letter-spacing: -.02em; }
    .an-kpi .rate { font-size: 12px; color: var(--bs-secondary-color); }
    .an-presets .btn { white-space: nowrap; }
    .an-heat-wrap { overflow-x: auto; }
    .an-agents td, .an-agents th { white-space: nowrap; }
    .an-agents .bar { height: 6px; border-radius: 3px; background: var(--bs-tertiary-bg); overflow: hidden; min-width: 90px; }
    .an-agents .bar > i { display: block; height: 100%; background: #0d9488; border-radius: 3px; }
    .an-avatar { width: 26px; height: 26px; border-radius: 999px; display: inline-flex; align-items: center; justify-content: center; font-size: 11px; font-weight: 600; background: var(--bs-primary-bg-subtle); color: var(--bs-primary-text-emphasis); }
    .an-empty { min-height: 180px; display: flex; align-items: center; justify-content: center; color: var(--bs-secondary-color); font-size: 13px; }
</style>
@endpush

@section('content')
@php
    $presets = [
        'Today'      => [today(), today()],
        'Yesterday'  => [today()->subDay(), today()->subDay()],
        '7 days'     => [today()->subDays(6), today()],
        '30 days'    => [today()->subDays(29), today()],
        'This month' => [today()->startOfMonth(), today()],
        'Last month' => [today()->subMonthNoOverflow()->startOfMonth(), today()->subMonthNoOverflow()->endOfMonth()],
    ];
@endphp

<div class="d-flex flex-wrap justify-content-between align-items-end gap-2 mb-3">
    <div>
        <h5 class="fw-bold mb-0">Analytics</h5>
        <div class="text-muted small">Every chart counts the leads <strong>created</strong> in the range below, narrowed by the filters.</div>
    </div>
    <div class="d-flex flex-wrap align-items-center gap-2">
        <div class="btn-group btn-group-sm an-presets" id="anPresets">
            @foreach($presets as $label => [$pFrom, $pTo])
                <button type="button" class="btn btn-outline-secondary" data-from="{{ $pFrom->toDateString() }}" data-to="{{ $pTo->toDateString() }}">{{ $label }}</button>
            @endforeach
        </div>
        <div class="input-group input-group-sm" style="width: 260px">
            <span class="input-group-text" title="On the date the lead was created"><i class="bi bi-calendar-range me-1"></i>Lead created</span>
            <input type="text" class="form-control" id="anRange" aria-label="Date range">
        </div>
    </div>
</div>

{{-- Filters --}}
<div class="card border-0 shadow-sm mb-3">
    <div class="card-body py-2 d-flex flex-wrap align-items-center gap-2">
        <select class="form-select form-select-sm an-filter" id="fCenter" name="center_id" style="width:auto">
            <option value="">All centres</option>
            @foreach($centers as $c)<option value="{{ $c->id }}" @selected($filters['center_id'] == $c->id)>{{ $c->name }}</option>@endforeach
        </select>
        @if($agents->isNotEmpty())
            <select class="form-select form-select-sm an-filter" id="fAgent" name="assigned_to" style="width:auto">
                <option value="">All agents</option>
                @foreach($agents as $a)<option value="{{ $a->id }}" @selected($filters['assigned_to'] == $a->id)>{{ $a->name }}</option>@endforeach
            </select>
        @endif
        <select class="form-select form-select-sm an-filter" id="fSource" name="lead_source_id" style="width:auto">
            <option value="">All sources</option>
            @foreach($sources as $s)<option value="{{ $s->id }}" @selected($filters['lead_source_id'] == $s->id)>{{ $s->name }}</option>@endforeach
        </select>
        <select class="form-select form-select-sm an-filter" id="fChannel" name="channel" style="width:auto">
            <option value="">All channels</option>
            @foreach($channelOptions as $value => $label)<option value="{{ $value }}" @selected($filters['channel'] == $value)>{{ $label }}</option>@endforeach
        </select>
        <select class="form-select form-select-sm an-filter" id="fCampaign" name="campaign_id" style="width:auto; max-width: 220px">
            <option value="">All campaigns</option>
            @foreach($campaigns as $c)<option value="{{ $c->id }}" @selected($filters['campaign_id'] == $c->id)>{{ $c->name }}</option>@endforeach
        </select>
        <select class="form-select form-select-sm an-filter" id="fStage" name="stage_id" style="width:auto">
            <option value="">Any stage</option>
            @foreach($stages as $s)<option value="{{ $s->id }}" @selected($filters['stage_id'] == $s->id)>{{ $s->name }}</option>@endforeach
        </select>
        <select class="form-select form-select-sm an-filter" id="fStatus" name="status" style="width:auto">
            <option value="">Any status</option>
            @foreach($statusOptions as $value => $label)<option value="{{ $value }}" @selected($filters['status'] == $value)>{{ $label }}</option>@endforeach
        </select>
        <div class="ms-auto d-flex gap-2">
            <button type="button" class="btn btn-sm btn-outline-secondary" id="anReset">Reset</button>
            <button type="button" class="btn btn-sm btn-primary" id="anApply"><i class="bi bi-funnel me-1"></i>Apply</button>
        </div>
    </div>
</div>

{{-- KPIs --}}
<div class="row g-3 mb-3" id="anKpis">
    @for($i = 0; $i < 6; $i++)
        <div class="col-6 col-md-4 col-xl-2"><div class="card stat-card shadow-sm h-100"><div class="card-body p-3"><div class="placeholder-glow"><span class="placeholder col-8"></span><span class="placeholder col-5 d-block mt-2"></span></div></div></div></div>
    @endfor
</div>

{{-- Funnel + channels --}}
<div class="row g-3 mb-3">
    <div class="col-lg-8">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-transparent d-flex justify-content-between align-items-start flex-wrap gap-2">
                <div><div class="fw-semibold">Conversion funnel</div><div class="small text-muted">Each step is a share of the leads created in the range; the step rate is against the previous step.</div></div>
            </div>
            <div class="card-body"><div id="chartFunnel" style="min-height: 300px"></div><div class="d-flex flex-wrap gap-3 small text-muted mt-2" id="funnelRates"></div></div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-transparent"><div class="fw-semibold">Leads by channel</div><div class="small text-muted">Where the enquiries came from</div></div>
            <div class="card-body"><div id="chartChannels" style="min-height: 300px"></div></div>
        </div>
    </div>
</div>

{{-- Trend + stages --}}
<div class="row g-3 mb-3">
    <div class="col-lg-8">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-transparent"><div class="fw-semibold">Leads, bookings and visits by day</div><div class="small text-muted">New leads each day, with the consultations booked and visited for them</div></div>
            <div class="card-body"><div id="chartTrend" style="min-height: 300px"></div></div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-transparent"><div class="fw-semibold">Pipeline stage</div><div class="small text-muted">Where these leads stand today</div></div>
            <div class="card-body"><div id="chartStages" style="min-height: 300px"></div></div>
        </div>
    </div>
</div>

{{-- Agents + heat map --}}
<div class="row g-3 mb-3">
    <div class="col-lg-7">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-transparent d-flex justify-content-between align-items-start flex-wrap gap-2">
                <div><div class="fw-semibold">Top agents</div><div class="small text-muted">Ranked by consultations visited from the leads assigned to them in the range</div></div>
                <button type="button" class="btn btn-sm btn-outline-secondary" id="anAgentsCsv"><i class="bi bi-download me-1"></i>CSV</button>
            </div>
            <div class="table-responsive">
                <table class="table table-sm table-hover align-middle mb-0 small an-agents">
                    <thead class="table-light"><tr><th>#</th><th>Agent</th><th class="text-end">Leads</th><th class="text-end">Contacted</th><th class="text-end">Dials</th><th class="text-end">Connected</th><th class="text-end">Booked</th><th class="text-end">Visited</th><th class="text-end">Won</th><th>Lead → visit</th></tr></thead>
                    <tbody id="agentsBody"><tr><td colspan="10" class="text-center text-muted py-4">Loading…</td></tr></tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="col-lg-5">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-transparent"><div class="fw-semibold">When leads arrive</div><div class="small text-muted">Leads created by weekday and hour — darker is busier</div></div>
            <div class="card-body an-heat-wrap"><div id="chartHeat" style="min-height: 300px; min-width: 420px"></div></div>
        </div>
    </div>
</div>

{{-- Centres + sources --}}
<div class="row g-3 mb-3">
    <div class="col-lg-7">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-transparent"><div class="fw-semibold">Centre comparison</div><div class="small text-muted">Leads, bookings, visits and conversions per centre</div></div>
            <div class="card-body"><div id="chartCenters" style="min-height: 280px"></div></div>
        </div>
    </div>
    <div class="col-lg-5">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-transparent"><div class="fw-semibold">Top lead sources</div><div class="small text-muted">The configured source each enquiry was recorded under</div></div>
            <div class="card-body"><div id="chartSources" style="min-height: 280px"></div></div>
        </div>
    </div>
</div>

{{-- Lost reasons + call outcomes --}}
<div class="row g-3 mb-3">
    <div class="col-lg-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-transparent"><div class="fw-semibold">Why leads are lost</div><div class="small text-muted">Reasons recorded with the stop-calling dispositions</div></div>
            <div class="card-body"><div id="chartLost" style="min-height: 280px"></div></div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-transparent"><div class="fw-semibold">Call outcomes</div><div class="small text-muted">Dispositions saved on the calls made to these leads in the range</div></div>
            <div class="card-body"><div id="chartCalls" style="min-height: 280px"></div></div>
        </div>
    </div>
</div>
@endsection

@include('analytics._charts')

@push('scripts')
<script>
$(function () {
    const filters = @json($filters);
    const dataUrl = @json(route('analytics.data'));
    const pageUrl = @json(route('analytics.index'));
    const C = window.CrmCharts;

    // One range picker on lead-created date; the presets fill it.
    const picker = flatpickr('#anRange', {
        mode: 'range', altInput: true, altFormat: 'd M Y', dateFormat: 'Y-m-d',
        defaultDate: [filters.from, filters.to], maxDate: 'today',
        onClose(dates, _s, fp) {
            if (dates.length === 2) {
                filters.from = fp.formatDate(dates[0], 'Y-m-d');
                filters.to   = fp.formatDate(dates[1], 'Y-m-d');
                markPreset(); load();
            }
        },
    });

    function markPreset() {
        $('#anPresets button').each(function () {
            const on = $(this).data('from') === filters.from && $(this).data('to') === filters.to;
            $(this).toggleClass('btn-secondary', on).toggleClass('btn-outline-secondary', !on);
        });
    }

    $('#anPresets').on('click', 'button', function () {
        filters.from = $(this).data('from'); filters.to = $(this).data('to');
        picker.setDate([filters.from, filters.to], false);
        markPreset(); load();
    });

    function currentFilters() {
        const f = { from: filters.from, to: filters.to };
        $('.an-filter').each(function () { f[this.name] = this.value; });
        return f;
    }

    $('#anApply').on('click', load);
    $('.an-filter').on('change', load);
    $('#anReset').on('click', () => { $('.an-filter').val(''); load(); });

    let lastData = null;

    function load() {
        const f = currentFilters();
        // Keep the filters in the address so a reload or a shared link lands on the same view.
        history.replaceState({}, '', pageUrl + '?' + new URLSearchParams(f).toString());
        $('#anApply').prop('disabled', true);

        $.get(dataUrl, f).done(res => {
            lastData = res;
            C.kpis('#anKpis', res.kpis, res.range);
            C.funnel('#chartFunnel', res.funnel, '#funnelRates');
            C.donut('#chartChannels', res.channels, 'leads');
            C.trend('#chartTrend', res.trend);
            C.bars('#chartStages', res.stages, 'Leads', res.stages.map(s => s.color), 'No leads in this range.');
            C.agents('#agentsBody', res.agents);
            C.heat('#chartHeat', res.heatmap);
            C.centers('#chartCenters', res.centers);
            C.bars('#chartSources', res.sources, 'Leads', ['#2c7be5'], 'No leads in this range.');
            C.bars('#chartLost', res.lost, 'Lost leads', ['#d92d20'], 'No lost leads in this range.');
            C.donut('#chartCalls', res.calls.items, 'calls');
        }).fail(xhr => toastr.error(xhr.responseJSON?.message || 'Could not load the analytics.'))
          .always(() => $('#anApply').prop('disabled', false));
    }

    $('#anAgentsCsv').on('click', function () {
        if (!lastData?.agents?.length) return toastr.info('Nothing to export.');
        const head = ['Rank', 'Agent', 'Leads', 'Contacted', 'Dials', 'Connected', 'Booked', 'Visited', 'Won', 'Lead to visit %'];
        const lines = [head].concat(lastData.agents.map(r => [r.rank, r.name, r.leads, r.contacted, r.dials, r.connected, r.booked, r.visited, r.won, r.visit_rate]));
        const csv = lines.map(l => l.map(v => `"${String(v).replace(/"/g, '""')}"`).join(',')).join('\n');
        const a = document.createElement('a');
        a.href = URL.createObjectURL(new Blob([csv], { type: 'text/csv' }));
        a.download = `top-agents-${filters.from}-to-${filters.to}.csv`;
        a.click();
    });

    markPreset();
    load();
});
</script>
@endpush
