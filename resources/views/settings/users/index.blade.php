@extends('layouts.app')

@section('title', 'Users')

@section('content')
<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h4 class="mb-0">Users</h4>
        @can('users.create')
        <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#userModal">
            <i class="bi bi-plus-lg"></i> Add User
        </button>
        @endcan
    </div>

    <div class="card shadow-sm">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table id="usersTable" class="table table-hover align-middle mb-0 w-100">
                    <thead class="table-light">
                        <tr>
                            <th>Name</th>
                            <th>Email</th>
                            <th>Center</th>
                            <th>Role</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                </table>
            </div>
        </div>
    </div>
</div>

{{-- Create / Edit Modal --}}
<div class="modal fade" id="userModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <form id="userForm" novalidate>
            @csrf
            <input type="hidden" id="userId">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="userModalTitle">Add User</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="name" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Email <span class="text-danger">*</span></label>
                        <input type="email" class="form-control" name="email" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Phone</label>
                        <input type="text" class="form-control" name="phone">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Employee Code</label>
                        <input type="text" class="form-control" name="employee_code">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Tata Smartflo agent number</label>
                        <input type="text" class="form-control" name="tata_agent_number" placeholder="98XXXXXXXX or 050XXXXXX">
                        <small class="text-muted">The number Smartflo rings for click-to-call. Blank = use Phone.</small>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Password <span class="text-danger" id="pwRequired">*</span></label>
                        <input type="password" class="form-control" name="password" id="passwordField">
                        <small class="text-muted d-none" id="pwHint">Leave blank to keep current password</small>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Role <span class="text-danger">*</span></label>
                        <select class="form-select" name="role" required>
                            <option value="">— select —</option>
                            @foreach($roles as $role)
                            <option value="{{ $role->name }}">{{ $role->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Center</label>
                        <select class="form-select" name="center_id">
                            <option value="">— none —</option>
                            @foreach($centers as $c)
                            <option value="{{ $c->id }}">{{ $c->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Designation</label>
                        <input type="text" class="form-control" name="designation">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Language</label>
                        <select class="form-select" name="language">
                            <option value="en">English</option>
                            <option value="hi">Hindi</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Daily Lead Cap</label>
                        <input type="number" class="form-control" name="daily_lead_cap" min="1">
                    </div>
                    <div class="col-md-4 d-flex align-items-end">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" name="is_active" id="isActive" value="1" checked>
                            <label class="form-check-label" for="isActive">Active</label>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary btn-sm">Save</button>
                </div>
            </div>
        </form>
    </div>
</div>
@endsection

@push('scripts')
<script>
$(function () {
    const table = $('#usersTable').DataTable({
        processing: true,
        serverSide: true,
        ajax: '{{ route('settings.users.index') }}',
        columns: [
            { data: 'name' },
            { data: 'email' },
            { data: 'center.name', defaultContent: '—' },
            { data: 'roles_list', orderable: false },
            { data: 'status_badge', orderable: false },
            { data: 'action', orderable: false, searchable: false },
        ]
    });

    const modal  = new bootstrap.Modal('#userModal');
    const form   = document.getElementById('userForm');

    // Open for create
    $('#userModal').on('show.bs.modal', function (e) {
        if (e.relatedTarget && !$(e.relatedTarget).data('id')) {
            resetForm('Add User', true);
        }
    });

    // Save
    $(form).on('submit', function (e) {
        e.preventDefault();
        const id  = $('#userId').val();
        const url = id ? `/settings/users/${id}` : '{{ route('settings.users.store') }}';
        const method = id ? 'PUT' : 'POST';

        $.ajax({
            url, method,
            data: $(form).serialize() + (id ? '' : '') + '&is_active=' + ($('#isActive').is(':checked') ? 1 : 0),
            success(res) {
                if (res.success) {
                    modal.hide();
                    table.ajax.reload(null, false);
                    toastr.success(res.message);
                }
            },
            error(xhr) {
                const errors = xhr.responseJSON?.errors;
                if (errors) {
                    toastr.error(Object.values(errors).flat().join('<br>'));
                } else {
                    toastr.error(xhr.responseJSON?.message ?? 'Error saving user.');
                }
            }
        });
    });

    // Edit
    $(document).on('click', '.btn-edit-user', function () {
        const id = $(this).data('id');
        $.get(`/settings/users/${id}/edit`, function (res) {
            resetForm('Edit User', false);
            $('#userId').val(res.user.id);
            $('[name=name]').val(res.user.name);
            $('[name=email]').val(res.user.email);
            $('[name=phone]').val(res.user.phone);
            $('[name=employee_code]').val(res.user.employee_code);
            $('[name=role]').val(res.role);
            $('[name=center_id]').val(res.user.center_id);
            $('[name=designation]').val(res.user.designation);
            $('[name=language]').val(res.user.language);
            $('[name=daily_lead_cap]').val(res.user.daily_lead_cap);
            $('[name=tata_agent_number]').val(res.user.tata_agent_number);
            $('#isActive').prop('checked', res.user.is_active);
            modal.show();
        });
    });

    // Delete
    $(document).on('click', '.btn-delete-user', function () {
        const id = $(this).data('id');
        Swal.fire({ title: 'Delete user?', icon: 'warning', showCancelButton: true, confirmButtonText: 'Delete', confirmButtonColor: '#dc3545' })
            .then(r => {
                if (r.isConfirmed) {
                    $.ajax({ url: `/settings/users/${id}`, method: 'DELETE', data: { _token: '{{ csrf_token() }}' },
                        success(res) { toastr.success(res.message); table.ajax.reload(null, false); },
                        error(xhr)  { toastr.error(xhr.responseJSON?.message ?? 'Error.'); }
                    });
                }
            });
    });

    // Toggle status
    $(document).on('click', '.btn-toggle-user', function () {
        const id = $(this).data('id');
        $.ajax({ url: `/settings/users/${id}/toggle-status`, method: 'PATCH', data: { _token: '{{ csrf_token() }}' },
            success(res) { toastr.success(res.message); table.ajax.reload(null, false); },
            error(xhr)  { toastr.error(xhr.responseJSON?.message ?? 'Error.'); }
        });
    });

    function resetForm(title, requirePw) {
        form.reset();
        $('#userId').val('');
        $('#userModalTitle').text(title);
        $('#isActive').prop('checked', true);
        if (requirePw) {
            $('#passwordField').attr('required', true);
            $('#pwRequired').show();
            $('#pwHint').addClass('d-none');
        } else {
            $('#passwordField').removeAttr('required');
            $('#pwRequired').hide();
            $('#pwHint').removeClass('d-none');
        }
    }
});
</script>
@endpush
