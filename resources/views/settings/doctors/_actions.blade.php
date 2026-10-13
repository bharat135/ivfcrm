<div class="d-flex gap-1">
    @can('doctors.edit')
    <button class="btn btn-xs btn-outline-primary btn-edit-doctor" data-id="{{ $doctor->id }}" title="Edit">
        <i class="bi bi-pencil"></i>
    </button>
    <button class="btn btn-xs btn-outline-{{ $doctor->is_active ? 'secondary' : 'success' }} btn-toggle-doctor" data-id="{{ $doctor->id }}"
            title="{{ $doctor->is_active ? 'Deactivate' : 'Activate' }}">
        <i class="bi bi-{{ $doctor->is_active ? 'pause' : 'play' }}"></i>
    </button>
    @endcan
    @can('doctors.delete')
    <button class="btn btn-xs btn-outline-danger btn-delete-doctor" data-id="{{ $doctor->id }}" title="Delete">
        <i class="bi bi-trash"></i>
    </button>
    @endcan
</div>
