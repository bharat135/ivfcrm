@extends('layouts.app')

@section('title', 'Centers')

@section('content')
<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h4 class="mb-0">Fertility Centers</h4>
        @can('centers.create')
        <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#centerModal">
            <i class="bi bi-plus-lg"></i> Add Center
        </button>
        @endcan
    </div>

    <div class="card shadow-sm">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table id="centersTable" class="table table-hover align-middle mb-0 w-100">
                    <thead class="table-light">
                        <tr>
                            <th>Name</th>
                            <th>Code</th>
                            <th>Phone</th>
                            <th>City</th>
                            <th>Users</th>
                            <th>Leads</th>
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
<div class="modal fade" id="centerModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <form id="centerForm" novalidate>
            @csrf
            <input type="hidden" id="centerId">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="centerModalTitle">Add Center</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body row g-3">
                    <div class="col-md-8">
                        <label class="form-label">Center Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="name" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Code <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="code" required maxlength="30">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Phone</label>
                        <input type="text" class="form-control" name="phone">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Email</label>
                        <input type="email" class="form-control" name="email">
                    </div>
                    <div class="col-12">
                        <label class="form-label">Address</label>
                        <textarea class="form-control" name="address" rows="2"></textarea>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">State</label>
                        <select class="form-select" name="state_id" id="stateSelect">
                            <option value="">— select —</option>
                            @foreach($states as $s)
                            <option value="{{ $s->id }}">{{ $s->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">City</label>
                        <select class="form-select" name="city_id" id="citySelect">
                            <option value="">— select state first —</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Pincode</label>
                        <input type="text" class="form-control" name="pincode" maxlength="10">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Timezone</label>
                        <input type="text" class="form-control" name="timezone" placeholder="Asia/Kolkata">
                    </div>
                    <div class="col-md-6 d-flex align-items-end">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" name="is_active" value="1" id="centerActive" checked>
                            <label class="form-check-label" for="centerActive">Active</label>
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
    const table = $('#centersTable').DataTable({
        processing: true,
        serverSide: true,
        ajax: '{{ route('settings.centers.index') }}',
        columns: [
            { data: 'name' },
            { data: 'code' },
            { data: 'phone', defaultContent: '—' },
            { data: 'city.name', defaultContent: '—' },
            { data: 'users_count' },
            { data: 'leads_count' },
            { data: 'status_badge', orderable: false },
            { data: 'action', orderable: false, searchable: false },
        ]
    });

    const modal = new bootstrap.Modal('#centerModal');
    const form  = document.getElementById('centerForm');

    $('#centerModal').on('show.bs.modal', function (e) {
        if (e.relatedTarget && !$(e.relatedTarget).data('id')) resetForm('Add Center');
    });

    // State → City cascade
    $('#stateSelect').on('change', function () {
        const stateId = $(this).val();
        $('#citySelect').html('<option value="">Loading…</option>');
        if (!stateId) { $('#citySelect').html('<option value="">— select state first —</option>'); return; }
        $.get(`/api/cities?state_id=${stateId}`, function (cities) {
            let opts = '<option value="">— select city —</option>';
            cities.forEach(c => opts += `<option value="${c.id}">${c.name}</option>`);
            $('#citySelect').html(opts);
        });
    });

    $(form).on('submit', function (e) {
        e.preventDefault();
        const id  = $('#centerId').val();
        const url = id ? `/settings/centers/${id}` : '{{ route('settings.centers.store') }}';

        $.ajax({
            url, method: id ? 'PUT' : 'POST',
            data: $(form).serialize() + '&is_active=' + ($('#centerActive').is(':checked') ? 1 : 0),
            success(res) { if (res.success) { modal.hide(); table.ajax.reload(null, false); toastr.success(res.message); } },
            error(xhr)   {
                const errors = xhr.responseJSON?.errors;
                toastr.error(errors ? Object.values(errors).flat().join('<br>') : (xhr.responseJSON?.message ?? 'Error.'));
            }
        });
    });

    $(document).on('click', '.btn-edit-center', function () {
        const id = $(this).data('id');
        $.get(`/settings/centers/${id}/edit`, function (c) {
            resetForm('Edit Center');
            $('#centerId').val(c.id);
            $('[name=name]').val(c.name);
            $('[name=code]').val(c.code);
            $('[name=phone]').val(c.phone);
            $('[name=email]').val(c.email);
            $('[name=address]').val(c.address);
            $('[name=state_id]').val(c.state_id).trigger('change');
            setTimeout(() => $('[name=city_id]').val(c.city_id), 300);
            $('[name=pincode]').val(c.pincode);
            $('[name=timezone]').val(c.timezone);
            $('#centerActive').prop('checked', !!c.is_active);
            modal.show();
        });
    });

    $(document).on('click', '.btn-delete-center', function () {
        const id = $(this).data('id');
        Swal.fire({ title: 'Delete center?', icon: 'warning', showCancelButton: true, confirmButtonText: 'Delete', confirmButtonColor: '#dc3545' })
            .then(r => {
                if (r.isConfirmed) {
                    $.ajax({ url: `/settings/centers/${id}`, method: 'DELETE', data: { _token: '{{ csrf_token() }}' },
                        success(res) { toastr.success(res.message); table.ajax.reload(null, false); },
                        error(xhr)  { toastr.error(xhr.responseJSON?.message ?? 'Error.'); }
                    });
                }
            });
    });

    function resetForm(title) {
        form.reset();
        $('#centerId').val('');
        $('#centerModalTitle').text(title);
        $('#centerActive').prop('checked', true);
        $('#citySelect').html('<option value="">— select state first —</option>');
    }
});
</script>
@endpush
