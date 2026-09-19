@extends('layouts.app')

@section('title', 'Roles & Permissions')

@section('content')
<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h4 class="mb-0">Roles & Permissions</h4>
        @can('roles.create')
        <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#roleModal">
            <i class="bi bi-plus-lg"></i> Add Role
        </button>
        @endcan
    </div>

    <div class="card shadow-sm">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table id="rolesTable" class="table table-hover align-middle mb-0 w-100">
                    <thead class="table-light">
                        <tr>
                            <th>Role Name</th>
                            <th>Users</th>
                            <th>Permissions</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                </table>
            </div>
        </div>
    </div>
</div>

{{-- Role Modal --}}
<div class="modal fade" id="roleModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <form id="roleForm" novalidate>
            @csrf
            <input type="hidden" id="roleId">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="roleModalTitle">Add Role</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Role Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="name" required maxlength="100">
                    </div>

                    <label class="form-label fw-semibold">Permissions</label>
                    <div class="mb-2 d-flex gap-2">
                        <button type="button" class="btn btn-xs btn-outline-secondary" id="checkAll">Select All</button>
                        <button type="button" class="btn btn-xs btn-outline-secondary" id="uncheckAll">Deselect All</button>
                    </div>

                    <div class="row g-2">
                        @foreach($permissions as $module => $perms)
                        <div class="col-md-4">
                            <div class="card border">
                                <div class="card-header py-1 px-2 d-flex justify-content-between align-items-center">
                                    <strong class="text-capitalize small">{{ str_replace('_', ' ', $module) }}</strong>
                                    <input class="form-check-input module-toggle" type="checkbox" data-module="{{ $module }}" title="Toggle all">
                                </div>
                                <div class="card-body py-2 px-2">
                                    @foreach($perms as $perm)
                                    <div class="form-check form-check-sm">
                                        <input class="form-check-input perm-check perm-{{ $module }}"
                                               type="checkbox"
                                               name="permissions[]"
                                               value="{{ $perm->name }}"
                                               id="perm_{{ $perm->id }}">
                                        <label class="form-check-label small" for="perm_{{ $perm->id }}">
                                            {{ explode('.', $perm->name)[1] ?? $perm->name }}
                                        </label>
                                    </div>
                                    @endforeach
                                </div>
                            </div>
                        </div>
                        @endforeach
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary btn-sm">Save Role</button>
                </div>
            </div>
        </form>
    </div>
</div>
@endsection

@push('scripts')
<script>
$(function () {
    const table = $('#rolesTable').DataTable({
        processing: true,
        serverSide: true,
        ajax: '{{ route('settings.roles.index') }}',
        columns: [
            { data: 'name' },
            { data: 'users_count' },
            { data: 'permissions_count', orderable: false },
            { data: 'action', orderable: false, searchable: false },
        ]
    });

    const modal = new bootstrap.Modal('#roleModal');
    const form  = document.getElementById('roleForm');

    $('#roleModal').on('show.bs.modal', function (e) {
        if (e.relatedTarget && !$(e.relatedTarget).data('id')) {
            resetForm('Add Role');
        }
    });

    // Check all / uncheck all
    $('#checkAll').on('click',   () => $('.perm-check').prop('checked', true));
    $('#uncheckAll').on('click', () => $('.perm-check').prop('checked', false));

    // Module toggle
    $(document).on('change', '.module-toggle', function () {
        const module = $(this).data('module');
        $(`.perm-${module}`).prop('checked', $(this).is(':checked'));
    });

    $(form).on('submit', function (e) {
        e.preventDefault();
        const id  = $('#roleId').val();
        const url = id ? `/settings/roles/${id}` : '{{ route('settings.roles.store') }}';

        $.ajax({
            url, method: id ? 'PUT' : 'POST',
            data: $(form).serialize(),
            success(res) {
                if (res.success) { modal.hide(); table.ajax.reload(null, false); toastr.success(res.message); }
            },
            error(xhr) {
                const errors = xhr.responseJSON?.errors;
                toastr.error(errors ? Object.values(errors).flat().join('<br>') : (xhr.responseJSON?.message ?? 'Error.'));
            }
        });
    });

    // Edit
    $(document).on('click', '.btn-edit-role', function () {
        const id = $(this).data('id');
        $.get(`/settings/roles/${id}/edit`, function (res) {
            resetForm('Edit Role');
            $('#roleId').val(res.role.id);
            $('[name=name]').val(res.role.name);
            res.permissions.forEach(p => $(`input[value="${p}"]`).prop('checked', true));
            modal.show();
        });
    });

    // Delete
    $(document).on('click', '.btn-delete-role', function () {
        const id = $(this).data('id');
        Swal.fire({ title: 'Delete role?', icon: 'warning', showCancelButton: true, confirmButtonText: 'Delete', confirmButtonColor: '#dc3545' })
            .then(r => {
                if (r.isConfirmed) {
                    $.ajax({ url: `/settings/roles/${id}`, method: 'DELETE', data: { _token: '{{ csrf_token() }}' },
                        success(res) { toastr.success(res.message); table.ajax.reload(null, false); },
                        error(xhr)  { toastr.error(xhr.responseJSON?.message ?? 'Error.'); }
                    });
                }
            });
    });

    function resetForm(title) {
        form.reset();
        $('#roleId').val('');
        $('#roleModalTitle').text(title);
        $('.perm-check, .module-toggle').prop('checked', false);
    }
});
</script>
@endpush
