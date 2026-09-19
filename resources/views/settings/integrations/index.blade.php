@extends('layouts.app')

@section('title', 'Integrations')

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">Dashboard</a></li>
    <li class="breadcrumb-item">Settings</li>
    <li class="breadcrumb-item active">Integrations</li>
@endsection

@section('content')

<div class="d-flex align-items-center justify-content-between mb-3">
    <div>
        <h5 class="mb-0">Meta &amp; WhatsApp Integration</h5>
        <small class="text-muted">Connect your Meta Business account to send WhatsApp messages, run ads and capture leads.</small>
    </div>
    <div class="d-flex gap-2">
        <button class="btn btn-sm btn-outline-primary" id="btnTest"><i class="bi bi-plug me-1"></i>Test connection</button>
        @can('integrations.edit')
            <button class="btn btn-sm btn-outline-secondary" id="btnSync"><i class="bi bi-arrow-repeat me-1"></i>Sync now</button>
        @endcan
    </div>
</div>

{{-- Status strip --}}
<div class="row g-3 mb-3">
    @foreach([
        ['label' => 'Meta account', 'ok' => $status['connected'], 'icon' => 'bi-meta',      'hint' => 'Access token'],
        ['label' => 'WhatsApp',     'ok' => $status['whatsapp'],  'icon' => 'bi-whatsapp',  'hint' => 'Phone number ID'],
        ['label' => 'Ads',          'ok' => $status['ads'],       'icon' => 'bi-badge-ad',  'hint' => 'Ad account ID'],
    ] as $item)
        <div class="col-md-4">
            <div class="card stat-card shadow-sm">
                <div class="card-body d-flex align-items-center gap-3">
                    <div class="icon-box {{ $item['ok'] ? 'bg-success-subtle text-success' : 'bg-secondary-subtle text-secondary' }}">
                        <i class="bi {{ $item['icon'] }}"></i>
                    </div>
                    <div>
                        <div class="fw-semibold">{{ $item['label'] }}</div>
                        <small class="{{ $item['ok'] ? 'text-success' : 'text-muted' }}">
                            {{ $item['ok'] ? 'Connected' : 'Not configured — ' . $item['hint'] }}
                        </small>
                    </div>
                </div>
            </div>
        </div>
    @endforeach
</div>

<div id="testResults" class="mb-3"></div>

