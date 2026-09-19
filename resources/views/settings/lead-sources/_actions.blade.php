<div class="d-flex gap-1">
    @can('lead_sources.edit')
    <button class="btn btn-xs btn-outline-primary btn-edit-source" data-id="{{ $source->id }}" title="Edit">
        <i class="bi bi-pencil"></i>
    </button>
    @endcan
    @can('lead_sources.delete')
    <button class="btn btn-xs btn-outline-danger btn-delete-source" data-id="{{ $source->id }}" title="Delete">
        <i class="bi bi-trash"></i>
    </button>
    @endcan
</div>
