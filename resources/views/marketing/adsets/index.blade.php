@extends('layouts.app')

@section('title', 'Ad Sets')

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">Dashboard</a></li>
    <li class="breadcrumb-item">Marketing</li>
    <li class="breadcrumb-item active">Ad Sets</li>
@endsection

@section('content')

@unless($configured)
    <div class="alert alert-warning d-flex align-items-center gap-2">
        <i class="bi bi-exclamation-triangle-fill"></i>
        <div class="flex-grow-1">Connect a Meta Ad Account before creating ad sets.</div>
        @can('integrations.view')
            <a href="{{ route('settings.integrations.index') }}" class="btn btn-sm btn-warning">Connect Meta</a>
        @endcan
    </div>
@endunless

<div class="card shadow-sm">
    <div class="card-header bg-white d-flex flex-wrap align-items-center gap-2">
        <span class="fw-semibold me-auto"><i class="bi bi-collection me-2"></i>Ad Sets</span>

        <select class="form-select form-select-sm w-auto" id="fCampaign">
            <option value="">All campaigns</option>
            @foreach($campaigns as $campaign)
                <option value="{{ $campaign->id }}">{{ $campaign->name }}</option>
            @endforeach
        </select>

        <select class="form-select form-select-sm w-auto" id="fCenter">
            <option value="">All centres</option>
            @foreach($centers as $center)
                <option value="{{ $center->id }}">{{ $center->name }}</option>
            @endforeach
        </select>

        <select class="form-select form-select-sm w-auto" id="fObjective">
            <option value="">All objectives</option>
            @foreach($objectives as $value => $label)
                <option value="{{ $value }}">{{ $label }}</option>
            @endforeach
        </select>

        <select class="form-select form-select-sm w-auto" id="fStatus">
            <option value="">All statuses</option>
            <option value="ACTIVE">Active</option>
            <option value="PAUSED">Paused</option>
            <option value="ARCHIVED">Archived</option>
        </select>

        <input type="text" class="form-control form-control-sm w-auto" id="fRange" placeholder="Last 30 days" title="Spend is summed for this period">

        <button class="btn btn-sm btn-link text-decoration-none" id="fReset" title="Clear filters"><i class="bi bi-x-circle"></i></button>

        @can('campaigns.create')
            <button class="btn btn-sm btn-primary" id="btnNew"><i class="bi bi-plus-lg me-1"></i>New ad set</button>
        @endcan
    </div>

    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover align-middle w-100" id="adsetTable">
                <thead class="table-light">
                    <tr>
                        <th>Name</th>
                        <th>Campaign</th>
                        <th>Status</th>
                        <th>Goal</th>
                        <th>Budget</th>
                        <th>Audience</th>
                        <th class="text-end">Spend</th>
                        <th class="text-end">Ads</th>
                        <th class="text-end">Leads</th>
                        <th style="width:150px">Actions</th>
                    </tr>
                </thead>
            </table>
        </div>
    </div>
</div>

@include('marketing.adsets._modal')
@endsection

