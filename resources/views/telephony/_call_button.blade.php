{{--
    Click-to-call button.

    Expects $number, and optionally $leadId / $patientId / $conversationId.
    Rings the agent's own softphone first, then dials the contact — so it works
    with MicroSIP without any browser plugin.
--}}
@php
    $leadId         = $leadId ?? null;
    $patientId      = $patientId ?? null;
    $conversationId = $conversationId ?? null;
    $size           = $size ?? 'sm';
    $label          = $label ?? 'Call';
    $showWhatsApp   = $showWhatsApp ?? false;
    // Round, icon-only buttons (the profile's record card).
    $iconOnly       = $iconOnly ?? false;
@endphp

@php
    // Telecallers never receive the raw number: the button carries only the
    // lead / patient id and the server dials the number it holds.
    $canSeeNumber = \App\Support\PhoneNumber::canView();
    $dialNumber   = $canSeeNumber ? $number : '';
    $shownNumber  = \App\Support\PhoneNumber::display($number);
@endphp
@can('call_logs.create')
    @if($number)
        <div class="{{ $iconOnly ? 'd-inline-flex gap-2' : 'btn-group btn-group-' . $size }}">
            <button type="button" class="btn btn-success js-click-to-call {{ $iconOnly ? 'btn-icon-round' : '' }}"
                    data-number="{{ $dialNumber }}"
                    @if($leadId) data-lead="{{ $leadId }}" @endif
                    @if($patientId) data-patient="{{ $patientId }}" @endif
                    title="Call {{ $shownNumber }} from your softphone" aria-label="Call">
                <i class="bi bi-telephone-fill {{ $iconOnly ? '' : 'me-1' }}"></i>{{ $iconOnly ? '' : $label }}
            </button>

            {{-- Swapped in by the global dialer while a call to this contact
                 is live: ends the call from the profile itself. --}}
            <button type="button" class="btn btn-danger js-hangup-call d-none {{ $iconOnly ? 'btn-icon-round' : '' }}"
                    @if($leadId) data-lead="{{ $leadId }}" @endif
                    @if($patientId) data-patient="{{ $patientId }}" @endif
                    title="End the current call" aria-label="Disconnect">
                <i class="bi bi-telephone-x {{ $iconOnly ? '' : 'me-1' }}"></i>{{ $iconOnly ? '' : 'Disconnect' }}
            </button>

        </div>
    @endif
@endcan
