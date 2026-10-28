@extends('layouts.app')

@section('title', 'Leads')

@section('breadcrumb')
    <li class="breadcrumb-item active">Leads</li>
@endsection

@section('content')
<div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
    <div>
        <h5 class="fw-bold mb-1">Enquiries</h5>
        <p class="text-muted small mb-0">
            The follow-up worklist. Everyone here already has a patient record —
            <a href="{{ route('patients.index') }}">see them as patients</a>.
        </p>
    </div>
    <div class="d-flex gap-2">
        @can('lead_imports.create')
        <a href="{{ route('imports.create') }}" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-upload me-1"></i> Import CSV / Excel
        </a>
        @endcan
        @can('leads.create')
        <a href="{{ route('leads.create') }}" class="btn btn-primary btn-sm">
            <i class="bi bi-plus-lg me-1"></i> New Lead
        </a>
        @endcan
    </div>
</div>

<!-- Filters -->
<div class="card border-0 shadow-sm mb-3">
    <div class="card-body py-2">
        <div class="row g-2 align-items-end">
            <div class="col-md-2">
                <select class="form-select form-select-sm" id="filterChannel">
                    <option value="">All channels</option>
                    @foreach($channels as $key => $label)
                        <option value="{{ $key }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <select class="form-select form-select-sm" id="filterSource">
                    <option value="">All Sources</option>
                    @foreach($sources as $s)
                        <option value="{{ $s->id }}">{{ $s->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <select class="form-select form-select-sm" id="filterStage">
                    <option value="">All Stages</option>
                    @foreach($stages as $s)
                        <option value="{{ $s->id }}">{{ $s->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <select class="form-select form-select-sm" id="filterCenter">
                    <option value="">All Centers</option>
                    @foreach($centers as $c)
                        <option value="{{ $c->id }}">{{ $c->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <select class="form-select form-select-sm" id="filterStatus">
                    <option value="">All Status</option>
                    <option value="new">New</option>
                    <option value="working">Working</option>
                    <option value="won">Won</option>
                    <option value="lost">Lost</option>
                    <option value="junk">Junk</option>
                </select>
            </div>
            <div class="col-md-2">
                <select class="form-select form-select-sm" id="filterAssigned">
                    <option value="">All Telecallers</option>
                    @foreach($telecallers as $u)
                        <option value="{{ $u->id }}">{{ $u->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <select class="form-select form-select-sm" id="filterDisposition">
                    <option value="">All Last Dispositions</option>
                    <option value="none">Not dispositioned yet</option>
                    @foreach($dispositions as $d)
                        <option value="{{ $d->id }}">{{ $d->name }}</option>
                        @foreach($d->children as $child)
                            <option value="s{{ $child->id }}">&nbsp;&nbsp;&nbsp;↳ {{ $child->name }}</option>
                        @endforeach
                    @endforeach
                </select>
            </div>
            <div class="col-md-1">
                <button class="btn btn-outline-secondary btn-sm w-100" id="resetFilters">Reset</button>
            </div>
        </div>
    </div>
</div>

<!-- DataTable -->
<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        {{-- Only occupies space once rows are selected. --}}
        <div class="d-none align-items-center gap-2 px-3 py-2 border-bottom" id="bulkActions">
            <span class="text-muted small" id="selectedCount">0 selected</span>
            <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#bulkAssignModal">
                <i class="bi bi-person-check"></i>Bulk assign
            </button>
            @can('call_logs.create')
                <button class="btn btn-sm btn-success" id="bulkCall" title="Call the selected leads one after another; each outcome you save dials the next">
                    <i class="bi bi-telephone-outbound"></i>Bulk call
                </button>
            @endcan
            <button class="btn btn-sm btn-link ms-auto" id="clearSelection">Clear</button>
        </div>
        <div class="table-responsive">
            <table class="table table-hover table-sm mb-0" id="leadsTable" style="width:100%">
                <thead class="table-light">
                    <tr>
                        <th width="30"><input type="checkbox" id="selectAll" class="form-check-input"></th>
                        <th>Name</th>
                        <th>Mobile</th>
                        <th>Came from</th>
                        <th>Sub Source</th>
                        <th>Campaign</th>
                        <th>Stage</th>
                        <th>Disposition</th>
                        <th>Assigned To</th>
                        <th>Assigned On</th>
                        <th>Center</th>
                        <th>Score</th>
                        <th>Next Follow-Up</th>
                        <th>Added On</th>
                        <th width="80">Actions</th>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>
        </div>
    </div>
</div>

<!-- Bulk Assign Modal -->
<div class="modal fade" id="bulkAssignModal" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header">
                <h6 class="modal-title">Bulk Assign Leads</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <select class="form-select" id="bulkAssignUser">
                    @foreach($telecallers as $u)
                        <option value="{{ $u->id }}">{{ $u->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-primary btn-sm" id="doBulkAssign">Assign</button>
            </div>
        </div>
    </div>
</div>

@endsection

@push('scripts')
<script>
let selectedLeads = [];

const leadsTable = $('#leadsTable').DataTable({
    processing: true,
    serverSide: true,
    order: [[13, 'desc']],   // Added On, newest first
    ajax: {
        url: '{{ route("leads.index") }}',
        data: function(d) {
            d.channel     = $('#filterChannel').val();
            d.source_id   = $('#filterSource').val();
            d.stage_id    = $('#filterStage').val();
            d.center_id   = $('#filterCenter').val();
            d.status      = $('#filterStatus').val();
            d.assigned_to = $('#filterAssigned').val();
            d.disposition = $('#filterDisposition').val();
        }
    },
    columns: [
        { data: null, orderable: false, searchable: false,
          render: (d, t, row) => `<input type="checkbox" class="form-check-input lead-check" value="${row.id}">` },
        { data: 'name', defaultContent: '<span class="text-muted">—</span>',
          render: (d, t, row) => {
              const code = row.patient?.patient_code ? `<div class="text-muted small">${row.patient.patient_code}</div>` : '';
              return `<a href="/leads/${row.id}" class="text-decoration-none fw-semibold">${d || row.mobile}</a>${code}`;
          } },
        { data: 'mobile' },
        { data: 'origin', name: 'channel', orderable: true },
        { data: 'sub_source', defaultContent: '—' },
        { data: 'campaign_name', defaultContent: '—' },
        { data: 'stage_badge', orderable: false },
        { data: 'disposition_badge', orderable: false },
        { data: 'assigned_to.name', defaultContent: '<span class="text-warning small">Unassigned</span>' },
        { data: 'assigned_at', defaultContent: '—',
          render: d => d ? FUI.dateTime(d) : '<span class="text-muted">—</span>' },
        { data: 'center.code', defaultContent: '—' },
        { data: 'score_band', defaultContent: '—',
          render: d => d ? `<span class="badge ${d==='hot'?'bg-danger':d==='warm'?'bg-warning text-dark':'bg-info'}">${d}</span>` : '—' },
        { data: 'next_follow_up_at', defaultContent: '—',
          render: d => d
            ? `<span class="${new Date(d) < new Date() ? 'text-danger fw-semibold' : ''}">${FUI.dateTime(d)}</span>`
            : '<span class="text-muted">—</span>' },
        { data: 'created_at', render: d => d ? FUI.dateTime(d) : '—' },
        { data: 'action', orderable: false, searchable: false },
    ],
    buttons: [
        { extend: 'csv', text: '<i class="bi bi-download"></i>CSV', titleAttr: 'Export the current page as CSV', title: 'Leads' },
        { extend: 'excel', text: '<i class="bi bi-file-earmark-spreadsheet"></i>Excel', titleAttr: 'Export the current page as Excel', title: 'Leads' },
    ],
    language: {
        emptyTable: FUI.emptyState('bi-people', 'No leads yet', 'Leads appear here as they arrive from your campaigns and forms.'),
    },
    drawCallback: function() {
        $('[data-bs-toggle="tooltip"]').tooltip();
    }
});

// Reload on filter change
$('#filterChannel,#filterSource,#filterStage,#filterCenter,#filterStatus,#filterAssigned,#filterDisposition').on('change', () => leadsTable.ajax.reload());
$('#resetFilters').on('click', function() {
    $('#filterChannel,#filterSource,#filterStage,#filterCenter,#filterStatus,#filterAssigned,#filterDisposition').val('');
    leadsTable.ajax.reload();
});

// Select all
$('#selectAll').on('change', function() {
    $('.lead-check').prop('checked', this.checked);
    updateBulkBar();
});
$(document).on('change', '.lead-check', updateBulkBar);

$('#clearSelection').on('click', function () {
    $('.lead-check, #selectAll').prop('checked', false);
    updateBulkBar();
});

function updateBulkBar() {
    selectedLeads = $('.lead-check:checked').map((i, el) => $(el).val()).get();
    if (selectedLeads.length > 0) {
        $('#bulkActions').removeClass('d-none').addClass('d-flex');
        $('#selectedCount').text(selectedLeads.length + ' selected');
    } else {
        $('#bulkActions').removeClass('d-flex').addClass('d-none');
    }
}

// Bulk call: the selected leads become a queue; the first one opens and is
// dialled, and every saved outcome dials the next.
$('#bulkCall').on('click', function () {
    if (!selectedLeads.length) return;
    const $btn = $(this).prop('disabled', true);

    Swal.fire({
        title: 'Start bulk calling?',
        text: selectedLeads.length + ' lead(s) will be called one after another. Your phone rings first for each call, then the lead is connected.',
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Start calling',
    }).then(result => {
        if (!result.isConfirmed) return $btn.prop('disabled', false);

        $.post('{{ route("leads.bulk_call.start") }}', { lead_ids: selectedLeads })
            .done(r => {
                if (!r.success) { toastr.error(r.message); return $btn.prop('disabled', false); }
                toastr.success(r.message);
                window.location.href = r.redirect;
            })
            .fail(xhr => { toastr.error(xhr.responseJSON?.message || 'Could not start bulk calling.'); $btn.prop('disabled', false); });
    });
});

// Bulk assign
$('#doBulkAssign').on('click', function() {
    if (!selectedLeads.length) return;
    $.post('{{ route("leads.bulk_assign") }}', {
        lead_ids: selectedLeads,
        user_id: $('#bulkAssignUser').val()
    }).done(r => {
        if (r.success) {
            toastr.success(r.count + ' leads assigned successfully.');
            bootstrap.Modal.getInstance($('#bulkAssignModal')[0]).hide();
            leadsTable.ajax.reload();
        }
    });
});
</script>
@endpush
