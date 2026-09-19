@extends('layouts.app')

@section('title', $campaign->name)

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">Dashboard</a></li>
    <li class="breadcrumb-item"><a href="{{ route('marketing.campaigns.index') }}">Campaigns</a></li>
    <li class="breadcrumb-item active">{{ Str::limit($campaign->name, 40) }}</li>
@endsection

@section('content')

<div class="d-flex flex-wrap align-items-center gap-2 mb-3">
    <div class="me-auto">
        <h5 class="mb-1">
            {{ $campaign->name }}
            <span class="badge bg-{{ $campaign->statusBadge() }}">{{ $campaign->effective_status ?: $campaign->status }}</span>
        </h5>
        <small class="text-muted">
            {{ $campaign->objective ?: 'No objective' }}
            @if($campaign->external_id) · <span class="font-monospace">{{ $campaign->external_id }}</span> @endif
            @if($campaign->center) · {{ $campaign->center->name }} @endif
            @if($campaign->synced_at) · synced {{ $campaign->synced_at->diffForHumans() }} @endif
        </small>
    </div>

    @can('campaigns.create')
        <button class="btn btn-sm btn-primary" id="btnNewAdset"><i class="bi bi-plus-lg me-1"></i>New ad set</button>
    @endcan
</div>

@if($campaign->sync_error)
    <div class="alert alert-danger small"><i class="bi bi-exclamation-octagon me-1"></i>{{ $campaign->sync_error }}</div>
@endif

{{-- KPI strip --}}
<div class="row g-3 mb-3">
    @foreach([
        ['Spend',       '₹' . number_format($totals['spend'], 0),        'bi-currency-rupee', 'primary'],
        ['Impressions', number_format($totals['impressions']),           'bi-eye',            'info'],
        ['Clicks',      number_format($totals['clicks']),                'bi-hand-index',     'warning'],
        ['Reach',       number_format($totals['reach']),                 'bi-people',         'secondary'],
        ['CRM Leads',   number_format($totals['leads']),                 'bi-person-plus',    'success'],
        ['CPL',         $totals['leads'] ? '₹' . number_format($totals['spend'] / $totals['leads'], 0) : '—', 'bi-tag', 'danger'],
    ] as [$label, $value, $icon, $color])
        <div class="col-6 col-lg-2">
            <div class="card stat-card shadow-sm h-100">
                <div class="card-body p-3">
                    <div class="icon-box bg-{{ $color }}-subtle text-{{ $color }} mb-2"><i class="bi {{ $icon }}"></i></div>
                    <div class="h5 mb-0">{{ $value }}</div>
                    <small class="text-muted">{{ $label }}</small>
                </div>
            </div>
        </div>
    @endforeach
</div>

