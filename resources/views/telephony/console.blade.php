@extends('layouts.app')

@section('title', 'Call Console')

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">Dashboard</a></li>
    <li class="breadcrumb-item active">Call Console</li>
@endsection

@push('styles')
<style>
    .agent-tile {
        border-radius: var(--fui-radius-lg);
        padding: 10px 12px;
        border: 1px solid var(--fui-border-subtle);
        background: var(--fui-surface);
        display: flex; align-items: center; gap: 9px;
        transition: border-color .12s ease;
    }
    .agent-tile:hover { border-color: var(--fui-border-strong); }
    .agent-dot { width: 7px; height: 7px; border-radius: 999px; flex-shrink: 0; }
    .keypad button { height: 40px; font-size: var(--fui-text-md); font-weight: 500; }
</style>
@endpush

@section('content')

@unless($configured)
    <div class="alert alert-warning d-flex align-items-center gap-2">
        <i class="bi bi-exclamation-triangle-fill"></i>
        <div class="flex-grow-1">
            {{ $providerLabel }} is not connected yet, so click-to-call will not work.
        </div>
        @can('telephony.view')
            <a href="{{ route('settings.telephony.index') }}" class="btn btn-sm btn-warning">Configure {{ $providerLabel }}</a>
        @endcan
    </div>
@endunless

@unless($canDial)
    <div class="alert alert-info d-flex align-items-center gap-2">
        <i class="bi bi-info-circle-fill"></i>
        <div class="flex-grow-1">
            @if($usesTata)
                You have no Tata Smartflo agent number (or phone) on your profile, so you cannot place calls from the CRM.
            @else
                You do not have a SIP extension yet, so you cannot place calls from the CRM.
            @endif
        </div>
        @if($usesTata)
            @can('users.edit')
                <a href="{{ route('settings.users.index') }}" class="btn btn-sm btn-info">Add one</a>
            @endcan
        @else
            @can('telephony.create')
                <a href="{{ route('settings.telephony.index') }}" class="btn btn-sm btn-info">Assign one</a>
            @endcan
        @endif
    </div>
@endunless

