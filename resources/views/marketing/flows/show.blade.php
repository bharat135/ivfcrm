@extends('layouts.app')

@section('title', $flow->name)

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">Dashboard</a></li>
    <li class="breadcrumb-item"><a href="{{ route('marketing.flows.index') }}">Flows</a></li>
    <li class="breadcrumb-item active">{{ Str::limit($flow->name, 40) }}</li>
@endsection

@section('content')

<div class="page-head">
    <div>
        <h1 class="page-title">
            {{ $flow->name }}
            <span class="badge bg-{{ $flow->statusBadge() }}">{{ $flow->status }}</span>
            @if($flow->isEndpointFlow())
                <span class="badge bg-light text-dark border">Endpoint flow</span>
            @endif
        </h1>
        <p class="page-subtitle">
            {{ $flow->description ?: config("whatsapp_flows.starters.{$flow->purpose}.description", 'Custom flow') }}
        </p>
    </div>

    <div class="d-flex gap-2 flex-wrap">
        @if($flow->external_id)
            <button class="btn btn-sm btn-outline-secondary js-preview" data-id="{{ $flow->id }}">
                <i class="bi bi-phone"></i>Preview
            </button>
        @endif

        @can('whatsapp_flows.edit')
            @unless($flow->isPublished())
                <button class="btn btn-sm btn-outline-primary" id="btnPush">
                    <i class="bi bi-cloud-upload"></i>Upload to Meta
                </button>
                <button class="btn btn-sm btn-primary" id="btnPublish" @disabled(! $flow->external_id)>
                    <i class="bi bi-broadcast"></i>Publish
                </button>
            @else
                <button class="btn btn-sm btn-outline-warning" id="btnDeprecate">
                    <i class="bi bi-archive"></i>Deprecate
                </button>
            @endunless
        @endcan

        @can('whatsapp_flows.create')
            @if($flow->canSend())
                <button class="btn btn-sm btn-success js-send" data-id="{{ $flow->id }}" data-name="{{ e($flow->name) }}">
                    <i class="bi bi-send"></i>Send
                </button>
            @endif
        @endcan
    </div>
</div>

@if($reason = $flow->blockReason())
    <div class="alert alert-warning"><i class="bi bi-info-circle"></i>{{ $reason }}</div>
@endif

@if($flow->sync_error)
    <div class="alert alert-danger"><i class="bi bi-exclamation-octagon"></i>{{ $flow->sync_error }}</div>
@endif

@if($flow->validation_errors)
    <div class="alert alert-danger">
        <i class="bi bi-exclamation-triangle-fill"></i>
        <div>
            <strong>Meta rejected this Flow JSON:</strong>
            <ul class="mb-0 mt-1">
                @foreach($flow->validation_errors as $error)
                    <li>{{ $error['message'] ?? json_encode($error) }}
                        @if(! empty($error['pointers']))
                            <span class="text-muted">({{ collect($error['pointers'])->pluck('line_start')->join(', ') }})</span>
                        @endif
                    </li>
                @endforeach
            </ul>
        </div>
    </div>
@endif

{{-- Stats --}}
<div class="row g-3 mb-3">
    @foreach([
        ['Sent',        number_format($stats['sent']),      'bi-send',          'primary'],
        ['Responses',   number_format($stats['responses']), 'bi-inbox',         'success'],
        ['Completion',  $stats['rate'] . '%',               'bi-graph-up',      'info'],
        ['Awaiting',    number_format($stats['pending']),   'bi-hourglass-split','warning'],
    ] as [$label, $value, $icon, $tone])
        <div class="col-6 col-lg-3">
            <div class="card stat-card shadow-sm h-100">
                <div class="card-body p-3">
                    <div class="icon-box bg-{{ $tone }}-subtle text-{{ $tone }} mb-2"><i class="bi {{ $icon }}"></i></div>
                    <div class="h4 mb-0">{{ $value }}</div>
                    <small class="text-muted">{{ $label }}</small>
                </div>
            </div>
        </div>
    @endforeach
</div>