@push('scripts')
<script>
$(function () {
    let range = { from: null, to: null };

    flatpickr('#fRange', {
        mode: 'range', altInput: true, altFormat: 'd-m-Y', dateFormat: 'Y-m-d',
        defaultDate: [@json(now()->subDays(30)->toDateString()), @json(now()->toDateString())],
        onClose(dates, _str, fp) {
            if (dates.length === 2) {
                range.from = fp.formatDate(dates[0], 'Y-m-d');
                range.to   = fp.formatDate(dates[1], 'Y-m-d');
                table.ajax.reload();
            }
        },
    });

    const table = $('#adsetTable').DataTable({
        processing: true,
        serverSide: true,
        order: [[0, 'desc']],
        ajax: {
            url: @json(route('marketing.adsets.index')),
            data: d => {
                d.campaign_id = $('#fCampaign').val();
                d.center_id   = $('#fCenter').val();
                d.objective   = $('#fObjective').val();
                d.status      = $('#fStatus').val();
                d.from        = range.from;
                d.to          = range.to;
            },
        },
        columns: [
            { data: 'name' },
            { data: 'campaign_name' },
            { data: 'status_badge', orderable: false },
            { data: 'optimization_goal', defaultContent: '—' },
            { data: 'budget_display', orderable: false },
            { data: 'targeting_summary', orderable: false, className: 'small text-muted' },
            { data: 'spend_period', className: 'text-end', orderable: false },
            { data: 'ads_count', className: 'text-end' },
            { data: 'leads_count', className: 'text-end' },
            { data: 'action', orderable: false, searchable: false },
        ],
    });

    $('#fCampaign, #fCenter, #fObjective, #fStatus').on('change', () => table.ajax.reload());
    $('#fReset').on('click', () => {
        $('#fCampaign, #fCenter, #fObjective, #fStatus').val('');
        range = { from: null, to: null };
        document.getElementById('fRange')._flatpickr?.setDate([@json(now()->subDays(30)->toDateString()), @json(now()->toDateString())], false);
        table.ajax.reload();
    });

    $('#btnNew').on('click', function () {
        $('#adsetForm')[0].reset();
        $('#adsetId').val('');
        $('#citySelect, #interestSelect').val(null).trigger('change');
        $('#adsetModalTitle').text('New ad set');
        new bootstrap.Modal('#adsetModal').show();
    });

    $(document).on('click', '.js-edit', function () {
        const id = $(this).data('id');

        $.get(`{{ url('marketing/adsets') }}/${id}/edit`).done(res => {
            const a = res.adset, t = res.targeting;
            const $form = $('#adsetForm');

            $form[0].reset();
            $('#adsetId').val(a.id);
            $form.find('[name=name]').val(a.name);
            $form.find('[name=campaign_id]').val(a.campaign_id);
            $form.find('[name=optimization_goal]').val(a.optimization_goal);
            $form.find('[name=billing_event]').val(a.billing_event);
            $form.find('[name=destination_type]').val(a.destination_type);
            $form.find('[name=daily_budget]').val(a.daily_budget);
            $form.find('[name=lifetime_budget]').val(a.lifetime_budget);
            $form.find('[name=bid_amount]').val(a.bid_amount);
            $form.find('[name=age_min]').val(t.age_min);
            $form.find('[name=age_max]').val(t.age_max);
            $form.find('[name=genders]').val(t.genders);

            // Re-seed the Select2 controls with their saved options.
            $('#citySelect').empty();
            (t.cities || []).forEach(c => $('#citySelect').append(new Option(c.name || c.key, c.key, true, true)));
            $('#citySelect').trigger('change');

            $('#interestSelect').empty();
            (t.interests || []).forEach(i => $('#interestSelect').append(new Option(i.name || i.id, i.id, true, true)));
            $('#interestSelect').trigger('change');

            $('[name="platforms[]"]').each(function () {
                $(this).prop('checked', (t.platforms || []).includes(this.value));
            });

            $('#adsetModalTitle').text('Edit ad set');
            new bootstrap.Modal('#adsetModal').show();
        });
    });

    $(document).on('click', '.js-status', function () {
        const id = $(this).data('id'), status = $(this).data('status');

        $.post(`{{ url('marketing/adsets') }}/${id}/status`, { _method: 'PATCH', status })
            .done(res => { toastr.success(res.message); table.ajax.reload(null, false); })
            .fail(xhr => toastr.error(xhr.responseJSON?.message || 'Status change failed.'));
    });

    $(document).on('click', '.js-delete', function () {
        const id = $(this).data('id');

        Swal.fire({
            title: 'Delete this ad set?',
            text: 'Its ads will be deleted on Meta too.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#dc3545',
        }).then(result => {
            if (!result.isConfirmed) return;

            $.post(`{{ url('marketing/adsets') }}/${id}`, { _method: 'DELETE' })
                .done(res => { toastr.success(res.message); table.ajax.reload(); })
                .fail(xhr => toastr.error(xhr.responseJSON?.message || 'Delete failed.'));
        });
    });
});
</script>
@endpush
