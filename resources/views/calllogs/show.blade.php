@extends('layouts.app')

@section('title', 'Call #' . $call->id)

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">Dashboard</a></li>
    <li class="breadcrumb-item"><a href="{{ route('call_logs.index') }}">Call Logs</a></li>
    <li class="breadcrumb-item active">Call #{{ $call->id }}</li>
@endsection

@section('content')

<div class="d-flex flex-wrap align-items-center gap-2 mb-3">
    <div class="me-auto">
        <h5 class="mb-1">
            <i class="bi bi-telephone-{{ $call->direction === 'inbound' ? 'inbound text-success' : 'outbound text-primary' }}"></i>
            {{ $call->contactName() }}
            <span class="badge bg-{{ $call->statusBadge() }}">{{ $call->status }}</span>
        </h5>
        <small class="text-muted">
            {{ $call->contactNumber() }} ·
            {{ ucfirst($call->direction) }} {{ $call->channel }} call ·
            {{ $call->started_at?->format('d-m-Y, g:i A') }}
        </small>
    </div>

    @if($call->lead_id)
        <a href="{{ route('leads.show', $call->lead_id) }}" class="btn btn-sm btn-outline-primary">
            <i class="bi bi-person me-1"></i>Open lead
        </a>
    @endif

    @include('telephony._call_button', [
        'number'    => $call->contactNumber(),
        'leadId'    => $call->lead_id,
        'patientId' => $call->patient_id,
        'label'     => 'Call back',
    ])
</div>

