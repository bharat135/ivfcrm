@extends('layouts.app')

@section('title', 'Google Ads')

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('settings.integrations.index') }}">Integrations</a></li>
    <li class="breadcrumb-item active">Google Ads</li>
@endsection

@php
    $connected = $missing === [];
    $capture   = $counts['leads'] > 0 ? round($counts['with_click_id'] / $counts['leads'] * 100) : null;
@endphp

@section('content')

<div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
    <div>
        <h5 class="fw-bold mb-1"><i class="bi bi-google me-2" style="color:#4285f4"></i>Google Ads</h5>
        <p class="text-muted small mb-0">
            Capture the click that produced each enquiry, then send the booking back so bidding optimises for couples who turn up.
        </p>
    </div>
    <div class="d-flex gap-2">
        <button class="btn btn-outline-secondary btn-sm" id="testConnection">
            <i class="bi bi-plug me-1"></i>Test connection
        </button>
        @can('integrations.edit')
        <button class="btn btn-outline-secondary btn-sm" id="syncSpend">
            <i class="bi bi-arrow-repeat me-1"></i>Sync spend
        </button>
        @endcan
        @can('integrations.edit')
        <button class="btn btn-primary btn-sm" id="saveGoogle"><i class="bi bi-save me-1"></i>Save</button>
        @endcan
    </div>
</div>

{{-- Capture health. Google spend with no stored click ids almost always means
     auto-tagging is off, and no amount of correct credentials will fix it. --}}
<div class="row g-3 mb-3">
    <div class="col-md-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="text-muted small">Connection</div>
                <div class="fs-5 fw-semibold">
                    @if($connected)
                        <span class="text-success"><i class="bi bi-check-circle me-1"></i>Configured</span>
                    @else
                        <span class="text-warning"><i class="bi bi-exclamation-circle me-1"></i>Incomplete</span>
                    @endif
                </div>
                @unless($connected)
                    <div class="text-muted small mt-1">Missing: {{ implode(', ', $missing) }}</div>
                @endunless
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="text-muted small">Google Ads enquiries</div>
                <div class="fs-5 fw-semibold">{{ number_format($counts['leads']) }}</div>
                <div class="text-muted small mt-1">All of them are patient records too.</div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="text-muted small">Spend synced (30 days)</div>
                <div class="fs-5 fw-semibold">
                    @if($spend['total'] > 0)
                        ₹{{ number_format($spend['total'], 0) }}
                    @else
                        <span class="text-muted">—</span>
                    @endif
                </div>
                <div class="text-muted small mt-1">
                    @if($lastSync)
                        Last sync {{ \Illuminate\Support\Carbon::parse($lastSync)->diffForHumans() }}.
                    @else
                        Not synced yet.
                    @endif
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-2">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <div class="text-muted small">With a click ID</div>
                <div class="fs-5 fw-semibold">
                    {{ number_format($counts['with_click_id']) }}
                    @if($capture !== null)
                        <span class="text-muted fs-6">({{ $capture }}%)</span>
                    @endif
                </div>
                @if($capture !== null && $capture < 80)
                    <div class="text-warning small mt-1">
                        <i class="bi bi-exclamation-triangle me-1"></i>Turn on auto-tagging in Google Ads.
                    </div>
                @else
                    <div class="text-muted small mt-1">gclid, gbraid or wbraid stored.</div>
                @endif
            </div>
        </div>
    </div>
</div>

@if($syncError)
    <div class="alert alert-warning d-flex mb-3">
        <i class="bi bi-exclamation-triangle mt-1"></i>
        <div>
            The last spend sync failed: {{ $syncError }}
            <div class="small mt-1">Conversion uploads use the same credentials, so they are probably failing too.</div>
        </div>
    </div>
@endif

<div id="testResult"></div>

