@php
    $url = $call->lead_id
        ? route('leads.show', $call->lead_id)
        : ($call->patient_id ? route('patients.show', $call->patient_id) : null);
@endphp

<div class="d-flex align-items-center gap-2">
    <i class="bi bi-telephone-{{ $call->direction === 'inbound' ? 'inbound' : 'outbound' }}
              text-{{ $call->direction === 'inbound' ? 'success' : 'primary' }}"></i>
    <div class="min-w-0">
        @if($url)
            <a href="{{ $url }}" class="text-decoration-none d-block text-truncate">{{ $call->contactName() }}</a>
        @else
            <span class="d-block text-truncate">{{ $call->contactName() }}</span>
        @endif
        <small class="text-muted">{{ $call->contactNumber() ?: '—' }}</small>
    </div>
</div>
