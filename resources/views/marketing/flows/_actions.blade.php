<div class="btn-group btn-group-sm">
    <a href="{{ route('marketing.flows.show', $flow) }}" class="btn btn-outline-primary" title="Open">
        <i class="bi bi-eye"></i>
    </a>

    @if($flow->external_id)
        <button class="btn btn-outline-secondary js-preview" data-id="{{ $flow->id }}" title="Preview on Meta">
            <i class="bi bi-phone"></i>
        </button>
    @endif

    @can('whatsapp_flows.create')
        @if($flow->canSend())
            <button class="btn btn-outline-success js-send" data-id="{{ $flow->id }}"
                    data-name="{{ e($flow->name) }}" title="Send to a contact">
                <i class="bi bi-send"></i>
            </button>
        @endif
    @endcan

    @can('whatsapp_flows.delete')
        @unless($flow->isPublished())
            <button class="btn btn-outline-danger js-delete" data-id="{{ $flow->id }}" title="Delete">
                <i class="bi bi-trash"></i>
            </button>
        @endunless
    @endcan
</div>
