{{--
    Where this person came from.

    Shown on both the enquiry and the patient screen, because tracing a treatment
    cycle back to the ad that paid for it is the whole reason the two records were
    merged into one. Expects $lead (nullable) and optionally $patient.
--}}
@php
    use App\Support\AcquisitionChannel;

    $channel = $lead?->channel ?: 'manual';
    $clickId = $lead?->clickIdentifier();
    $first   = ($patient ?? null)?->first_enquiry_at ?: $lead?->lead_date;
@endphp

<div class="card border-0 shadow-sm mb-3">
    <div class="card-header bg-transparent py-2 d-flex align-items-center gap-2">
        <i class="bi {{ AcquisitionChannel::icon($channel) }}" style="color: {{ AcquisitionChannel::color($channel) }}"></i>
        <h6 class="mb-0 fw-semibold small">Came from {{ AcquisitionChannel::label($channel) }}</h6>
        @if(AcquisitionChannel::isPaid($channel))
            <span class="badge text-bg-light text-body ms-auto">Paid</span>
        @endif
    </div>
    <div class="card-body p-0">
        <table class="table table-sm mb-0">
            <tr><td class="text-muted small ps-3" width="42%">Source</td><td>{{ $lead?->source?->name ?? '—' }}</td></tr>
            <tr><td class="text-muted small ps-3">First enquiry</td><td>{{ $first?->format('d-m-Y, h:i A') ?? '—' }}</td></tr>

            @if($lead?->campaign)
                <tr><td class="text-muted small ps-3">Campaign</td><td class="text-truncate">{{ $lead->campaign->name }}</td></tr>
            @endif
            @if($lead?->adset)
                <tr><td class="text-muted small ps-3">Ad set</td><td class="text-truncate">{{ $lead->adset->name }}</td></tr>
            @endif
            @if($lead?->ad)
                <tr><td class="text-muted small ps-3">Ad</td><td class="text-truncate">{{ $lead->ad->name }}</td></tr>
            @endif

            @if($lead?->utm_campaign)
                <tr><td class="text-muted small ps-3">utm_campaign</td><td class="text-truncate">{{ $lead->utm_campaign }}</td></tr>
            @endif
            @if($lead?->utm_term)
                <tr><td class="text-muted small ps-3">Keyword</td><td class="text-truncate">{{ $lead->utm_term }}</td></tr>
            @endif
            @if($lead?->utm_content)
                <tr><td class="text-muted small ps-3">utm_content</td><td class="text-truncate">{{ $lead->utm_content }}</td></tr>
            @endif

            @if($clickId)
                <tr>
                    <td class="text-muted small ps-3">{{ $clickId['key'] }}</td>
                    <td>
                        <code class="small text-truncate d-inline-block" style="max-width:170px"
                              title="{{ $clickId['value'] }}">{{ $clickId['value'] }}</code>
                    </td>
                </tr>
            @endif

            @if($lead?->external_lead_id)
                <tr><td class="text-muted small ps-3">Meta lead id</td><td><code class="small">{{ $lead->external_lead_id }}</code></td></tr>
            @endif

            @if(($lead?->extra['landing_page'] ?? null))
                <tr>
                    <td class="text-muted small ps-3">Landing page</td>
                    <td class="text-truncate">{{ $lead->extra['landing_page'] }}</td>
                </tr>
            @endif
        </table>
    </div>
</div>