<div class="row g-3">
    {{-- Responses --}}
    <div class="col-lg-7">
        <div class="card shadow-sm">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-inbox me-2"></i>Responses</div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover align-middle w-100" id="responseTable">
                        <thead class="table-light">
                            <tr>
                                <th>Contact</th>
                                <th>Answers</th>
                                <th>Status</th>
                                <th>Received</th>
                                <th style="width:60px"></th>
                            </tr>
                        </thead>
                    </table>
                </div>
            </div>
        </div>
    </div>

    {{-- Settings --}}
    <div class="col-lg-5">
        <form id="flowSettings">
            @csrf
            @method('PUT')

            <div class="card shadow-sm mb-3">
                <div class="card-header bg-white fw-semibold">Settings</div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label">Name</label>
                        <input type="text" name="name" class="form-control" value="{{ $flow->name }}" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Description</label>
                        <input type="text" name="description" class="form-control" value="{{ $flow->description }}">
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Centre</label>
                        <select name="center_id" class="form-select">
                            <option value="">All centres</option>
                            @foreach(\App\Models\Center::where('is_active', true)->orderBy('name')->get() as $center)
                                <option value="{{ $center->id }}" @selected($flow->center_id == $center->id)>{{ $center->name }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Assign leads to</label>
                        <select name="assign_to" class="form-select">
                            <option value="">Auto-assign</option>
                            @foreach(\App\Models\User::where('is_active', true)->orderBy('name')->get(['id','name']) as $agent)
                                <option value="{{ $agent->id }}" @selected($flow->assign_to == $agent->id)>{{ $agent->name }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" name="auto_create_lead" value="1"
                               id="autoLead" @checked($flow->auto_create_lead)>
                        <label class="form-check-label" for="autoLead">Create a lead from each submission</label>
                    </div>
                </div>
            </div>

            {{-- Field mapping --}}
            <div class="card shadow-sm mb-3">
                <div class="card-header bg-white fw-semibold">Field mapping</div>
                <div class="card-body">
                    <p class="small text-muted">
                        Which CRM field each answer lands in. Unmapped answers are still stored on the
                        response and on the lead's extra data, so nothing is lost.
                    </p>

                    @php $map = $flow->effectiveFieldMap(); @endphp

                    @forelse($flow->fieldNames() as $field)
                        <div class="row g-2 align-items-center mb-2">
                            <div class="col-5">
                                <span class="badge bg-light text-dark border w-100 text-truncate" title="{{ $field }}">
                                    {{ $field }}
                                </span>
                            </div>
                            <div class="col-1 text-center text-muted"><i class="bi bi-arrow-right"></i></div>
                            <div class="col-6">
                                <select class="form-select form-select-sm" name="field_map[{{ $field }}]">
                                    <option value="">Store only</option>
                                    @foreach($crmFields as $key => $label)
                                        <option value="{{ $key }}" @selected(($map[$field] ?? null) === $key)>{{ $label }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>
                    @empty
                        <p class="text-muted small mb-0">No fields detected — check the Flow JSON below.</p>
                    @endforelse
                </div>
            </div>

            {{-- JSON --}}
            <div class="card shadow-sm">
                <div class="card-header bg-white d-flex align-items-center">
                    <span class="fw-semibold me-auto">Flow JSON</span>
                    <span class="badge bg-light text-dark border">v{{ $flow->json_version }}</span>
                </div>
                <div class="card-body">
                    @if($flow->isPublished())
                        <div class="alert alert-light border small">
                            <i class="bi bi-lock"></i>
                            Published flows are frozen on Meta. To change the screens, deprecate this
                            flow and publish a new version.
                        </div>
                    @endif

                    <textarea name="flow_json" class="form-control font-monospace" rows="16"
                              spellcheck="false" @disabled($flow->isPublished())
                              style="font-size:12px">{{ $flow->flow_json }}</textarea>
                    <div class="form-text">
                        Screens: {{ implode(' → ', $flow->screenIds()) ?: 'none' }}
                    </div>
                </div>

                @can('whatsapp_flows.edit')
                    <div class="card-footer bg-white text-end">
                        <button type="submit" class="btn btn-primary"><i class="bi bi-save"></i>Save</button>
                    </div>
                @endcan
            </div>
        </form>
    </div>
</div>

{{-- Response detail --}}
<div class="modal fade" id="responseModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Submission</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="responseBody"></div>
            <div class="modal-footer">
                @can('whatsapp_flows.edit')
                    <button class="btn btn-outline-secondary" id="btnReprocess">
                        <i class="bi bi-arrow-repeat"></i>Reprocess
                    </button>
                @endcan
                <button class="btn btn-light" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

@include('marketing.flows._send_modal')
@endsection

@push('scripts')
<script>
$(function () {
    const flowId = @json($flow->id);
    let currentResponseId = null;

    const table = $('#responseTable').DataTable({
        processing: true,
        serverSide: true,
        order: [[3, 'desc']],
        ajax: @json(route('marketing.flows.show', $flow)),
        columns: [
            { data: 'contact', orderable: false },
            { data: 'summary_text', orderable: false },
            { data: 'status_badge', name: 'status', orderable: false },
            { data: 'created_at', render: d => FUI.dateTime(d) },
            { data: 'action', orderable: false, searchable: false },
        ],
        language: {
            emptyTable: FUI.emptyState('bi-inbox', 'No responses yet',
                'Answers appear here the moment someone completes the flow.'),
        },
    });

    // --- Save -----------------------------------------------------------
    $('#flowSettings').on('submit', function (e) {
        e.preventDefault();
        const $btn = $(this).find('[type=submit]').prop('disabled', true);

        $.post(`{{ url('marketing/flows') }}/${flowId}`, $(this).serialize())
            .done(res => res.success ? toastr.success(res.message) : toastr.error(res.message))
            .fail(xhr => toastr.error(xhr.responseJSON?.message || 'Could not save.'))
            .always(() => $btn.prop('disabled', false));
    });

    // --- Lifecycle ------------------------------------------------------
    $('#btnPush').on('click', function () {
        const $b = $(this).prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span>Uploading…');

        $.post(`{{ url('marketing/flows') }}/${flowId}/push`)
            .done(res => { toastr.success(res.message); setTimeout(() => location.reload(), 700); })
            .fail(xhr => {
                toastr.error(xhr.responseJSON?.message || 'Upload failed.');
                if (xhr.responseJSON?.errors) setTimeout(() => location.reload(), 1200);
            })
            .always(() => $b.prop('disabled', false).html('<i class="bi bi-cloud-upload"></i>Upload to Meta'));
    });

    $('#btnPublish').on('click', function () {
        Swal.fire({
            title: 'Publish this flow?',
            text: 'Publishing is one-way — the screens are frozen on Meta afterwards.',
            icon: 'question', showCancelButton: true, confirmButtonText: 'Publish',
        }).then(r => {
            if (!r.isConfirmed) return;
            $.post(`{{ url('marketing/flows') }}/${flowId}/publish`)
                .done(res => { toastr.success(res.message); setTimeout(() => location.reload(), 800); })
                .fail(xhr => toastr.error(xhr.responseJSON?.message || 'Publish failed.'));
        });
    });

    $('#btnDeprecate').on('click', function () {
        Swal.fire({
            title: 'Deprecate this flow?',
            text: 'It can no longer be sent. Sessions already open will still finish.',
            icon: 'warning', showCancelButton: true, confirmButtonColor: '#dc3545',
        }).then(r => {
            if (!r.isConfirmed) return;
            $.post(`{{ url('marketing/flows') }}/${flowId}/deprecate`)
                .done(res => { toastr.success(res.message); setTimeout(() => location.reload(), 800); })
                .fail(xhr => toastr.error(xhr.responseJSON?.message || 'Failed.'));
        });
    });

    $('.js-preview').on('click', function () {
        const $b = $(this).prop('disabled', true);
        $.get(`{{ url('marketing/flows') }}/${flowId}/preview`)
            .done(res => res.success ? window.open(res.url, '_blank') : toastr.error(res.message))
            .fail(xhr => toastr.error(xhr.responseJSON?.message || 'Preview unavailable.'))
            .always(() => $b.prop('disabled', false));
    });

    // --- Response detail -------------------------------------------------
    $(document).on('click', '.js-view-response', function () {
        currentResponseId = $(this).data('id');

        $.get(`{{ url('marketing/flows/responses') }}/${currentResponseId}`).done(res => {
            const rows = Object.entries(res.answers || {})
                .filter(([k]) => !['flow_token', 'version'].includes(k))
                .map(([k, v]) => `
                    <tr>
                        <td class="text-muted" style="width:40%">${$('<div>').text(k.replace(/_/g, ' ')).html()}</td>
                        <td>${$('<div>').text(Array.isArray(v) ? v.join(', ') : (v ?? '—')).html()}</td>
                    </tr>`).join('');

            $('#responseBody').html(`
                <table class="table table-sm mb-3">${rows || '<tr><td class="text-muted">No answers recorded.</td></tr>'}</table>
                <div class="small text-muted">Received ${$('<div>').text(res.received_at).html()}</div>
                ${res.error ? `<div class="alert alert-danger small mt-2 mb-0">${$('<div>').text(res.error).html()}</div>` : ''}
                ${res.lead_url ? `<a href="${res.lead_url}" class="btn btn-sm btn-outline-primary mt-2"><i class="bi bi-person"></i>Open lead</a>` : ''}
            `);

            new bootstrap.Modal('#responseModal').show();
        });
    });

    $('#btnReprocess').on('click', function () {
        if (!currentResponseId) return;
        const $b = $(this).prop('disabled', true);

        $.post(`{{ url('marketing/flows/responses') }}/${currentResponseId}/reprocess`)
            .done(res => { toastr.success(res.message); table.ajax.reload(null, false); })
            .fail(xhr => toastr.error(xhr.responseJSON?.message || 'Reprocess failed.'))
            .always(() => $b.prop('disabled', false));
    });
});
</script>
@endpush
