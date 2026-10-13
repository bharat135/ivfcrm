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
        <div class="btn-group btn-group-{{ $size }}">
            <button type="button" class="btn btn-success js-click-to-call"
                    data-number="{{ $dialNumber }}"
                    @if($leadId) data-lead="{{ $leadId }}" @endif
                    @if($patientId) data-patient="{{ $patientId }}" @endif
                    title="Call {{ $shownNumber }} from your softphone">
                <i class="bi bi-telephone-outbound me-1"></i>{{ $label }}
            </button>

            {{-- Swapped in by the global dialer while a call to this contact
                 is live: ends the call from the profile itself. --}}
            <button type="button" class="btn btn-danger js-hangup-call d-none"
                    @if($leadId) data-lead="{{ $leadId }}" @endif
                    @if($patientId) data-patient="{{ $patientId }}" @endif
                    title="End the current call">
                <i class="bi bi-telephone-x me-1"></i>Disconnect
            </button>

            @if($showWhatsApp && config('telephony.whatsapp_calling.enabled'))
                <button type="button" class="btn btn-outline-success js-whatsapp-call"
                        data-number="{{ $dialNumber }}"
                        @if($leadId) data-lead="{{ $leadId }}" @endif
                        @if($conversationId) data-conversation="{{ $conversationId }}" @endif
                        title="Call over WhatsApp">
                    <i class="bi bi-whatsapp"></i>
                </button>
            @endif

            @if($canSeeNumber)
            <a href="tel:{{ $number }}" class="btn btn-outline-secondary" title="Dial with your desktop softphone">
                <i class="bi bi-box-arrow-up-right"></i>
            </a>
            @endif
        </div>
    @endif
@endcan
