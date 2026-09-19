@extends('layouts.app')

@section('title', 'Conversion Funnel')

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">Dashboard</a></li>
    <li class="breadcrumb-item"><a href="{{ route('marketing.analytics.index') }}">Analytics</a></li>
    <li class="breadcrumb-item active">Lead → Visit → Booking Funnel</li>
@endsection

@push('styles')
<style>
    /* The bar is drawn as a hard-stop gradient so one element carries both the
       fill width and the track — no nested wrapper needed. */
    .funnel-step {
        display: flex; align-items: center; gap: 12px;
        padding: 12px 14px;
        border-radius: var(--fui-radius-lg);
        margin-bottom: 8px;
        border: 1px solid var(--fui-border-subtle);
        background: linear-gradient(90deg, var(--step-color) var(--step-width), var(--fui-surface) var(--step-width));
        transition: transform .12s ease;
    }
    .funnel-step:hover { transform: translateX(2px); }
    .funnel-step .step-icon {
        width: 30px; height: 30px;
        border-radius: var(--fui-radius);
        background: rgba(255, 255, 255, .9);
        color: var(--fui-gray-950);
        display: flex; align-items: center; justify-content: center;
        font-size: 14px;
        flex-shrink: 0;
    }
    .funnel-step .step-count { font-size: var(--fui-text-md); font-weight: 600; letter-spacing: -0.02em; }
    .drop-arrow { color: var(--fui-ink-faint); font-size: var(--fui-text-xs); margin: -4px 0 4px 54px; }
</style>
@endpush

@section('content')

