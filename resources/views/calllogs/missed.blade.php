@extends('layouts.app')

@section('title', 'Missed Calls')

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">Dashboard</a></li>
    <li class="breadcrumb-item"><a href="{{ route('call_logs.index') }}">Call Logs</a></li>
    <li class="breadcrumb-item active">Missed Calls</li>
@endsection

@section('content')

<div class="card border-0 shadow-sm">
    <div class="card-header bg-white d-flex flex-wrap align-items-center gap-2">
        <span class="fw-semibold me-auto"><i class="bi bi-telephone-x me-2 text-danger"></i>Missed Calls</span>

        @if($agents->count() > 1 && auth()->user()->hasRole(['Super Admin', 'Admin', 'Management', 'Marketing Head', 'Center Manager', 'Call Quality Analyst']))
            <select class="form-select form-select-sm w-auto" id="fAgent">
                <option value="">All agents</option>
                @foreach($agents as $agent)
                    <option value="{{ $agent->id }}">{{ $agent->name }}</option>
                @endforeach
            </select>
        @endif

        <input type="text" class="form-control form-control-sm w-auto" id="fRange" placeholder="Date range">
    </div>

    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover table-sm align-middle w-100" id="missedTable">
                <thead class="table-light">
                    <tr>
                        <th>Caller</th>
                        <th>Agent</th>
                        <th>Status</th>
                        <th>Rang</th>
                        <th>When</th>
                        <th style="width:150px">Actions</th>
                    </tr>
                </thead>
            </table>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
$(function () {
    let range = { from: null, to: null };

    flatpickr('#fRange', {
        mode: 'range',
        altInput: true,
        altFormat: 'd-m-Y',
        dateFormat: 'Y-m-d',
        onClose(dates) {
            range.from = dates[0] ? flatpickr.formatDate(dates[0], 'Y-m-d') : null;
            range.to   = dates[1] ? flatpickr.formatDate(dates[1], 'Y-m-d') : null;
            table.ajax.reload();
        },
    });

    const table = $('#missedTable').DataTable({
        processing: true,
        serverSide: true,
        order: [[4, 'desc']],
        ajax: {
            url: '{{ route("call_logs.missed") }}',
            data: d => {
                d.seen    = @json($seenBefore?->toIso8601String());
                d.user_id = $('#fAgent').val();
                d.from    = range.from;
                d.to      = range.to;
            },
        },
        columns: [
            { data: 'contact', name: 'from_number', orderable: false },
            { data: 'agent', name: 'user.name', orderable: false },
            { data: 'status_badge', name: 'status' },
            { data: 'ring_fmt', name: 'ring_seconds', className: 'text-muted small' },
            { data: 'when', name: 'started_at' },
            { data: 'action', orderable: false, searchable: false },
        ],
        language: {
            emptyTable: FUI.emptyState('bi-telephone-x', 'No missed calls', 'Inbound Smartflo calls nobody picked up appear here.'),
        },
    });

    $('#fAgent').on('change', () => table.ajax.reload());
});
</script>
@endpush
