@extends('layouts.app')

@section('title', $broadcast->name)

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">Dashboard</a></li>
    <li class="breadcrumb-item"><a href="{{ route('tata.broadcasts.index') }}">Broadcasts</a></li>
    <li class="breadcrumb-item active">{{ $broadcast->name }}</li>
@endsection

@section('content')
<div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
    <div>
        <h5 class="mb-0">{{ $broadcast->name }} <span class="badge bg-{{ $broadcast->statusBadge() }}-subtle text-{{ $broadcast->statusBadge() }}">{{ ucfirst($broadcast->status) }}</span></h5>
        <small class="text-muted">{{ $broadcast->lead_count }} leads · caller ID {{ $broadcast->caller_id }} · {{ $broadcast->scheduled_start_at?->format('d-m h:i A') }}–{{ $broadcast->scheduled_end_at?->format('d-m h:i A') }}</small>
    </div>
    <div class="d-flex gap-2">
        <button class="btn btn-sm btn-outline-secondary" id="btnRefresh"><i class="bi bi-arrow-repeat me-1"></i>Refresh</button>
        @can('tata.edit')
            @if($broadcast->isControllable())
                @if($broadcast->status !== 'running')<button class="btn btn-sm btn-success js-ctl" data-action="{{ $broadcast->status === 'paused' ? 'resume' : 'start' }}"><i class="bi bi-play-fill me-1"></i>Start</button>@endif
                @if($broadcast->status === 'running')<button class="btn btn-sm btn-warning js-ctl" data-action="pause"><i class="bi bi-pause-fill me-1"></i>Pause</button>@endif
                <button class="btn btn-sm btn-outline-danger js-ctl" data-action="end"><i class="bi bi-stop-fill me-1"></i>End</button>
            @endif
        @endcan
    </div>
</div>

@if($broadcast->last_error)<div class="alert alert-danger small">{{ $broadcast->last_error }}</div>@endif

<div class="row g-3 mb-3" id="statTiles">
    @php $ls = $broadcast->stats['leads'] ?? []; @endphp
    @foreach(['queued' => 'secondary', 'dialed' => 'primary', 'answered' => 'success', 'missed' => 'warning', 'failed' => 'danger'] as $k => $tone)
        <div class="col"><div class="card shadow-sm"><div class="card-body py-2">
            <div class="small text-muted text-capitalize">{{ $k }}</div>
            <div class="h5 mb-0 text-{{ $tone }}" data-stat="{{ $k }}">{{ $ls[$k] ?? $broadcast->leads()->where('status', $k)->count() }}</div>
        </div></div></div>
    @endforeach
</div>

<div class="card shadow-sm">
    <div class="card-header bg-white fw-semibold">Leads</div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0">
                <thead class="table-light"><tr><th>Lead</th><th>Mobile</th><th>Status</th><th>Dial status</th><th>Attempts</th></tr></thead>
                <tbody>
                @foreach($leads as $row)
                    <tr>
                        <td>@if($row->lead)<a href="{{ route('leads.show', $row->lead) }}" class="text-decoration-none">{{ $row->lead->name }}</a>@else — @endif</td>
                        <td class="font-monospace small">{{ $row->mobile }}</td>
                        <td><span class="badge bg-secondary-subtle text-secondary">{{ ucfirst($row->status) }}</span></td>
                        <td class="small text-muted">{{ $row->dial_status ?: '—' }}</td>
                        <td>{{ $row->attempts }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>
</div>
<div class="mt-3">{{ $leads->links() }}</div>
@endsection

@push('scripts')
<script>
$(function () {
    $('#btnRefresh').on('click', function () {
        const $b = $(this).prop('disabled', true);
        $.post(@json(route('tata.broadcasts.refresh', $broadcast))).done(res => {
            toastr.success(res.message);
            const ls = res.stats?.leads || {};
            Object.entries(ls).forEach(([k, v]) => $(`[data-stat="${k}"]`).text(v));
        }).fail(xhr => toastr.error(xhr.responseJSON?.message || 'Refresh failed.')).always(() => $b.prop('disabled', false));
    });
    $('.js-ctl').on('click', function () {
        const action = $(this).data('action');
        $.post(@json(url('tata/broadcasts')) + '/{{ $broadcast->id }}/' + action, { _token: @json(csrf_token()) })
            .done(res => { toastr.success(res.message); setTimeout(() => location.reload(), 1000); })
            .fail(xhr => toastr.error(xhr.responseJSON?.message || 'Action failed.'));
    });
});
</script>
@endpush
