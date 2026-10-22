@php $open = in_array($appointment->status, ['booked', 'confirmed', 'rescheduled'], true); @endphp
<div class="d-flex gap-1">
    @if($open)
        @can('appointments.complete')
            <button type="button" class="btn btn-xs btn-outline-success js-appt-done" data-url="{{ route('appointments.update', $appointment) }}" title="Mark done">
                <i class="bi bi-check2-circle"></i>
            </button>
        @endcan
        @can('appointments.cancel')
            <button type="button" class="btn btn-xs btn-outline-danger js-appt-cancel" data-url="{{ route('appointments.update', $appointment) }}" title="Cancel">
                <i class="bi bi-x-circle"></i>
            </button>
        @endcan
    @elseif($appointment->status === 'cancelled' && $appointment->cancel_reason)
        <span class="text-muted small" title="Cancelled by {{ $appointment->canceller?->name ?? '—' }}{{ $appointment->cancelled_at ? ' on ' . $appointment->cancelled_at->format('d-m-Y h:i A') : '' }}">
            <i class="bi bi-info-circle"></i> {{ \Illuminate\Support\Str::limit($appointment->cancel_reason, 40) }}
        </span>
    @endif
</div>
