<div class="d-flex gap-1">
    @can('assignment_rules.edit')
    <button class="btn btn-xs btn-outline-primary btn-edit-rule" data-id="{{ $rule->id }}" title="Edit">
        <i class="bi bi-pencil"></i>
    </button>
    <button class="btn btn-xs btn-outline-secondary btn-toggle-rule" data-id="{{ $rule->id }}" title="{{ $rule->is_active ? 'Pause' : 'Activate' }}">
        <i class="bi {{ $rule->is_active ? 'bi-pause' : 'bi-play' }}"></i>
    </button>
    @endcan
    @can('assignment_rules.delete')
    <button class="btn btn-xs btn-outline-danger btn-delete-rule" data-id="{{ $rule->id }}" title="Delete">
        <i class="bi bi-trash"></i>
    </button>
    @endcan
</div>
