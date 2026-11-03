@extends('layouts.app')

@section('title', 'Dashboard')

@section('breadcrumb')
    <li class="breadcrumb-item active">Dashboard</li>
@endsection

@section('content')
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="fw-bold mb-0">Good {{ now()->hour < 12 ? 'Morning' : (now()->hour < 17 ? 'Afternoon' : 'Evening') }}, {{ auth()->user()->name }} 👋</h4>
        <p class="text-muted small mb-0">{{ now()->format('d-m-Y') }}</p>
    </div>
    <a href="{{ route('leads.create') }}" class="btn btn-primary">
        <i class="bi bi-plus-lg me-1"></i> New Lead
    </a>
</div>

@php
    $isSingleDay = $from->isSameDay($to);
    $periodLabel = $isSingleDay
        ? ($from->isToday() ? 'Today' : $from->format('d-m-Y'))
        : $from->format('d-m-Y') . ' to ' . $to->format('d-m-Y');
    $presets = [
        'Today'      => [today(), today()],
        'Yesterday'  => [today()->subDay(), today()->subDay()],
        'This week'  => [today()->startOfWeek(), today()],
        'This month' => [today()->startOfMonth(), today()],
        'Last month' => [today()->subMonthNoOverflow()->startOfMonth(), today()->subMonthNoOverflow()->endOfMonth()],
    ];
@endphp

<!-- Period -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-body py-2">
        <form method="get" action="{{ route('dashboard') }}" class="row g-2 align-items-center" id="periodForm">
            <div class="col-auto text-muted small"><i class="bi bi-calendar-range me-1"></i>Period</div>
            <div class="col-auto">
                <input type="date" name="from" class="form-control form-control-sm" value="{{ $from->toDateString() }}" max="{{ today()->toDateString() }}">
            </div>
            <div class="col-auto text-muted small">to</div>
            <div class="col-auto">
                <input type="date" name="to" class="form-control form-control-sm" value="{{ $to->toDateString() }}" max="{{ today()->toDateString() }}">
            </div>
            <div class="col-auto">
                <button type="submit" class="btn btn-sm btn-primary">Apply</button>
            </div>
            <div class="col-auto d-flex flex-wrap gap-1">
                @foreach($presets as $label => [$pFrom, $pTo])
                    @php $active = $pFrom->isSameDay($from) && $pTo->isSameDay($to); @endphp
                    <a href="{{ route('dashboard', ['from' => $pFrom->toDateString(), 'to' => $pTo->toDateString()]) }}"
                       class="btn btn-sm {{ $active ? 'btn-secondary' : 'btn-outline-secondary' }}">{{ $label }}</a>
                @endforeach
            </div>
            <div class="col-auto ms-auto fw-semibold small">{{ $periodLabel }}</div>
        </form>
    </div>
</div>

<!-- KPI Cards -->
<div class="row g-3 mb-4">
    @php
    // Tone names, not hex: the design system owns the palette so these cards
    // follow light/dark and any future rebrand automatically.
    $p = $isSingleDay && $from->isToday() ? 'Today' : $periodLabel;
    $cards = [
        ['label' => "Leads · $p",          'value' => $metrics['todayLeads'],        'icon' => 'bi-people',            'tone' => 'primary'],
        ['label' => 'Active Leads',        'value' => $metrics['activeLeads'],       'icon' => 'bi-graph-up-arrow',    'tone' => 'info'],
        ['label' => "Calls · $p",          'value' => $metrics['todayCalls'],        'icon' => 'bi-telephone',         'tone' => 'success'],
        ['label' => "Follow-Ups · $p",     'value' => $metrics['todayFollows'],      'icon' => 'bi-alarm',             'tone' => 'warning'],
        ['label' => 'Overdue Follow-Ups',  'value' => $metrics['overdueFollows'],    'icon' => 'bi-exclamation-triangle', 'tone' => 'danger'],
        ['label' => "Appointments · $p",   'value' => $metrics['todayAppointments'], 'icon' => 'bi-calendar-check',    'tone' => 'info'],
        ['label' => "Conversions · $p",    'value' => $metrics['monthlyConversions'],'icon' => 'bi-trophy',            'tone' => 'success'],
        ['label' => 'Total Leads',         'value' => $metrics['totalLeads'],        'icon' => 'bi-database',          'tone' => 'secondary'],
    ];
    @endphp

    @foreach($cards as $card)
    <div class="col-6 col-md-3">
        <div class="card stat-card h-100 shadow-sm">
            <div class="card-body d-flex align-items-center gap-3">
                <div class="icon-box bg-{{ $card['tone'] }}-subtle text-{{ $card['tone'] }}">
                    <i class="bi {{ $card['icon'] }}"></i>
                </div>
                <div class="min-w-0">
                    <div class="fw-semibold lh-1 mb-1" style="font-size:19px;letter-spacing:-.02em">
                        {{ number_format($card['value']) }}
                    </div>
                    <div class="text-muted small text-truncate">{{ $card['label'] }}</div>
                </div>
            </div>
        </div>
    </div>
    @endforeach
