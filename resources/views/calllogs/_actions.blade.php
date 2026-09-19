<div class="btn-group btn-group-sm">
    <a href="{{ route('call_logs.show', $call) }}" class="btn btn-outline-primary" title="Call detail">
        <i class="bi bi-eye"></i>
    </a>

    @if($call->hasRecording())
        <button class="btn btn-outline-secondary js-play" data-src="{{ $call->recordingUrl() }}" title="Play recording">
            <i class="bi bi-play-fill"></i>
        </button>
    @endif

    @if($call->isLive())
        @can('call_logs.edit')
            <button class="btn btn-outline-danger js-hangup" data-id="{{ $call->id }}" title="Hang up">
                <i class="bi bi-telephone-x"></i>
            </button>
        @endcan
    @elseif(! $call->disposition_id)
        @can('call_logs.edit')
            <button class="btn btn-outline-warning js-disposition" data-id="{{ $call->id }}" title="Add outcome">
                <i class="bi bi-clipboard-check"></i>
            </button>
        @endcan
    @endif

    @if($call->lead_id)
        @can('call_logs.create')
            <button class="btn btn-outline-success js-callback"
                    data-lead="{{ $call->lead_id }}" title="Call back">
                <i class="bi bi-telephone-outbound"></i>
            </button>
        @endcan
    @endif
</div>
