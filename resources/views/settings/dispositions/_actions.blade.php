<div class="d-flex gap-1">
    @can('dispositions.edit')
    <button class="btn btn-xs btn-outline-primary btn-edit-disp" data-id="{{ $disposition->id }}" title="Edit">
        <i class="bi bi-pencil"></i>
    </button>
    @endcan
    @can('dispositions.delete')
    <button class="btn btn-xs btn-outline-danger btn-delete-disp" data-id="{{ $disposition->id }}" title="Delete">
        <i class="bi bi-trash"></i>
    </button>
    @endcan
</div>