</div>

@if($team)
<!-- Team performance: the sheet management tracks, per agent -->
<div class="card shadow-sm border-0 mb-4">
    <div class="card-header bg-transparent d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div>
            <h6 class="mb-0 fw-semibold">Team Performance · {{ $periodLabel }}</h6>
            <div class="text-muted small">
                DM Camp Count <strong>{{ number_format($team['summary']['dm_camp_count']) }}</strong>
                <span class="mx-1">·</span>
                Total Leads count <strong>{{ number_format($team['summary']['total_leads']) }}</strong>
            </div>
        </div>
        <div class="text-muted small">FTD = visits on {{ $to->format('d-m-Y') }} · MTD = visits {{ $to->copy()->startOfMonth()->format('d-m') }} to {{ $to->format('d-m-Y') }}</div>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm table-hover text-center align-middle mb-0" id="teamTable">
                <thead class="table-light">
                    <tr>
                        <th rowspan="2" class="text-start">Agent Name</th>
                        <th rowspan="2">Dial Count</th>
                        <th rowspan="2">Connected</th>
                        <th rowspan="2">Not connected</th>
                        <th rowspan="2">Missed calls</th>
                        <th rowspan="2">Appointmented</th>
                        <th rowspan="2">Visited</th>
                        <th rowspan="2">Missed visit</th>
                        <th colspan="{{ max($team['centers']->count(), 1) }}">Center wise visit</th>
                        <th rowspan="2" title="Visits for the day">FTD</th>
                        <th rowspan="2" title="Visits month to date">MTD</th>
                    </tr>
                    <tr>
                        @forelse($team['centers'] as $center)
                            <th class="fw-normal small">{{ $center->name }}</th>
                        @empty
                            <th>—</th>
                        @endforelse
                    </tr>
                </thead>
                <tbody>
                    @forelse($team['rows'] as $row)
                        <tr>
                            <td class="text-start fw-semibold">{{ $row['agent'] }}</td>
                            <td>{{ $row['dials'] }}</td>
                            <td class="text-success">{{ $row['connected'] }}</td>
                            <td>{{ $row['not_connected'] }}</td>
                            <td class="{{ $row['missed_calls'] ? 'text-danger' : '' }}">{{ $row['missed_calls'] }}</td>
                            <td>{{ $row['appointments_booked'] }}</td>
                            <td class="text-success">{{ $row['visited'] }}</td>
                            <td class="{{ $row['missed_visits'] ? 'text-danger' : '' }}">{{ $row['missed_visits'] }}</td>
                            @forelse($team['centers'] as $center)
                                <td>{{ $row['visits_by_center'][$center->id] ?? 0 }}</td>
                            @empty
                                <td>—</td>
                            @endforelse
                            <td>{{ $row['visits_ftd'] }}</td>
                            <td>{{ $row['visits_mtd'] }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="{{ 10 + max($team['centers']->count(), 1) }}" class="text-muted py-3">No active telecallers.</td></tr>
                    @endforelse
                </tbody>
                @if(count($team['rows']) > 1)
                <tfoot class="table-light fw-semibold">
                    <tr>
                        <td class="text-start">Team</td>
                        <td>{{ $team['totals']['dials'] }}</td>
                        <td>{{ $team['totals']['connected'] }}</td>
                        <td>{{ $team['totals']['not_connected'] }}</td>
                        <td>{{ $team['totals']['missed_calls'] }}</td>
                        <td>{{ $team['totals']['appointments_booked'] }}</td>
                        <td>{{ $team['totals']['visited'] }}</td>
                        <td>{{ $team['totals']['missed_visits'] }}</td>
                        @forelse($team['centers'] as $center)
                            <td>{{ $team['totals']['visits_by_center'][$center->id] ?? 0 }}</td>
                        @empty
                            <td>—</td>
                        @endforelse
                        <td>{{ $team['totals']['visits_ftd'] }}</td>
                        <td>{{ $team['totals']['visits_mtd'] }}</td>
                    </tr>
                </tfoot>
                @endif
            </table>
        </div>
    </div>
</div>
@endif

<!-- Charts Row -->
<div class="row g-3 mb-4">
    <div class="col-lg-8">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-header bg-transparent d-flex justify-content-between align-items-center">
                <h6 class="mb-0 fw-semibold">Daily Lead Trend (Last 14 Days)</h6>
            </div>
            <div class="card-body"><div id="dailyTrendChart"></div></div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card shadow-sm border-0 h-100">
            <div class="card-header bg-transparent">
                <h6 class="mb-0 fw-semibold">Leads by Source</h6>
            </div>
            <div class="card-body"><div id="sourceChart"></div></div>
        </div>
    </div>
</div>

<!-- Recent Leads -->
<div class="card shadow-sm border-0">
    <div class="card-header bg-transparent d-flex justify-content-between align-items-center">
        <h6 class="mb-0 fw-semibold">Recent Leads</h6>
        <a href="{{ route('leads.index') }}" class="btn btn-sm btn-outline-primary">View All</a>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0" id="recentLeadsTable">
                <thead class="table-light">
                    <tr>
                        <th>Name</th><th>Mobile</th><th>Source</th>
                        <th>Stage</th><th>Assigned To</th><th>Date</th><th></th>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
// Daily trend chart
const trendData = @json($dailyTrend);
const trendOptions = {
    chart: { type: 'area', height: 220, toolbar: { show: false }, sparkline: { enabled: false } },
    series: [{ name: 'Leads', data: trendData.map(d => d.total) }],
    xaxis: { categories: trendData.map(d => d.date), labels: { rotate: -45, style: { fontSize: '11px' } } },
    colors: [FUI.colors[0]],
    fill: { type: 'gradient', gradient: { opacityFrom: .4, opacityTo: .05 } },
    stroke: { curve: 'smooth', width: 2 },
    dataLabels: { enabled: false },
    grid: { borderColor: '#f0f0f0' },
    tooltip: { x: { format: 'dd MMM' } },
};
new ApexCharts(document.querySelector('#dailyTrendChart'), trendOptions).render();

// Source donut chart
const sourceData = @json($sourceChart);
const sourceOptions = {
    chart: { type: 'donut', height: 220 },
    series: sourceData.map(d => d.total),
    labels: sourceData.map(d => d.source),
    legend: { position: 'bottom', fontSize: '12px' },
    dataLabels: { enabled: false },
    plotOptions: { pie: { donut: { size: '65%' } } },
    colors: FUI.colors,
};
new ApexCharts(document.querySelector('#sourceChart'), sourceOptions).render();

// Recent leads mini-table
$('#recentLeadsTable').DataTable({
    processing: true, serverSide: true,
    ajax: '{{ route("leads.index") }}',
    columns: [
        { data: 'name', defaultContent: '—' },
        { data: 'mobile' },
        { data: 'source.name', defaultContent: '—' },
        { data: 'stage_badge', orderable: false },
        // Rendered from a relation; not a real column, so it cannot be sorted or searched server-side.
        { data: 'assigned_to.name', defaultContent: 'Unassigned', orderable: false, searchable: false },
        { data: 'created_at', render: d => FUI.date(d) },
        { data: 'action', orderable: false, searchable: false },
    ],
    pageLength: 10, ordering: false,
    dom: 'tp',
    language: { emptyTable: FUI.emptyState('bi-alarm', 'Nothing due right now') },
});
</script>
@endpush
