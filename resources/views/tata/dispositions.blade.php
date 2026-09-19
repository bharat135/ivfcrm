@extends('layouts.app')

@section('title', 'Tata Dispositions')

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">Dashboard</a></li>
    <li class="breadcrumb-item"><a href="{{ route('tata.index') }}">Tata Teleservices</a></li>
    <li class="breadcrumb-item active">Dispositions</li>
@endsection

@section('content')
<div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
    <div>
        <h5 class="mb-0">Dispositions</h5>
        <small class="text-muted">CRM outcomes mirrored to the Smartflo dialer list “{{ $listName }}”{{ $listId ? ' (#' . $listId . ')' : '' }} · {{ $stats['synced'] }} synced · {{ $stats['pending'] }} pending · {{ $stats['errors'] }} errors</small>
    </div>
    @can('tata.edit')
    <div class="d-flex gap-2">
        <button class="btn btn-sm btn-outline-secondary" id="btnPull"><i class="bi bi-arrow-down-circle me-1"></i>Pull</button>
        <button class="btn btn-sm btn-outline-secondary" id="btnPush"><i class="bi bi-arrow-up-circle me-1"></i>Push</button>
        <button class="btn btn-sm btn-primary" id="btnSync"><i class="bi bi-arrow-repeat me-1"></i>Sync both ways</button>
    </div>
    @endcan
</div>

@include('tata._nav')

<div class="card shadow-sm">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light"><tr><th>CRM disposition</th><th>Type</th><th>Smartflo status id</th><th>Sync</th><th>Last synced</th></tr></thead>
                <tbody>
                @foreach($rows as $row)
                    @php $d = $row['model']; $link = $row['link']; @endphp
                    <tr>
                        <td>@if($d->parent_id)<span class="text-muted ms-3">↳ </span>@endif{{ $d->name }}</td>
                        <td class="small text-muted">{{ $d->parent_id ? 'Sub of ' . $d->parent?->name : 'Parent' }}</td>
                        <td class="font-monospace small">{{ $link?->smartflo_status_id ?: '—' }}</td>
                        <td>
                            @if($link)
                                <span class="badge bg-{{ $link->statusBadge() }}-subtle text-{{ $link->statusBadge() }}" @if($link->last_error) title="{{ $link->last_error }}" @endif>{{ ucfirst($link->sync_status) }}</span>
                            @else
                                <span class="badge bg-secondary-subtle text-secondary">Not pushed</span>
                            @endif
                        </td>
                        <td class="small text-muted">{{ $link?->last_synced_at?->diffForHumans() ?? '—' }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>
</div>

@if($orphans->isNotEmpty())
<div class="alert alert-light border mt-3 small">
    <strong>{{ $orphans->count() }}</strong> Smartflo disposition(s) have no CRM match yet. Run <em>Pull</em> to create them in the CRM.
</div>
@endif
@endsection

@push('scripts')
<script>
$(function () {
    const run = (url) => $.post(url).done(res => { res.success ? toastr.success(res.message) : toastr.warning(res.message); setTimeout(() => location.reload(), 1400); })
        .fail(xhr => toastr.error(xhr.responseJSON?.message || 'Sync failed.'));
    $('#btnSync').on('click', function () { $(this).prop('disabled', true); run(@json(route('tata.dispositions.sync'))); });
    $('#btnPush').on('click', function () { $(this).prop('disabled', true); run(@json(route('tata.dispositions.push'))); });
    $('#btnPull').on('click', function () { $(this).prop('disabled', true); run(@json(route('tata.dispositions.pull'))); });
});
</script>
@endpush
