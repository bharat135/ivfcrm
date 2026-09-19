@extends('layouts.app')

@section('title', 'Lead Sources')

@section('content')
<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h4 class="mb-0">Lead Sources & Platforms</h4>
        @can('lead_sources.create')
        <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#sourceModal">
            <i class="bi bi-plus-lg"></i> Add Source
        </button>
        @endcan
    </div>

    <div class="card shadow-sm">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table id="sourcesTable" class="table table-hover align-middle mb-0 w-100">
                    <thead class="table-light">
                        <tr>
                            <th>Name</th>
                            <th>Channel</th>
                            <th>Type</th>
                            <th>Leads</th>
                            <th>Campaigns</th>
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
<div class="modal fade" id="sourceModal" tabindex="-1">
    <div class="modal-dialog">
        <form id="sourceForm" novalidate>
            @csrf
            <input type="hidden" id="sourceId">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="sourceModalTitle">Add Lead Source</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body row g-3">
                    <div class="col-12">
                        <label class="form-label">Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="name" required>
                    </div>
                    <div class="col-12">
                        <label class="form-label">Channel / Platform <span class="text-danger">*</span></label>
                        <select class="form-select" name="channel" required>
                            <option value="">— select —</option>
                            @foreach($channels as $key => $label)
                            <option value="{{ $key }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-6">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" name="is_paid" value="1" id="isPaid">
                            <label class="form-check-label" for="isPaid">Paid Source</label>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" name="is_active" value="1" id="sourceActive" checked>
                            <label class="form-check-label" for="sourceActive">Active</label>
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
    const table = $('#sourcesTable').DataTable({
        processing: true,
        serverSide: true,
        ajax: '{{ route('settings.lead_sources.index') }}',
        columns: [
            { data: 'name' },
            { data: 'channel_badge', orderable: false },
            { data: 'paid_badge', orderable: false },
            { data: 'leads_count' },
            { data: 'campaigns_count' },
            { data: 'status_badge', orderable: false },
            { data: 'action', orderable: false, searchable: false },
        ]
    });

    const modal = new bootstrap.Modal('#sourceModal');
    const form  = document.getElementById('sourceForm');

    $('#sourceModal').on('show.bs.modal', function (e) {
        if (e.relatedTarget && !$(e.relatedTarget).data('id')) resetForm('Add Lead Source');
    });

    $(form).on('submit', function (e) {
        e.preventDefault();
        const id  = $('#sourceId').val();
        const url = id ? `/settings/lead-sources/${id}` : '{{ route('settings.lead_sources.store') }}';

        $.ajax({
            url, method: id ? 'PUT' : 'POST',
            data: $(form).serialize()
                + '&is_paid='   + ($('#isPaid').is(':checked')     ? 1 : 0)
                + '&is_active=' + ($('#sourceActive').is(':checked') ? 1 : 0),
            success(res) { if (res.success) { modal.hide(); table.ajax.reload(null, false); toastr.success(res.message); } },
            error(xhr)   {
                const errors = xhr.responseJSON?.errors;
                toastr.error(errors ? Object.values(errors).flat().join('<br>') : (xhr.responseJSON?.message ?? 'Error.'));
            }
        });
    });

    $(document).on('click', '.btn-edit-source', function () {
        const id = $(this).data('id');
        $.get(`/settings/lead-sources/${id}/edit`, function (s) {
            resetForm('Edit Lead Source');
            $('#sourceId').val(s.id);
            $('[name=name]').val(s.name);
            $('[name=channel]').val(s.channel);
            $('#isPaid').prop('checked', !!s.is_paid);
            $('#sourceActive').prop('checked', !!s.is_active);
            modal.show();
        });
    });

    $(document).on('click', '.btn-delete-source', function () {
        const id = $(this).data('id');
        Swal.fire({ title: 'Delete lead source?', icon: 'warning', showCancelButton: true, confirmButtonText: 'Delete', confirmButtonColor: '#dc3545' })
            .then(r => {
                if (r.isConfirmed) {
                    $.ajax({ url: `/settings/lead-sources/${id}`, method: 'DELETE', data: { _token: '{{ csrf_token() }}' },
                        success(res) { toastr.success(res.message); table.ajax.reload(null, false); },
                        error(xhr)  { toastr.error(xhr.responseJSON?.message ?? 'Error.'); }
                    });
                }
            });
    });

    function resetForm(title) {
        form.reset();
        $('#sourceId').val('');
        $('#sourceModalTitle').text(title);
        $('#sourceActive').prop('checked', true);
        $('#isPaid').prop('checked', false);
    }
});
</script>
@endpush
