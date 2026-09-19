@extends('layouts.app')

@section('title', 'Tata Numbers & Routing')

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">Dashboard</a></li>
    <li class="breadcrumb-item"><a href="{{ route('tata.index') }}">Tata Teleservices</a></li>
    <li class="breadcrumb-item active">Numbers &amp; Routing</li>
@endsection

@section('content')
<div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
    <h5 class="mb-0">Numbers &amp; Routing</h5>
    @can('tata.edit')<button class="btn btn-sm btn-outline-primary" id="btnPull"><i class="bi bi-arrow-down-circle me-1"></i>Pull from Smartflo</button>@endcan
</div>

@include('tata._nav')

<div class="card shadow-sm mb-3">
    <div class="card-header bg-white fw-semibold">DIDs (caller IDs)</div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light"><tr><th>Number</th><th>Name</th><th>Routes to</th><th>Centre caller ID</th><th>Default</th></tr></thead>
                <tbody>
                @forelse($numbers as $number)
                    <tr>
                        <td class="font-monospace">{{ $number->did }}</td>
                        <td>{{ $number->name ?: '—' }}</td>
                        <td class="small text-muted">{{ $number->destination_name ?: $number->destination ?: '—' }}</td>
                        <td>
                            @can('tata.edit')
                            <select class="form-select form-select-sm js-center" data-url="{{ route('tata.numbers.update', $number) }}" style="max-width:220px">
                                <option value="">— none —</option>
                                @foreach($centers as $c)<option value="{{ $c->id }}" @selected($number->center_id === $c->id)>{{ $c->name }}</option>@endforeach
                            </select>
                            @else
                                {{ $number->center?->name ?? '—' }}
                            @endcan
                        </td>
                        <td>
                            @if($number->is_default)
                                <span class="badge bg-success-subtle text-success">Default</span>
                            @else
                                @can('tata.edit')<button class="btn btn-sm btn-outline-secondary js-default" data-url="{{ route('tata.numbers.default', $number) }}">Make default</button>@endcan
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="text-center text-muted py-4">No numbers yet. Pull from Smartflo.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
    <div class="card-footer bg-white small text-muted">A DID assigned to a centre is used as the caller ID for that centre's agents; otherwise the default DID is shown.</div>
</div>

<div class="row g-3">
    <div class="col-lg-6">
        <div class="card shadow-sm h-100">
            <div class="card-header bg-white fw-semibold">Departments</div>
            <div class="card-body p-0">
                <table class="table table-sm align-middle mb-0">
                    <thead class="table-light"><tr><th>Name</th><th>Ring strategy</th><th>Agents</th></tr></thead>
                    <tbody>
                    @forelse($departments as $d)<tr><td>{{ $d->name }}</td><td class="small text-muted">{{ $d->ring_strategy ?: '—' }}</td><td>{{ $d->agent_count }}</td></tr>
                    @empty<tr><td colspan="3" class="text-center text-muted py-3">None.</td></tr>@endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card shadow-sm h-100">
            <div class="card-header bg-white fw-semibold">IVRs</div>
            <div class="card-body p-0">
                <table class="table table-sm align-middle mb-0">
                    <thead class="table-light"><tr><th>Name</th><th>Options</th></tr></thead>
                    <tbody>
                    @forelse($ivrs as $ivr)<tr><td>{{ $ivr->name }}</td><td class="small text-muted">{{ is_array($ivr->menu) ? count($ivr->menu) . ' option(s)' : '—' }}</td></tr>
                    @empty<tr><td colspan="2" class="text-center text-muted py-3">None.</td></tr>@endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
$(function () {
    $('#btnPull').on('click', function () {
        const $b = $(this).prop('disabled', true);
        $.post(@json(route('tata.numbers.pull'))).done(res => { toastr.success(res.message); setTimeout(() => location.reload(), 1200); })
            .fail(xhr => toastr.error(xhr.responseJSON?.message || 'Pull failed.')).always(() => $b.prop('disabled', false));
    });
    $('.js-default').on('click', function () {
        $.post($(this).data('url')).done(res => { toastr.success(res.message); setTimeout(() => location.reload(), 900); }).fail(() => toastr.error('Failed.'));
    });
    $('.js-center').on('change', function () {
        $.ajax({ url: $(this).data('url'), method: 'PATCH', data: { center_id: this.value, _token: @json(csrf_token()) } })
            .done(res => toastr.success(res.message)).fail(() => toastr.error('Could not save.'));
    });
});
</script>
@endpush
