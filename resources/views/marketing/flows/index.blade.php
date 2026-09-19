@extends('layouts.app')

@section('title', 'WhatsApp Flows')

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">Dashboard</a></li>
    <li class="breadcrumb-item">Marketing</li>
    <li class="breadcrumb-item active">WhatsApp Flows</li>
@endsection

@section('content')

@unless($configured)
    <div class="alert alert-warning d-flex align-items-start">
        <i class="bi bi-exclamation-triangle-fill"></i>
        <div class="flex-grow-1">Connect WhatsApp and add your WABA ID before building flows.</div>
        @can('integrations.view')
            <a href="{{ route('settings.integrations.index') }}" class="btn btn-sm btn-warning">Open settings</a>
        @endcan
    </div>
@endunless

<div class="alert alert-light border">
    <i class="bi bi-info-circle"></i>
    A Flow is a small form that opens inside WhatsApp — no link, no browser. Use one to qualify a lead
    or let a patient book a slot without a phone call. Flows are sent inside the 24-hour window,
    like any other interactive message.
</div>

{{-- Endpoint encryption --}}
<div class="card shadow-sm mb-3" id="keyCard">
    <div class="card-header bg-white d-flex align-items-center">
        <span class="fw-semibold me-auto"><i class="bi bi-key me-2"></i>Endpoint encryption</span>
        <span class="badge bg-{{ $hasKeyPair ? 'success' : 'secondary' }}" id="keyBadge">
            {{ $hasKeyPair ? 'Key pair ready' : 'Not set up' }}
        </span>
    </div>
    <div class="card-body">
        <p class="text-muted small mb-3">
            Only needed for flows that fetch live data mid-conversation, such as appointment slots.
            Meta encrypts every endpoint call with your public key; the private half never leaves this server.
        </p>

        <div class="mb-3">
            <label class="form-label">Endpoint URL</label>
            <div class="input-group">
                <input type="text" class="form-control font-monospace" id="endpointUrl" value="{{ $endpointUrl }}" readonly>
                <button class="btn btn-outline-secondary" id="btnCopyEndpoint" type="button"><i class="bi bi-clipboard"></i></button>
            </div>
            <div class="form-text">Must be HTTPS with a publicly trusted certificate — Meta will not call a self-signed host.</div>
        </div>

        <div id="keyDetail" class="small text-muted mb-3"></div>

        @can('whatsapp_flows.edit')
            <div class="d-flex gap-2 flex-wrap">
                <button class="btn btn-sm btn-outline-secondary" id="btnKeyStatus">
                    <i class="bi bi-search"></i>Check status
                </button>
                <button class="btn btn-sm btn-outline-primary" id="btnGenerateKeys">
                    <i class="bi bi-shuffle"></i>{{ $hasKeyPair ? 'Regenerate key pair' : 'Generate key pair' }}
                </button>
                <button class="btn btn-sm btn-primary" id="btnUploadKey" @disabled(! $hasKeyPair)>
                    <i class="bi bi-cloud-upload"></i>Upload public key to Meta
                </button>
            </div>
        @endcan
    </div>
</div>

<div class="card shadow-sm">
    <div class="card-header bg-white d-flex flex-wrap align-items-center gap-2">
        <span class="fw-semibold me-auto"><i class="bi bi-ui-checks-grid me-2"></i>Flows</span>

        <select class="form-select form-select-sm w-auto" id="fStatus">
            <option value="">All statuses</option>
            <option value="DRAFT">Draft</option>
            <option value="PUBLISHED">Published</option>
            <option value="DEPRECATED">Deprecated</option>
        </select>

        <button class="btn btn-sm btn-outline-secondary" id="btnSync"><i class="bi bi-arrow-repeat"></i>Sync</button>

        @can('whatsapp_flows.create')
            <button class="btn btn-sm btn-primary" id="btnNew"><i class="bi bi-plus-lg"></i>New flow</button>
        @endcan
    </div>

    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover align-middle w-100" id="flowTable">
                <thead class="table-light">
                    <tr>
                        <th>Name</th>
                        <th>Type</th>
                        <th>Status</th>
                        <th class="text-end">Sent</th>
                        <th class="text-end">Responses</th>
                        <th class="text-end">Completion</th>
                        <th style="width:150px">Actions</th>
                    </tr>
                </thead>
            </table>
        </div>
    </div>