<div class="row g-3">
    <div class="col-lg-8">
        <div class="card shadow-sm mb-3">
            <div class="card-header bg-white fw-semibold">Daily performance (last 30 days)</div>
            <div class="card-body"><div id="chartPerf" style="height:280px"></div></div>
        </div>

        <div class="card shadow-sm">
            <div class="card-header bg-white fw-semibold">Ad sets</div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Name</th><th>Status</th><th>Goal</th><th>Budget</th>
                            <th>Audience</th><th class="text-end">Ads</th><th class="text-end">Leads</th><th></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($campaign->adsets as $adset)
                            <tr>
                                <td><a href="{{ route('marketing.adsets.show', $adset) }}">{{ $adset->name }}</a></td>
                                <td><span class="badge bg-{{ $adset->statusBadge() }}">{{ $adset->effective_status ?: $adset->status }}</span></td>
                                <td class="small">{{ $adset->optimization_goal ?: '—' }}</td>
                                <td class="small">
                                    @if($adset->daily_budget) ₹{{ number_format($adset->daily_budget, 0) }}<span class="text-muted">/day</span>
                                    @elseif($adset->lifetime_budget) ₹{{ number_format($adset->lifetime_budget, 0) }}
                                    @else — @endif
                                </td>
                                <td class="small text-muted">{{ $adset->targetingSummary() }}</td>
                                <td class="text-end">{{ $adset->ads_count }}</td>
                                <td class="text-end">{{ $adset->leads_count }}</td>
                                <td class="text-end">
                                    <a href="{{ route('marketing.adsets.show', $adset) }}" class="btn btn-sm btn-outline-primary">
                                        <i class="bi bi-arrow-right"></i>
                                    </a>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="8" class="text-center text-muted py-4">
                                No ad sets yet. Create one to define budget and audience.
                            </td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="card shadow-sm mb-3">
            <div class="card-header bg-white fw-semibold">Campaign details</div>
            <div class="card-body small">
                <dl class="row mb-0">
                    <dt class="col-5 text-muted">Platform</dt><dd class="col-7">{{ ucfirst($campaign->platform ?: '—') }}</dd>
                    <dt class="col-5 text-muted">Daily budget</dt><dd class="col-7">{{ $campaign->daily_budget ? '₹' . number_format($campaign->daily_budget, 0) : '—' }}</dd>
                    <dt class="col-5 text-muted">Lifetime budget</dt><dd class="col-7">{{ $campaign->lifetime_budget ? '₹' . number_format($campaign->lifetime_budget, 0) : '—' }}</dd>
                    <dt class="col-5 text-muted">Bid strategy</dt><dd class="col-7">{{ $campaign->bid_strategy ?: '—' }}</dd>
                    <dt class="col-5 text-muted">Runs</dt>
                    <dd class="col-7">
                        {{ $campaign->start_date?->format('d-m-Y') ?: '—' }} → {{ $campaign->end_date?->format('d-m-Y') ?: 'ongoing' }}
                    </dd>
                    <dt class="col-5 text-muted">Lead source</dt><dd class="col-7">{{ $campaign->source?->name ?: '—' }}</dd>
                    <dt class="col-5 text-muted">UTM campaign</dt><dd class="col-7">{{ $campaign->utm_campaign ?: '—' }}</dd>
                </dl>
            </div>
        </div>

        <div class="card shadow-sm">
            <div class="card-header bg-white fw-semibold">Recent leads</div>
            <div class="list-group list-group-flush" style="max-height:400px;overflow-y:auto">
                @forelse($campaign->leads()->with('stage')->latest()->limit(15)->get() as $lead)
                    <a href="{{ route('leads.show', $lead) }}" class="list-group-item list-group-item-action py-2">
                        <div class="d-flex justify-content-between">
                            <span class="fw-semibold">{{ $lead->name ?: 'Unnamed' }}</span>
                            <small class="text-muted">{{ $lead->created_at->diffForHumans() }}</small>
                        </div>
                        <small class="text-muted">{{ $lead->mobile }} · {{ $lead->stage?->name ?: $lead->status }}</small>
                    </a>
                @empty
                    <div class="list-group-item text-muted small text-center py-4">No leads attributed yet.</div>
                @endforelse
            </div>
        </div>
    </div>
</div>

@include('marketing.adsets._modal', [
    'campaigns'         => collect([$campaign]),
    'optimizationGoals' => $optimizationGoals,
    'billingEvents'     => $billingEvents,
    'destinationTypes'  => $destinationTypes,
    'lockedCampaignId'  => $campaign->id,
])
@endsection

@push('scripts')
@php
    // Shaped here rather than inline: Blade's @json argument parser cannot span
    // a multi-line closure.
    $chartData = $insights->map(fn ($i) => [
        'date'        => $i->date->format('d-m'),
        'spend'       => (float) $i->spend,
        'impressions' => (int) $i->impressions,
        'clicks'      => (int) $i->clicks,
    ])->values();
@endphp
<script>
$(function () {
    const insights = @json($chartData);

    new ApexCharts(document.querySelector('#chartPerf'), {
        chart: { type: 'line', height: 280, toolbar: { show: false } },
        series: [
            { name: 'Spend (₹)',   type: 'column', data: insights.map(i => i.spend) },
            { name: 'Impressions', type: 'line',   data: insights.map(i => i.impressions) },
            { name: 'Clicks',      type: 'line',   data: insights.map(i => i.clicks) },
        ],
        xaxis: { categories: insights.map(i => i.date) },
        yaxis: [
            { title: { text: 'Spend (₹)' } },
            { opposite: true, title: { text: 'Impressions' } },
            { show: false },
        ],
        stroke: { width: [0, 2, 2], curve: 'smooth' },
        colors: FUI.colors,
        noData: { text: 'No insight data yet — run a sync once the campaign has spend.' },
    }).render();

    $('#btnNewAdset').on('click', () => new bootstrap.Modal('#adsetModal').show());
});
</script>
@endpush
