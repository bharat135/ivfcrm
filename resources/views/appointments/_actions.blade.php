@php $open = in_array($appointment->status, ['booked', 'confirmed', 'rescheduled'], true); @endphp
<div class="d-flex gap-1 align-items-center">
    @if($open)
        @can('appointments.complete')
            <button type="button" class="btn btn-xs btn-outline-success js-appt-done" data-url="{{ route('appointments.update', $appointment) }}" title="Mark done (patient visited)">
                <i class="bi bi-check2-circle"></i>
            </button>
        @endcan
        @can('appointments.edit')
            <button type="button" class="btn btn-xs btn-outline-primary js-appt-reschedule" data-url="{{ route('appointments.update', $appointment) }}" data-when="{{ $appointment->scheduled_at?->format('Y-m-d H:i') }}" title="Reschedule">
                <i class="bi bi-calendar-event"></i>
            </button>
        @endcan
        @can('appointments.cancel')
            <button type="button" class="btn btn-xs btn-outline-danger js-appt-cancel" data-url="{{ route('appointments.update', $appointment) }}" title="Cancel">
                <i class="bi bi-x-circle"></i>
            </button>
        @endcan
    @elseif($appointment->isClosed())
        {{-- Marked done or no-show by mistake: an admin reopens it, anyone else asks an admin to. --}}
        @if($appointment->hasReopenRequest())
            @php
                $reopenNote = 'Reopen requested by ' . ($appointment->reopenRequester?->name ?? '—')
                    . ' on ' . $appointment->reopen_requested_at->format('d-m-Y h:i A') . ': ' . $appointment->reopen_reason;
            @endphp
            @can('reopen_appointments')
                <button type="button" class="btn btn-xs btn-warning js-appt-reopen-approve" data-url="{{ route('appointments.reopen', $appointment) }}" data-request="{{ $reopenNote }}" title="{{ $reopenNote }}">
                    <i class="bi bi-arrow-counterclockwise"></i> Approve reopen
                </button>
                <button type="button" class="btn btn-xs btn-outline-secondary js-appt-reopen-decline" data-url="{{ route('appointments.reopen.decline', $appointment) }}" data-request="{{ $reopenNote }}" title="Decline the request; the appointment stays as it is">
                    <i class="bi bi-x-lg"></i>
                </button>
            @else
                <span class="badge text-bg-warning" title="{{ $reopenNote }}"><i class="bi bi-hourglass-split"></i> Awaiting admin approval</span>
            @endcan
        @elseif($appointment->canRequestReopen(auth()->user()))
            @php $direct = auth()->user()->can('reopen_appointments'); @endphp
            <button type="button" class="btn btn-xs btn-outline-secondary js-appt-reopen" data-url="{{ route('appointments.reopen', $appointment) }}" data-direct="{{ $direct ? '1' : '0' }}"
                    title="{{ $direct ? 'Marked by mistake? Reopen it (back to booked)' : 'Marked by mistake? Ask an admin to reopen it' }}">
                <i class="bi bi-arrow-counterclockwise"></i>
            </button>
        @endif
    @elseif($appointment->status === 'cancelled' && $appointment->cancel_reason)
        <span class="text-muted small" title="Cancelled by {{ $appointment->canceller?->name ?? '—' }}{{ $appointment->cancelled_at ? ' on ' . $appointment->cancelled_at->format('d-m-Y h:i A') : '' }}">
            <i class="bi bi-info-circle"></i> {{ \Illuminate\Support\Str::limit($appointment->cancel_reason, 40) }}
        </span>
    @endif
</div>
