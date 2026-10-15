@extends('layouts.app')

@section('title', 'WhatsApp Templates')

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">Dashboard</a></li>
    <li class="breadcrumb-item">Marketing</li>
    <li class="breadcrumb-item active">WhatsApp Templates</li>
@endsection

@section('content')

@unless($configured)
    <div class="alert alert-warning d-flex align-items-center gap-2">
        <i class="bi bi-exclamation-triangle-fill"></i>
        <div class="flex-grow-1">Add your WhatsApp Business Account ID (WABA ID) to manage templates.</div>
        @can('integrations.view')
            <a href="{{ route('settings.integrations.index') }}" class="btn btn-sm btn-warning">Open settings</a>
        @endcan
    </div>
@endunless

<div class="alert alert-info small d-flex gap-2">
    <i class="bi bi-info-circle-fill"></i>
    <div>
        Templates are required to message anyone outside the 24-hour reply window. Meta reviews each one —
        approval usually takes minutes, occasionally up to 24 hours.
    </div>
</div>

<div class="card shadow-sm">
    <div class="card-header bg-white d-flex flex-wrap align-items-center gap-2">
        <span class="fw-semibold me-auto"><i class="bi bi-file-earmark-text me-2"></i>Template library</span>

        {{-- Templates live in one WABA each, so the library is shown per
             provider; Sync and New template act on the one selected here. --}}
        <select class="form-select form-select-sm w-auto" id="fSource" title="Provider / WhatsApp account">
            @foreach($sources as $value => $label)
                <option value="{{ $value }}" @selected($value === $selectedSource)>{{ $label }}</option>
            @endforeach
            <option value="">All providers</option>
        </select>

        <select class="form-select form-select-sm w-auto" id="fStatus">
            <option value="">All statuses</option>
            <option value="APPROVED">Approved</option>
            <option value="PENDING">Pending</option>
            <option value="REJECTED">Rejected</option>
        </select>

        <select class="form-select form-select-sm w-auto" id="fCategory">
            <option value="">All categories</option>
            @foreach($categories as $value => $label)
                <option value="{{ $value }}">{{ $label }}</option>
            @endforeach
        </select>

        <select class="form-select form-select-sm w-auto" id="fLanguage">
            <option value="">All languages</option>
            @foreach(\App\Models\WhatsappTemplate::query()->select('language')->distinct()->orderBy('language')->pluck('language') as $lang)
                <option value="{{ $lang }}">{{ $languages[$lang] ?? $lang }}</option>
            @endforeach
        </select>

        <select class="form-select form-select-sm w-auto" id="fVariables">
            <option value="">With or without variables</option>
            <option value="1">Has variables</option>
            <option value="0">No variables</option>
        </select>

        <button class="btn btn-sm btn-link text-decoration-none" id="fReset" title="Clear filters"><i class="bi bi-x-circle"></i></button>

        <button class="btn btn-sm btn-outline-secondary" id="btnSync"><i class="bi bi-arrow-repeat me-1"></i>Sync</button>

        @can('whatsapp_templates.create')
            <button class="btn btn-sm btn-primary" id="btnNew"><i class="bi bi-plus-lg me-1"></i>New template</button>
        @endcan
    </div>

    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover align-middle w-100" id="templateTable">
                <thead class="table-light">
                    <tr>
                        <th>Name</th>
                        <th>Provider</th>
                        <th>Language</th>
                        <th>Category</th>
                        <th>Status</th>
                        <th>Preview</th>
                        <th class="text-center">Vars</th>
                        <th style="width:100px">Actions</th>
                    </tr>
                </thead>
            </table>
        </div>
    </div>
</div>

