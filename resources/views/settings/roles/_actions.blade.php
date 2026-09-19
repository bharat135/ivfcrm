<div class="d-flex gap-1">
    @can('roles.edit')
    <button class="btn btn-xs btn-outline-primary btn-edit-role" data-id="{{ $role->id }}" title="Edit">
        <i class="bi bi-pencil"></i>
    </button>
    @endcan
    @can('roles.delete')
    @if(!in_array($role->name, ['Super Admin', 'Management']))
    <button class="btn btn-xs btn-outline-danger btn-delete-role" data-id="{{ $role->id }}" title="Delete">
        <i class="bi bi-trash"></i>
    </button>
    @endif
    @endcan
</div>