</div>

{{-- New flow --}}
<div class="modal fade" id="flowModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <form id="flowForm">
                <div class="modal-header">
                    <h5 class="modal-title">New WhatsApp flow</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Start from</label>
                        <div class="row g-2" id="starterCards">
                            @foreach($starters as $key => $starter)
                                <div class="col-md-4">
                                    <label class="d-block h-100" style="cursor:pointer">
                                        <input type="radio" name="purpose" value="{{ $key }}" class="btn-check"
                                               @checked($loop->first)>
                                        <span class="card h-100 starter-card">
                                            <span class="card-body p-3 d-block">
                                                <span class="fw-semibold d-block mb-1">{{ $starter['label'] }}</span>
                                                <span class="small text-muted d-block">{{ $starter['description'] }}</span>
                                                @if(($starter['mode'] ?? '') === 'data_exchange')
                                                    <span class="badge bg-info mt-2">Needs endpoint</span>
                                                @endif
                                            </span>
                                        </span>
                                    </label>
                                </div>
                            @endforeach
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Flow name <span class="text-danger">*</span></label>
                        <input type="text" name="name" class="form-control" required placeholder="e.g. New enquiry — Delhi">
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Internal description</label>
                        <input type="text" name="description" class="form-control">
                    </div>

                    <hr>
                    <h6 class="text-uppercase text-muted small fw-bold">Where responses go</h6>

                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Lead source</label>
                            <select name="lead_source_id" class="form-select">
                                <option value="">Default</option>
                                @foreach($sources as $source)
                                    <option value="{{ $source->id }}">{{ $source->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Campaign</label>
                            <select name="campaign_id" class="form-select">
                                <option value="">None</option>
                                @foreach($campaigns as $campaign)
                                    <option value="{{ $campaign->id }}">{{ $campaign->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Centre</label>
                            <select name="center_id" class="form-select">
                                <option value="">All centres</option>
                                @foreach($centers as $center)
                                    <option value="{{ $center->id }}">{{ $center->name }}</option>
                                @endforeach
                            </select>
                            <div class="form-text">Also limits the centres offered inside a booking flow.</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Assign leads to</label>
                            <select name="assign_to" class="form-select">
                                <option value="">Auto-assign</option>
                                @foreach($agents as $agent)
                                    <option value="{{ $agent->id }}">{{ $agent->name }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>

                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" name="auto_create_lead" value="1" id="autoLead" checked>
                        <label class="form-check-label" for="autoLead">
                            Create a lead from each submission
                            <span class="text-muted small d-block">Deduplicated on mobile, so a repeat submission enriches the existing lead.</span>
                        </label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Create draft</button>
                </div>
            </form>
        </div>
    </div>
</div>

@include('marketing.flows._send_modal')
@endsection

@push('styles')
<style>
    .starter-card { border: 1px solid var(--fui-border-subtle); transition: border-color .12s ease, background .12s ease; }
    .btn-check:checked + .starter-card { border-color: var(--fui-gray-950); background: var(--fui-surface-subtle); }
</style>
@endpush

@push('scripts')
<script>
$(function () {
    const table = $('#flowTable').DataTable({
        processing: true,
        serverSide: true,
        order: [[0, 'desc']],
        ajax: {
            url: @json(route('marketing.flows.index')),
            data: d => { d.status = $('#fStatus').val(); },
        },
        columns: [
            { data: 'name' },
            { data: 'purpose_label' },
            { data: 'status_badge', orderable: false },
            { data: 'sent_count', className: 'text-end' },
            { data: 'responses_count', className: 'text-end' },
            { data: 'completion', className: 'text-end', orderable: false },
            { data: 'action', orderable: false, searchable: false },
        ],
        language: { emptyTable: FUI.emptyState('bi-ui-checks-grid', 'No flows yet', 'Create one to collect answers inside WhatsApp.') },
    });

    $('#fStatus').on('change', () => table.ajax.reload());

    // --- Create ---------------------------------------------------------
    $('#btnNew').on('click', function () {
        $('#flowForm')[0].reset();
        $('#starterCards input').first().prop('checked', true);
        new bootstrap.Modal('#flowModal').show();
    });

    $('#flowForm').on('submit', function (e) {
        e.preventDefault();
        const $btn = $(this).find('[type=submit]').prop('disabled', true);

        $.post(@json(route('marketing.flows.store')), $(this).serialize())
            .done(res => {
                if (!res.success) return toastr.error(res.message);
                toastr.success(res.message);
                location.href = res.redirect;
            })
            .fail(xhr => toastr.error(xhr.responseJSON?.message || 'Could not create the flow.'))
            .always(() => $btn.prop('disabled', false));
    });

    $('#btnSync').on('click', function () {
        const $btn = $(this).prop('disabled', true);
        $.post(@json(route('marketing.flows.sync')))
            .done(res => { toastr.success(res.message); table.ajax.reload(); })
            .fail(xhr => toastr.error(xhr.responseJSON?.message || 'Sync failed.'))
            .always(() => $btn.prop('disabled', false));
    });

    // --- Row actions ----------------------------------------------------
    $(document).on('click', '.js-preview', function () {
        const $b = $(this).prop('disabled', true);
        $.get(`{{ url('marketing/flows') }}/${$b.data('id')}/preview`)
            .done(res => res.success ? window.open(res.url, '_blank') : toastr.error(res.message))
            .fail(xhr => toastr.error(xhr.responseJSON?.message || 'Preview unavailable.'))
            .always(() => $b.prop('disabled', false));
    });

    $(document).on('click', '.js-delete', function () {
        const id = $(this).data('id');
        Swal.fire({
            title: 'Delete this flow?',
            text: 'Draft flows are removed from Meta as well.',
            icon: 'warning', showCancelButton: true, confirmButtonColor: '#dc3545',
        }).then(r => {
            if (!r.isConfirmed) return;
            $.post(`{{ url('marketing/flows') }}/${id}`, { _method: 'DELETE' })
                .done(res => { toastr.success(res.message); table.ajax.reload(); })
                .fail(xhr => toastr.error(xhr.responseJSON?.message || 'Delete failed.'));
        });
    });

    // --- Endpoint keys --------------------------------------------------
    $('#btnCopyEndpoint').on('click', () => {
        navigator.clipboard.writeText($('#endpointUrl').val());
        toastr.success('Endpoint URL copied.');
    });

    function renderKeyStatus(res) {
        $('#keyBadge')
            .attr('class', 'badge bg-' + (res.has_key_pair ? 'success' : 'secondary'))
            .text(res.has_key_pair ? 'Key pair ready' : 'Not set up');

        $('#btnUploadKey').prop('disabled', !res.has_key_pair);

        $('#keyDetail').html(res.has_key_pair
            ? `Local fingerprint <code>${$('<div>').text(res.fingerprint || '—').html()}</code>`
              + (res.meta_status ? ` · Meta reports <strong>${$('<div>').text(res.meta_status).html()}</strong>` : '')
            : 'No key pair on this server yet.');
    }

    $('#btnKeyStatus').on('click', function () {
        const $b = $(this).prop('disabled', true);
        $.get(@json(route('marketing.flows.keys')))
            .done(renderKeyStatus)
            .always(() => $b.prop('disabled', false));
    });

    $('#btnGenerateKeys').on('click', function () {
        Swal.fire({
            title: 'Generate a new key pair?',
            text: 'Any endpoint flow already running will fail until you upload the new public key to Meta.',
            icon: 'warning', showCancelButton: true, confirmButtonText: 'Generate',
        }).then(r => {
            if (!r.isConfirmed) return;
            $.post(@json(route('marketing.flows.keys.generate')))
                .done(res => { toastr.success(res.message); $('#btnKeyStatus').click(); })
                .fail(xhr => toastr.error(xhr.responseJSON?.message || 'Could not generate keys.'));
        });
    });

    $('#btnUploadKey').on('click', function () {
        const $b = $(this).prop('disabled', true);
        $.post(@json(route('marketing.flows.keys.upload')))
            .done(res => res.success ? toastr.success(res.message) : toastr.error(res.message))
            .fail(xhr => toastr.error(xhr.responseJSON?.message || 'Upload failed.'))
            .always(() => $b.prop('disabled', false));
    });

    $('#btnKeyStatus').click();
});
</script>
@endpush