<form id="googleForm">
    @csrf
    @method('PUT')

    <div class="row g-3">
        <div class="col-lg-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-transparent py-2">
                    <h6 class="mb-0 fw-semibold small">API access</h6>
                </div>
                <div class="card-body">
                    <div class="alert d-flex mb-3">
                        <i class="bi bi-info-circle mt-1"></i>
                        <div>
                            These come from a Google Cloud OAuth client plus an approved developer token from your
                            Google Ads manager account. Leave a secret blank to keep the stored value.
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small fw-semibold">OAuth client ID</label>
                        <input type="text" class="form-control form-control-sm" name="client_id"
                               value="{{ $google['client_id'] ?? '' }}" placeholder="1234567890-abc.apps.googleusercontent.com">
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">OAuth client secret</label>
                        <input type="text" class="form-control form-control-sm" name="client_secret"
                               value="{{ $google['client_secret'] ?? '' }}" placeholder="GOCSPX-…">
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Refresh token</label>
                        <input type="text" class="form-control form-control-sm" name="refresh_token"
                               value="{{ $google['refresh_token'] ?? '' }}" placeholder="1//0g…">
                        <div class="form-text">Generated once by authorising the OAuth client against the Ads account.</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-semibold">Developer token</label>
                        <input type="text" class="form-control form-control-sm" name="developer_token"
                               value="{{ $google['developer_token'] ?? '' }}">
                    </div>

                    <div class="row g-2">
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Customer ID</label>
                            <input type="text" class="form-control form-control-sm" name="customer_id"
                                   value="{{ $google['customer_id'] ?? '' }}" placeholder="123-456-7890">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label small fw-semibold">Manager (MCC) ID</label>
                            <input type="text" class="form-control form-control-sm" name="login_customer_id"
                                   value="{{ $google['login_customer_id'] ?? '' }}" placeholder="Optional">
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-header bg-transparent py-2 d-flex justify-content-between align-items-center">
                    <h6 class="mb-0 fw-semibold small">What to report back</h6>
                    <button type="button" class="btn btn-xs btn-outline-secondary" id="loadActions">
                        <i class="bi bi-arrow-repeat me-1"></i>Load actions
                    </button>
                </div>
                <div class="card-body">
                    <div class="alert d-flex mb-3">
                        <i class="bi bi-lightbulb mt-1"></i>
                        <div>
                            Map each stage of the funnel to a Google conversion action. Leave a stage blank to not report
                            it. Most clinics bid on <strong>Consultation</strong> once they have enough volume.
                        </div>
                    </div>

                    @foreach($stages as $key => $label)
                        <div class="row g-2 mb-2 align-items-end">
                            <div class="col-7">
                                <label class="form-label small fw-semibold mb-1">
                                    <i class="bi {{ \App\Support\PatientJourney::icon($key) }} me-1"></i>{{ $label }}
                                </label>
                                <input type="text" class="form-control form-control-sm action-input"
                                       name="action_{{ $key }}" data-stage="{{ $key }}"
                                       list="conversionActions"
                                       value="{{ $google['action_' . $key] ?? '' }}"
                                       placeholder="customers/…/conversionActions/…">
                            </div>
                            <div class="col-5">
                                <label class="form-label small fw-semibold mb-1">Value</label>
                                <input type="number" step="0.01" min="0" class="form-control form-control-sm"
                                       name="value_{{ $key }}" value="{{ $google['value_' . $key] ?? '' }}"
                                       placeholder="Optional">
                            </div>
                        </div>
                    @endforeach

                    <datalist id="conversionActions"></datalist>

                    <div class="mt-3">
                        <label class="form-label small fw-semibold">Currency</label>
                        <input type="text" class="form-control form-control-sm" name="currency" style="max-width:120px"
                               value="{{ $google['currency'] ?? 'INR' }}">
                    </div>
                </div>
            </div>
        </div>
    </div>
</form>
@endsection

@push('scripts')
<script>
const form = document.getElementById('googleForm');

function payload() {
    const data = Object.fromEntries(new FormData(form).entries());
    delete data._method;
    return data;
}

function notice(ok, message, hint) {
    const cls = ok ? 'alert-success' : 'alert-warning';
    const icon = ok ? 'bi-check-circle' : 'bi-exclamation-triangle';
    document.getElementById('testResult').innerHTML =
        `<div class="alert ${cls} d-flex mb-3"><i class="bi ${icon} mt-1"></i><div>${message}${hint ? '<div class="small mt-1">' + hint + '</div>' : ''}</div></div>`;
}

$('#saveGoogle').on('click', function () {
    const btn = $(this).prop('disabled', true);

    $.ajax({
        url: '{{ route("settings.integrations.google.update") }}',
        method: 'POST',
        data: Object.assign(payload(), { _method: 'PUT' }),
    }).done(r => {
        toastr.success(r.message || 'Saved.');
        setTimeout(() => window.location.reload(), 600);
    }).fail(xhr => {
        const errors = xhr.responseJSON?.errors || {};
        toastr.error(Object.values(errors).flat().join('<br>') || 'Could not save these settings.');
        btn.prop('disabled', false);
    });
});

$('#testConnection').on('click', function () {
    const btn = $(this).prop('disabled', true);
    notice(true, 'Checking…');

    $.post('{{ route("settings.integrations.google.test") }}')
        .done(r => {
            if (r.success) {
                notice(true, `Connected to <strong>${r.account.name}</strong> — ${r.account.currency}, ${r.account.timezone}.`);
            } else {
                notice(false, r.message, r.hint);
            }
        })
        .fail(() => notice(false, 'The connection test could not be run.'))
        .always(() => btn.prop('disabled', false));
});

$('#syncSpend').on('click', function () {
    const btn = $(this).prop('disabled', true);
    notice(true, 'Pulling the last 30 days of spend from Google…');

    $.post('{{ route("settings.integrations.google.sync") }}')
        .done(r => {
            notice(r.success, r.message);
            if (r.success) setTimeout(() => window.location.reload(), 1200);
        })
        .fail(() => notice(false, 'The spend sync could not be run.'))
        .always(() => btn.prop('disabled', false));
});

// Populates the datalist so the resource names can be picked, not typed.
$('#loadActions').on('click', function () {
    const btn = $(this).prop('disabled', true);

    $.get('{{ route("settings.integrations.google.actions") }}')
        .done(r => {
            if (!r.success) { toastr.error(r.message); return; }

            const list = document.getElementById('conversionActions');
            list.innerHTML = r.actions
                .map(a => `<option value="${a.resource_name}">${a.name} (${a.category})</option>`)
                .join('');

            toastr.success(`${r.actions.length} conversion actions loaded — click a field to pick one.`);
        })
        .fail(() => toastr.error('Could not load conversion actions.'))
        .always(() => btn.prop('disabled', false));
});
</script>
@endpush
