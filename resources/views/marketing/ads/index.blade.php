@extends('layouts.app')

@section('title', 'Ads')

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">Dashboard</a></li>
    <li class="breadcrumb-item">Marketing</li>
    <li class="breadcrumb-item active">Ads</li>
@endsection

@section('content')

@unless($configured)
    <div class="alert alert-warning d-flex align-items-center gap-2">
        <i class="bi bi-exclamation-triangle-fill"></i>
        <div class="flex-grow-1">Connect a Meta Ad Account before creating ads.</div>
        @can('integrations.view')
            <a href="{{ route('settings.integrations.index') }}" class="btn btn-sm btn-warning">Connect Meta</a>
        @endcan
    </div>
@endunless

<div class="card shadow-sm">
    <div class="card-header bg-white d-flex flex-wrap align-items-center gap-2">
        <span class="fw-semibold me-auto"><i class="bi bi-badge-ad me-2"></i>Ads</span>

        <select class="form-select form-select-sm w-auto" id="fAdset">
            <option value="">All ad sets</option>
            @foreach($adsets as $adset)
                <option value="{{ $adset->id }}" @selected(request('adset_id') == $adset->id)>
                    {{ $adset->campaign?->name }} → {{ $adset->name }}
                </option>
            @endforeach
        </select>

        <select class="form-select form-select-sm w-auto" id="fStatus">
            <option value="">All statuses</option>
            <option value="ACTIVE">Active</option>
            <option value="PAUSED">Paused</option>
        </select>

        @can('campaigns.create')
            <button class="btn btn-sm btn-primary" id="btnNew"><i class="bi bi-plus-lg me-1"></i>New ad</button>
        @endcan
    </div>

    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover align-middle w-100" id="adTable">
                <thead class="table-light">
                    <tr>
                        <th style="width:60px"></th>
                        <th>Name</th>
                        <th>Ad set</th>
                        <th>Campaign</th>
                        <th>Status</th>
                        <th class="text-end">Spend</th>
                        <th class="text-end">Impr.</th>
                        <th class="text-end">CTR</th>
                        <th class="text-end">Leads</th>
                        <th style="width:130px">Actions</th>
                    </tr>
                </thead>
            </table>
        </div>
    </div>
</div>