<div class="row g-3">
    {{-- --------------------------------------------------------- --}}
    {{-- Dial pad                                                   --}}
    {{-- --------------------------------------------------------- --}}
    <div class="col-lg-4">
        <div class="card shadow-sm mb-3">
            <div class="card-header bg-white d-flex align-items-center">
                <span class="fw-semibold me-auto"><i class="bi bi-grid-3x3-gap me-2"></i>Dial pad</span>
                @if($canDial && $dialIdentity)
                    <span class="badge bg-light text-dark border">{{ $usesTata ? 'Smartflo' : 'Ext' }} {{ $dialIdentity }}</span>
                @endif
            </div>
            <div class="card-body">
                <input type="tel" class="form-control form-control-lg text-center mb-3" id="padInput"
                       placeholder="Enter a number" autocomplete="off">

                <div class="row g-2 keypad mb-3">
                    @foreach(['1','2','3','4','5','6','7','8','9','*','0','#'] as $key)
                        <div class="col-4">
                            <button class="btn btn-outline-secondary w-100 pad-key" data-key="{{ $key }}">{{ $key }}</button>
                        </div>
                    @endforeach
                </div>

                <div class="d-flex gap-2">
                    <button class="btn btn-success flex-grow-1" id="padCall" @disabled(! $canDial)>
                        <i class="bi bi-telephone-outbound me-1"></i>Call
                    </button>
                    <button class="btn btn-outline-secondary" id="padClear" title="Backspace">
                        <i class="bi bi-backspace"></i>
                    </button>
                </div>

                @if($canDial)
                    <div class="mt-3 small text-muted">
                        <i class="bi bi-info-circle me-1"></i>
                        @if($usesTata)
                            Tata Smartflo rings your phone first — answer it, and the customer is dialled and bridged to you.
                        @else
                            Your softphone rings first — answer it, and the CRM then dials the customer.
                        @endif
                    </div>
                @endif
            </div>
        </div>

        @if($extension)
            <div class="card shadow-sm">
                <div class="card-header bg-white fw-semibold">Your softphone</div>
                <div class="card-body small">
                    <dl class="row mb-3">
                        <dt class="col-5 text-muted">Extension</dt><dd class="col-7">{{ $extension->extension }}</dd>
                        <dt class="col-5 text-muted">SIP server</dt><dd class="col-7">{{ $extension->domain ?: '—' }}</dd>
                        <dt class="col-5 text-muted">Transport</dt><dd class="col-7">{{ strtoupper($extension->transport) }}</dd>
                        <dt class="col-5 text-muted">Registration</dt>
                        <dd class="col-7">
                            <span class="badge bg-{{ $extension->registrationBadge() }}">
                                {{ $extension->registration_status ?: 'Unknown' }}
                            </span>
                        </dd>
                    </dl>

                    <a href="{{ route('telephony.my_microsip') }}" class="btn btn-sm btn-outline-primary w-100">
                        <i class="bi bi-download me-1"></i>Download MicroSIP profile
                    </a>
                    <div class="form-text mt-2">
                        Save the file next to <code>microsip.exe</code> (or import it under
                        Menu → Settings → Account) and the softphone configures itself.
                    </div>
                </div>
            </div>
        @endif
    </div>

    {{-- --------------------------------------------------------- --}}
    {{-- Live board                                                 --}}
    {{-- --------------------------------------------------------- --}}
    <div class="col-lg-8">
        @if($isSupervisor)
            <div class="row g-3 mb-3" id="statCards">
                @foreach([
                    ['Live calls', 'live', 'bi-broadcast', 'primary'],
                    ['Calls today', 'today', 'bi-telephone', 'info'],
                    ['Connected today', 'connected', 'bi-check2-circle', 'success'],
                ] as [$label, $key, $icon, $color])
                    <div class="col-4">
                        <div class="card stat-card shadow-sm h-100">
                            <div class="card-body p-3">
                                <div class="icon-box bg-{{ $color }}-subtle text-{{ $color }} mb-2">
                                    <i class="bi {{ $icon }}"></i>
                                </div>
                                <div class="h4 mb-0" data-stat="{{ $key }}">0</div>
                                <small class="text-muted">{{ $label }}</small>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>

            <div class="card shadow-sm mb-3">
                <div class="card-header bg-white d-flex align-items-center">
                    <span class="fw-semibold me-auto"><i class="bi bi-people me-2"></i>Agents</span>
                    <small class="text-muted" id="boardUpdated">—</small>
                </div>
                <div class="card-body">
                    <div class="row g-2" id="agentBoard">
                        <div class="col-12 text-center text-muted py-3">Loading…</div>
                    </div>
                </div>
            </div>

            <div class="card shadow-sm mb-3">
                <div class="card-header bg-white fw-semibold"><i class="bi bi-broadcast me-2"></i>Calls in progress</div>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead class="table-light">
                            <tr><th>Agent</th><th>Contact</th><th>Direction</th><th>Started</th><th class="text-end">Monitor</th></tr>
                        </thead>
                        <tbody id="liveCalls">
                            <tr><td colspan="5" class="text-center text-muted py-3">No calls in progress.</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        @endif

        <div class="card shadow-sm">
            <div class="card-header bg-white d-flex align-items-center">
                <span class="fw-semibold me-auto"><i class="bi bi-clock-history me-2"></i>Recent calls</span>
                <a href="{{ route('call_logs.index') }}" class="btn btn-sm btn-outline-secondary">All call logs</a>
            </div>
            <div class="table-responsive">
                <table class="table table-hover table-sm align-middle mb-0">
                    <thead class="table-light">
                        <tr><th>Contact</th><th>Direction</th><th>Status</th><th>Duration</th><th>Outcome</th><th></th></tr>
                    </thead>
                    <tbody id="recentTable">
                        <tr><td colspan="6" class="text-center text-muted py-3">Loading…</td></tr>
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
    const isSupervisor = @json($isSupervisor);

    // --- Dial pad -------------------------------------------------------
    $('.pad-key').on('click', function () {
        $('#padInput').val($('#padInput').val() + $(this).data('key'));
    });

    $('#padClear').on('click', function () {
        $('#padInput').val($('#padInput').val().slice(0, -1));
    });

    $('#padCall').on('click', function () {
        const number = $('#padInput').val().trim();
        if (!number) return toastr.warning('Enter a number first.');

        // Reuse the global dialer handler so behaviour stays identical.
        $('<button>').addClass('js-click-to-call d-none').attr('data-number', number)
            .appendTo('body').trigger('click').remove();
    });

    $('#padInput').on('keydown', e => {
        if (e.key === 'Enter') { e.preventDefault(); $('#padCall').click(); }
    });

    // --- Recent calls ---------------------------------------------------
    function loadRecent() {
        $.get(@json(route('calls.recent'))).done(res => {
            if (!res.data.length) {
                return $('#recentTable').html('<tr><td colspan="6" class="text-center text-muted py-3">No calls yet.</td></tr>');
            }

            $('#recentTable').html(res.data.map(c => `
                <tr>
                    <td>
                        ${c.lead_url ? `<a href="${c.lead_url}">${esc(c.contact)}</a>` : esc(c.contact)}
                        <br><small class="text-muted">${esc(c.number || '')}</small>
                    </td>
                    <td><i class="bi bi-telephone-${c.direction === 'inbound' ? 'inbound text-success' : 'outbound text-primary'}"></i>
                        <i class="bi ${c.channel_icon} text-muted ms-1"></i></td>
                    <td><span class="badge bg-${c.badge}">${esc(c.status)}</span></td>
                    <td>${c.duration}</td>
                    <td>${c.disposition ? esc(c.disposition) :
                        (c.needs_disposition ? '<span class="badge bg-warning text-dark">Needs outcome</span>' : '—')}</td>
                    <td class="text-end">
                        ${c.recording ? `<button class="btn btn-sm btn-outline-secondary js-play" data-src="${c.recording}"><i class="bi bi-play-fill"></i></button>` : ''}
                        ${c.needs_disposition ? `<button class="btn btn-sm btn-outline-warning js-disposition" data-id="${c.id}"><i class="bi bi-clipboard-check"></i></button>` : ''}
                    </td>
                </tr>`).join(''));
        });
    }

    // --- Supervisor board ----------------------------------------------
    function loadBoard() {
        if (!isSupervisor) return;

        $.get(@json(route('calls.board'))).done(res => {
            Object.entries(res.stats).forEach(([k, v]) => $(`[data-stat="${k}"]`).text(v));

            $('#agentBoard').html(res.agents.length
                ? res.agents.map(a => `
                    <div class="col-md-6 col-xl-4">
                        <div class="agent-tile">
                            <span class="agent-dot bg-${a.badge}"></span>
                            <div class="flex-grow-1 min-w-0">
                                <div class="fw-semibold text-truncate">${esc(a.name)}</div>
                                <small class="text-muted">
                                    Ext ${esc(a.extension || '—')} · ${esc(a.label)}
                                    ${a.since ? ' · ' + a.since : ''}
                                </small>
                                ${a.call ? `<div class="small text-primary text-truncate">
                                    <i class="bi bi-telephone-fill me-1"></i>${esc(a.call.contact || a.call.number || '')}
                                </div>` : ''}
                            </div>
                        </div>
                    </div>`).join('')
                : '<div class="col-12 text-center text-muted py-3">No agents with a SIP extension yet.</div>');

            $('#liveCalls').html(res.calls.length
                ? res.calls.map(c => `
                    <tr>
                        <td>${esc(c.agent || '—')}</td>
                        <td>${esc(c.contact)}<br><small class="text-muted">${esc(c.number || '')}</small></td>
                        <td><span class="badge bg-light text-dark border">${esc(c.direction)}</span></td>
                        <td>${esc(c.started || '')} ago</td>
                        <td class="text-end">
                            <div class="btn-group btn-group-sm">
                                <button class="btn btn-outline-secondary js-monitor" data-id="${c.id}" data-mode="listen" title="Listen">
                                    <i class="bi bi-ear"></i></button>
                                <button class="btn btn-outline-primary js-monitor" data-id="${c.id}" data-mode="whisper" title="Whisper">
                                    <i class="bi bi-mic"></i></button>
                                <button class="btn btn-outline-warning js-monitor" data-id="${c.id}" data-mode="barge" title="Barge in">
                                    <i class="bi bi-people"></i></button>
                            </div>
                        </td>
                    </tr>`).join('')
                : '<tr><td colspan="5" class="text-center text-muted py-3">No calls in progress.</td></tr>');

            $('#boardUpdated').text('Updated ' + new Date().toLocaleTimeString());
        });
    }

    $(document).on('click', '.js-monitor', function () {
        const $b = $(this).prop('disabled', true);

        $.post(`{{ url('calls') }}/${$b.data('id')}/monitor`, { mode: $b.data('mode') })
            .done(res => res.success ? toastr.success(res.message) : toastr.error(res.message))
            .fail(xhr => toastr.error(xhr.responseJSON?.message || 'Could not start monitoring.'))
            .always(() => $b.prop('disabled', false));
    });

    function esc(v) { return $('<div>').text(v == null ? '' : v).html(); }

    loadRecent();
    loadBoard();
    setInterval(() => { loadRecent(); loadBoard(); }, 8000);
});
</script>
@endpush
