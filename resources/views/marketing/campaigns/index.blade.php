@extends('layouts.app')

@section('title', 'Meta Campaigns')

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">Dashboard</a></li>
    <li class="breadcrumb-item">Marketing</li>
    <li class="breadcrumb-item active">Campaigns</li>
@endsection

@section('content')

@unless($configured)
    <div class="alert alert-warning d-flex align-items-center gap-2">
        <i class="bi bi-exclamation-triangle-fill"></i>
        <div class="flex-grow-1">Connect a Meta Ad Account to create and manage campaigns from here.</div>
        @can('integrations.view')
            <a href="{{ route('settings.integrations.index') }}" class="btn btn-sm btn-warning">Connect Meta</a>
        @endcan
    </div>
@endunless

<div class="card shadow-sm">
    <div class="card-header bg-white d-flex flex-wrap align-items-center gap-2">
        <span class="fw-semibold me-auto"><i class="bi bi-megaphone me-2"></i>Campaigns</span>

        <select class="form-select form-select-sm w-auto" id="fCenter">
            <option value="">All centres</option>
            @foreach($centers as $center)
                <option value="{{ $center->id }}">{{ $center->name }}</option>
            @endforeach
        </select>

        <select class="form-select form-select-sm w-auto" id="fStatus">
            <option value="">All statuses</option>
            <option value="ACTIVE">Active</option>
            <option value="PAUSED">Paused</option>
            <option value="ARCHIVED">Archived</option>
        </select>

        <select class="form-select form-select-sm w-auto" id="fObjective">
            <option value="">All objectives</option>
            @foreach($objectives as $value => $label)
                <option value="{{ $value }}">{{ $label }}</option>
            @endforeach
        </select>

        <select class="form-select form-select-sm w-auto" id="fPlatform">
            <option value="">All platforms</option>
            <option value="meta">Meta</option>
            <option value="google">Google Ads</option>
            <option value="manual">Manual</option>
        </select>

        <select class="form-select form-select-sm w-auto" id="fSource">
            <option value="">All lead sources</option>
            @foreach($sources as $source)
                <option value="{{ $source->id }}">{{ $source->name }}</option>
            @endforeach
        </select>

        <input type="text" class="form-control form-control-sm w-auto" id="fRange" placeholder="Last 30 days" title="Spend, impressions and clicks are summed for this period">

        <button class="btn btn-sm btn-link text-decoration-none" id="fReset" title="Clear filters"><i class="bi bi-x-circle"></i></button>

        <button class="btn btn-sm btn-outline-secondary" id="btnSync"><i class="bi bi-arrow-repeat me-1"></i>Sync</button>

        @can('campaigns.create')
            <button class="btn btn-sm btn-primary" id="btnNew"><i class="bi bi-plus-lg me-1"></i>New campaign</button>
        @endcan
    </div>

    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover align-middle w-100" id="campaignTable">
                <thead class="table-light">
                    <tr>
                        <th>Name</th>
                        <th>Status</th>
                        <th>Objective</th>
                        <th>Budget</th>
                        <th class="text-end">Spend</th>
                        <th class="text-end">Impr.</th>
                        <th class="text-end">Clicks</th>
                        <th class="text-end">Leads</th>
                        <th class="text-end">CPL</th>
                        <th class="text-end">Ad sets</th>
                        <th style="width:150px">Actions</th>
                    </tr>
                </thead>
            </table>
        </div>
    </div>
</div>