<div class="row g-3">
    {{-- ------------------------------------------------------------ --}}
    {{-- Credentials                                                    --}}
    {{-- ------------------------------------------------------------ --}}
    <div class="col-lg-7">
        <div class="card shadow-sm">
            <div class="card-header bg-white">
                <ul class="nav nav-tabs card-header-tabs">
                    <li class="nav-item"><a class="nav-link active" data-bs-toggle="tab" href="#tabApp">App &amp; Token</a></li>
                    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tabWhatsapp">WhatsApp</a></li>
                    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tabAds">Ads &amp; Page</a></li>
                    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tabWebhook">Webhook</a></li>
                    {{-- Google is the other half of the same paid-acquisition
                         loop, so it lives beside Meta rather than in its own
                         corner of settings. It is a page, not a tab, because it
                         has its own connection test and conversion mapping. --}}
                    <li class="nav-item ms-auto">
                        <a class="nav-link" href="{{ route('settings.integrations.google.index') }}">
                            <i class="bi bi-google me-1" style="color:#4285f4"></i>Google Ads
                        </a>
                    </li>
                </ul>
            </div>

            <form id="integrationForm">
                @csrf
                @method('PUT')
                <div class="card-body tab-content">

                    {{-- App --}}
                    <div class="tab-pane fade show active" id="tabApp">
                        <div class="alert alert-light border small">
                            Create an app at
                            <a href="https://developers.facebook.com/apps" target="_blank" rel="noopener">developers.facebook.com</a>,
                            add the <strong>WhatsApp</strong> and <strong>Marketing API</strong> products, then generate a
                            <strong>System User</strong> token with these permissions:
                            <code>whatsapp_business_messaging</code>, <code>whatsapp_business_management</code>,
                            <code>ads_management</code>, <code>leads_retrieval</code>, <code>pages_show_list</code>,
                            <code>pages_manage_metadata</code>, <code>business_management</code>.
                        </div>

                        <div class="mb-3">
                            <label class="form-label">App ID</label>
                            <input type="text" name="app_id" class="form-control" value="{{ $meta['app_id'] ?? '' }}" placeholder="1234567890123456">
                        </div>

                        <div class="mb-3">
                            <label class="form-label">App Secret <span class="text-danger">*</span></label>
                            <input type="password" name="app_secret" class="form-control" placeholder="{{ ($meta['app_secret'] ?? '') ?: 'Enter app secret' }}" autocomplete="new-password">
                            <div class="form-text">Used to verify incoming webhook signatures. Stored encrypted; leave blank to keep the saved value.</div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">System User Access Token <span class="text-danger">*</span></label>
                            <textarea name="access_token" class="form-control font-monospace" rows="3" placeholder="{{ ($meta['access_token'] ?? '') ?: 'EAAG…' }}"></textarea>
                            <div class="form-text">Prefer a never-expiring System User token over a short-lived user token.</div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Business ID</label>
                            <input type="text" name="business_id" class="form-control" value="{{ $meta['business_id'] ?? '' }}">
                        </div>

                        <div class="mb-0">
                            <label class="form-label">Default country code</label>
                            <input type="text" name="default_country_code" class="form-control" style="max-width:120px"
                                   value="{{ $meta['default_country_code'] ?? '91' }}">
                            <div class="form-text">Prepended to 10-digit mobile numbers when messaging.</div>
                        </div>
                    </div>

                    {{-- WhatsApp --}}
                    <div class="tab-pane fade" id="tabWhatsapp">
                        <div class="mb-3">
                            <label class="form-label">WhatsApp Business Account ID (WABA ID)</label>
                            <input type="text" name="waba_id" class="form-control" value="{{ $meta['waba_id'] ?? '' }}">
                            <div class="form-text">Found under WhatsApp → API Setup in the Meta app dashboard.</div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Phone Number ID</label>
                            <input type="text" name="phone_number_id" class="form-control" value="{{ $meta['phone_number_id'] ?? '' }}">
                            <div class="form-text">The numeric ID of the sending number — not the phone number itself.</div>
                        </div>

                        <div class="d-flex gap-2">
                            @can('integrations.edit')
                                <button type="button" class="btn btn-sm btn-outline-success" id="btnSubscribe">
                                    <i class="bi bi-broadcast me-1"></i>Subscribe webhook to this WABA
                                </button>
                            @endcan
                            <a href="{{ route('marketing.templates.index') }}" class="btn btn-sm btn-outline-secondary">
                                <i class="bi bi-file-earmark-text me-1"></i>Manage templates
                            </a>
                        </div>
                    </div>

                    {{-- Ads --}}
                    <div class="tab-pane fade" id="tabAds">
                        <div class="mb-3">
                            <label class="form-label">Ad Account ID</label>
                            <div class="input-group">
                                <span class="input-group-text">act_</span>
                                <input type="text" name="ad_account_id" class="form-control"
                                       value="{{ str_replace('act_', '', $meta['ad_account_id'] ?? '') }}">
                                <button type="button" class="btn btn-outline-secondary" id="btnDiscover">
                                    <i class="bi bi-search me-1"></i>Discover
                                </button>
                            </div>
                        </div>

                        <div class="mb-3 d-none" id="adAccountList"></div>

                        <div class="mb-3">
                            <label class="form-label">Facebook Page ID</label>
                            <input type="text" name="page_id" class="form-control" value="{{ $meta['page_id'] ?? '' }}">
                            <div class="form-text">The page that owns your lead forms and runs your ad creatives.</div>
                        </div>

                        <div class="mb-3 d-none" id="pageList"></div>

                        @can('integrations.edit')
                        <div class="mb-3">
                            <button type="button" class="btn btn-sm btn-outline-success" id="btnSubscribePage">
                                <i class="bi bi-broadcast me-1"></i>Subscribe Page to Lead Ads
                            </button>
                            <div class="form-text">
                                Required once per Page: Meta only sends <code>leadgen</code> webhooks for a Page after the app
                                is subscribed to it. Save the Page ID and run Discover first so the Page token is stored.
                            </div>
                        </div>
                        @endcan

                        <div class="mb-3">
                            <label class="form-label">Meta Pixel ID</label>
                            <input type="text" name="pixel_id" class="form-control" value="{{ $meta['pixel_id'] ?? '' }}">
                            <div class="form-text">Injected into landing pages for conversion tracking.</div>
                        </div>

                        <hr class="my-3">

                        {{-- Conversions API. Without this Meta only ever sees the
                             form fill, so it buys more cheap form fills. Sending
                             the consultation and the started cycle back is what
                             teaches it to find couples who actually book. --}}
                        <h6 class="fw-semibold small mb-2">Conversions API</h6>
                        <p class="text-muted small">
                            Reports each stage of the funnel back to Meta — enquiry, contacted, consultation, treatment —
                            so bidding optimises for bookings instead of form fills.
                        </p>

                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Dataset ID</label>
                                <input type="text" name="dataset_id" class="form-control" value="{{ $meta['dataset_id'] ?? '' }}">
                                <div class="form-text">From Events Manager. Leave blank to use the pixel above.</div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Test event code</label>
                                <input type="text" name="test_event_code" class="form-control" value="{{ $meta['test_event_code'] ?? '' }}">
                                <div class="form-text">Optional, for verifying events in Events Manager.</div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Value of a started cycle</label>
                                <input type="number" step="0.01" min="0" name="treatment_value" class="form-control"
                                       value="{{ $meta['treatment_value'] ?? '' }}">
                                <div class="form-text">Sent with the treatment event so ROAS is meaningful.</div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Currency</label>
                                <input type="text" name="currency" class="form-control" value="{{ $meta['currency'] ?? 'INR' }}">
                            </div>
                        </div>
                    </div>

                    {{-- Webhook --}}
                    <div class="tab-pane fade" id="tabWebhook">
                        <div class="mb-3">
                            <label class="form-label">Callback URL</label>
                            <div class="input-group">
                                <input type="text" class="form-control font-monospace" id="webhookUrl" value="{{ $webhookUrl }}" readonly>
                                <button type="button" class="btn btn-outline-secondary" id="btnCopyUrl"><i class="bi bi-clipboard"></i></button>
                            </div>
                            <div class="form-text">
                                Paste this into Meta App Dashboard → Webhooks, and subscribe to
                                <code>messages</code> (WhatsApp Business Account) and <code>leadgen</code> (Page).
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Verify Token</label>
                            <div class="input-group">
                                <input type="text" name="webhook_verify_token" class="form-control font-monospace"
                                       id="verifyToken" placeholder="{{ ($meta['webhook_verify_token'] ?? '') ?: 'Generate or type a token' }}">
                                <button type="button" class="btn btn-outline-secondary" id="btnGenToken">Generate</button>
                            </div>
                            <div class="form-text">Enter the same value in Meta's webhook configuration screen.</div>
                        </div>

                        @if($webhookUrl && ! str_starts_with($webhookUrl, 'https://'))
                            <div class="alert alert-warning small mb-0">
                                <i class="bi bi-shield-exclamation me-1"></i>
                                Meta only delivers webhooks to <strong>HTTPS</strong> URLs with a valid certificate.
                                On localhost, use a tunnel such as ngrok and set <code>APP_URL</code> to the tunnel address.
                            </div>
                        @endif
                    </div>
                </div>

                @can('integrations.edit')
                    <div class="card-footer bg-white text-end">
                        <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i>Save settings</button>
                    </div>
                @endcan
            </form>
        </div>
    </div>

    {{-- ------------------------------------------------------------ --}}
    {{-- Channel accounts + recent events                               --}}
    {{-- ------------------------------------------------------------ --}}
    <div class="col-lg-5">
        <div class="card shadow-sm mb-3">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <span class="fw-semibold">Channel accounts</span>
                @can('integrations.create')
                    <button class="btn btn-sm btn-primary" id="btnAddAccount"><i class="bi bi-plus-lg"></i></button>
                @endcan
            </div>
            <div class="list-group list-group-flush">
                @forelse($accounts as $account)
                    <div class="list-group-item d-flex align-items-center gap-2">
                        <i class="bi bi-{{ $account->channel === 'whatsapp' ? 'whatsapp text-success' : 'chat-dots text-primary' }} fs-5"></i>
                        <div class="flex-grow-1 min-w-0">
                            <div class="fw-semibold text-truncate">
                                {{ $account->name }}
                                @if($account->is_default)<span class="badge bg-primary ms-1">Default</span>@endif
                                @unless($account->is_active)<span class="badge bg-secondary ms-1">Inactive</span>@endunless
                            </div>
                            <small class="text-muted">
                                {{ ucfirst($account->channel) }} · {{ $account->identifier ?: 'no identifier' }}
                                @if($account->center) · {{ $account->center->name }} @endif
                            </small>
                        </div>
                        @can('integrations.edit')
                            <button class="btn btn-sm btn-outline-secondary edit-account" data-id="{{ $account->id }}"><i class="bi bi-pencil"></i></button>
                        @endcan
                        @can('integrations.delete')
                            <button class="btn btn-sm btn-outline-danger del-account" data-id="{{ $account->id }}"><i class="bi bi-trash"></i></button>
                        @endcan
                    </div>
                @empty
                    <div class="list-group-item text-muted small text-center py-4">
                        No channel accounts yet. The credentials above are used as the default WhatsApp sender.
                    </div>
                @endforelse
            </div>
        </div>

        <div class="card shadow-sm">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <span class="fw-semibold">Recent webhook events</span>
                @if($lastSync)
                    <small class="text-muted">Last sync {{ \Carbon\Carbon::parse($lastSync['at'])->diffForHumans() }}</small>
                @endif
            </div>
            <div class="list-group list-group-flush" style="max-height:340px;overflow-y:auto">
                @forelse($recentEvents as $event)
                    <div class="list-group-item py-2">
                        <div class="d-flex justify-content-between align-items-center">
                            <span class="small font-monospace">{{ $event->event_type ?: 'unknown' }}</span>
                            <span class="badge bg-{{ match($event->status) {
                                'processed' => 'success', 'failed' => 'danger', 'ignored' => 'secondary', default => 'info'
                            } }}">{{ $event->status }}</span>
                        </div>
                        <small class="text-muted">{{ $event->created_at->diffForHumans() }}</small>
                        @if($event->error)
                            <div class="small text-danger text-truncate">{{ $event->error }}</div>
                        @endif
                    </div>
                @empty
                    <div class="list-group-item text-muted small text-center py-4">
                        No webhook events received yet.
                    </div>
                @endforelse
            </div>
        </div>
    </div>
