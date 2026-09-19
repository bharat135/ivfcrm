<div class="d-flex gap-1">
    @can('leaves.edit')
    <button class="btn btn-xs btn-outline-primary btn-edit-leave" data-id="{{ $leave->id }}" title="Edit">
        <i class="bi bi-pencil"></i>
    </button>
    @endcan
    @can('leaves.delete')
    <button class="btn btn-xs btn-outline-danger btn-delete-leave" data-id="{{ $leave->id }}" title="Remove">
        <i class="bi bi-trash"></i>
    </button>
    @endcan
</div>
