@extends('layouts.app')

@section('title', 'Tata Call Records')

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">Dashboard</a></li>
    <li class="breadcrumb-item"><a href="{{ route('tata.index') }}">Tata Teleservices</a></li>
    <li class="breadcrumb-item active">Call Records</li>
@endsection

@section('content')
<div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
    <div>
        <h5 class="mb-0">Call Records</h5>
        <small class="text-muted">{{ $total }} Tata calls · {{ $connected }} connected · {{ $pending }} without an outcome · {{ $unsynced }} outcomes not yet pushed</small>
    </div>
    @can('tata.edit')
    <div class="input-group input-group-sm" style="max-width:420px">
        <input type="date" id="pullFrom" class="form-control" value="{{ now()->subDay()->toDateString() }}">
        <input type="date" id="pullTo" class="form-control" value="{{ now()->toDateString() }}">
        <button class="btn btn-outline-primary" id="btnPull"><i class="bi bi-cloud-download me-1"></i>Backfill from CDR</button>
    </div>
    @endcan
</div>

@include('tata._nav')

<div class="card shadow-sm">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table id="tataCalls" class="table table-hover align-middle mb-0 w-100">
                <thead class="table-light"><tr>
                    <th>Contact</th><th>Agent</th><th>Outcome</th><th>Note</th><th>Duration</th><th>Rec</th><th>Sync</th><th>When</th><th></th>
                </tr></thead>
            </table>
        </div>
    </div>
</div>

<audio id="tataPlayer" class="d-none" controls></audio>
@endsection

@push('scripts')
<script>
$(function () {
    const table = $('#tataCalls').DataTable({
        processing: true, serverSide: true, order: [[7, 'desc']],
        ajax: @json(route('tata.calls.index')),
        columns: [
            { data: 'contact', name: 'from_number' },
            { data: 'agent', name: 'user.name', orderable: false },
            { data: 'outcome', name: 'disposition.name', orderable: false },
            { data: 'note', name: 'remark' },
            { data: 'duration_fmt', name: 'talk_seconds' },
            { data: 'recording', orderable: false, searchable: false },
            { data: 'synced', name: 'tata_synced_at', orderable: false },
            { data: 'when', name: 'started_at' },
            { data: 'id', orderable: false, searchable: false, render: (id, t, row) =>
                (row.synced.includes('Pending') ? `<button class="btn btn-sm btn-outline-secondary js-resync" data-id="${id}"><i class="bi bi-arrow-repeat"></i></button>` : '') },
        ],
    });

    $('#btnPull').on('click', function () {
        const $b = $(this).prop('disabled', true);
        $.post(@json(route('tata.calls.pull')), { from: $('#pullFrom').val(), to: $('#pullTo').val(), _token: @json(csrf_token()) })
            .done(res => { res.success ? toastr.success(res.message) : toastr.warning(res.message); table.ajax.reload(null, false); })
            .fail(xhr => toastr.error(xhr.responseJSON?.message || 'Backfill failed.')).always(() => $b.prop('disabled', false));
    });

    $('#tataCalls').on('click', '.js-play', function () {
        const p = document.getElementById('tataPlayer'); p.src = $(this).data('src'); p.classList.remove('d-none'); p.play();
    });
    $('#tataCalls').on('click', '.js-resync', function () {
        $.post(@json(url('tata/calls')) + '/' + $(this).data('id') + '/resync', { _token: @json(csrf_token()) })
            .done(res => { res.success ? toastr.success(res.message) : toastr.warning(res.message); table.ajax.reload(null, false); })
            .fail(xhr => toastr.error(xhr.responseJSON?.message || 'Resync failed.'));
    });
});
</script>
@endpush
