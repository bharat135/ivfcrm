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

<!-- KPI Cards -->
<div class="row g-3 mb-4">
    @php
    // Tone names, not hex: the design system owns the palette so these cards
    // follow light/dark and any future rebrand automatically.
    $cards = [
        ['label' => "Today's Leads",       'value' => $metrics['todayLeads'],        'icon' => 'bi-people',            'tone' => 'primary'],
        ['label' => 'Active Leads',        'value' => $metrics['activeLeads'],       'icon' => 'bi-graph-up-arrow',    'tone' => 'info'],
        ['label' => "Today's Calls",       'value' => $metrics['todayCalls'],        'icon' => 'bi-telephone',         'tone' => 'success'],
        ['label' => 'Follow-Ups Today',    'value' => $metrics['todayFollows'],      'icon' => 'bi-alarm',             'tone' => 'warning'],
        ['label' => 'Overdue Follow-Ups',  'value' => $metrics['overdueFollows'],    'icon' => 'bi-exclamation-triangle', 'tone' => 'danger'],
        ['label' => 'Appointments Today',  'value' => $metrics['todayAppointments'], 'icon' => 'bi-calendar-check',    'tone' => 'info'],
        ['label' => 'Monthly Conversions', 'value' => $metrics['monthlyConversions'],'icon' => 'bi-trophy',            'tone' => 'success'],
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
        { data: 'assigned_to.name', defaultContent: 'Unassigned' },
        { data: 'created_at', render: d => FUI.date(d) },
        { data: 'action', orderable: false, searchable: false },
    ],
    pageLength: 10, ordering: false,
    dom: 'tp',
    language: { emptyTable: FUI.emptyState('bi-alarm', 'Nothing due right now') },
});
</script>
@endpush
