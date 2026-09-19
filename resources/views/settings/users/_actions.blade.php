<div class="d-flex gap-1">
    @can('users.edit')
    <button class="btn btn-xs btn-outline-primary btn-edit-user" data-id="{{ $user->id }}" title="Edit">
        <i class="bi bi-pencil"></i>
    </button>
    <button class="btn btn-xs btn-outline-{{ $user->is_active ? 'warning' : 'success' }} btn-toggle-user"
            data-id="{{ $user->id }}"
            title="{{ $user->is_active ? 'Deactivate' : 'Activate' }}">
        <i class="bi bi-{{ $user->is_active ? 'pause-circle' : 'play-circle' }}"></i>
    </button>
    @endcan
    @can('users.delete')
    <button class="btn btn-xs btn-outline-danger btn-delete-user" data-id="{{ $user->id }}" title="Delete">
        <i class="bi bi-trash"></i>
    </button>
    @endcan
</div>
