@extends('layouts.app')

@section('title', 'Holidays & Leaves')

@section('content')
<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h4 class="mb-0">Holidays &amp; Leaves</h4>
            <p class="text-muted small mb-0">
                While an agent is on leave, their follow-up calls are covered by another present agent and no new
                leads are assigned to them. The lead itself keeps its owner.
            </p>
        </div>
        @can('leaves.create')
        <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#leaveModal">
            <i class="bi bi-plus-lg"></i> Add leave / holiday
        </button>
        @endcan
    </div>

    @if($onLeaveToday->isNotEmpty())
        <div class="alert alert-info small d-flex flex-wrap gap-2 align-items-center">
            <i class="bi bi-calendar-x"></i>
            <span class="fw-semibold">Off today:</span>
            @foreach($onLeaveToday as $l)
                <span class="badge {{ $l->isHoliday() ? 'bg-warning text-dark' : 'bg-light text-dark border' }}">
                    {{ $l->user?->name ?? 'Whole clinic' }}{{ $l->reason ? ' · ' . $l->reason : '' }}
                </span>
            @endforeach
        </div>
    @endif

    <div class="card shadow-sm">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table id="leavesTable" class="table table-hover align-middle mb-0 w-100">
                    <thead class="table-light">
                        <tr>
                            <th>Who</th>
                            <th>Dates</th>
                            <th>Type</th>
                            <th>Reason</th>
                            <th>Status</th>
                            <th>Added by</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                </table>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="leaveModal" tabindex="-1">
    <div class="modal-dialog">
        <form id="leaveForm" novalidate>
            @csrf
            <input type="hidden" id="leaveId">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="leaveModalTitle">Add leave / holiday</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body row g-3">
                    <div class="col-12">
                        <label class="form-label">Type</label>
                        <select class="form-select" name="type" id="leaveType">
                            @foreach($types as $key => $label)
                                <option value="{{ $key }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-12" id="agentWrap">
                        <label class="form-label">Agent <span class="text-danger">*</span></label>
                        <select class="form-select" name="user_id">
                            <option value="">— select —</option>
                            @foreach($agents as $u)
                                <option value="{{ $u->id }}">{{ $u->name }}{{ $u->center ? ' · ' . $u->center->name : '' }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-6">
                        <label class="form-label">From <span class="text-danger">*</span></label>
                        <input type="date" class="form-control" name="starts_on" required>
                    </div>
                    <div class="col-6">
                        <label class="form-label">To <span class="text-danger">*</span></label>
                        <input type="date" class="form-control" name="ends_on" required>
                    </div>
                    <div class="col-12">
                        <label class="form-label">Reason</label>
                        <input type="text" class="form-control" name="reason" maxlength="191" placeholder="e.g. Diwali, sick leave">
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
    const base  = '{{ url('settings/leaves') }}';
    const token = '{{ csrf_token() }}';

    const table = $('#leavesTable').DataTable({
        processing: true,
        serverSide: true,
        ajax: '{{ route('settings.leaves.index') }}',
        order: [[1, 'desc']],
        columns: [
            { data: 'who', orderable: false },
            { data: 'period', name: 'starts_on' },
            { data: 'type_badge', name: 'type', orderable: false },
            { data: 'reason', defaultContent: '—' },
            { data: 'state', orderable: false, searchable: false },
            { data: 'by', orderable: false, searchable: false },
            { data: 'action', orderable: false, searchable: false },
        ]
    });

    const modal = new bootstrap.Modal('#leaveModal');
    const form  = document.getElementById('leaveForm');

    function syncType() {
        $('#agentWrap').toggleClass('d-none', $('#leaveType').val() === 'holiday');
    }
    $('#leaveType').on('change', syncType);

    $('#leaveModal').on('show.bs.modal', function (e) {
        if (e.relatedTarget && !$(e.relatedTarget).data('id')) resetForm('Add leave / holiday');
    });

    $(form).on('submit', function (e) {
        e.preventDefault();
        const id  = $('#leaveId').val();
        $.ajax({
            url: id ? `${base}/${id}` : '{{ route('settings.leaves.store') }}',
            method: id ? 'PUT' : 'POST',
            data: $(form).serialize(),
            success(res) { modal.hide(); table.ajax.reload(null, false); toastr.success(res.message); },
            error(xhr) {
                const errors = xhr.responseJSON?.errors;
                toastr.error(errors ? Object.values(errors).flat().join('<br>') : (xhr.responseJSON?.message ?? 'Error.'));
            }
        });
    });

    $(document).on('click', '.btn-edit-leave', function () {
        $.get(`${base}/${$(this).data('id')}/edit`, function (l) {
            resetForm('Edit leave / holiday');
            $('#leaveId').val(l.id);
            $('[name=type]').val(l.user_id ? 'leave' : 'holiday');
            $('[name=user_id]').val(l.user_id || '');
            $('[name=starts_on]').val(l.starts_on || '');
            $('[name=ends_on]').val(l.ends_on || '');
            $('[name=reason]').val(l.reason || '');
            syncType();
            modal.show();
        });
    });

    $(document).on('click', '.btn-delete-leave', function () {
        const id = $(this).data('id');
        Swal.fire({ title: 'Remove this entry?', text: 'Any follow-up cover for these days is withdrawn.', icon: 'warning', showCancelButton: true, confirmButtonText: 'Remove', confirmButtonColor: '#dc3545' })
            .then(r => {
                if (!r.isConfirmed) return;
                $.ajax({ url: `${base}/${id}`, method: 'DELETE', data: { _token: token },
                    success(res) { toastr.success(res.message); table.ajax.reload(null, false); },
                    error(xhr)  { toastr.error(xhr.responseJSON?.message ?? 'Error.'); }
                });
            });
    });

    function resetForm(title) {
        form.reset();
        $('#leaveId').val('');
        $('#leaveModalTitle').text(title);
        syncType();
    }
});
</script>
@endpush
