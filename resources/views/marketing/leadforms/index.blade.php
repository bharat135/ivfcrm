@extends('layouts.app')

@section('title', 'Lead Forms')

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">Dashboard</a></li>
    <li class="breadcrumb-item">Marketing</li>
    <li class="breadcrumb-item active">Lead Forms</li>
@endsection

@section('content')

@unless($configured)
    <div class="alert alert-warning d-flex align-items-center gap-2">
        <i class="bi bi-exclamation-triangle-fill"></i>
        <div class="flex-grow-1">Connect a Facebook Page under Integrations to manage lead forms.</div>
        @can('integrations.view')
            <a href="{{ route('settings.integrations.index') }}" class="btn btn-sm btn-warning">Connect</a>
        @endcan
    </div>
@endunless

<div class="alert alert-info small d-flex gap-2">
    <i class="bi bi-lightning-charge-fill"></i>
    <div>
        New submissions arrive in real time through the <code>leadgen</code> webhook once the Page is
        subscribed to the app, and every auto-sync form is also polled every 15 minutes as a backstop. Use
        <strong>Pull leads</strong> to backfill older history. Leads are attributed and routed by the
        <a href="{{ route('marketing.campaigns.index') }}">campaign</a> they came from (centre, agent, Meta
        source); the form only maps question keys onto CRM fields.
    </div>
    @can('integrations.edit')
        <button type="button" class="btn btn-sm btn-outline-success ms-auto text-nowrap" id="btnSubscribePage">
            <i class="bi bi-broadcast me-1"></i>Subscribe Page to Lead Ads
        </button>
    @endcan
</div>

<div class="card shadow-sm">
    <div class="card-header bg-white d-flex flex-wrap align-items-center gap-2">
        <span class="fw-semibold me-auto"><i class="bi bi-ui-checks me-2"></i>Instant Forms</span>
        <button class="btn btn-sm btn-outline-secondary" id="btnSync"><i class="bi bi-arrow-repeat me-1"></i>Sync from Meta</button>
        @can('campaigns.create')
            <button class="btn btn-sm btn-primary" id="btnNew"><i class="bi bi-plus-lg me-1"></i>New form</button>
        @endcan
    </div>

    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover align-middle w-100" id="formTable">
                <thead class="table-light">
                    <tr>
                        <th>Form</th>
                        <th>Status</th>
                        <th>Routing</th>
                        <th class="text-end">Meta leads</th>
                        <th class="text-end">In CRM</th>
                        <th class="text-end">Ads</th>
                        <th>Last synced</th>
                        <th style="width:130px">Actions</th>
                    </tr>
                </thead>
            </table>
        </div>
    </div>
</div>

