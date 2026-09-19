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

@can('call_logs.create')
    @if($number)
        <div class="btn-group btn-group-{{ $size }}">
            <button type="button" class="btn btn-success js-click-to-call"
                    data-number="{{ $number }}"
                    @if($leadId) data-lead="{{ $leadId }}" @endif
                    @if($patientId) data-patient="{{ $patientId }}" @endif
                    title="Call {{ $number }} from your softphone">
                <i class="bi bi-telephone-outbound me-1"></i>{{ $label }}
            </button>

            @if($showWhatsApp && config('telephony.whatsapp_calling.enabled'))
                <button type="button" class="btn btn-outline-success js-whatsapp-call"
                        data-number="{{ $number }}"
                        @if($leadId) data-lead="{{ $leadId }}" @endif
                        @if($conversationId) data-conversation="{{ $conversationId }}" @endif
                        title="Call over WhatsApp">
                    <i class="bi bi-whatsapp"></i>
                </button>
            @endif

            <a href="tel:{{ $number }}" class="btn btn-outline-secondary" title="Dial with your desktop softphone">
                <i class="bi bi-box-arrow-up-right"></i>
            </a>
        </div>
    @endif
@endcan
