@extends('layouts.app')

@section('title', 'Follow-Ups')

@section('breadcrumb')
    <li class="breadcrumb-item active">Follow-Ups</li>
@endsection

@section('content')
<div class="d-flex justify-content-between align-items-center mb-3">
    <h5 class="fw-bold mb-0">Follow-Up Management</h5>
</div>

<!-- Stats -->
<div class="row g-3 mb-3">
    <div class="col-md-3">
        <div class="card border-0 shadow-sm">
            <div class="card-body d-flex align-items-center gap-3 py-3">
                <div class="icon-box" style="background:#fff8e1;color:#ffc107"><i class="bi bi-calendar-day"></i></div>
                <div><div class="fw-bold fs-5">{{ $todayCount }}</div><div class="text-muted small">Today</div></div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-0 shadow-sm">
            <div class="card-body d-flex align-items-center gap-3 py-3">
                <div class="icon-box" style="background:#fde8ea;color:#dc3545"><i class="bi bi-exclamation-triangle"></i></div>
                <div><div class="fw-bold fs-5">{{ $overdueCount }}</div><div class="text-muted small">Overdue</div></div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <a href="#" class="card border-0 shadow-sm text-decoration-none text-reset" id="importantCard" title="Show only important pending follow-ups">
            <div class="card-body d-flex align-items-center gap-3 py-3">
                <div class="icon-box" style="background:#fff3cd;color:#b8860b"><i class="bi bi-star-fill"></i></div>
                <div><div class="fw-bold fs-5">{{ $importantCount }}</div><div class="text-muted small">Important pending</div></div>
            </div>
        </a>
    </div>
</div>

<!-- Tabs -->
<ul class="nav nav-pills mb-3" id="fuTabs">
    <li class="nav-item"><a class="nav-link active" href="#" data-filter="today">Today</a></li>
    <li class="nav-item"><a class="nav-link" href="#" data-filter="overdue">Overdue</a></li>
    <li class="nav-item"><a class="nav-link" href="#" data-filter="pending">All Pending</a></li>
    <li class="nav-item"><a class="nav-link" href="#" data-filter="done">Completed</a></li>
</ul>

<!-- Filters -->
<div class="card border-0 shadow-sm mb-3">
    <div class="card-body py-2">
        <div class="row g-2 align-items-end">
            <div class="col-md-2">
                <select class="form-select form-select-sm fu-filter" id="filterType">
                    <option value="">All types</option>
                    <option value="call">Call</option>
                    <option value="whatsapp">WhatsApp</option>
                    <option value="email">Email</option>
                    <option value="visit">Visit</option>
                </select>
            </div>
            <div class="col-md-3">
                <select class="form-select form-select-sm fu-filter" id="filterDisposition">
                    <option value="">All dispositions</option>
                    @foreach($dispositions as $d)
                        <option value="{{ $d->id }}">{{ $d->name }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <input type="date" class="form-control form-control-sm fu-filter" id="filterFrom" title="Scheduled from">
            </div>
            <div class="col-md-2">
                <input type="date" class="form-control form-control-sm fu-filter" id="filterTo" title="Scheduled up to">
            </div>
            <div class="col-md-2">
                <select class="form-select form-select-sm fu-filter" id="filterImportant">
                    <option value="">Important &amp; normal</option>
                    <option value="1">&#9733; Important only</option>
                    <option value="0">Normal only</option>
                </select>
            </div>
            <div class="col-md-1">
                <button class="btn btn-outline-secondary btn-sm w-100" id="resetFilters">Reset</button>
            </div>
        </div>
    </div>
</div>

<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover table-sm mb-0" id="fuTable" style="width:100%">
                <thead class="table-light">
                    <tr>
                        <th width="28" title="Important"><i class="bi bi-star"></i></th>
                        <th>Lead</th><th>Mobile</th><th>Type</th>
                        <th>Scheduled At</th><th>Status</th><th>Disposition</th>
                        <th>Remark</th><th width="100">Actions</th>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
let currentFilter = 'today';

const table = $('#fuTable').DataTable({
    processing: true, serverSide: true,
    order: [[4, 'asc']],    // Soonest due first
    ajax: {
        url: '{{ route("followups.index") }}',
        data: d => {
            d[currentFilter] = 1; if (currentFilter === 'done') { d.status = 'done'; delete d.done; }
            d.type           = $('#filterType').val();
            d.disposition_id = $('#filterDisposition').val();
            d.from           = $('#filterFrom').val();
            d.to             = $('#filterTo').val();
            d.important      = $('#filterImportant').val();
        }
    },
    createdRow: (row, data) => { if (data.is_important) $(row).addClass('table-warning'); },
    columns: [
        { data: 'is_important', searchable: false, className: 'text-center', render: d =>
            d ? '<i class="bi bi-star-fill text-warning" title="Important follow-up"></i>' : '<span class="text-muted">&middot;</span>' },
        { data: 'lead.name', render: (d, t, row) =>
            `<a href="/leads/${row.lead_id}" class="text-decoration-none">${d || row.lead?.mobile || '—'}</a>` },
        { data: 'lead_mobile', orderable: false, searchable: false, defaultContent: '—',
          render: (d, t, row) => (d || '—') + (row.cover ? ' ' + row.cover : '') },
        { data: 'type', render: d => `<span class="badge bg-secondary">${d}</span>` },
        { data: 'scheduled_at', render: d => {
            const past = d && new Date(d) < new Date();
            return `<span class="${past ? 'text-danger fw-semibold' : ''}">${FUI.dateTime(d)}</span>`;
        }},
        { data: 'status', render: d => {
            const c = {pending:'warning text-dark',done:'success',cancelled:'secondary',missed:'danger'};
            return `<span class="badge bg-${c[d]||'secondary'}">${d}</span>`;
        }},
        { data: 'disposition.name', defaultContent: '—' },
        { data: 'remark', defaultContent: '—', render: d => d ? `<span title="${d}">${d.substring(0,40)}${d.length>40?'...':''}</span>` : '—' },
        { data: 'action', orderable: false, searchable: false },
    ],
    language: {
        emptyTable: FUI.emptyState('bi-alarm', 'No follow-ups', 'Schedule one when you disposition a call.'),
    }
});

$('#fuTabs .nav-link').on('click', function(e) {
    e.preventDefault();
    $('#fuTabs .nav-link').removeClass('active');
    $(this).addClass('active');
    currentFilter = $(this).data('filter');
    table.ajax.reload();
});

$('.fu-filter').on('change', () => table.ajax.reload());
$('#resetFilters').on('click', () => { $('.fu-filter').val(''); table.ajax.reload(); });

// The "Important pending" card jumps to every pending follow-up that is flagged.
$('#importantCard').on('click', function (e) {
    e.preventDefault();
    $('#fuTabs .nav-link').removeClass('active').filter('[data-filter=pending]').addClass('active');
    currentFilter = 'pending';
    $('#filterImportant').val('1');
    table.ajax.reload();
});
</script>
@endpush
