@extends('layouts.app')

@section('title', 'Audiences')

@section('breadcrumb')
    <li class="breadcrumb-item">Marketing</li>
    <li class="breadcrumb-item active">Audiences</li>
@endsection

@section('content')

<div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
    <div>
        <h5 class="fw-bold mb-1">Audiences</h5>
        <p class="text-muted small mb-0">
            Target Meta using the clinic's own patient list — retarget the couples who stalled, stop paying to reach the
            ones already in treatment, and find more of the ones who converted.
        </p>
    </div>
    <form method="GET" class="d-flex gap-2">
        <select name="center_id" class="form-select form-select-sm" style="width:auto" onchange="this.form.submit()">
            <option value="">All centers</option>
            @foreach($centers as $center)
                <option value="{{ $center->id }}" @selected($centerId == $center->id)>{{ $center->name }}</option>
            @endforeach
        </select>
    </form>
</div>

@unless($connected)
    <div class="alert alert-warning d-flex mb-3">
        <i class="bi bi-exclamation-triangle mt-1"></i>
        <div>
            Meta Ads is not connected, so audiences cannot be uploaded yet. The segment sizes below are still accurate.
            <a href="{{ route('settings.integrations.index') }}" class="alert-link">Add your access token and ad account</a>.
        </div>
    </div>
@endunless

<div class="alert d-flex mb-3">
    <i class="bi bi-shield-lock mt-1"></i>
    <div>
        Every name, number and email is hashed with SHA-256 before it is uploaded. Meta matches the hashes against its own
        and never receives readable patient details.
    </div>
</div>

<div class="row g-3">
    @foreach($segments as $segment)
        <div class="col-md-6">
            <div class="card border-0 shadow-sm h-100">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-start gap-2">
                        <div class="min-w-0">
                            <h6 class="fw-semibold mb-1">{{ $segment['label'] }}</h6>
                            <p class="text-muted small mb-2">{{ $segment['description'] }}</p>
                        </div>
                        <div class="text-end">
                            <div class="fs-4 fw-semibold">{{ number_format($segment['size']) }}</div>
                            <div class="text-muted small">people</div>
                        </div>
                    </div>

                    @if($segment['audience_id'])
                        <div class="identity-strip mb-3">
                            <i class="bi bi-link-45deg"></i>
                            <span class="text-truncate">Linked to Meta audience <code>{{ $segment['audience_id'] }}</code></span>
                        </div>
                    @endif

                    <div class="d-flex flex-wrap gap-2">
                        @can('campaigns.edit')
                            <button class="btn btn-sm btn-primary sync-audience"
                                    data-segment="{{ $segment['key'] }}"
                                    @disabled(! $connected || $segment['size'] === 0)>
                                <i class="bi bi-cloud-upload me-1"></i>
                                {{ $segment['audience_id'] ? 'Refresh on Meta' : 'Create on Meta' }}
                            </button>

                            @if($segment['audience_id'])
                                <button class="btn btn-sm btn-outline-secondary make-lookalike"
                                        data-audience="{{ $segment['audience_id'] }}">
                                    <i class="bi bi-people me-1"></i>Lookalike
                                </button>
                                <button class="btn btn-sm btn-link text-muted unlink-audience"
                                        data-segment="{{ $segment['key'] }}">Unlink</button>
                            @endif
                        @endcan
                    </div>
                </div>
            </div>
        </div>
    @endforeach
</div>

@if($remote)
    <div class="card border-0 shadow-sm mt-3">
        <div class="card-header bg-transparent d-flex justify-content-between align-items-center">
            <span class="fw-semibold">Audiences on your ad account</span>
            <span class="text-muted small">Live from Meta</span>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 small">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Type</th>
                        <th class="text-end">Approx. size</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($remote as $audience)
                        <tr>
                            <td class="fw-semibold">{{ $audience['name'] }}</td>
                            <td><span class="badge text-bg-light text-body">{{ $audience['subtype'] }}</span></td>
                            <td class="text-end">
                                {{-- Meta reports "below 1000" as -1 rather than a number. --}}
                                @if($audience['size'] === null || $audience['size'] < 0)
                                    <span class="text-muted">Under 1,000</span>
                                @else
                                    {{ number_format($audience['size']) }}
                                @endif
                            </td>
                            <td class="text-muted">{{ $audience['status'] ?? '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
@endif

{{-- Lookalike --}}
<div class="modal fade" id="lookalikeModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content">
            <div class="modal-header">
                <h6 class="modal-title">Create lookalike</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <label class="form-label small fw-semibold">How wide?</label>
                <select class="form-select form-select-sm" id="lookalikeRatio">
                    <option value="0.01">1% — closest match, converts best</option>
                    <option value="0.03">3% — a balance</option>
                    <option value="0.05">5% — wider, cheaper reach</option>
                    <option value="0.10">10% — widest</option>
                </select>
                <div class="form-text">The seed audience needs at least 100 matched people before Meta will build this.</div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-light btn-sm" data-bs-dismiss="modal">Cancel</button>
                <button class="btn btn-primary btn-sm" id="confirmLookalike">Create</button>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
const centerId = @json($centerId);
let lookalikeTarget = null;

$('.sync-audience').on('click', function () {
    const btn = $(this).prop('disabled', true);
    const original = btn.html();

    btn.html('<span class="spinner-border spinner-border-sm me-1"></span>Uploading…');

    $.post('{{ route("marketing.audiences.sync") }}', {
        segment: btn.data('segment'),
        center_id: centerId,
    }).done(r => {
        toastr.success(r.message);
        setTimeout(() => window.location.reload(), 1500);
    }).fail(xhr => {
        toastr.error(xhr.responseJSON?.message || 'The upload could not be completed.');
        btn.prop('disabled', false).html(original);
    });
});

$('.make-lookalike').on('click', function () {
    lookalikeTarget = $(this).data('audience');
    new bootstrap.Modal('#lookalikeModal').show();
});

$('#confirmLookalike').on('click', function () {
    const btn = $(this).prop('disabled', true);

    $.post('{{ route("marketing.audiences.lookalike") }}', {
        audience_id: lookalikeTarget,
        ratio: $('#lookalikeRatio').val(),
    }).done(r => {
        toastr.success(r.message);
        bootstrap.Modal.getInstance(document.getElementById('lookalikeModal')).hide();
    }).fail(xhr => {
        toastr.error(xhr.responseJSON?.message || 'The lookalike could not be created.');
    }).always(() => btn.prop('disabled', false));
});

$('.unlink-audience').on('click', function () {
    const segment = $(this).data('segment');

    Swal.fire({
        title: 'Unlink this audience?',
        text: 'The audience stays in Meta Ads Manager. The CRM will create a new one next time you sync.',
        icon: 'question',
        showCancelButton: true,
        confirmButtonText: 'Unlink',
    }).then(result => {
        if (!result.isConfirmed) return;

        $.post('{{ route("marketing.audiences.unlink") }}', { segment })
            .done(r => { toastr.success(r.message); window.location.reload(); })
            .fail(() => toastr.error('Could not unlink this audience.'));
    });
});
</script>
@endpush
