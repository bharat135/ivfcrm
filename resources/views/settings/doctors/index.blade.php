@extends('layouts.app')

@section('title', 'Doctors')

@section('content')
<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="mb-0">Doctors</h4>
            <div class="text-muted small">Who patients can be booked with. Only active doctors appear in consultation booking.</div>
        </div>
        @can('doctors.create')
        <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#doctorModal">
            <i class="bi bi-plus-lg"></i> Add Doctor
        </button>
        @endcan
    </div>

    <div class="card shadow-sm">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table id="doctorsTable" class="table table-hover align-middle mb-0 w-100">
                    <thead class="table-light">
                        <tr>
                            <th>Name</th>
                            <th>Code</th>
                            <th>Specialization</th>
                            <th>Centre</th>
                            <th>Phone</th>
                            <th>Login</th>
                            <th>Slot</th>
                            <th>Appointments</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                </table>
            </div>
        </div>
    </div>
</div>

{{-- Modal --}}
<div class="modal fade" id="doctorModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <form id="doctorForm" novalidate>
            @csrf
            <input type="hidden" id="doctorId">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="doctorModalTitle">Add Doctor</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body row g-3">
                    <div class="col-md-8">
                        <label class="form-label">Name <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <span class="input-group-text">Dr.</span>
                            <input type="text" class="form-control" name="name" required placeholder="Full name without the title">
                        </div>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Code</label>
                        <input type="text" class="form-control" name="code" maxlength="40" placeholder="e.g. DR01">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Qualification</label>
                        <input type="text" class="form-control" name="qualification" placeholder="MBBS, MS (OBG), Fellowship in Reproductive Medicine">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Specialization</label>
                        <input type="text" class="form-control" name="specialization" placeholder="IVF Specialist / Embryologist / Andrologist">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Phone</label>
                        <input type="text" class="form-control" name="phone" maxlength="20">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Email</label>
                        <input type="email" class="form-control" name="email">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Centre</label>
                        <select class="form-select" name="center_id">
                            <option value="">All centres</option>
                            @foreach($centers as $c)
                                <option value="{{ $c->id }}">{{ $c->name }}</option>
                            @endforeach
                        </select>
                        <div class="form-text">Leave blank if the doctor consults at every centre.</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">CRM login</label>
                        <select class="form-select" name="user_id">
                            <option value="">Not linked</option>
                            @foreach($users as $u)
                                <option value="{{ $u->id }}">{{ $u->name }} ({{ $u->email }})</option>
                            @endforeach
                        </select>
                        <div class="form-text">Users with the Doctor role. Linking lets the doctor see their own appointments.</div>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Consultation slot (minutes)</label>
                        <input type="number" class="form-control" name="consultation_minutes" min="5" max="240" value="30">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Consultation fee (₹)</label>
                        <input type="number" class="form-control" name="consultation_fee" min="0" step="1">
                    </div>
                    <div class="col-md-4 d-flex align-items-end">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" name="is_active" value="1" id="doctorActive" checked>
                            <label class="form-check-label" for="doctorActive">Active</label>
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
    const table = $('#doctorsTable').DataTable({
        processing: true,
        serverSide: true,
        ajax: '{{ route('settings.doctors.index') }}',
        columns: [
            { data: 'name', render: d => 'Dr. ' + $('<div>').text(d).html() },
            { data: 'code', defaultContent: '—' },
            { data: 'specialization', defaultContent: '—' },
            { data: 'center.name', defaultContent: 'All centres' },
            { data: 'phone', defaultContent: '—' },
            { data: 'login', orderable: false, searchable: false },
            { data: 'consultation_minutes', render: d => d + ' min' },
            { data: 'appointments_count', searchable: false },
            { data: 'status_badge', orderable: false, searchable: false },
            { data: 'action', orderable: false, searchable: false },
        ]
    });

    const modal = new bootstrap.Modal('#doctorModal');
    const form  = document.getElementById('doctorForm');

    $('#doctorModal').on('show.bs.modal', function (e) {
        if (e.relatedTarget && !$(e.relatedTarget).data('id')) resetForm('Add Doctor');
    });

    $(form).on('submit', function (e) {
        e.preventDefault();
        const id  = $('#doctorId').val();
        const url = id ? `/settings/doctors/${id}` : '{{ route('settings.doctors.store') }}';

        $.ajax({
            url, method: id ? 'PUT' : 'POST',
            data: $(form).serialize() + '&is_active=' + ($('#doctorActive').is(':checked') ? 1 : 0),
            success(res) { if (res.success) { modal.hide(); table.ajax.reload(null, false); toastr.success(res.message); } },
            error(xhr)   {
                const errors = xhr.responseJSON?.errors;
                toastr.error(errors ? Object.values(errors).flat().join('<br>') : (xhr.responseJSON?.message ?? 'Error.'));
            }
        });
    });

    $(document).on('click', '.btn-edit-doctor', function () {
        const id = $(this).data('id');
        $.get(`/settings/doctors/${id}/edit`, function (d) {
            resetForm('Edit Doctor');
            $('#doctorId').val(d.id);
            ['name', 'code', 'qualification', 'specialization', 'phone', 'email', 'center_id', 'user_id', 'consultation_minutes', 'consultation_fee']
                .forEach(f => $(form).find(`[name=${f}]`).val(d[f] ?? ''));
            $('#doctorActive').prop('checked', !!d.is_active);
            modal.show();
        });
    });

    $(document).on('click', '.btn-toggle-doctor', function () {
        $.ajax({ url: `/settings/doctors/${$(this).data('id')}/toggle`, method: 'PATCH', data: { _token: '{{ csrf_token() }}' },
            success(res) { toastr.success(res.message); table.ajax.reload(null, false); },
            error(xhr)  { toastr.error(xhr.responseJSON?.message ?? 'Error.'); }
        });
    });

    $(document).on('click', '.btn-delete-doctor', function () {
        const id = $(this).data('id');
        Swal.fire({ title: 'Delete doctor?', text: 'A doctor with appointments is deactivated instead.', icon: 'warning', showCancelButton: true, confirmButtonText: 'Delete', confirmButtonColor: '#dc3545' })
            .then(r => {
                if (r.isConfirmed) {
                    $.ajax({ url: `/settings/doctors/${id}`, method: 'DELETE', data: { _token: '{{ csrf_token() }}' },
                        success(res) { toastr.success(res.message); table.ajax.reload(null, false); },
                        error(xhr)  { toastr.error(xhr.responseJSON?.message ?? 'Error.'); }
                    });
                }
            });
    });

    function resetForm(title) {
        form.reset();
        $('#doctorId').val('');
        $('#doctorModalTitle').text(title);
        $('#doctorActive').prop('checked', true);
        $(form).find('[name=consultation_minutes]').val(30);
    }
});
</script>
@endpush
