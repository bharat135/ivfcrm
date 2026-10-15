@extends('layouts.app')

@section('title', 'Marketing Analytics')

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">Dashboard</a></li>
    <li class="breadcrumb-item">Marketing</li>
    <li class="breadcrumb-item active">Analytics</li>
@endsection

@section('content')

{{-- Filters --}}
<div class="card shadow-sm mb-3">
    <div class="card-body d-flex flex-wrap align-items-end gap-2">
        <div>
            <label class="form-label small mb-1">Date range</label>
            <input type="text" class="form-control form-control-sm" id="fRange" style="min-width:220px">
        </div>
        <div>
            <label class="form-label small mb-1">Centre</label>
            <select class="form-select form-select-sm" id="fCenter">
                <option value="">All centres</option>
                @foreach($centers as $center)
                    <option value="{{ $center->id }}" @selected($filters['center_id'] == $center->id)>{{ $center->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="form-label small mb-1">Campaign</label>
            <select class="form-select form-select-sm" id="fCampaign">
                <option value="">All campaigns</option>
                @foreach($campaigns as $campaign)
                    <option value="{{ $campaign->id }}" @selected($filters['campaign_id'] == $campaign->id)>{{ $campaign->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="form-label small mb-1">Ad set</label>
            <select class="form-select form-select-sm" id="fAdset" style="max-width:240px">
                <option value="">All ad sets</option>
                @foreach($adsets as $adset)
                    <option value="{{ $adset->id }}" data-campaign="{{ $adset->campaign_id }}" @selected($filters['adset_id'] == $adset->id)>{{ $adset->campaign?->name }} → {{ $adset->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="form-label small mb-1">Lead source</label>
            <select class="form-select form-select-sm" id="fSource">
                <option value="">All sources</option>
                @foreach($sources as $source)
                    <option value="{{ $source->id }}" @selected($filters['lead_source_id'] == $source->id)>{{ $source->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="form-label small mb-1">Channel</label>
            <select class="form-select form-select-sm" id="fChannel">
                <option value="">All channels</option>
                @foreach($channelOptions as $value => $label)
                    <option value="{{ $value }}" @selected($filters['channel'] == $value)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <button class="btn btn-sm btn-primary" id="btnApply"><i class="bi bi-funnel me-1"></i>Apply</button>
        <button class="btn btn-sm btn-link text-decoration-none" id="fReset" title="Clear filters"><i class="bi bi-x-circle"></i></button>
        <a href="{{ route('marketing.analytics.funnel') }}" class="btn btn-sm btn-outline-secondary ms-auto">
            <i class="bi bi-filter-circle me-1"></i>Conversion funnel
        </a>
    </div>
</div>

{{-- KPI cards --}}
<div class="row g-3 mb-3">
    @php
        $delta = function (?float $value) {
            if ($value === null) return '';
            $up = $value >= 0;
            return '<small class="' . ($up ? 'text-success' : 'text-danger') . '">'
                . '<i class="bi bi-arrow-' . ($up ? 'up' : 'down') . '"></i> ' . abs($value) . '%</small>';
        };
    @endphp

    @foreach([
        ['Ad spend',      '₹' . number_format($summary['spend'], 0),        'bi-currency-rupee', 'primary', $summary['spend_change']],
        ['Impressions',   number_format($summary['impressions']),           'bi-eye',            'info',    null],
        ['Clicks',        number_format($summary['clicks']),                'bi-hand-index',     'warning', null],
        ['CTR',           $summary['ctr'] . '%',                            'bi-percent',        'secondary', null],
        ['CRM leads',     number_format($summary['crm_leads']),             'bi-person-plus',    'success', $summary['crm_leads_change']],
        ['Cost per lead', $summary['cpl'] ? '₹' . number_format($summary['cpl'], 0) : '—', 'bi-tag', 'danger', null],
    ] as [$label, $value, $icon, $color, $change])
        <div class="col-6 col-lg-2">
            <div class="card stat-card shadow-sm h-100">
                <div class="card-body p-3">
                    <div class="icon-box bg-{{ $color }}-subtle text-{{ $color }} mb-2"><i class="bi {{ $icon }}"></i></div>
                    <div class="h5 mb-0">{{ $value }}</div>
                    <small class="text-muted d-block">{{ $label }}</small>
                    {!! $delta($change) !!}
                </div>
            </div>
        </div>
    @endforeach
</div>

<div class="row g-3 mb-3">
    @foreach([
        ['Converted (won)',   number_format($summary['won']),                                          'bi-trophy',    'success'],
        ['Cost per acquisition', $summary['cac'] ? '₹' . number_format($summary['cac'], 0) : '—',       'bi-cash-coin', 'danger'],
        ['Lead → won rate',   $summary['conversion_rate'] . '%',                                       'bi-graph-up',  'primary'],
        ['CPM',              '₹' . number_format($summary['cpm'], 0),                                  'bi-broadcast', 'info'],
        ['WhatsApp chats',    number_format($summary['whatsapp_conversations']),                       'bi-whatsapp',  'success'],
        ['WhatsApp messages', number_format($summary['whatsapp_messages']),                            'bi-chat-dots', 'secondary'],
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
            <div class="card-header bg-white fw-semibold">Spend vs leads vs conversions</div>
            <div class="card-body"><div id="chartTrend" style="height:320px"></div></div>
        </div>

        <div class="card shadow-sm">
            <div class="card-header bg-white d-flex align-items-center gap-2">
                <span class="fw-semibold me-auto">Performance breakdown</span>
                <div class="btn-group btn-group-sm">
                    <input type="radio" class="btn-check" name="level" id="lvlCampaign" value="campaign" checked>
                    <label class="btn btn-outline-secondary" for="lvlCampaign">Campaign</label>
                    <input type="radio" class="btn-check" name="level" id="lvlAdset" value="adset">
                    <label class="btn btn-outline-secondary" for="lvlAdset">Ad set</label>
                    <input type="radio" class="btn-check" name="level" id="lvlAd" value="ad">
                    <label class="btn btn-outline-secondary" for="lvlAd">Ad</label>
                </div>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 small">
                    <thead class="table-light">
                        <tr>
                            <th>Name</th><th>Status</th>
                            <th class="text-end">Spend</th><th class="text-end">Impr.</th>
                            <th class="text-end">Clicks</th><th class="text-end">CTR</th>
                            <th class="text-end">CPC</th><th class="text-end">Leads</th>
                            <th class="text-end">CPL</th><th class="text-end">Won</th>
                            <th class="text-end">CAC</th>
                        </tr>
                    </thead>
                    <tbody id="breakdownBody">
                        <tr><td colspan="11" class="text-center text-muted py-4">Loading…</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="card shadow-sm mb-3">
            <div class="card-header bg-white fw-semibold">Cost per lead over time</div>
            <div class="card-body"><div id="chartCpl" style="height:220px"></div></div>
        </div>

        <div class="card shadow-sm">
            <div class="card-header bg-white fw-semibold">Landing pages</div>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0 small">
                    <thead class="table-light">
                        <tr><th>Page</th><th class="text-end">Views</th><th class="text-end">Subs</th><th class="text-end">Rate</th></tr>
                    </thead>
                    <tbody id="lpBody">
                        <tr><td colspan="4" class="text-center text-muted py-3">Loading…</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
$(function () {
    let filters = @json($filters);

    flatpickr('#fRange', {
        mode: 'range',
        altInput: true,
        altFormat: 'd-m-Y',
        dateFormat: 'Y-m-d',
        defaultDate: [filters.from, filters.to],
        onClose(dates, _str, fp) {
            if (dates.length === 2) {
                filters.from = fp.formatDate(dates[0], 'Y-m-d');
                filters.to   = fp.formatDate(dates[1], 'Y-m-d');
            }
        },
    });

    let trendChart, cplChart;

    function currentFilters() {
        return {
            from: filters.from,
            to: filters.to,
            center_id: $('#fCenter').val(),
            campaign_id: $('#fCampaign').val(),
            adset_id: $('#fAdset').val(),
            lead_source_id: $('#fSource').val(),
            channel: $('#fChannel').val(),
        };
    }

    function loadTrend() {
        $.get(@json(route('marketing.analytics.trend')), currentFilters()).done(res => {
            const options = {
                chart: { type: 'line', height: 320, toolbar: { show: false } },
                series: [
                    { name: 'Spend (₹)', type: 'column', data: res.series.spend },
                    { name: 'Leads',     type: 'line',   data: res.series.leads },
                    { name: 'Won',       type: 'line',   data: res.series.won },
                ],
                xaxis: { categories: res.labels },
                yaxis: [
                    { title: { text: 'Spend (₹)' } },
                    { opposite: true, title: { text: 'Count' } },
                    { show: false },
                ],
                stroke: { width: [0, 3, 3], curve: 'smooth' },
                colors: FUI.colors,
                legend: { position: 'top' },
            };

            if (trendChart) trendChart.updateOptions(options);
            else { trendChart = new ApexCharts(document.querySelector('#chartTrend'), options); trendChart.render(); }

            const cplOptions = {
                chart: { type: 'area', height: 220, toolbar: { show: false }, sparkline: { enabled: false } },
                series: [{ name: 'CPL (₹)', data: res.series.cpl }],
                xaxis: { categories: res.labels, labels: { show: false } },
                stroke: { width: 2, curve: 'smooth' },
                fill: { type: 'gradient', gradient: { opacityFrom: .4, opacityTo: .05 } },
                colors: ['#dc3545'],
                dataLabels: { enabled: false },
            };

            if (cplChart) cplChart.updateOptions(cplOptions);
            else { cplChart = new ApexCharts(document.querySelector('#chartCpl'), cplOptions); cplChart.render(); }
        });
    }

    function loadBreakdown() {
        const level = $('input[name=level]:checked').val();

        $.get(@json(route('marketing.analytics.breakdown')), { ...currentFilters(), level }).done(res => {
            if (!res.data.length) {
                return $('#breakdownBody').html('<tr><td colspan="11" class="text-center text-muted py-4">No spend recorded for this period. Run a Meta sync once the campaigns have delivered.</td></tr>');
            }

            const money = n => n === null ? '—' : '₹' + Number(n).toLocaleString('en-IN', { maximumFractionDigits: 0 });

            $('#breakdownBody').html(res.data.map(r => `
                <tr>
                    <td class="text-truncate" style="max-width:220px" title="${$('<div>').text(r.name).html()}">${$('<div>').text(r.name).html()}</td>
                    <td><span class="badge bg-${r.badge}">${r.status || '—'}</span></td>
                    <td class="text-end">${money(r.spend)}</td>
                    <td class="text-end">${r.impressions.toLocaleString('en-IN')}</td>
                    <td class="text-end">${r.clicks.toLocaleString('en-IN')}</td>
                    <td class="text-end">${r.ctr}%</td>
                    <td class="text-end">${money(r.cpc)}</td>
                    <td class="text-end fw-semibold">${r.leads}</td>
                    <td class="text-end">${money(r.cpl)}</td>
                    <td class="text-end">${r.won}</td>
                    <td class="text-end">${money(r.cac)}</td>
                </tr>`).join(''));
        });
    }

    function loadLandingPages() {
        $.get(@json(route('marketing.analytics.landing_pages')), currentFilters()).done(res => {
            if (!res.data.length) {
                return $('#lpBody').html('<tr><td colspan="4" class="text-center text-muted py-3">No landing pages yet.</td></tr>');
            }

            $('#lpBody').html(res.data.map(p => `
                <tr>
                    <td class="text-truncate" style="max-width:140px">
                        <a href="${p.url}" target="_blank">${$('<div>').text(p.name).html()}</a>
                    </td>
                    <td class="text-end">${p.views.toLocaleString('en-IN')}</td>
                    <td class="text-end">${p.submissions.toLocaleString('en-IN')}</td>
                    <td class="text-end">${p.rate}%</td>
                </tr>`).join(''));
        });
    }

    function loadAll() { loadTrend(); loadBreakdown(); loadLandingPages(); }

    // An ad set belongs to one campaign: picking it narrows the campaign too.
    $('#fAdset').on('change', function () {
        const campaign = this.selectedOptions[0]?.dataset.campaign;
        if (campaign) $('#fCampaign').val(campaign);
    });
    $('#fCampaign').on('change', function () {
        const adset = $('#fAdset')[0].selectedOptions[0];
        if (this.value && adset?.dataset.campaign && adset.dataset.campaign !== this.value) $('#fAdset').val('');
    });
    $('#fReset').on('click', () => { location.href = '{{ route('marketing.analytics.index') }}'; });

    $('#btnApply').on('click', function () {
        const params = new URLSearchParams(currentFilters());
        // Reload so the server-rendered KPI cards move with the filters too.
        location.href = '{{ route('marketing.analytics.index') }}?' + params.toString();
    });

    $('input[name=level]').on('change', loadBreakdown);

    loadAll();
});
</script>
@endpush
