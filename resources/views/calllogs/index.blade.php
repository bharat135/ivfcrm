@extends('layouts.app')

@section('title', 'Call Logs')

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">Dashboard</a></li>
    <li class="breadcrumb-item active">Call Logs</li>
@endsection

@section('content')

<div class="card border-0 shadow-sm">
    <div class="card-header bg-white d-flex flex-wrap align-items-center gap-2">
        <span class="fw-semibold me-auto"><i class="bi bi-telephone me-2"></i>Call Logs</span>

        <select class="form-select form-select-sm w-auto" id="fChannel">
            <option value="">All channels</option>
            <option value="pstn">Phone</option>
            <option value="whatsapp">WhatsApp</option>
            <option value="internal">Internal</option>
        </select>

        <select class="form-select form-select-sm w-auto" id="fDirection">
            <option value="">Both directions</option>
            <option value="inbound">Inbound</option>
            <option value="outbound">Outbound</option>
        </select>

        <select class="form-select form-select-sm w-auto" id="fStatus">
            <option value="">All statuses</option>
            <option value="completed">Completed</option>
            <option value="no-answer">No answer</option>
            <option value="busy">Busy</option>
            <option value="failed">Failed</option>
        </select>

        <select class="form-select form-select-sm w-auto" id="fAgent">
            <option value="">All agents</option>
            @foreach($agents as $agent)
                <option value="{{ $agent->id }}">{{ $agent->name }}</option>
            @endforeach
        </select>

        <input type="text" class="form-control form-control-sm w-auto" id="fRange" placeholder="Date range">

        <a href="{{ route('calls.console') }}" class="btn btn-sm btn-outline-primary">
            <i class="bi bi-headset me-1"></i>Console
        </a>
    </div>

    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover table-sm align-middle w-100" id="callTable">
                <thead class="table-light">
                    <tr>
                        <th>Contact</th>
                        <th>Agent</th>
                        <th>Channel</th>
                        <th>Status</th>
                        <th>Ring</th>
                        <th>Talk</th>
                        <th>Outcome</th>
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
            range.from = dates[0] ? dates[0].toISOString().slice(0, 10) : null;
            range.to   = dates[1] ? dates[1].toISOString().slice(0, 10) : null;
            table.ajax.reload();
        },
    });

    const table = $('#callTable').DataTable({
        processing: true,
        serverSide: true,
        order: [[7, 'desc']],
        ajax: {
            url: '{{ route("call_logs.index") }}',
            data: d => {
                d.channel   = $('#fChannel').val();
                d.direction = $('#fDirection').val();
                d.status    = $('#fStatus').val();
                d.user_id   = $('#fAgent').val();
                d.from      = range.from;
                d.to        = range.to;
            },
        },
        columns: [
            { data: 'contact', name: 'lead.name', orderable: false },
            { data: 'user.name', name: 'user.name', defaultContent: '—' },
            { data: 'channel_badge', name: 'channel', orderable: false },
            { data: 'status_badge', name: 'status' },
            { data: 'ring_fmt', name: 'ring_seconds', className: 'text-muted small' },
            { data: 'duration_fmt', name: 'talk_seconds' },
            { data: 'disposition.name', name: 'disposition.name', defaultContent: '—' },
            { data: 'created_at', name: 'created_at',
              render: d => d ? new Date(d).toLocaleString('en-IN', {
                  day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit',
              }) : '—' },
            { data: 'action', orderable: false, searchable: false },
        ],
        buttons: [{ extend: 'csv', className: 'btn btn-sm btn-outline-secondary', text: '<i class="bi bi-download me-1"></i>Export' }],
        language: {
            emptyTable: FUI.emptyState('bi-telephone', 'No calls logged', 'Calls placed from the CRM or received on the PBX appear here.'),
        },
    });

    $('#fChannel, #fDirection, #fStatus, #fAgent').on('change', () => table.ajax.reload());
});
</script>
@endpush
