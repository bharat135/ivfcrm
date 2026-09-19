@extends('layouts.app')

@section('title', 'WhatsApp Broadcasts')

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">Dashboard</a></li>
    <li class="breadcrumb-item">Marketing</li>
    <li class="breadcrumb-item active">Broadcasts</li>
@endsection

@section('content')

<div class="alert alert-warning small d-flex gap-2">
    <i class="bi bi-shield-exclamation"></i>
    <div>
        Only message contacts who opted in. Sending marketing templates to people who did not consent gets
        your number quality rating downgraded and can suspend the WhatsApp Business Account.
    </div>
</div>

<div class="card shadow-sm">
    <div class="card-header bg-white d-flex align-items-center gap-2">
        <span class="fw-semibold me-auto"><i class="bi bi-megaphone me-2"></i>Broadcasts</span>
        @can('broadcasts.create')
            <button class="btn btn-sm btn-primary" id="btnNew"><i class="bi bi-plus-lg me-1"></i>New broadcast</button>
        @endcan
    </div>

    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover align-middle w-100" id="broadcastTable">
                <thead class="table-light">
                    <tr>
                        <th>Name</th>
                        <th>Template</th>
                        <th>Status</th>
                        <th style="width:180px">Progress</th>
                        <th>Delivery</th>
                        <th>Created</th>
                        <th style="width:150px">Actions</th>
                    </tr>
                </thead>
            </table>
        </div>
    </div>
</div>