{{-- Routing + field mapping --}}
<div class="modal fade" id="routingModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <form id="routingForm">
                <div class="modal-header">
                    <h5 class="modal-title">Form routing &amp; field mapping</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" id="formId">

                    <h6 class="text-uppercase text-muted small fw-bold">Routing</h6>
                    <p class="small text-muted">Applied to every lead captured through this form.</p>

                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <label class="form-label">Lead source</label>
                            <select name="lead_source_id" class="form-select">
                                <option value="">Default (Facebook)</option>
                                @foreach($sources as $source)
                                    <option value="{{ $source->id }}">{{ $source->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Campaign</label>
                            <select name="campaign_id" class="form-select">
                                <option value="">Auto-detect from the ad</option>
                                @foreach($campaigns as $campaign)
                                    <option value="{{ $campaign->id }}">{{ $campaign->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Centre</label>
                            <select name="center_id" class="form-select">
                                <option value="">Use assignment rules</option>
                                @foreach($centers as $center)
                                    <option value="{{ $center->id }}">{{ $center->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Assign to</label>
                            <select name="assign_to" class="form-select">
                                <option value="">Auto-assign (round robin)</option>
                                @foreach($agents as $agent)
                                    <option value="{{ $agent->id }}">{{ $agent->name }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>

                    <h6 class="text-uppercase text-muted small fw-bold">Field mapping</h6>
                    <p class="small text-muted">Map each question on the Meta form to a CRM lead field.</p>
                    <div id="fieldMapWrap" class="mb-0">
                        <div class="text-muted small">Loading questions…</div>
                    </div>

                    <hr>
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" name="auto_sync" value="1" id="autoSync" checked>
                        <label class="form-check-label" for="autoSync">Automatically import leads from this form</label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Save</button>
                </div>
            </form>
        </div>
    </div>
</div>

{{-- Create form on Meta --}}
<div class="modal fade" id="createFormModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <form id="createForm">
                <div class="modal-header">
                    <h5 class="modal-title">Create an Instant Form on your Page</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Form name <span class="text-danger">*</span></label>
                        <input type="text" name="name" class="form-control" required placeholder="IVF Consultation — Delhi">
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Questions to ask</label>
                        @foreach([
                            'FULL_NAME' => 'Full name', 'PHONE' => 'Phone number', 'EMAIL' => 'Email',
                            'CITY' => 'City', 'STATE' => 'State', 'GENDER' => 'Gender',
                            'DATE_OF_BIRTH' => 'Date of birth', 'POST_CODE' => 'Pincode',
                        ] as $value => $label)
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="questions[]" value="{{ $value }}"
                                       id="q{{ $value }}" @checked(in_array($value, ['FULL_NAME', 'PHONE', 'EMAIL']))>
                                <label class="form-check-label" for="q{{ $value }}">{{ $label }}</label>
                            </div>
                        @endforeach
                        <div class="form-text">Fewer questions means more leads, but lower intent. Name + phone is the usual floor.</div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Privacy policy URL <span class="text-danger">*</span></label>
                        <input type="url" name="privacy_policy_url" class="form-control" required placeholder="https://your-clinic.com/privacy">
                        <div class="form-text">Meta requires this for every lead form.</div>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Intro headline</label>
                            <input type="text" name="intro_headline" class="form-control" placeholder="Start your parenthood journey">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Intro description</label>
                            <input type="text" name="intro_description" class="form-control" placeholder="Free first consultation with a fertility specialist">
                        </div>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Thank-you title</label>
                            <input type="text" name="thank_you_title" class="form-control" placeholder="Thank you!">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Thank-you message</label>
                            <input type="text" name="thank_you_body" class="form-control" placeholder="Our counsellor will call you within 24 hours.">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Website shown after submitting</label>
                        <input type="url" name="website_url" class="form-control" placeholder="https://your-clinic.com">
                    </div>

                    <hr>
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label">Lead source</label>
                            <select name="lead_source_id" class="form-select">
                                <option value="">Default</option>
                                @foreach($sources as $source)
                                    <option value="{{ $source->id }}">{{ $source->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Centre</label>
                            <select name="center_id" class="form-select">
                                <option value="">Auto</option>
                                @foreach($centers as $center)
                                    <option value="{{ $center->id }}">{{ $center->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Assign to</label>
                            <select name="assign_to" class="form-select">
                                <option value="">Auto-assign</option>
                                @foreach($agents as $agent)
                                    <option value="{{ $agent->id }}">{{ $agent->name }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">Create on Meta</button>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
$(function () {
    const crmFields = @json($crmFields);

    const table = $('#formTable').DataTable({
        processing: true,
        serverSide: true,
        order: [[0, 'asc']],
        ajax: @json(route('marketing.leadforms.index')),
        columns: [
            { data: 'name' },
            { data: 'status_badge', orderable: false },
            { data: 'routing', orderable: false },
            { data: 'leads_count', className: 'text-end' },
            { data: 'crm_leads', className: 'text-end', orderable: false, searchable: false },
            { data: 'ads_count', className: 'text-end' },
            { data: 'synced', orderable: false },
            { data: 'action', orderable: false, searchable: false },
        ],
    });

    $('#btnSubscribePage').on('click', function () {
        const $btn = $(this).prop('disabled', true);

        $.post(@json(route('settings.integrations.subscribe_page')))
            .done(res => res.success ? toastr.success(res.message) : toastr.error(res.message))
            .fail(xhr => toastr.error(xhr.responseJSON?.message || 'Page subscription failed.'))
            .always(() => $btn.prop('disabled', false));
    });

    $('#btnSync').on('click', function () {
        const $btn = $(this).prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span>Syncing…');

        $.post(@json(route('marketing.leadforms.sync')))
            .done(res => { toastr.success(res.message); table.ajax.reload(); })
            .fail(xhr => toastr.error(xhr.responseJSON?.message || 'Sync failed.'))
            .always(() => $btn.prop('disabled', false).html('<i class="bi bi-arrow-repeat me-1"></i>Sync from Meta'));
    });

    // --- Routing modal ---------------------------------------------------
    $(document).on('click', '.js-edit', function () {
        const id = $(this).data('id');

        $.get(`{{ url('marketing/lead-forms') }}/${id}/edit`).done(res => {
            const f = res.form;
            const $form = $('#routingForm');

            $form[0].reset();
            $('#formId').val(f.id);
            $form.find('[name=lead_source_id]').val(f.lead_source_id || '');
            $form.find('[name=campaign_id]').val(f.campaign_id || '');
            $form.find('[name=center_id]').val(f.center_id || '');
            $form.find('[name=assign_to]').val(f.assign_to || '');
            $form.find('[name=auto_sync]').prop('checked', f.auto_sync);

            const options = Object.entries(crmFields)
                .map(([k, v]) => `<option value="${k}">${v}</option>`).join('');

            const rows = (res.questions || []).map(q => {
                const key = q.key || q.type;
                const selected = res.field_map[key] || '';

                return `
                    <div class="row g-2 align-items-center mb-2">
                        <div class="col-5">
                            <span class="badge bg-light text-dark border w-100 text-truncate" title="${key}">
                                ${$('<div>').text(q.label || key).html()}
                            </span>
                        </div>
                        <div class="col-1 text-center text-muted"><i class="bi bi-arrow-right"></i></div>
                        <div class="col-6">
                            <select class="form-select form-select-sm" name="field_map[${key}]">
                                <option value="">Ignore</option>${options}
                            </select>
                        </div>
                    </div>`;
            }).join('');

            $('#fieldMapWrap').html(rows || '<div class="text-muted small">This form has no questions synced yet.</div>');

            // Apply the saved selections after the selects exist.
            (res.questions || []).forEach(q => {
                const key = q.key || q.type;
                if (res.field_map[key]) $(`[name="field_map[${key}]"]`).val(res.field_map[key]);
            });

            new bootstrap.Modal('#routingModal').show();
        });
    });

    $('#routingForm').on('submit', function (e) {
        e.preventDefault();

        const id = $('#formId').val();

        $.post(`{{ url('marketing/lead-forms') }}/${id}`, $(this).serialize() + '&_method=PUT')
            .done(res => {
                toastr.success(res.message);
                bootstrap.Modal.getInstance(document.getElementById('routingModal')).hide();
                table.ajax.reload(null, false);
            })
            .fail(xhr => toastr.error(xhr.responseJSON?.message || 'Save failed.'));
    });

    // --- Pull leads ------------------------------------------------------
    $(document).on('click', '.js-pull', function () {
        const id = $(this).data('id');
        const $btn = $(this).prop('disabled', true);

        $.post(`{{ url('marketing/lead-forms') }}/${id}/pull-leads`)
            .done(res => { toastr.success(res.message); table.ajax.reload(null, false); })
            .fail(xhr => toastr.error(xhr.responseJSON?.message || 'Could not pull leads.'))
            .always(() => $btn.prop('disabled', false));
    });

    // --- Create form -----------------------------------------------------
    $('#btnNew').on('click', () => new bootstrap.Modal('#createFormModal').show());

    $('#createForm').on('submit', function (e) {
        e.preventDefault();

        const $btn = $(this).find('[type=submit]').prop('disabled', true);

        $.post(@json(route('marketing.leadforms.store')), $(this).serialize())
            .done(res => {
                if (!res.success) return toastr.error(res.message);
                toastr.success(res.message);
                bootstrap.Modal.getInstance(document.getElementById('createFormModal')).hide();
                table.ajax.reload();
            })
            .fail(xhr => toastr.error(xhr.responseJSON?.message || 'Could not create the form.'))
            .always(() => $btn.prop('disabled', false));
    });

    $(document).on('click', '.js-delete', function () {
        const id = $(this).data('id');

        Swal.fire({
            title: 'Remove this form from the CRM?',
            text: 'The form stays live on your Facebook Page; only the CRM mirror and its routing are removed.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#dc3545',
        }).then(result => {
            if (!result.isConfirmed) return;

            $.post(`{{ url('marketing/lead-forms') }}/${id}`, { _method: 'DELETE' })
                .done(res => { toastr.success(res.message); table.ajax.reload(); })
                .fail(() => toastr.error('Delete failed.'));
        });
    });
});
</script>
@endpush
