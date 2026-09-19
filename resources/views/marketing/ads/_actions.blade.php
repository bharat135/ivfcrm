<div class="btn-group btn-group-sm">
    <button class="btn btn-outline-primary js-preview" data-id="{{ $ad->id }}" title="Preview on Meta">
        <i class="bi bi-window"></i>
    </button>

    @can('campaigns.edit')
        @if($ad->status === 'ACTIVE')
            <button class="btn btn-outline-warning js-status" data-id="{{ $ad->id }}" data-status="PAUSED" title="Pause">
                <i class="bi bi-pause-fill"></i>
            </button>
        @else
            <button class="btn btn-outline-success js-status" data-id="{{ $ad->id }}" data-status="ACTIVE" title="Activate">
                <i class="bi bi-play-fill"></i>
            </button>
        @endif
    @endcan

    @can('campaigns.delete')
        <button class="btn btn-outline-danger js-delete" data-id="{{ $ad->id }}" title="Delete">
            <i class="bi bi-trash"></i>
        </button>
    @endcan
</div>