</div>

{{-- Channel account modal --}}
<div class="modal fade" id="accountModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form id="accountForm">
                <div class="modal-header">
                    <h5 class="modal-title" id="accountModalTitle">Add channel account</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" id="accountId">

                    <div class="mb-3">
                        <label class="form-label">Display name</label>
                        <input type="text" name="name" class="form-control" required placeholder="e.g. Delhi Centre WhatsApp">
                    </div>

                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="form-label">Channel</label>
                            <select name="channel" class="form-select">
                                <option value="whatsapp">WhatsApp</option>
                                <option value="sms">SMS</option>
                                <option value="email">Email</option>
                            </select>
                        </div>
                        <div class="col-6">
                            <label class="form-label">Provider</label>
                            <select name="provider" class="form-select">
                                <option value="meta">Meta Cloud API</option>
                                <option value="wati">Wati</option>
                                <option value="msg91">MSG91</option>
                            </select>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Centre</label>
                        <select name="center_id" class="form-select">
                            <option value="">All centres</option>
                            @foreach($centers as $center)
                                <option value="{{ $center->id }}">{{ $center->name }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Display phone number</label>
                        <input type="text" name="identifier" class="form-control" placeholder="+91 98xxxxxxx">
                    </div>

                    <hr>
                    <p class="small text-muted">Leave blank to use the account-level credentials above.</p>

                    <div class="row g-2 mb-3">
                        <div class="col-6">
                            <label class="form-label small">Phone Number ID</label>
                            <input type="text" name="phone_number_id" class="form-control form-control-sm">
                        </div>
                        <div class="col-6">
                            <label class="form-label small">WABA ID</label>
                            <input type="text" name="waba_id" class="form-control form-control-sm">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label small">Access token override</label>
                        <input type="password" name="access_token" class="form-control form-control-sm" autocomplete="new-password">
                    </div>

                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" name="is_default" value="1" id="accIsDefault">
                        <label class="form-check-label" for="accIsDefault">Default sender for this channel</label>
                    </div>
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" name="is_active" value="1" id="accIsActive" checked>
                        <label class="form-check-label" for="accIsActive">Active</label>
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
@endsection

@push('scripts')
<script>
$(function () {
    // --- Save credentials -------------------------------------------------
    $('#integrationForm').on('submit', function (e) {
        e.preventDefault();

        const $btn = $(this).find('[type=submit]').prop('disabled', true);

        $.ajax({
            url: @json(route('settings.integrations.update')),
            method: 'POST',
            data: $(this).serialize(),
        })
        .done(res => {
            toastr.success(res.message);
            setTimeout(() => location.reload(), 800);
        })
        .fail(xhr => toastr.error(xhr.responseJSON?.message || 'Could not save settings.'))
        .always(() => $btn.prop('disabled', false));
    });

    // --- Test connection --------------------------------------------------
    $('#btnTest').on('click', function () {
        const $btn = $(this).prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span>Testing…');
        $('#testResults').html('');

        $.post(@json(route('settings.integrations.test')))
            .done(res => {
                const rows = Object.entries(res.results).map(([key, r]) => `
                    <div class="d-flex align-items-start gap-2 py-1">
                        <i class="bi ${r.ok ? 'bi-check-circle-fill text-success' : 'bi-x-circle-fill text-danger'}"></i>
                        <div><strong class="text-capitalize">${key.replace('_', ' ')}:</strong> ${$('<div>').text(r.message).html()}</div>
                    </div>`).join('');

                $('#testResults').html(`<div class="card shadow-sm"><div class="card-body py-2">${rows}</div></div>`);
            })
            .fail(() => toastr.error('Connection test failed.'))
            .always(() => $btn.prop('disabled', false).html('<i class="bi bi-plug me-1"></i>Test connection'));
    });

    // --- Discover ad accounts / pages ------------------------------------
    $('#btnDiscover').on('click', function () {
        const $btn = $(this).prop('disabled', true);

        $.post(@json(route('settings.integrations.discover')))
            .done(res => {
                if (!res.success) return toastr.error(res.message);

                const accounts = (res.ad_accounts || []).map(a =>
                    `<button type="button" class="list-group-item list-group-item-action pick-account" data-id="${a.id}">
                        ${$('<div>').text(a.name).html()} <small class="text-muted">${a.id} · ${a.currency || ''}</small>
                     </button>`).join('');

                const pages = (res.pages || []).map(p =>
                    `<button type="button" class="list-group-item list-group-item-action pick-page" data-id="${p.id}" data-token="${p.access_token || ''}">
                        ${$('<div>').text(p.name).html()} <small class="text-muted">${p.id}</small>
                     </button>`).join('');

                $('#adAccountList').removeClass('d-none')
                    .html(`<label class="form-label small">Available ad accounts</label><div class="list-group">${accounts || '<div class="list-group-item text-muted small">None found.</div>'}</div>`);
                $('#pageList').removeClass('d-none')
                    .html(`<label class="form-label small">Available pages</label><div class="list-group">${pages || '<div class="list-group-item text-muted small">None found.</div>'}</div>`);

                // Page tokens are what make lead retrieval work; save them now.
                const tokens = {};
                (res.pages || []).forEach(p => { if (p.access_token) tokens[p.id] = p.access_token; });

                if (Object.keys(tokens).length) {
                    $.post(@json(route('settings.integrations.page_tokens')), { tokens });
                }
            })
            .fail(xhr => toastr.error(xhr.responseJSON?.message || 'Discovery failed.'))
            .always(() => $btn.prop('disabled', false));
    });

    $(document).on('click', '.pick-account', function () {
        $('input[name=ad_account_id]').val($(this).data('id').toString().replace('act_', ''));
        toastr.success('Ad account selected — remember to save.');
    });

    $(document).on('click', '.pick-page', function () {
        $('input[name=page_id]').val($(this).data('id'));
        toastr.success('Page selected — remember to save.');
    });

    // --- Webhook helpers --------------------------------------------------
    $('#btnCopyUrl').on('click', function () {
        navigator.clipboard.writeText($('#webhookUrl').val());
        toastr.success('Callback URL copied.');
    });

    $('#btnGenToken').on('click', function () {
        const bytes = new Uint8Array(24);
        crypto.getRandomValues(bytes);
        $('#verifyToken').val(Array.from(bytes, b => b.toString(16).padStart(2, '0')).join(''));
        toastr.info('Token generated — save, then paste it into Meta.');
    });

    $('#btnSubscribe').on('click', function () {
        const $btn = $(this).prop('disabled', true);

        $.post(@json(route('settings.integrations.subscribe')))
            .done(res => res.success ? toastr.success(res.message) : toastr.error(res.message))
            .fail(xhr => toastr.error(xhr.responseJSON?.message || 'Subscription failed.'))
            .always(() => $btn.prop('disabled', false));
    });

    $('#btnSubscribePage').on('click', function () {
        const $btn = $(this).prop('disabled', true);

        $.post(@json(route('settings.integrations.subscribe_page')))
            .done(res => res.success ? toastr.success(res.message) : toastr.error(res.message))
            .fail(xhr => toastr.error(xhr.responseJSON?.message || 'Page subscription failed.'))
            .always(() => $btn.prop('disabled', false));
    });

    $('#btnSync').on('click', function () {
        $.post(@json(route('settings.integrations.sync')))
            .done(res => toastr.success(res.message))
            .fail(() => toastr.error('Could not queue the sync.'));
    });

    // --- Channel accounts -------------------------------------------------
    $('#btnAddAccount').on('click', function () {
        $('#accountForm')[0].reset();
        $('#accountId').val('');
        $('#accountModalTitle').text('Add channel account');
        new bootstrap.Modal('#accountModal').show();
    });

    $('.edit-account').on('click', function () {
        const id = $(this).data('id');

        $.get(`{{ url('settings/integrations/accounts') }}/${id}/edit`).done(a => {
            const $form = $('#accountForm');
            $form[0].reset();
            $('#accountId').val(a.id);
            $form.find('[name=name]').val(a.name);
            $form.find('[name=channel]').val(a.channel);
            $form.find('[name=provider]').val(a.provider);
            $form.find('[name=center_id]').val(a.center_id || '');
            $form.find('[name=identifier]').val(a.identifier || '');
            $form.find('[name=phone_number_id]').val(a.phone_number_id || '');
            $form.find('[name=waba_id]').val(a.waba_id || '');
            $form.find('[name=is_default]').prop('checked', a.is_default);
            $form.find('[name=is_active]').prop('checked', a.is_active);
            $('#accountModalTitle').text('Edit channel account');
            new bootstrap.Modal('#accountModal').show();
        });
    });

    $('#accountForm').on('submit', function (e) {
        e.preventDefault();

        const id = $('#accountId').val();
        const data = $(this).serialize()
            + '&is_default=' + ($('#accIsDefault').is(':checked') ? 1 : 0)
            + '&is_active=' + ($('#accIsActive').is(':checked') ? 1 : 0)
            + (id ? '&_method=PUT' : '');

        $.post(id ? `{{ url('settings/integrations/accounts') }}/${id}` : @json(route('settings.integrations.accounts.store')), data)
            .done(res => {
                toastr.success(res.message);
                setTimeout(() => location.reload(), 700);
            })
            .fail(xhr => toastr.error(xhr.responseJSON?.message || 'Could not save the account.'));
    });

    $('.del-account').on('click', function () {
        const id = $(this).data('id');

        Swal.fire({
            title: 'Delete this channel account?',
            text: 'Conversations already linked to it will keep working using the default sender.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#dc3545',
            confirmButtonText: 'Delete',
        }).then(result => {
            if (!result.isConfirmed) return;

            $.post(`{{ url('settings/integrations/accounts') }}/${id}`, { _method: 'DELETE' })
                .done(res => { toastr.success(res.message); setTimeout(() => location.reload(), 700); })
                .fail(xhr => toastr.error(xhr.responseJSON?.message || 'Delete failed.'));
        });
    });
});
</script>
@endpush