{{-- Campaign modal --}}
<div class="modal fade" id="campaignModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <form id="campaignForm">
                <div class="modal-header">
                    <h5 class="modal-title" id="campaignModalTitle">New campaign</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" id="campaignId">

                    <div class="mb-3">
                        <label class="form-label">Campaign name <span class="text-danger">*</span></label>
                        <input type="text" name="name" class="form-control" required placeholder="e.g. IVF Delhi — Leads — Aug">
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Objective <span class="text-danger">*</span></label>
                            <select name="objective" class="form-select" required>
                                @foreach($objectives as $value => $label)
                                    <option value="{{ $value }}" @selected($value === 'OUTCOME_LEADS')>{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Bid strategy</label>
                            <select name="bid_strategy" class="form-select">
                                @foreach($bidStrategies as $value => $label)
                                    <option value="{{ $value }}">{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Daily budget (₹)</label>
                            <input type="number" name="daily_budget" class="form-control" min="100" step="10" placeholder="1000">
                            <div class="form-text">Set either a daily or a lifetime budget.</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Lifetime budget (₹)</label>
                            <input type="number" name="lifetime_budget" class="form-control" min="100" step="100">
                        </div>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Start date</label>
                            <input type="date" name="start_date" class="form-control">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">End date</label>
                            <input type="date" name="end_date" class="form-control">
                        </div>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Centre</label>
                            <select name="center_id" class="form-select">
                                <option value="">All centres</option>
                                @foreach($centers as $center)
                                    <option value="{{ $center->id }}">{{ $center->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Lead source</label>
                            <select name="lead_source_id" class="form-select">
                                <option value="">Auto (Meta)</option>
                                @foreach($sources as $source)
                                    <option value="{{ $source->id }}">{{ $source->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-12">
                            <label class="form-label">Assign leads to</label>
                            <select name="assign_to" class="form-select">
                                <option value="">Assignment rules (Settings → Lead Assignment)</option>
                                @foreach($agents as $agent)
                                    <option value="{{ $agent->id }}">{{ $agent->name }}</option>
                                @endforeach
                            </select>
                            <div class="form-text">Every lead captured from this campaign goes to this agent and the centre above.</div>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Special ad category</label>
                        <select name="special_ad_categories[]" class="form-select">
                            @foreach($specialCategories as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                        <div class="form-text">Healthcare advertising generally needs "None", but check local policy.</div>
                    </div>

                    <div class="form-check form-switch" id="pushWrap">
                        <input class="form-check-input" type="checkbox" name="push_to_meta" value="1" id="pushToMeta" checked>
                        <label class="form-check-label" for="pushToMeta">
                            Create on Meta now <span class="text-muted small">(otherwise it is saved locally for tracking only)</span>
                        </label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check2 me-1"></i>Save campaign
                    </button>
                </div>
            </form>
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
        defaultDate: [@json(now()->subDays(30)->toDateString()), @json(now()->toDateString())],
        onClose(dates, _str, fp) {
            if (dates.length === 2) {
                // Format in local time: toISOString() shifts IST dates back a day.
                range.from = fp.formatDate(dates[0], 'Y-m-d');
                range.to   = fp.formatDate(dates[1], 'Y-m-d');
                table.ajax.reload();
            }
        },
    });

    const table = $('#campaignTable').DataTable({
        processing: true,
        serverSide: true,
        order: [[0, 'desc']],
        ajax: {
            url: @json(route('marketing.campaigns.index')),
            data: d => {
                d.center_id      = $('#fCenter').val();
                d.status         = $('#fStatus').val();
                d.objective      = $('#fObjective').val();
                d.platform       = $('#fPlatform').val();
                d.lead_source_id = $('#fSource').val();
                d.from           = range.from;
                d.to             = range.to;
            },
        },
        columns: [
            { data: 'name' },
            { data: 'status_badge', orderable: false },
            { data: 'objective', defaultContent: '—' },
            { data: 'budget_display', orderable: false },
            { data: 'spend_period', className: 'text-end', orderable: false },
            { data: 'impressions', className: 'text-end', orderable: false },
            { data: 'clicks', className: 'text-end', orderable: false },
            { data: 'leads_count', className: 'text-end' },
            { data: 'cpl', className: 'text-end', orderable: false },
            { data: 'adsets_count', className: 'text-end' },
            { data: 'action', orderable: false, searchable: false },
        ],
    });

    $('#fCenter, #fStatus, #fObjective, #fPlatform, #fSource').on('change', () => table.ajax.reload());
    $('#fReset').on('click', () => {
        $('#fCenter, #fStatus, #fObjective, #fPlatform, #fSource').val('');
        range = { from: null, to: null };
        document.getElementById('fRange')._flatpickr?.setDate([@json(now()->subDays(30)->toDateString()), @json(now()->toDateString())], false);
        table.ajax.reload();
    });

    // --- Create / edit ---------------------------------------------------
    $('#btnNew').on('click', function () {
        $('#campaignForm')[0].reset();
        $('#campaignId').val('');
        $('#campaignModalTitle').text('New campaign');
        $('#pushWrap').removeClass('d-none');
        new bootstrap.Modal('#campaignModal').show();
    });

    $(document).on('click', '.js-edit', function () {
        const id = $(this).data('id');

        $.get(`{{ url('marketing/campaigns') }}/${id}/edit`).done(c => {
            const $form = $('#campaignForm');
            $form[0].reset();
            $('#campaignId').val(c.id);
            $form.find('[name=name]').val(c.name);
            $form.find('[name=objective]').val(c.objective);
            $form.find('[name=bid_strategy]').val(c.bid_strategy);
            $form.find('[name=daily_budget]').val(c.daily_budget);
            $form.find('[name=lifetime_budget]').val(c.lifetime_budget);
            $form.find('[name=center_id]').val(c.center_id || '');
            $form.find('[name=assign_to]').val(c.assign_to || '');
            $form.find('[name=lead_source_id]').val(c.lead_source_id || '');
            $form.find('[name=end_date]').val(c.end_date ? c.end_date.slice(0, 10) : '');
            $('#campaignModalTitle').text('Edit campaign');
            $('#pushWrap').addClass('d-none');
            new bootstrap.Modal('#campaignModal').show();
        });
    });

    $('#campaignForm').on('submit', function (e) {
        e.preventDefault();

        const id = $('#campaignId').val();
        const $btn = $(this).find('[type=submit]').prop('disabled', true);
        const data = $(this).serialize() + (id ? '&_method=PUT' : '');

        $.post(id ? `{{ url('marketing/campaigns') }}/${id}` : @json(route('marketing.campaigns.store')), data)
            .done(res => {
                if (!res.success) return toastr.error(res.message);
                toastr.success(res.message);
                bootstrap.Modal.getInstance(document.getElementById('campaignModal')).hide();
                table.ajax.reload();
                if (res.redirect) setTimeout(() => location.href = res.redirect, 900);
            })
            .fail(xhr => toastr.error(xhr.responseJSON?.message || 'Could not save the campaign.'))
            .always(() => $btn.prop('disabled', false));
    });

    // --- Status / delete -------------------------------------------------
    $(document).on('click', '.js-status', function () {
        const id = $(this).data('id'), status = $(this).data('status');

        $.post(`{{ url('marketing/campaigns') }}/${id}/status`, { _method: 'PATCH', status })
            .done(res => { toastr.success(res.message); table.ajax.reload(null, false); })
            .fail(xhr => toastr.error(xhr.responseJSON?.message || 'Status change failed.'));
    });

    $(document).on('click', '.js-delete', function () {
        const id = $(this).data('id');

        Swal.fire({
            title: 'Delete this campaign?',
            text: 'It will be deleted on Meta as well. This cannot be undone.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#dc3545',
            confirmButtonText: 'Delete',
        }).then(result => {
            if (!result.isConfirmed) return;

            $.post(`{{ url('marketing/campaigns') }}/${id}`, { _method: 'DELETE' })
                .done(res => { toastr.success(res.message); table.ajax.reload(); })
                .fail(xhr => toastr.error(xhr.responseJSON?.message || 'Delete failed.'));
        });
    });

    $('#btnSync').on('click', function () {
        const $btn = $(this).prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span>Syncing…');

        $.post(@json(route('marketing.campaigns.sync')), { inline: 1 })
            .done(res => { toastr.success(res.message); table.ajax.reload(); })
            .fail(xhr => toastr.error(xhr.responseJSON?.message || 'Sync failed.'))
            .always(() => $btn.prop('disabled', false).html('<i class="bi bi-arrow-repeat me-1"></i>Sync'));
    });
});
</script>
@endpush