{{-- Ad builder --}}
<div class="modal fade" id="adModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <form id="adForm" enctype="multipart/form-data">
                <div class="modal-header">
                    <h5 class="modal-title">Create an ad</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body">
                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Ad set <span class="text-danger">*</span></label>
                            <select name="adset_id" class="form-select" required>
                                <option value="">Choose an ad set…</option>
                                @foreach($adsets as $adset)
                                    <option value="{{ $adset->id }}">{{ $adset->campaign?->name }} → {{ $adset->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Ad name <span class="text-danger">*</span></label>
                            <input type="text" name="name" class="form-control" required placeholder="e.g. Free consult — carousel A">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Primary text <span class="text-danger">*</span></label>
                        <textarea name="message" class="form-control" rows="3" required
                                  placeholder="Struggling to conceive? Talk to our fertility specialists — first consultation free."></textarea>
                        <div class="form-text">
                            Avoid personal-attribute claims ("Are <em>you</em> infertile?") — Meta rejects health ads that imply
                            knowledge of a person's condition.
                        </div>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Headline <span class="text-danger">*</span></label>
                            <input type="text" name="headline" class="form-control" required maxlength="255" placeholder="Book a Free Fertility Consultation">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Description</label>
                            <input type="text" name="description" class="form-control" maxlength="500" placeholder="Trusted by 5,000+ couples">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Creative image</label>
                        <input type="file" name="image" class="form-control" accept="image/*" id="adImage">
                        <div class="form-text">1080×1080 or 1200×628 works best. Max 5 MB.</div>
                        <img id="adImagePreview" class="img-fluid rounded mt-2 d-none" style="max-height:200px">
                    </div>

                    <hr>

                    <div class="mb-3">
                        <label class="form-label">Where should this ad send people? <span class="text-danger">*</span></label>
                        <select name="destination" class="form-select" id="destinationSelect">
                            <option value="lead_form">Instant Form (Meta lead form)</option>
                            <option value="landing_page">CRM landing page</option>
                            <option value="whatsapp">WhatsApp chat</option>
                            <option value="website">External website</option>
                        </select>
                    </div>

                    <div class="mb-3 dest-block" data-dest="lead_form">
                        <label class="form-label">Lead form</label>
                        <select name="lead_form_id" class="form-select">
                            <option value="">Choose a form…</option>
                            @foreach($leadForms as $form)
                                <option value="{{ $form->id }}">{{ $form->name }}</option>
                            @endforeach
                        </select>
                        @if($leadForms->isEmpty())
                            <div class="form-text text-warning">
                                No lead forms synced yet — <a href="{{ route('marketing.leadforms.index') }}">sync or create one</a>.
                            </div>
                        @endif
                    </div>

                    <div class="mb-3 dest-block d-none" data-dest="landing_page">
                        <label class="form-label">Landing page</label>
                        <select name="landing_page_id" class="form-select">
                            <option value="">Choose a page…</option>
                            @foreach($landingPages as $page)
                                <option value="{{ $page->id }}">{{ $page->name }}</option>
                            @endforeach
                        </select>
                        @if($landingPages->isEmpty())
                            <div class="form-text text-warning">
                                No published landing pages — <a href="{{ route('marketing.landing.create') }}">create one</a>.
                            </div>
                        @endif
                    </div>

                    <div class="mb-3 dest-block d-none" data-dest="website">
                        <label class="form-label">Destination URL</label>
                        <input type="url" name="link" class="form-control" placeholder="https://your-clinic.com/ivf">
                    </div>

                    <div class="mb-3 dest-block d-none" data-dest="whatsapp">
                        <div class="alert alert-success small mb-0">
                            <i class="bi bi-whatsapp me-1"></i>
                            Clicks open a WhatsApp chat with your connected business number. Those conversations appear
                            directly in the CRM inbox.
                        </div>
                    </div>

                    <div class="mb-0">
                        <label class="form-label">Call to action</label>
                        <select name="call_to_action" class="form-select">
                            @foreach($ctas as $value => $label)
                                <option value="{{ $value }}" @selected($value === 'SIGN_UP')>{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-check2 me-1"></i>Create ad (paused)</button>
                </div>
            </form>
        </div>
    </div>
</div>

{{-- Meta-rendered preview --}}
<div class="modal fade" id="previewModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Ad preview</h5>
                <div class="ms-auto me-2">
                    <select class="form-select form-select-sm" id="previewFormat">
                        <option value="DESKTOP_FEED_STANDARD">Facebook desktop feed</option>
                        <option value="MOBILE_FEED_STANDARD">Facebook mobile feed</option>
                        <option value="INSTAGRAM_STANDARD">Instagram feed</option>
                        <option value="INSTAGRAM_STORY">Instagram story</option>
                    </select>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body text-center" id="previewBody">
                <div class="spinner-border text-secondary"></div>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
$(function () {
    let previewAdId = null;

    const table = $('#adTable').DataTable({
        processing: true,
        serverSide: true,
        order: [[1, 'desc']],
        ajax: {
            url: @json(route('marketing.ads.index')),
            data: d => {
                d.adset_id = $('#fAdset').val();
                d.status   = $('#fStatus').val();
            },
        },
        columns: [
            { data: 'thumb', orderable: false, searchable: false },
            { data: 'name' },
            { data: 'adset_name' },
            { data: 'campaign_name' },
            { data: 'status_badge', orderable: false },
            { data: 'spend_period', className: 'text-end', orderable: false },
            { data: 'impressions', className: 'text-end', orderable: false },
            { data: 'ctr', className: 'text-end', orderable: false },
            { data: 'leads_count', className: 'text-end' },
            { data: 'action', orderable: false, searchable: false },
        ],
    });

    $('#fAdset, #fStatus').on('change', () => table.ajax.reload());

    $('#btnNew').on('click', function () {
        $('#adForm')[0].reset();
        $('#adImagePreview').addClass('d-none');
        new bootstrap.Modal('#adModal').show();
    });

    // Show only the block relevant to the chosen destination.
    $('#destinationSelect').on('change', function () {
        const dest = this.value;
        $('.dest-block').addClass('d-none').filter(`[data-dest="${dest}"]`).removeClass('d-none');

        const defaultCta = { lead_form: 'SIGN_UP', whatsapp: 'WHATSAPP_MESSAGE', landing_page: 'LEARN_MORE', website: 'LEARN_MORE' }[dest];
        $('[name=call_to_action]').val(defaultCta);
    }).trigger('change');

    $('#adImage').on('change', function () {
        if (!this.files.length) return $('#adImagePreview').addClass('d-none');
        $('#adImagePreview').attr('src', URL.createObjectURL(this.files[0])).removeClass('d-none');
    });

    $('#adForm').on('submit', function (e) {
        e.preventDefault();

        const $btn = $(this).find('[type=submit]').prop('disabled', true)
            .html('<span class="spinner-border spinner-border-sm me-1"></span>Publishing…');

        $.ajax({
            url: @json(route('marketing.ads.store')),
            method: 'POST',
            data: new FormData(this),
            processData: false,
            contentType: false,
        })
        .done(res => {
            if (!res.success) return toastr.error(res.message);
            toastr.success(res.message);
            bootstrap.Modal.getInstance(document.getElementById('adModal')).hide();
            table.ajax.reload();
        })
        .fail(xhr => toastr.error(xhr.responseJSON?.message || 'Could not create the ad.'))
        .always(() => $btn.prop('disabled', false).html('<i class="bi bi-check2 me-1"></i>Create ad (paused)'));
    });

    // --- Preview ---------------------------------------------------------
    function loadPreview() {
        $('#previewBody').html('<div class="spinner-border text-secondary"></div>');

        $.get(`{{ url('marketing/ads') }}/${previewAdId}/preview`, { format: $('#previewFormat').val() })
            .done(res => {
                $('#previewBody').html(res.success
                    ? res.html
                    : `<div class="alert alert-warning mb-0">${$('<div>').text(res.message).html()}</div>`);
            })
            .fail(xhr => $('#previewBody').html(
                `<div class="alert alert-danger mb-0">${$('<div>').text(xhr.responseJSON?.message || 'Preview unavailable.').html()}</div>`
            ));
    }

    $(document).on('click', '.js-preview', function () {
        previewAdId = $(this).data('id');
        new bootstrap.Modal('#previewModal').show();
        loadPreview();
    });

    $('#previewFormat').on('change', loadPreview);

    // --- Status / delete -------------------------------------------------
    $(document).on('click', '.js-status', function () {
        const id = $(this).data('id'), status = $(this).data('status');

        $.post(`{{ url('marketing/ads') }}/${id}/status`, { _method: 'PATCH', status })
            .done(res => { toastr.success(res.message); table.ajax.reload(null, false); })
            .fail(xhr => toastr.error(xhr.responseJSON?.message || 'Status change failed.'));
    });

    $(document).on('click', '.js-delete', function () {
        const id = $(this).data('id');

        Swal.fire({
            title: 'Delete this ad?',
            text: 'It will be removed from Meta as well.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#dc3545',
        }).then(result => {
            if (!result.isConfirmed) return;

            $.post(`{{ url('marketing/ads') }}/${id}`, { _method: 'DELETE' })
                .done(res => { toastr.success(res.message); table.ajax.reload(); })
                .fail(xhr => toastr.error(xhr.responseJSON?.message || 'Delete failed.'));
        });
    });
});
</script>
@endpush
