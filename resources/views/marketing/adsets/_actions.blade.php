<div class="btn-group btn-group-sm">
    <a href="{{ route('marketing.adsets.show', $adset) }}" class="btn btn-outline-primary" title="Open">
        <i class="bi bi-eye"></i>
    </a>

    @can('campaigns.edit')
        @if($adset->status === 'ACTIVE')
            <button class="btn btn-outline-warning js-status" data-id="{{ $adset->id }}" data-status="PAUSED" title="Pause">
                <i class="bi bi-pause-fill"></i>
            </button>
        @else
            <button class="btn btn-outline-success js-status" data-id="{{ $adset->id }}" data-status="ACTIVE" title="Activate">
                <i class="bi bi-play-fill"></i>
            </button>
        @endif

        <button class="btn btn-outline-secondary js-edit" data-id="{{ $adset->id }}" title="Edit">
            <i class="bi bi-pencil"></i>
        </button>
    @endcan

    @can('campaigns.delete')
        <button class="btn btn-outline-danger js-delete" data-id="{{ $adset->id }}" title="Delete">
            <i class="bi bi-trash"></i>
        </button>
    @endcan
</div>
