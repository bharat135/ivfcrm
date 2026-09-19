<div class="d-flex gap-1">
    @can('centers.edit')
    <button class="btn btn-xs btn-outline-primary btn-edit-center" data-id="{{ $center->id }}" title="Edit">
        <i class="bi bi-pencil"></i>
    </button>
    @endcan
    @can('centers.delete')
    @unless($center->trashed())
    <button class="btn btn-xs btn-outline-danger btn-delete-center" data-id="{{ $center->id }}" title="Delete">
        <i class="bi bi-trash"></i>
    </button>
    @endunless
    @endcan
</div>
