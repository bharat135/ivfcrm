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
</div>

<!-- Tabs -->
<ul class="nav nav-pills mb-3" id="fuTabs">
    <li class="nav-item"><a class="nav-link active" href="#" data-filter="today">Today</a></li>
    <li class="nav-item"><a class="nav-link" href="#" data-filter="overdue">Overdue</a></li>
    <li class="nav-item"><a class="nav-link" href="#" data-filter="pending">All Pending</a></li>
    <li class="nav-item"><a class="nav-link" href="#" data-filter="done">Completed</a></li>
</ul>

<div class="card border-0 shadow-sm">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover table-sm mb-0" id="fuTable" style="width:100%">
                <thead class="table-light">
                    <tr>
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
    order: [[3, 'asc']],    // Soonest due first
    ajax: {
        url: '{{ route("followups.index") }}',
        data: d => { d[currentFilter] = 1; if (currentFilter === 'done') { d.status = 'done'; delete d.done; } }
    },
    columns: [
        { data: 'lead.name', render: (d, t, row) =>
            `<a href="/leads/${row.lead_id}" class="text-decoration-none">${d || row.lead?.mobile || '—'}</a>` },
        { data: 'lead.mobile', defaultContent: '—' },
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
</script>
@endpush