{{-- Filters --}}
<form method="GET" class="card shadow-sm mb-3">
    <div class="card-body d-flex flex-wrap align-items-end gap-2">
        <div>
            <label class="form-label small mb-1">From</label>
            <input type="date" name="from" class="form-control form-control-sm" value="{{ $filters['from'] }}">
        </div>
        <div>
            <label class="form-label small mb-1">To</label>
            <input type="date" name="to" class="form-control form-control-sm" value="{{ $filters['to'] }}">
        </div>
        <div>
            <label class="form-label small mb-1">Centre</label>
            <select name="center_id" class="form-select form-select-sm">
                <option value="">All centres</option>
                @foreach($centers as $center)
                    <option value="{{ $center->id }}" @selected($filters['center_id'] == $center->id)>{{ $center->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="form-label small mb-1">Campaign</label>
            <select name="campaign_id" class="form-select form-select-sm">
                <option value="">All campaigns</option>
                @foreach($allCampaigns as $campaign)
                    <option value="{{ $campaign->id }}" @selected($filters['campaign_id'] == $campaign->id)>{{ $campaign->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="form-label small mb-1">Lead source</label>
            <select name="lead_source_id" class="form-select form-select-sm">
                <option value="">All sources</option>
                @foreach($sources as $source)
                    <option value="{{ $source->id }}" @selected($filters['lead_source_id'] == $source->id)>{{ $source->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="form-label small mb-1">Channel</label>
            <select name="channel" class="form-select form-select-sm">
                <option value="">All channels</option>
                @foreach($channelOptions as $key => $label)
                    <option value="{{ $key }}" @selected(($filters['channel'] ?? null) === $key)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <button class="btn btn-sm btn-primary"><i class="bi bi-funnel me-1"></i>Apply</button>
    </div>
</form>

{{-- Cost efficiency --}}
<div class="row g-3 mb-3">
    @foreach([
        ['Ad spend',            '₹' . number_format($funnel['cost']['spend'], 0),                                       'bi-currency-rupee', 'primary'],
        ['Cost / lead',         $funnel['cost']['cost_per_lead'] ? '₹' . number_format($funnel['cost']['cost_per_lead'], 0) : '—', 'bi-person-plus', 'info'],
        ['Cost / booking',      $funnel['cost']['cost_per_booking'] ? '₹' . number_format($funnel['cost']['cost_per_booking'], 0) : '—', 'bi-calendar-check', 'warning'],
        ['Cost / visit',        $funnel['cost']['cost_per_visit'] ? '₹' . number_format($funnel['cost']['cost_per_visit'], 0) : '—', 'bi-door-open', 'secondary'],
        ['Lead → visit',        $funnel['cost']['lead_to_visit'] . '%',                                                 'bi-arrow-right-circle', 'success'],
        ['Visit → converted',   $funnel['cost']['visit_to_won'] . '%',                                                  'bi-trophy',         'danger'],
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
    {{-- Funnel --}}
    <div class="col-lg-6">
        <div class="card shadow-sm h-100">
            <div class="card-header bg-white fw-semibold">
                Funnel · {{ \Carbon\Carbon::parse($funnel['range']['from'])->format('d-m') }}
                – {{ \Carbon\Carbon::parse($funnel['range']['to'])->format('d-m-Y') }}
            </div>
            <div class="card-body">
                @php
                    // One hue deepening down the funnel reads as a single flow,
                    // where seven unrelated hues read as seven unrelated things.
                    $colors = ['#dbeafe', '#bfdbfe', '#93c5fd', '#60a5fa', '#3b82f6', '#2563eb', '#1d4ed8'];
                @endphp

                @foreach($funnel['steps'] as $i => $step)
                    @if($i > 0 && $step['dropped'] > 0)
                        <div class="drop-arrow">
                            <i class="bi bi-arrow-down"></i>
                            {{ number_format($step['dropped']) }} dropped off
                            ({{ round(100 - $step['step_rate'], 1) }}%)
                        </div>
                    @endif

                    <div class="funnel-step"
                         style="--step-color: {{ $colors[$i] ?? '#6c757d' }}; --step-width: {{ max($step['overall_rate'], 6) }}%;">
                        <div class="step-icon"><i class="bi {{ $step['icon'] }}"></i></div>
                        <div class="flex-grow-1">
                            <div class="fw-semibold">{{ $step['label'] }}</div>
                            <small class="text-muted">{{ $step['overall_rate'] }}% of all leads</small>
                        </div>
                        <div class="text-end">
                            <div class="step-count">{{ number_format($step['count']) }}</div>
                            @if($i > 0)
                                <small class="badge bg-light text-dark border">{{ $step['step_rate'] }}% of previous</small>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </div>

    {{-- Trend --}}
    <div class="col-lg-6">
        <div class="card shadow-sm h-100">
            <div class="card-header bg-white fw-semibold">Leads, bookings and visits over time</div>
            <div class="card-body"><div id="chartFunnelTrend" style="height:380px"></div></div>
        </div>
    </div>
</div>

{{-- Per-campaign --}}
{{-- Google versus Meta, all the way through to a started cycle. This table is
     the reason leads and patients are one record: the left half is marketing,
     the right half is clinical, and they are the same people. --}}
<div class="card shadow-sm mt-3">
    <div class="card-header bg-white d-flex justify-content-between align-items-center">
        <span class="fw-semibold">Which channel produces patients</span>
        <span class="text-muted small">Enquiry → consultation → treatment</span>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0 small">
            <thead>
                <tr>
                    <th>Channel</th>
                    <th class="text-end">Enquiries</th>
                    <th class="text-end">Contacted</th>
                    <th class="text-end">Consultation</th>
                    <th class="text-end">Treatment</th>
                    <th class="text-end">Enquiry → consult</th>
                    <th class="text-end">Spend</th>
                    <th class="text-end">Cost / enquiry</th>
                    <th class="text-end">Cost / consult</th>
                    <th class="text-end">Cost / cycle</th>
                </tr>
            </thead>
            <tbody>
                @forelse($channels as $row)
                    <tr>
                        <td>
                            <div class="d-flex align-items-center gap-2">
                                <i class="bi {{ $row['icon'] }}" style="color: {{ $row['color'] }}"></i>
                                <span class="fw-semibold">{{ $row['label'] }}</span>
                                @if($row['is_paid'])
                                    <span class="badge text-bg-light text-body">Paid</span>
                                @endif
                            </div>
                        </td>
                        <td class="text-end">{{ number_format($row['leads']) }}</td>
                        <td class="text-end">{{ number_format($row['contacted']) }}</td>
                        <td class="text-end">{{ number_format($row['consultation']) }}</td>
                        <td class="text-end fw-semibold">{{ number_format($row['treatment']) }}</td>
                        <td class="text-end">{{ $row['lead_to_visit'] }}%</td>
                        <td class="text-end">
                            @if($row['spend_known'])
                                ₹{{ number_format($row['spend'], 0) }}
                            @elseif($row['is_paid'])
                                {{-- Never show ₹0 for a paid channel with no synced
                                     spend: it would make it look free. --}}
                                <span class="text-muted" title="Connect this channel to sync its spend">Not synced</span>
                            @else
                                <span class="text-muted">—</span>
                            @endif
                        </td>
                        <td class="text-end">{{ $row['cost_per_lead'] ? '₹' . number_format($row['cost_per_lead'], 0) : '—' }}</td>
                        <td class="text-end">{{ $row['cost_per_visit'] ? '₹' . number_format($row['cost_per_visit'], 0) : '—' }}</td>
                        <td class="text-end">{{ $row['cost_per_cycle'] ? '₹' . number_format($row['cost_per_cycle'], 0) : '—' }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="10" class="text-center text-muted py-4">
                            No enquiries in this window.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

<div class="card shadow-sm mt-3">
    <div class="card-header bg-white fw-semibold">Funnel by campaign</div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0 small">
            <thead class="table-light">
                <tr>
                    <th>Campaign</th><th>Status</th>
                    <th class="text-end">Spend</th><th class="text-end">Impr.</th>
                    <th class="text-end">Leads</th><th class="text-end">Booked</th>
                    <th class="text-end">Visited</th><th class="text-end">Won</th>
                    <th class="text-end">CPL</th><th class="text-end">Cost / visit</th>
                    <th class="text-end">Lead → visit</th>
                </tr>
            </thead>
            <tbody>
                @forelse($campaigns as $campaign)
                    <tr>
                        <td class="text-truncate" style="max-width:220px">
                            <a href="{{ route('marketing.campaigns.show', $campaign) }}">{{ $campaign->name }}</a>
                        </td>
                        <td><span class="badge bg-{{ $campaign->statusBadge() }}">{{ $campaign->effective_status ?: $campaign->status }}</span></td>
                        <td class="text-end">₹{{ number_format($campaign->period_spend, 0) }}</td>
                        <td class="text-end">{{ number_format($campaign->period_impressions) }}</td>
                        <td class="text-end fw-semibold">{{ number_format($campaign->leads_count) }}</td>
                        <td class="text-end">{{ number_format($campaign->booked_count) }}</td>
                        <td class="text-end">{{ number_format($campaign->visited_count) }}</td>
                        <td class="text-end">{{ number_format($campaign->won_count) }}</td>
                        <td class="text-end">{{ $campaign->cpl ? '₹' . number_format($campaign->cpl, 0) : '—' }}</td>
                        <td class="text-end">{{ $campaign->cost_per_visit ? '₹' . number_format($campaign->cost_per_visit, 0) : '—' }}</td>
                        <td class="text-end">
                            <span class="badge bg-{{ $campaign->lead_to_visit >= 20 ? 'success' : ($campaign->lead_to_visit >= 10 ? 'warning' : 'secondary') }}">
                                {{ $campaign->lead_to_visit }}%
                            </span>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="11" class="text-center text-muted py-4">No campaigns with leads in this period.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection

@push('scripts')
<script>
$(function () {
    const trend = @json($trend);

    new ApexCharts(document.querySelector('#chartFunnelTrend'), {
        chart: { type: 'line', height: 380, toolbar: { show: false } },
        series: [
            { name: 'New leads', type: 'column', data: trend.series.leads },
            { name: 'Booked',    type: 'line',   data: trend.series.booked },
            { name: 'Visited',   type: 'line',   data: trend.series.visited },
            { name: 'Spend (₹)', type: 'line',   data: trend.series.spend },
        ],
        xaxis: { categories: trend.labels },
        yaxis: [
            { title: { text: 'Leads' } },
            { show: false }, { show: false },
            { opposite: true, title: { text: 'Spend (₹)' } },
        ],
        stroke: { width: [0, 3, 3, 2], curve: 'smooth', dashArray: [0, 0, 0, 4] },
        colors: FUI.colors,
        legend: { position: 'top' },
    }).render();
});
</script>
@endpush
