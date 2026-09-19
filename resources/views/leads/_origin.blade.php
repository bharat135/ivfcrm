@php
    use App\Support\AcquisitionChannel;
    $channel = $lead->channel ?: 'manual';
@endphp
<div class="d-flex align-items-center gap-2">
    <i class="bi {{ AcquisitionChannel::icon($channel) }}" style="color: {{ AcquisitionChannel::color($channel) }}"></i>
    <div class="min-w-0">
        <div class="text-truncate">{{ AcquisitionChannel::label($channel) }}</div>
        @if($lead->source)
            <div class="text-muted small text-truncate">{{ $lead->source->name }}</div>
        @endif
    </div>
</div>
