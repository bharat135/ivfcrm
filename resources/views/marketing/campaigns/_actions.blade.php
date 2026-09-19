<div class="btn-group btn-group-sm">
    <a href="{{ route('marketing.campaigns.show', $campaign) }}" class="btn btn-outline-primary" title="Open">
        <i class="bi bi-eye"></i>
    </a>

    @can('campaigns.edit')
        @if($campaign->status === 'ACTIVE')
            <button class="btn btn-outline-warning js-status" data-id="{{ $campaign->id }}" data-status="PAUSED" title="Pause">
                <i class="bi bi-pause-fill"></i>
            </button>
        @else
            <button class="btn btn-outline-success js-status" data-id="{{ $campaign->id }}" data-status="ACTIVE" title="Activate">
                <i class="bi bi-play-fill"></i>
            </button>
        @endif

        <button class="btn btn-outline-secondary js-edit" data-id="{{ $campaign->id }}" title="Edit">
            <i class="bi bi-pencil"></i>
        </button>
    @endcan

    @can('campaigns.delete')
        <button class="btn btn-outline-danger js-delete" data-id="{{ $campaign->id }}" title="Delete">
            <i class="bi bi-trash"></i>
        </button>
    @endcan
</div>