<div class="row g-3">
    <div class="col-lg-7">
        {{-- Timings --}}
        <div class="card shadow-sm mb-3">
            <div class="card-header bg-white fw-semibold">Call timings</div>
            <div class="card-body">
                <div class="row text-center g-3">
                    @foreach([
                        ['Ring',  $call->formatSeconds($call->ring_seconds),  'bi-bell',          'warning'],
                        ['Talk',  $call->formatSeconds($call->talk_seconds),  'bi-mic',           'success'],
                        ['Hold',  $call->formatSeconds($call->hold_seconds),  'bi-pause-circle',  'info'],
                        ['Wrap',  $call->formatSeconds($call->wrap_seconds),  'bi-clipboard',     'secondary'],
                        ['Total', $call->formatSeconds($call->duration_seconds), 'bi-clock',      'primary'],
                    ] as [$label, $value, $icon, $color])
                        <div class="col">
                            <div class="icon-box bg-{{ $color }}-subtle text-{{ $color }} mx-auto mb-2">
                                <i class="bi {{ $icon }}"></i>
                            </div>
                            <div class="h5 mb-0">{{ $value }}</div>
                            <small class="text-muted">{{ $label }}</small>
                        </div>
                    @endforeach
                </div>

                @if($call->hangup_cause)
                    <hr>
                    <div class="small text-muted">
                        <i class="bi bi-info-circle me-1"></i>
                        Hangup cause: <strong>{{ $call->hangupCauseLabel() }}</strong>
                        @if($call->hangup_by) · ended by {{ $call->hangup_by }} @endif
                    </div>
                @endif
            </div>
        </div>

        {{-- Recording --}}
        @if($call->hasRecording())
            <div class="card shadow-sm mb-3">
                <div class="card-header bg-white fw-semibold"><i class="bi bi-record-circle me-2"></i>Recording</div>
                <div class="card-body">
                    <audio controls preload="none" class="w-100" src="{{ $call->recordingUrl() }}"></audio>
                    <a href="{{ $call->recordingUrl() }}?download=1" class="btn btn-sm btn-outline-secondary mt-2">
                        <i class="bi bi-download me-1"></i>Download
                    </a>
                </div>
            </div>
        @endif

        {{-- Signalling timeline --}}
        <div class="card shadow-sm">
            <div class="card-header bg-white fw-semibold">Signalling timeline</div>
            <div class="card-body">
                @forelse($call->events as $event)
                    <div class="d-flex gap-3 mb-3">
                        <div class="fui-avatar flex-shrink-0"
                             style="width:32px;height:32px">
                            <i class="bi {{ $event->icon() }} text-primary"></i>
                        </div>
                        <div class="flex-grow-1 min-w-0">
                            <div class="d-flex justify-content-between">
                                <strong class="small text-capitalize">{{ str_replace('_', ' ', $event->event) }}</strong>
                                <small class="text-muted">{{ $event->occurred_at?->format('h:i:s A') }}</small>
                            </div>
                            <small class="text-muted">
                                {{ ucfirst($event->source) }}
                                @if($event->correlation_id) · <code>{{ Str::limit($event->correlation_id, 28) }}</code>@endif
                            </small>
                        </div>
                    </div>
                @empty
                    <p class="text-muted text-center py-3 mb-0">
                        <i class="bi bi-clock-history me-1"></i>
                        No signalling events recorded. Configure the Asterisk dial plan webhook to capture these.
                    </p>
                @endforelse
            </div>
        </div>
    </div>

    <div class="col-lg-5">
        {{-- Outcome --}}
        <div class="card shadow-sm mb-3">
            <div class="card-header bg-white fw-semibold">Outcome</div>
            <div class="card-body">
                @if($call->disposition)
                    <p class="mb-2">
                        <span class="badge" style="background: {{ $call->disposition->color ?? '#6c757d' }}">
                            {{ $call->disposition->name }}
                        </span>
                        @if($call->subDisposition)
                            <span class="badge bg-light text-dark border">{{ $call->subDisposition->name }}</span>
                        @endif
                    </p>
                    @if($call->remark)
                        <p class="small text-muted mb-0">{{ $call->remark }}</p>
                    @endif
                @else
                    <p class="text-muted small mb-2">No outcome recorded for this call yet.</p>
                    @can('call_logs.edit')
                        <button class="btn btn-sm btn-warning js-disposition" data-id="{{ $call->id }}">
                            <i class="bi bi-clipboard-check me-1"></i>Add outcome
                        </button>
                    @endcan
                @endif
            </div>
        </div>

        {{-- Details --}}
        <div class="card shadow-sm">
            <div class="card-header bg-white fw-semibold">Details</div>
            <div class="card-body small">
                <dl class="row mb-0">
                    <dt class="col-5 text-muted">Agent</dt><dd class="col-7">{{ $call->user?->name ?? '—' }}</dd>
                    <dt class="col-5 text-muted">Extension</dt><dd class="col-7">{{ $call->extension ?: '—' }}</dd>
                    <dt class="col-5 text-muted">Provider</dt><dd class="col-7">{{ ucfirst($call->provider ?: '—') }}</dd>
                    <dt class="col-5 text-muted">Channel</dt><dd class="col-7">{{ ucfirst($call->channel) }}</dd>
                    <dt class="col-5 text-muted">From</dt><dd class="col-7">{{ $call->from_number ?: '—' }}</dd>
                    <dt class="col-5 text-muted">To</dt><dd class="col-7">{{ $call->to_number ?: '—' }}</dd>
                    <dt class="col-5 text-muted">Started</dt><dd class="col-7">{{ $call->started_at?->format('d-m, h:i:s A') ?: '—' }}</dd>
                    <dt class="col-5 text-muted">Answered</dt><dd class="col-7">{{ $call->answered_at?->format('h:i:s A') ?: 'Not answered' }}</dd>
                    <dt class="col-5 text-muted">Ended</dt><dd class="col-7">{{ $call->ended_at?->format('h:i:s A') ?: '—' }}</dd>

                    @if($call->transferredTo)
                        <dt class="col-5 text-muted">Transferred to</dt>
                        <dd class="col-7">{{ $call->transferredTo->name }}</dd>
                    @endif

                    @if($call->parent_call_log_id)
                        <dt class="col-5 text-muted">Transferred from</dt>
                        <dd class="col-7">
                            <a href="{{ route('call_logs.show', $call->parent_call_log_id) }}">
                                Call #{{ $call->parent_call_log_id }}
                            </a>
                        </dd>
                    @endif

                    @if($call->wa_call_id)
                        <dt class="col-5 text-muted">WhatsApp call</dt>
                        <dd class="col-7"><code class="small">{{ Str::limit($call->wa_call_id, 22) }}</code></dd>
                    @endif

                    @if($call->linked_id)
                        <dt class="col-5 text-muted">Asterisk ID</dt>
                        <dd class="col-7"><code class="small">{{ $call->linked_id }}</code></dd>
                    @endif
                </dl>
            </div>
        </div>
    </div>
</div>
@endsection
