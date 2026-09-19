<div class="btn-group btn-group-sm">
    <a href="{{ route('marketing.broadcasts.show', $broadcast) }}" class="btn btn-outline-primary" title="Open">
        <i class="bi bi-eye"></i>
    </a>

    @can('broadcasts.create')
        @if($broadcast->isEditable())
            <button class="btn btn-outline-success js-send" data-id="{{ $broadcast->id }}" title="Send now">
                <i class="bi bi-send"></i>
            </button>
        @endif
    @endcan

    @can('broadcasts.edit')
        @if(in_array($broadcast->status, ['scheduled', 'running']))
            <button class="btn btn-outline-warning js-cancel" data-id="{{ $broadcast->id }}" title="Cancel">
                <i class="bi bi-x-octagon"></i>
            </button>
        @endif
    @endcan

    @can('broadcasts.delete')
        <button class="btn btn-outline-danger js-delete" data-id="{{ $broadcast->id }}" title="Delete">
            <i class="bi bi-trash"></i>
        </button>
    @endcan
</div>