{{-- Template builder --}}
<div class="modal fade" id="templateModal" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <form id="templateForm">
                <div class="modal-header">
                    <h5 class="modal-title">
                        Create a WhatsApp template
                        <div class="small text-muted fw-normal">Submitted to: <span id="templateTarget">—</span></div>
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-lg-7">
                            <div class="row g-3 mb-3">
                                <div class="col-md-6">
                                    <label class="form-label">Template name <span class="text-danger">*</span></label>
                                    <input type="text" name="name" class="form-control" required
                                           pattern="[a-z0-9_]+" placeholder="appointment_reminder">
                                    <div class="form-text">Lowercase letters, numbers and underscores only.</div>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Language</label>
                                    <select name="language" class="form-select">
                                        @foreach($languages as $code => $label)
                                            <option value="{{ $code }}" @selected($code === 'en')>{{ $label }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Category</label>
                                    <select name="category" class="form-select">
                                        @foreach($categories as $value => $label)
                                            <option value="{{ $value }}" @selected($value === 'UTILITY')>{{ $label }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>

                            <div class="row g-3 mb-3">
                                <div class="col-md-5">
                                    <label class="form-label">Header</label>
                                    <select name="header_format" class="form-select" id="headerFormat">
                                        <option value="NONE">None</option>
                                        <option value="TEXT">Text</option>
                                        <option value="IMAGE">Image</option>
                                        <option value="VIDEO">Video</option>
                                        <option value="DOCUMENT">Document</option>
                                    </select>
                                </div>
                                <div class="col-md-7 d-none" id="headerTextWrap">
                                    <label class="form-label">Header text</label>
                                    <input type="text" name="header_text" class="form-control" maxlength="60">
                                </div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Body <span class="text-danger">*</span></label>
                                <textarea name="body" class="form-control" id="bodyInput" rows="5" required maxlength="1024"
                                          placeholder="Hello @{{1}}, your consultation at @{{2}} is confirmed for @{{3}}. Reply here if you need to reschedule."></textarea>
                                <div class="form-text">
                                    Use <code>@{{1}}</code>, <code>@{{2}}</code>… for personalised values.
                                    <span id="charCount" class="float-end">0 / 1024</span>
                                </div>
                            </div>

                            <div class="mb-3 d-none" id="exampleWrap">
                                <label class="form-label">Example values <span class="text-danger">*</span></label>
                                <div id="exampleInputs"></div>
                                <div class="form-text">Meta needs a realistic sample for every placeholder before it will approve.</div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Footer</label>
                                <input type="text" name="footer" class="form-control" maxlength="60" placeholder="Reply STOP to opt out">
                            </div>

                            <div class="mb-0">
                                <label class="form-label d-flex justify-content-between">
                                    <span>Buttons (max 3)</span>
                                    <button type="button" class="btn btn-sm btn-outline-primary" id="btnAddButton">
                                        <i class="bi bi-plus"></i> Add
                                    </button>
                                </label>
                                <div id="buttonsWrap"></div>
                            </div>
                        </div>

                        {{-- Live preview --}}
                        <div class="col-lg-5">
                            <label class="form-label">Preview</label>
                            <div class="rounded p-3" style="background:#efeae2">
                                <div class="bg-white rounded shadow-sm p-2" style="max-width:300px;font-size:.85rem">
                                    <div class="fw-bold mb-1 d-none" id="pvHeader"></div>
                                    <div id="pvBody" class="mb-1 text-muted">Your message appears here…</div>
                                    <div class="text-muted d-none" id="pvFooter" style="font-size:.75rem"></div>
                                    <div id="pvButtons" class="mt-2"></div>
                                    <div class="text-end text-muted" style="font-size:.65rem">
                                        {{ now()->format('g:i A') }} <i class="bi bi-check-all"></i>
                                    </div>
                                </div>
                            </div>

                            <div class="alert alert-light border small mt-3 mb-0">
                                <strong>Approval tips</strong>
                                <ul class="mb-0 ps-3">
                                    <li>Marketing templates need explicit opt-in from the contact.</li>
                                    <li>Do not promise medical outcomes or guarantee results.</li>
                                    <li>Utility templates (reminders, confirmations) are approved fastest.</li>
                                    <li>Placeholders must not sit at the very start or end of the body.</li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-send me-1"></i>Submit for review</button>
                </div>
            </form>
        </div>
    </div>
</div>

{{-- Preview existing template --}}
<div class="modal fade" id="viewModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Template preview</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="viewBody"></div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
$(function () {
    const table = $('#templateTable').DataTable({
        processing: true,
        serverSide: true,
        order: [[0, 'asc']],
        ajax: {
            url: @json(route('marketing.templates.index')),
            data: d => {
                d.source        = $('#fSource').val();
                d.status        = $('#fStatus').val();
                d.category      = $('#fCategory').val();
                d.language      = $('#fLanguage').val();
                d.has_variables = $('#fVariables').val();
            },
        },
        columns: [
            { data: 'name' },
            { data: 'provider', orderable: false, searchable: false },
            { data: 'language' },
            { data: 'category' },
            { data: 'status_badge', orderable: false },
            { data: 'preview', orderable: false },
            { data: 'vars', className: 'text-center', orderable: false },
            { data: 'action', orderable: false, searchable: false },
        ],
    });

    $('#fSource, #fStatus, #fCategory, #fLanguage, #fVariables').on('change', () => table.ajax.reload());
    $('#fReset').on('click', () => {
        $('#fStatus, #fCategory, #fLanguage, #fVariables').val('');
        table.ajax.reload();
    });

    // The account Sync / New template act on: the selected provider, or the
    // default one when "All providers" is selected.
    const defaultSource = @json($selectedSource);
    function targetAccount() {
        const v = $('#fSource').val() || defaultSource;
        return /^\d+$/.test(v) ? v : '';
    }
    function targetLabel() {
        const v = $('#fSource').val() || defaultSource;
        return $('#fSource option').filter((i, o) => o.value === v).text() || 'Meta Cloud API';
    }

    $('#btnSync').on('click', function () {
        const $btn = $(this).prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span>Syncing…');

        $.post(@json(route('marketing.templates.sync')), { channel_account_id: targetAccount() })
            .done(res => { toastr.success(res.message); table.ajax.reload(); })
            .fail(xhr => toastr.error(xhr.responseJSON?.message || 'Sync failed.'))
            .always(() => $btn.prop('disabled', false).html('<i class="bi bi-arrow-repeat me-1"></i>Sync'));
    });

    $('#btnNew').on('click', function () {
        $('#templateForm')[0].reset();
        $('#buttonsWrap, #exampleInputs').empty();
        $('#exampleWrap').addClass('d-none');
        $('#templateTarget').text(targetLabel());
        updatePreview();
        new bootstrap.Modal('#templateModal').show();
    });

    // --- Builder ---------------------------------------------------------
    $('#headerFormat').on('change', function () {
        $('#headerTextWrap').toggleClass('d-none', this.value !== 'TEXT');
        updatePreview();
    });

    let buttonIndex = 0;

    $('#btnAddButton').on('click', function () {
        if ($('#buttonsWrap .btn-row').length >= 3) return toastr.warning('WhatsApp allows at most 3 buttons.');

        $('#buttonsWrap').append(`
            <div class="row g-2 mb-2 btn-row align-items-center">
                <div class="col-4">
                    <select class="form-select form-select-sm btn-type" name="buttons[${buttonIndex}][type]">
                        <option value="QUICK_REPLY">Quick reply</option>
                        <option value="URL">Website URL</option>
                        <option value="PHONE_NUMBER">Call</option>
                    </select>
                </div>
                <div class="col-4">
                    <input type="text" class="form-control form-control-sm btn-text" name="buttons[${buttonIndex}][text]"
                           placeholder="Button text" maxlength="25">
                </div>
                <div class="col-3">
                    <input type="text" class="form-control form-control-sm btn-value" name="buttons[${buttonIndex}][value]"
                           placeholder="—" disabled>
                </div>
                <div class="col-1 text-end">
                    <button type="button" class="btn btn-sm btn-link text-danger p-0 btn-remove"><i class="bi bi-x-lg"></i></button>
                </div>
            </div>`);

        buttonIndex++;
        updatePreview();
    });

    $(document).on('change', '.btn-type', function () {
        const needsValue = this.value !== 'QUICK_REPLY';
        $(this).closest('.btn-row').find('.btn-value')
            .prop('disabled', !needsValue)
            .attr('placeholder', this.value === 'URL' ? 'https://…' : (needsValue ? '+91…' : '—'));
        updatePreview();
    });

    $(document).on('click', '.btn-remove', function () {
        $(this).closest('.btn-row').remove();
        updatePreview();
    });

    $(document).on('input change', '#templateForm input, #templateForm textarea, #templateForm select', updatePreview);

    function updatePreview() {
        const body = $('#bodyInput').val() || '';
        $('#charCount').text(`${body.length} / 1024`);

        // Placeholders drive the "example values" inputs Meta requires.
        const matches = [...body.matchAll(/\{\{(\d+)\}\}/g)].map(m => parseInt(m[1], 10));
        const count = matches.length ? Math.max(...matches) : 0;

        const existing = $('.example-input').map((i, el) => el.value).get();

        if (count > 0) {
            let html = '';
            for (let i = 1; i <= count; i++) {
                html += `<input type="text" class="form-control form-control-sm mb-1 example-input"
                                name="example_params[${i}]" placeholder="Sample for @{{${i}}}" value="${existing[i - 1] || ''}">`;
            }
            $('#exampleInputs').html(html);
            $('#exampleWrap').removeClass('d-none');
        } else {
            $('#exampleInputs').empty();
            $('#exampleWrap').addClass('d-none');
        }

        // Preview bubble
        const headerFormat = $('#headerFormat').val();
        const headerText   = $('[name=header_text]').val();

        if (headerFormat === 'TEXT' && headerText) {
            $('#pvHeader').removeClass('d-none').text(headerText);
        } else if (headerFormat !== 'NONE' && headerFormat !== 'TEXT') {
            $('#pvHeader').removeClass('d-none').html(`<span class="text-muted"><i class="bi bi-image me-1"></i>${headerFormat} header</span>`);
        } else {
            $('#pvHeader').addClass('d-none');
        }

        let rendered = body || 'Your message appears here…';
        for (let i = 1; i <= count; i++) {
            const sample = $(`.example-input[name="example_params[${i}]"]`).val() || `[value ${i}]`;
            rendered = rendered.replaceAll('{' + '{' + i + '}' + '}', sample);
        }
        $('#pvBody').toggleClass('text-muted', !body).html($('<div>').text(rendered).html().replace(/\n/g, '<br>'));

        const footer = $('[name=footer]').val();
        $('#pvFooter').toggleClass('d-none', !footer).text(footer || '');

        const buttons = $('.btn-row').map((i, row) => $(row).find('.btn-text').val() || 'Button').get();
        $('#pvButtons').html(buttons.map(b =>
            `<div class="border-top pt-1 text-center text-primary" style="font-size:.8rem">${$('<div>').text(b).html()}</div>`
        ).join(''));
    }

    $('#templateForm').on('submit', function (e) {
        e.preventDefault();

        const $btn = $(this).find('[type=submit]').prop('disabled', true);

        $.post(@json(route('marketing.templates.store')), $(this).serialize() + '&channel_account_id=' + targetAccount())
            .done(res => {
                if (!res.success) return toastr.error(res.message);
                toastr.success(res.message);
                bootstrap.Modal.getInstance(document.getElementById('templateModal')).hide();
                table.ajax.reload();
            })
            .fail(xhr => toastr.error(xhr.responseJSON?.message || 'Submission failed.'))
            .always(() => $btn.prop('disabled', false));
    });

    // --- View / delete ---------------------------------------------------
    $(document).on('click', '.js-view', function () {
        const id = $(this).data('id');

        $.get(`{{ url('marketing/templates') }}/${id}`).done(res => {
            const t = res.template;

            $('#viewBody').html(`
                <div class="mb-2">
                    <span class="badge bg-secondary">${t.category}</span>
                    <span class="badge bg-light text-dark border">${t.language}</span>
                    ${t.rejected_reason ? `<div class="alert alert-danger small mt-2 mb-0">${$('<div>').text(t.rejected_reason).html()}</div>` : ''}
                </div>
                <div class="rounded p-3" style="background:#efeae2">
                    <div class="bg-white rounded shadow-sm p-2" style="font-size:.85rem">
                        ${$('<div>').text(t.body_text || '').html().replace(/\n/g, '<br>')}
                    </div>
                </div>
                <div class="small text-muted mt-2">${t.variable_count} variable(s)</div>`);

            new bootstrap.Modal('#viewModal').show();
        });
    });

    $(document).on('click', '.js-delete', function () {
        const id = $(this).data('id');

        Swal.fire({
            title: 'Delete this template?',
            text: 'It is removed from your WhatsApp Business Account. Messages already sent are unaffected.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#dc3545',
        }).then(result => {
            if (!result.isConfirmed) return;

            $.post(`{{ url('marketing/templates') }}/${id}`, { _method: 'DELETE' })
                .done(res => { toastr.success(res.message); table.ajax.reload(); })
                .fail(xhr => toastr.error(xhr.responseJSON?.message || 'Delete failed.'));
        });
    });
});
</script>
@endpush
