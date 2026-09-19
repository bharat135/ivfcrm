@extends('layouts.app')

@section('title', $adset->name)

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">Dashboard</a></li>
    <li class="breadcrumb-item"><a href="{{ route('marketing.campaigns.index') }}">Campaigns</a></li>
    @if($adset->campaign)
        <li class="breadcrumb-item"><a href="{{ route('marketing.campaigns.show', $adset->campaign) }}">{{ Str::limit($adset->campaign->name, 28) }}</a></li>
    @endif
    <li class="breadcrumb-item active">{{ Str::limit($adset->name, 30) }}</li>
@endsection

@section('content')

<div class="d-flex flex-wrap align-items-center gap-2 mb-3">
    <div class="me-auto">
        <h5 class="mb-1">
            {{ $adset->name }}
            <span class="badge bg-{{ $adset->statusBadge() }}">{{ $adset->effective_status ?: $adset->status }}</span>
        </h5>
        <small class="text-muted">
            {{ $adset->optimization_goal ?: 'No goal' }} · {{ $adset->targetingSummary() }}
            @if($adset->external_id) · <span class="font-monospace">{{ $adset->external_id }}</span>@endif
        </small>
    </div>

    @can('campaigns.create')
        <a href="{{ route('marketing.ads.index', ['adset_id' => $adset->id]) }}" class="btn btn-sm btn-primary">
            <i class="bi bi-plus-lg me-1"></i>Manage ads
        </a>
    @endcan
</div>

@if($adset->sync_error)
    <div class="alert alert-danger small"><i class="bi bi-exclamation-octagon me-1"></i>{{ $adset->sync_error }}</div>
@endif

<div class="row g-3 mb-3">
    @php
        $spend = $insights->sum('spend');
        $impressions = $insights->sum('impressions');
        $clicks = $insights->sum('clicks');
        $leads = $adset->leads()->count();
    @endphp
    @foreach([
        ['Spend',       '₹' . number_format($spend, 0),                                     'bi-currency-rupee', 'primary'],
        ['Impressions', number_format($impressions),                                        'bi-eye',            'info'],
        ['Clicks',      number_format($clicks),                                             'bi-hand-index',     'warning'],
        ['CTR',         $impressions ? round($clicks / $impressions * 100, 2) . '%' : '—',  'bi-percent',        'secondary'],
        ['CRM Leads',   number_format($leads),                                              'bi-person-plus',    'success'],
        ['CPL',         $leads ? '₹' . number_format($spend / $leads, 0) : '—',             'bi-tag',            'danger'],
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
            <div class="card-header bg-white fw-semibold">Daily delivery</div>
            <div class="card-body"><div id="chartAdset" style="height:260px"></div></div>
        </div>

        <div class="card shadow-sm">
            <div class="card-header bg-white fw-semibold">Ads in this ad set</div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr><th style="width:60px"></th><th>Name</th><th>Status</th><th>Headline</th><th class="text-end">Leads</th></tr>
                    </thead>
                    <tbody>
                        @forelse($adset->ads as $ad)
                            <tr>
                                <td>
                                    @if($ad->thumbnail_url)
                                        <img src="{{ $ad->thumbnail_url }}" class="rounded" style="width:44px;height:44px;object-fit:cover">
                                    @else
                                        <div class="bg-light rounded d-flex align-items-center justify-content-center" style="width:44px;height:44px">
                                            <i class="bi bi-image text-muted"></i>
                                        </div>
                                    @endif
                                </td>
                                <td>{{ $ad->name }}</td>
                                <td><span class="badge bg-{{ $ad->statusBadge() }}">{{ $ad->effective_status ?: $ad->status }}</span></td>
                                <td class="small text-muted">{{ Str::limit($ad->headline() ?? '—', 50) }}</td>
                                <td class="text-end">{{ $ad->leads_count ?? 0 }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="text-center text-muted py-4">No ads in this ad set yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="card shadow-sm">
            <div class="card-header bg-white fw-semibold">Targeting</div>
            <div class="card-body small">
                @php $t = $adset->targeting ?? []; @endphp
                <dl class="row mb-0">
                    <dt class="col-5 text-muted">Age</dt>
                    <dd class="col-7">{{ $t['age_min'] ?? 18 }} – {{ $t['age_max'] ?? 65 }}</dd>

                    <dt class="col-5 text-muted">Gender</dt>
                    <dd class="col-7">
                        @php $g = $t['genders'] ?? []; @endphp
                        {{ $g === [1] ? 'Men' : ($g === [2] ? 'Women' : 'All') }}
                    </dd>

                    <dt class="col-5 text-muted">Locations</dt>
                    <dd class="col-7">
                        @php $cities = $t['geo_locations']['cities'] ?? []; @endphp
                        @forelse($cities as $city)
                            <span class="badge bg-light text-dark border">{{ $city['name'] ?? $city['key'] }}</span>
                        @empty
                            {{ implode(', ', $t['geo_locations']['countries'] ?? ['—']) }}
                        @endforelse
                    </dd>

                    <dt class="col-5 text-muted">Interests</dt>
                    <dd class="col-7">
                        @forelse($t['flexible_spec'][0]['interests'] ?? [] as $interest)
                            <span class="badge bg-light text-dark border">{{ $interest['name'] ?? $interest['id'] }}</span>
                        @empty
                            Broad
                        @endforelse
                    </dd>

                    <dt class="col-5 text-muted">Placements</dt>
                    <dd class="col-7">{{ implode(', ', $t['publisher_platforms'] ?? ['facebook', 'instagram']) }}</dd>

                    <dt class="col-5 text-muted">Schedule</dt>
                    <dd class="col-7">
                        {{ $adset->start_time?->format('d-m-Y') ?: '—' }} → {{ $adset->end_time?->format('d-m-Y') ?: 'ongoing' }}
                    </dd>
                </dl>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
@php
    // Shaped here rather than inline: Blade's @json argument parser cannot span
    // a multi-line closure.
    $chartData = $insights->map(fn ($i) => [
        'date'        => $i->date->format('d-m'),
        'spend'       => (float) $i->spend,
        'impressions' => (int) $i->impressions,
    ])->values();
@endphp
<script>
$(function () {
    const insights = @json($chartData);

    new ApexCharts(document.querySelector('#chartAdset'), {
        chart: { type: 'area', height: 260, toolbar: { show: false } },
        series: [
            { name: 'Spend (₹)',   data: insights.map(i => i.spend) },
            { name: 'Impressions', data: insights.map(i => i.impressions) },
        ],
        xaxis: { categories: insights.map(i => i.date) },
        yaxis: [{ title: { text: 'Spend (₹)' } }, { opposite: true, title: { text: 'Impressions' } }],
        stroke: { width: 2, curve: 'smooth' },
        fill: { type: 'gradient', gradient: { opacityFrom: .4, opacityTo: .05 } },
        colors: FUI.colors,
        noData: { text: 'No insight data yet.' },
    }).render();
});
</script>
@endpush
