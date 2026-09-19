@extends('layouts.app')

@section('title', 'Dispositions')

@section('content')
<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h4 class="mb-0">Dispositions & Sub-Dispositions</h4>
        @can('dispositions.create')
        <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#dispSettingsModal">
            <i class="bi bi-plus-lg"></i> Add Disposition
        </button>
        @endcan
    </div>

    <div class="card shadow-sm">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table id="dispositionsTable" class="table table-hover align-middle mb-0 w-100">
                    <thead class="table-light">
                        <tr>
                            <th>Name</th>
                            <th>Type</th>
                            <th>Parent</th>
                            <th>Stage</th>
                            <th>Flags</th>
                            <th>Color</th>
                            <th>Sub-count</th>
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
<div class="modal fade" id="dispSettingsModal" tabindex="-1">
    <div class="modal-dialog">
        <form id="dispositionForm" novalidate>
            @csrf
            <input type="hidden" id="dispositionId">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="dispModalTitle">Add Disposition</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body row g-3">
                    <div class="col-12">
                        <label class="form-label">Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="name" required>
                    </div>
                    <div class="col-12">
                        <label class="form-label">Parent (leave blank for primary)</label>
                        <select class="form-select" name="parent_id" id="parentSelect">
                            <option value="">— Primary Disposition —</option>
                            @foreach($parents as $p)
                            <option value="{{ $p->id }}">{{ $p->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Color</label>
                        <input type="color" class="form-control form-control-color w-100" name="color" value="#6c757d">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Sort Order</label>
                        <input type="number" class="form-control" name="sort_order" min="0" value="0">
                    </div>
                    <div class="col-12">
                        <label class="form-label">Moves to Stage</label>
                        <select class="form-select" name="moves_to_stage_id">
                            <option value="">— no stage change —</option>
                            @foreach($stages as $s)
                            <option value="{{ $s->id }}">{{ $s->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-12">
                        <div class="row g-2">
                            <div class="col-6">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="requires_remark" value="1" id="reqRemark">
                                    <label class="form-check-label" for="reqRemark">Requires Remark</label>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="requires_followup" value="1" id="reqFollowup">
                                    <label class="form-check-label" for="reqFollowup">Requires Follow-up</label>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="is_won" value="1" id="isWon">
                                    <label class="form-check-label text-success fw-semibold" for="isWon">Won</label>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="is_lost" value="1" id="isLost">
                                    <label class="form-check-label text-danger fw-semibold" for="isLost">Lost</label>
                                </div>
                            </div>
                            <div class="col-6">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" name="is_active" value="1" id="dispActive" checked>
                                    <label class="form-check-label" for="dispActive">Active</label>
                                </div>
                            </div>
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
    const table = $('#dispositionsTable').DataTable({
        processing: true,
        serverSide: true,
        ajax: '{{ route('settings.dispositions.index') }}',
        columns: [
            { data: 'name' },
            { data: 'type', orderable: false },
            { data: 'parent.name', defaultContent: '—' },
            { data: 'moves_to_stage.name', defaultContent: '—' },
            { data: 'flags', orderable: false },
            { data: 'color', render: v => v ? `<span class="badge" style="background:${v}">${v}</span>` : '—', orderable: false },
            { data: 'children_count' },
            { data: 'status_badge', orderable: false },
            { data: 'action', orderable: false, searchable: false },
        ]
    });

    const modal = new bootstrap.Modal('#dispSettingsModal');
    const form  = document.getElementById('dispositionForm');

    $('#dispSettingsModal').on('show.bs.modal', function (e) {
        if (e.relatedTarget && !$(e.relatedTarget).data('id')) resetForm('Add Disposition');
    });

    $(form).on('submit', function (e) {
        e.preventDefault();
        const id  = $('#dispositionId').val();
        const url = id ? `/settings/dispositions/${id}` : '{{ route('settings.dispositions.store') }}';

        const formData = $(form).serialize()
            + '&is_active='        + ($('#dispActive').is(':checked')   ? 1 : 0)
            + '&requires_remark='  + ($('#reqRemark').is(':checked')    ? 1 : 0)
            + '&requires_followup='+ ($('#reqFollowup').is(':checked')  ? 1 : 0)
            + '&is_won='           + ($('#isWon').is(':checked')        ? 1 : 0)
            + '&is_lost='          + ($('#isLost').is(':checked')       ? 1 : 0);

        $.ajax({
            url, method: id ? 'PUT' : 'POST', data: formData,
            success(res) { if (res.success) { modal.hide(); table.ajax.reload(null, false); toastr.success(res.message); } },
            error(xhr)   { toastr.error(xhr.responseJSON?.message ?? 'Error.'); }
        });
    });

    $(document).on('click', '.btn-edit-disp', function () {
        const id = $(this).data('id');
        $.get(`/settings/dispositions/${id}/edit`, function (d) {
            resetForm('Edit Disposition');
            $('#dispositionId').val(d.id);
            $('[name=name]').val(d.name);
            $('[name=parent_id]').val(d.parent_id ?? '');
            $('[name=color]').val(d.color ?? '#6c757d');
            $('[name=sort_order]').val(d.sort_order ?? 0);
            $('[name=moves_to_stage_id]').val(d.moves_to_stage_id ?? '');
            $('#reqRemark').prop('checked', !!d.requires_remark);
            $('#reqFollowup').prop('checked', !!d.requires_followup);
            $('#isWon').prop('checked', !!d.is_won);
            $('#isLost').prop('checked', !!d.is_lost);
            $('#dispActive').prop('checked', !!d.is_active);
            modal.show();
        });
    });

    $(document).on('click', '.btn-delete-disp', function () {
        const id = $(this).data('id');
        Swal.fire({ title: 'Delete disposition?', icon: 'warning', showCancelButton: true, confirmButtonText: 'Delete', confirmButtonColor: '#dc3545' })
            .then(r => {
                if (r.isConfirmed) {
                    $.ajax({ url: `/settings/dispositions/${id}`, method: 'DELETE', data: { _token: '{{ csrf_token() }}' },
                        success(res) { toastr.success(res.message); table.ajax.reload(null, false); },
                        error(xhr)  { toastr.error(xhr.responseJSON?.message ?? 'Error.'); }
                    });
                }
            });
    });

    function resetForm(title) {
        form.reset();
        $('#dispositionId').val('');
        $('#dispModalTitle').text(title);
        $('#dispActive').prop('checked', true);
        $('[name=color]').val('#6c757d');
    }
});
</script>
@endpush