{{-- Broadcast composer --}}
<div class="modal fade" id="broadcastModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <form id="broadcastForm">
                <div class="modal-header">
                    <h5 class="modal-title">New broadcast</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body">
                    @if($templates->isEmpty())
                        <div class="alert alert-warning mb-0">
                            You need at least one Meta-approved template before you can broadcast.
                            <a href="{{ route('marketing.templates.index') }}">Create one</a>.
                        </div>
                    @else
                        <div class="row g-3 mb-3">
                            <div class="col-md-7">
                                <label class="form-label">Broadcast name <span class="text-danger">*</span></label>
                                <input type="text" name="name" class="form-control" required placeholder="August reactivation — cold leads">
                            </div>
                            <div class="col-md-5">
                                <label class="form-label">Send from</label>
                                <select name="channel_account_id" class="form-select">
                                    <option value="">Default number</option>
                                    @foreach($accounts as $account)
                                        <option value="{{ $account->id }}">{{ $account->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Template <span class="text-danger">*</span></label>
                            <select name="whatsapp_template_id" class="form-select" id="bcTemplate" required>
                                <option value="">Choose an approved template…</option>
                                @foreach($templates as $template)
                                    <option value="{{ $template->id }}"
                                            data-vars="{{ $template->variable_count }}"
                                            data-body="{{ e($template->body_text) }}">
                                        {{ $template->name }} ({{ $template->language }}) — {{ $template->category }}
                                    </option>
                                @endforeach
                            </select>
                        </div>

                        <div id="bcVars" class="mb-3"></div>

                        <hr>
                        <h6 class="text-uppercase text-muted small fw-bold">Audience</h6>

                        <div class="row g-3 mb-3">
                            <div class="col-md-4">
                                <label class="form-label">Send to</label>
                                <select name="audience_type" class="form-select" id="bcAudience">
                                    <option value="leads">Leads</option>
                                    <option value="patients">Patients</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Centre</label>
                                <select name="center_id" class="form-select">
                                    <option value="">All centres</option>
                                    @foreach($centers as $center)
                                        <option value="{{ $center->id }}">{{ $center->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-4 lead-filter">
                                <label class="form-label">Lead source</label>
                                <select name="lead_source_id" class="form-select">
                                    <option value="">Any</option>
                                    @foreach($sources as $source)
                                        <option value="{{ $source->id }}">{{ $source->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>

                        <div class="row g-3 mb-3">
                            <div class="col-md-4 lead-filter">
                                <label class="form-label">Pipeline stage</label>
                                <select name="stage_id" class="form-select">
                                    <option value="">Any stage</option>
                                    @foreach($stages as $stage)
                                        <option value="{{ $stage->id }}">{{ $stage->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Created from</label>
                                <input type="date" name="created_from" class="form-control">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Created to</label>
                                <input type="date" name="created_to" class="form-control">
                            </div>
                        </div>

                        <div class="d-flex align-items-center justify-content-between border rounded p-3 bg-light mb-3">
                            <div>
                                <div class="small text-muted">Estimated recipients</div>
                                <div class="h5 mb-0" id="bcEstimate">—</div>
                            </div>
                            <button type="button" class="btn btn-sm btn-outline-primary" id="btnEstimate">
                                <i class="bi bi-calculator me-1"></i>Estimate
                            </button>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Schedule for later (optional)</label>
                            <input type="datetime-local" name="scheduled_at" class="form-control">
                            <div class="form-text">Leave blank to send immediately or save as a draft.</div>
                        </div>
                    @endif
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    @unless($templates->isEmpty())
                        <button type="submit" class="btn btn-outline-secondary" name="send_now" value="0">Save as draft</button>
                        <button type="submit" class="btn btn-primary" name="send_now" value="1">
                            <i class="bi bi-send me-1"></i>Send now
                        </button>
                    @endunless
                </div>
            </form>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
$(function () {
    const personalisation = @json($leadFields);

    const table = $('#broadcastTable').DataTable({
        processing: true,
        serverSide: true,
        order: [[5, 'desc']],
        ajax: @json(route('marketing.broadcasts.index')),
        columns: [
            { data: 'name' },
            { data: 'template.name', defaultContent: '—' },
            { data: 'status_badge', orderable: false },
            { data: 'progress', orderable: false, searchable: false },
            { data: 'delivery', orderable: false, searchable: false },
            { data: 'created_at', render: d => d ? new Date(d).toLocaleDateString('en-IN', { day: '2-digit', month: 'short', year: 'numeric' }) : '—' },
            { data: 'action', orderable: false, searchable: false },
        ],
    });

    $('#btnNew').on('click', function () {
        $('#broadcastForm')[0].reset();
        $('#bcVars').empty();
        $('#bcEstimate').text('—');
        new bootstrap.Modal('#broadcastModal').show();
    });

    // Lead-only filters hide when broadcasting to patients.
    $('#bcAudience').on('change', function () {
        $('.lead-filter').toggleClass('d-none', this.value === 'patients');
    });

    // Build one mapping row per template placeholder.
    $('#bcTemplate').on('change', function () {
        const vars = parseInt(this.selectedOptions[0]?.dataset.vars || 0, 10);

        if (!vars) return $('#bcVars').empty();

        const options = Object.entries(personalisation)
            .map(([k, v]) => `<option value="${k}">${v}</option>`).join('');

        let html = '<label class="form-label">Personalisation</label>';
        for (let i = 1; i <= vars; i++) {
            html += `
                <div class="input-group input-group-sm mb-2">
                    <span class="input-group-text">@{{${i}}}</span>
                    <select class="form-select" name="variable_map[${i}]">
                        <option value="">— static text —</option>${options}
                    </select>
                    <input type="text" class="form-control" placeholder="or type fixed text"
                           oninput="this.previousElementSibling.value = this.value ? this.value : ''">
                </div>`;
        }
        $('#bcVars').html(html);
    });

    $('#btnEstimate').on('click', function () {
        const $btn = $(this).prop('disabled', true);
        $('#bcEstimate').text('…');

        $.post(@json(route('marketing.broadcasts.estimate')), $('#broadcastForm').serialize())
            .done(res => $('#bcEstimate').text(res.count.toLocaleString('en-IN') + ' contacts'))
            .fail(() => $('#bcEstimate').text('—'))
            .always(() => $btn.prop('disabled', false));
    });

    $('#broadcastForm').on('submit', function (e) {
        e.preventDefault();

        // Which submit button was pressed decides send-now vs draft.
        const sendNow = document.activeElement?.value === '1' ? 1 : 0;
        const $buttons = $(this).find('[type=submit]').prop('disabled', true);

        $.post(@json(route('marketing.broadcasts.store')), $(this).serialize() + '&send_now=' + sendNow)
            .done(res => {
                if (!res.success) return toastr.error(res.message);
                toastr.success(res.message);
                bootstrap.Modal.getInstance(document.getElementById('broadcastModal')).hide();
                table.ajax.reload();
            })
            .fail(xhr => toastr.error(xhr.responseJSON?.message || 'Could not create the broadcast.'))
            .always(() => $buttons.prop('disabled', false));
    });

    $(document).on('click', '.js-send', function () {
        const id = $(this).data('id');

        Swal.fire({
            title: 'Send this broadcast now?',
            text: 'Messages cannot be recalled once delivered.',
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'Send',
        }).then(result => {
            if (!result.isConfirmed) return;

            $.post(`{{ url('marketing/broadcasts') }}/${id}/send`)
                .done(res => { toastr.success(res.message); table.ajax.reload(); })
                .fail(xhr => toastr.error(xhr.responseJSON?.message || 'Send failed.'));
        });
    });

    $(document).on('click', '.js-cancel', function () {
        const id = $(this).data('id');

        $.post(`{{ url('marketing/broadcasts') }}/${id}/cancel`)
            .done(res => { toastr.success(res.message); table.ajax.reload(); })
            .fail(xhr => toastr.error(xhr.responseJSON?.message || 'Cancel failed.'));
    });

    $(document).on('click', '.js-delete', function () {
        const id = $(this).data('id');

        Swal.fire({
            title: 'Delete this broadcast?',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#dc3545',
        }).then(result => {
            if (!result.isConfirmed) return;

            $.post(`{{ url('marketing/broadcasts') }}/${id}`, { _method: 'DELETE' })
                .done(res => { toastr.success(res.message); table.ajax.reload(); })
                .fail(xhr => toastr.error(xhr.responseJSON?.message || 'Delete failed.'));
        });
    });
});
</script>
@endpush
