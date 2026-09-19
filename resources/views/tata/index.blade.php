@extends('layouts.app')

@section('title', 'Tata Teleservices')

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">Dashboard</a></li>
    <li class="breadcrumb-item active">Tata Teleservices</li>
@endsection

@section('content')
<div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
    <div>
        <h5 class="mb-0">Tata Teleservices <span class="text-muted fw-normal">(Smartflo)</span></h5>
        <small class="text-muted">Cloud telephony — agents, dispositions, numbers, recordings and broadcasts, kept in sync both ways.</small>
    </div>
    <div class="d-flex gap-2">
        @can('tata.edit')
            <button class="btn btn-sm btn-primary" id="btnSyncAll" @disabled(! $configured)><i class="bi bi-arrow-repeat me-1"></i>Sync everything</button>
        @endcan
    </div>
</div>

@unless($configured)
    <div class="alert alert-warning d-flex align-items-center gap-2">
        <i class="bi bi-exclamation-triangle-fill"></i>
        <div class="flex-grow-1">Smartflo is not connected yet. Add your API credentials to start syncing.</div>
        @can('tata.edit')<a href="{{ route('tata.config.index') }}" class="btn btn-sm btn-warning">Open configuration</a>@endcan
    </div>
@endunless

@if($configured && $provider !== 'tata')
    <div class="alert alert-info small">
        <i class="bi bi-info-circle me-1"></i>
        Smartflo is connected but the active calling provider is <strong>Asterisk</strong>. Switch it on the
        <a href="{{ route('tata.config.index') }}">Configuration</a> tab to place calls through Tata.
    </div>
@endif

@include('tata._nav')

<div class="row g-3">
    {{-- Connection --}}
    <div class="col-lg-4">
        <div class="card shadow-sm h-100">
            <div class="card-header bg-white fw-semibold">Connection</div>
            <div class="card-body">
                <dl class="row small mb-0">
                    <dt class="col-6 text-muted">Status</dt>
                    <dd class="col-6"><span class="badge bg-{{ $configured ? 'success' : 'secondary' }}-subtle text-{{ $configured ? 'success' : 'secondary' }}">{{ $configured ? 'Connected' : 'Not configured' }}</span></dd>
                    <dt class="col-6 text-muted">Active provider</dt>
                    <dd class="col-6">{{ $provider === 'tata' ? 'Tata Smartflo' : 'Asterisk' }}</dd>
                    <dt class="col-6 text-muted">Caller ID</dt>
                    <dd class="col-6 font-monospace">{{ $callerId ?: '—' }}</dd>
                    <dt class="col-6 text-muted">Webhook token</dt>
                    <dd class="col-6">{!! $webhookToken ? '<span class="text-success">Set</span>' : '<span class="text-danger">Missing</span>' !!}</dd>
                    <dt class="col-6 text-muted">Force disposition</dt>
                    <dd class="col-6">{{ $forceDisposition ? 'On' : 'Off' }}</dd>
                    <dt class="col-6 text-muted">Last config sync</dt>
                    <dd class="col-6">{{ $lastSync ? \Illuminate\Support\Carbon::parse($lastSync)->diffForHumans() : 'Never' }}</dd>
                    <dt class="col-6 text-muted">Last CDR pull</dt>
                    <dd class="col-6">{{ $lastCdrSync ? \Illuminate\Support\Carbon::parse($lastCdrSync)->diffForHumans() : 'Never' }}</dd>
                </dl>
            </div>
        </div>
    </div>

    {{-- What's synced --}}
    <div class="col-lg-8">
        <div class="row g-3">
            @php
                $tiles = [
                    ['Agents linked', $agents['linked'], $agents['unlinked'] . ' unlinked · ' . $agents['crmUsersWithoutAgent'] . ' CRM users without an agent', 'people', route('tata.agents.index')],
                    ['Dispositions synced', $dispositions['synced'], $dispositions['pending'] . ' pending of ' . $dispositions['total'], 'tags', route('tata.dispositions.index')],
                    ['DIDs', $numbers, 'Numbers on the account', 'hash', route('tata.numbers.index')],
                    ['Active broadcasts', $broadcasts, 'Running or paused', 'megaphone', route('tata.broadcasts.index')],
                    ['Calls today', $callsToday, $connectedToday . ' connected', 'telephone', route('tata.calls.index')],
                    ['Outcomes to push', $unsynced, $pendingOutcome . ' calls still need an outcome', 'cloud-arrow-up', route('tata.calls.index')],
                ];
            @endphp
            @foreach($tiles as [$label, $value, $hint, $icon, $url])
                <div class="col-sm-6 col-xl-4">
                    <a href="{{ $url }}" class="card shadow-sm h-100 text-decoration-none text-reset">
                        <div class="card-body">
                            <div class="d-flex align-items-center gap-2 text-muted small mb-1"><i class="bi bi-{{ $icon }}"></i>{{ $label }}</div>
                            <div class="h4 mb-0">{{ $value }}</div>
                            <div class="text-muted small">{{ $hint }}</div>
                        </div>
                    </a>
                </div>
            @endforeach
        </div>
    </div>

    {{-- Live calls --}}
    <div class="col-12">
        <div class="card shadow-sm">
            <div class="card-header bg-white d-flex align-items-center">
                <span class="fw-semibold me-auto"><i class="bi bi-broadcast me-2"></i>Live calls on Smartflo</span>
                <button class="btn btn-sm btn-outline-secondary" id="btnRefreshLive"><i class="bi bi-arrow-repeat"></i></button>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead class="table-light"><tr><th>Direction</th><th>Agent</th><th>Customer</th><th>Status</th><th>Since</th></tr></thead>
                        <tbody id="liveCalls"><tr><td colspan="5" class="text-center text-muted small py-3">Loading…</td></tr></tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
$(function () {
    $('#btnSyncAll').on('click', function () {
        const $b = $(this).prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span>Syncing…');
        $.post(@json(route('tata.sync_all')))
            .done(res => { res.success ? toastr.success(res.message) : toastr.warning(res.message); setTimeout(() => location.reload(), 1200); })
            .fail(xhr => toastr.error(xhr.responseJSON?.message || 'Sync failed.'))
            .always(() => $b.prop('disabled', false).html('<i class="bi bi-arrow-repeat me-1"></i>Sync everything'));
    });

    function loadLive() {
        $.get(@json(route('tata.live'))).done(res => {
            if (!res.success || !res.calls.length) {
                return $('#liveCalls').html('<tr><td colspan="5" class="text-center text-muted small py-3">' + (res.message || 'No live calls.') + '</td></tr>');
            }
            const esc = s => $('<div>').text(s ?? '—').html();
            $('#liveCalls').html(res.calls.map(c => `<tr>
                <td>${esc(c.direction)}</td><td>${esc(c.agent)}</td><td>${esc(c.customer)}</td>
                <td><span class="badge bg-primary-subtle text-primary">${esc(c.status)}</span></td><td class="small text-muted">${esc(c.since)}</td>
            </tr>`).join(''));
        });
    }
    @if($configured)
        loadLive();
        let liveTimer = setInterval(loadLive, 15000);
        $('#btnRefreshLive').on('click', loadLive);
        document.addEventListener('visibilitychange', () => { clearInterval(liveTimer); if (!document.hidden) { loadLive(); liveTimer = setInterval(loadLive, 15000); } });
    @endif
});
</script>
@endpush
