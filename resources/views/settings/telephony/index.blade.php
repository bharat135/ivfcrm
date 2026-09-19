@extends('layouts.app')

@section('title', 'Telephony')

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">Dashboard</a></li>
    <li class="breadcrumb-item">Settings</li>
    <li class="breadcrumb-item active">Telephony</li>
@endsection

@section('content')

<div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
    <div>
        <h5 class="mb-0">Telephony</h5>
        <small class="text-muted">
            Asterisk PBX, Tata Tele Business Services (Smartflo), agent softphones and WhatsApp calling.
            Active provider: <strong>{{ $provider === 'tata' ? 'Tata Smartflo' : 'Asterisk' }}</strong>.
        </small>
    </div>
    <div class="d-flex gap-2">
        <button class="btn btn-sm btn-outline-primary" id="btnTest" data-provider="{{ $provider }}">
            <i class="bi bi-plug me-1"></i>Test {{ $provider === 'tata' ? 'Smartflo' : 'Asterisk' }}
        </button>
        <button class="btn btn-sm btn-outline-secondary" id="btnSyncReg"><i class="bi bi-arrow-repeat me-1"></i>Refresh registrations</button>
    </div>
</div>

@if($provider !== 'tata' && $unassigned > 0)
    <div class="alert alert-info small">
        <i class="bi bi-info-circle me-1"></i>
        {{ $unassigned }} active user(s) have no SIP extension and cannot place calls from the CRM.
    </div>
@endif

@if($provider === 'tata' && ! $tataConfigured)
    <div class="alert alert-warning small">
        <i class="bi bi-exclamation-triangle me-1"></i>
        Tata Smartflo is the active provider but no API credentials are saved yet. Fill in the Tata Smartflo tab.
    </div>
@endif

<div id="testResults" class="mb-3"></div>

<div class="row g-3">
    {{-- ------------------------------------------------------------ --}}
    {{-- Asterisk + WhatsApp calling                                    --}}
    {{-- ------------------------------------------------------------ --}}
    <div class="col-lg-6">
        <div class="card shadow-sm">
            <div class="card-header bg-white">
                <ul class="nav nav-tabs card-header-tabs">
                    <li class="nav-item"><a class="nav-link {{ $provider === 'tata' ? 'active' : '' }}" data-bs-toggle="tab" href="#tabTata">Tata Smartflo</a></li>
                    <li class="nav-item"><a class="nav-link {{ $provider !== 'tata' ? 'active' : '' }}" data-bs-toggle="tab" href="#tabAmi">Asterisk</a></li>
                    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tabDialplan">Dial plan</a></li>
                    <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tabWaCalling">WhatsApp calling</a></li>
                </ul>
            </div>

            <div class="card-body tab-content">
                {{-- Tata Tele Business Services (Smartflo) --}}
                <div class="tab-pane fade {{ $provider === 'tata' ? 'show active' : '' }}" id="tabTata">
                    @include('settings.telephony._tata')
                </div>

                {{-- AMI --}}
                <div class="tab-pane fade {{ $provider !== 'tata' ? 'show active' : '' }}" id="tabAmi">
                    <form id="telephonyForm" class="js-telephony-form">
                        @csrf
                        @method('PUT')

                        <div class="alert alert-light border small">
                            Create a manager account in <code>/etc/asterisk/manager.conf</code> with
                            <code>read = system,call,verbose</code> and
                            <code>write = system,call,originate</code>, then restrict it to this server's IP.
                        </div>

                        <div class="row g-3 mb-3">
                            <div class="col-8">
                                <label class="form-label">AMI host <span class="text-danger">*</span></label>
                                <input type="text" name="ami_host" class="form-control"
                                       value="{{ $telephony['ami_host'] ?? '' }}" placeholder="192.168.1.10">
                            </div>
                            <div class="col-4">
                                <label class="form-label">Port</label>
                                <input type="number" name="ami_port" class="form-control"
                                       value="{{ $telephony['ami_port'] ?? 5038 }}">
                            </div>
                        </div>

                        <div class="row g-3 mb-3">
                            <div class="col-6">
                                <label class="form-label">AMI username</label>
                                <input type="text" name="ami_username" class="form-control"
                                       value="{{ $telephony['ami_username'] ?? '' }}">
                            </div>
                            <div class="col-6">
                                <label class="form-label">AMI secret</label>
                                <input type="password" name="ami_secret" class="form-control" autocomplete="new-password"
                                       placeholder="{{ ($telephony['ami_secret'] ?? '') ?: 'Enter secret' }}">
                                <div class="form-text">Stored encrypted; leave blank to keep.</div>
                            </div>
                        </div>

                        <div class="row g-3 mb-3">
                            <div class="col-6">
                                <label class="form-label">SIP domain</label>
                                <input type="text" name="sip_domain" class="form-control"
                                       value="{{ $telephony['sip_domain'] ?? '' }}" placeholder="pbx.clinic.com">
                                <div class="form-text">What agents' softphones register to.</div>
                            </div>
                            <div class="col-6">
                                <label class="form-label">Channel technology</label>
                                <select name="technology" class="form-select">
                                    @foreach(['PJSIP', 'SIP', 'IAX2'] as $tech)
                                        <option value="{{ $tech }}" @selected(($telephony['technology'] ?? 'PJSIP') === $tech)>{{ $tech }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>

                        <div class="row g-3 mb-3">
                            <div class="col-6">
                                <label class="form-label">Dialplan context</label>
                                <input type="text" name="context" class="form-control"
                                       value="{{ $telephony['context'] ?? 'from-internal' }}">
                            </div>
                            <div class="col-6">
                                <label class="form-label">Outbound prefix</label>
                                <input type="text" name="outbound_prefix" class="form-control"
                                       value="{{ $telephony['outbound_prefix'] ?? '' }}" placeholder="e.g. 9">
                            </div>
                        </div>

                        <div class="row g-3 mb-3">
                            <div class="col-6">
                                <label class="form-label">Trunk</label>
                                <input type="text" name="trunk" class="form-control"
                                       value="{{ $telephony['trunk'] ?? '' }}" placeholder="my-sip-trunk">
                            </div>
                            <div class="col-6">
                                <label class="form-label">Default caller ID</label>
                                <input type="text" name="caller_id" class="form-control"
                                       value="{{ $telephony['caller_id'] ?? '' }}">
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Recording path on the CRM disk</label>
                            <input type="text" name="recording_path" class="form-control"
                                   value="{{ $telephony['recording_path'] ?? 'call-recordings' }}">
                            <div class="form-text">
                                Mount or sync Asterisk's <code>{{ config('telephony.asterisk.recording.server_path') }}</code>
                                to this path so recordings play back in the CRM.
                            </div>
                        </div>

                        @can('telephony.edit')
                            <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i>Save</button>
                        @endcan
                    </form>
                </div>

                {{-- Dialplan --}}
                <div class="tab-pane fade" id="tabDialplan">
                    <div class="mb-3">
                        <label class="form-label">Webhook base URL</label>
                        <div class="input-group">
                            <input type="text" class="form-control font-monospace" id="webhookUrl" value="{{ $webhookUrl }}" readonly>
                            <button class="btn btn-outline-secondary" id="btnCopyUrl" type="button"><i class="bi bi-clipboard"></i></button>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Webhook token</label>
                        <div class="input-group">
                            <input type="text" name="webhook_token" form="telephonyForm" class="form-control font-monospace"
                                   id="webhookToken" placeholder="{{ ($telephony['webhook_token'] ?? '') ?: 'Generate a token' }}">
                            <button class="btn btn-outline-secondary" id="btnGenToken" type="button">Generate</button>
                        </div>
                        <div class="form-text">
                            Save this on the Asterisk tab, then use it as <code>CRM_TOKEN</code> in the dialplan below.
                        </div>
                    </div>

                    <label class="form-label">Add to <code>/etc/asterisk/extensions.conf</code></label>
                    <pre class="bg-light border rounded p-3 small mb-2" style="max-height:340px;overflow:auto"><code>[globals]
CRM_URL={{ $webhookUrl }}
CRM_TOKEN=&lt;the token above&gt;

; Post one call event to the CRM. ARG1 is the event name.
[macro-crm]
exten =&gt; s,1,Set(CRM_QS=token=${CRM_TOKEN}&amp;channel_id=${UNIQUEID}&amp;linked_id=${LINKEDID})
 same =&gt; n,Set(CRM_QS=${CRM_QS}&amp;channel=${CHANNEL}&amp;from_number=${CALLERID(num)})
 same =&gt; n,Set(CRM_QS=${CRM_QS}&amp;to_number=${EXTEN}&amp;crm_call_id=${CRM_CALL_ID})
 same =&gt; n,Set(CRM_QS=${CRM_QS}&amp;token_var=${CRM_TOKEN_VAR}&amp;hangup_cause=${HANGUPCAUSE})
 same =&gt; n,Set(CRM_QS=${CRM_QS}&amp;billsec=${CDR(billsec)}&amp;duration=${CDR(duration)})
 same =&gt; n,Set(CURL_RESULT=${CURL(${CRM_URL}/${ARG1},${CRM_QS})})
 same =&gt; n,Return()

; --- Outbound (click-to-call lands here after the agent answers) ---
[from-internal]
exten =&gt; _X.,1,NoOp(CRM outbound ${EXTEN})
 same =&gt; n,Set(CDR(userfield)=${CRM_CALL_ID})
 same =&gt; n,ExecIf($["${CRM_RECORD}" = "1"]?MixMonitor(${UNIQUEID}.wav,b))
 same =&gt; n,Macro(crm,ringing)
 same =&gt; n,Dial(PJSIP/${EXTEN}@my-sip-trunk,45,U(crm-answered))
 same =&gt; n,Macro(crm,hangup)
 same =&gt; n,Hangup()

; Runs the moment the far end answers.
[crm-answered]
exten =&gt; s,1,Macro(crm,answered)
 same =&gt; n,Return()

; --- Inbound ---
[from-trunk]
exten =&gt; _X.,1,NoOp(CRM inbound from ${CALLERID(num)})
 same =&gt; n,Set(CRM_RESPONSE=${CURL(${CRM_URL}/inbound,token=${CRM_TOKEN}&amp;channel_id=${UNIQUEID}&amp;linked_id=${LINKEDID}&amp;from_number=${CALLERID(num)}&amp;to_number=${EXTEN})})
 same =&gt; n,Set(AGENT=${JSONPRETTY(${CRM_RESPONSE},agent_extension)})
 same =&gt; n,MixMonitor(${UNIQUEID}.wav,b)
 same =&gt; n,ExecIf($["${AGENT}" != ""]?Dial(PJSIP/${AGENT},30,U(crm-answered)))
 same =&gt; n,Queue(support,t,,,30)
 same =&gt; n,Macro(crm,hangup)
 same =&gt; n,Hangup()

; --- Registration state (optional but useful) ---
; In pjsip.conf set: contact_user=${EXTEN}
; and add a hook that POSTs to ${CRM_URL}/registration</code></pre>

                    <div class="alert alert-warning small mb-0">
                        <i class="bi bi-shield-lock me-1"></i>
                        Restrict <code>/api/webhooks/asterisk/*</code> to the PBX's IP at your firewall or reverse proxy.
                        The token is the second line of defence, not the first.
                    </div>
                </div>

                {{-- WhatsApp calling --}}
                <div class="tab-pane fade" id="tabWaCalling">
                    <div class="alert alert-light border small">
                        With a SIP server configured, Meta delivers WhatsApp voice calls straight to your Asterisk,
                        so they ring the agent's MicroSIP extension through the normal dial plan — no browser
                        or media gateway needed.
                    </div>

                    <div id="waCallingStatus" class="mb-3">
                        <button class="btn btn-sm btn-outline-secondary" id="btnCheckWa">
                            <i class="bi bi-search me-1"></i>Check current status
                        </button>
                    </div>

                    <form id="waCallingForm">
                        <div class="row g-3 mb-3">
                            <div class="col-8">
                                <label class="form-label">Asterisk SIP hostname (public, TLS)</label>
                                <input type="text" name="sip_hostname" class="form-control"
                                       value="{{ $telephony['whatsapp_sip_hostname'] ?? '' }}"
                                       placeholder="pbx.clinic.com">
                            </div>
                            <div class="col-4">
                                <label class="form-label">SIP port</label>
                                <input type="number" name="sip_port" class="form-control"
                                       value="{{ $telephony['whatsapp_sip_port'] ?? 5061 }}">
                            </div>
                        </div>

                        <div class="row g-3 mb-3">
                            <div class="col-6">
                                <label class="form-label">Call hours open</label>
                                <input type="time" name="open_time" class="form-control" value="09:00">
                            </div>
                            <div class="col-6">
                                <label class="form-label">Call hours close</label>
                                <input type="time" name="close_time" class="form-control" value="19:00">
                            </div>
                        </div>

                        <div class="form-check form-switch mb-3">
                            <input class="form-check-input" type="checkbox" name="call_hours" value="1" id="waCallHours" checked>
                            <label class="form-check-label" for="waCallHours">
                                Only show the call button during those hours
                            </label>
                        </div>

                        @can('telephony.edit')
                            <button type="submit" class="btn btn-success">
                                <i class="bi bi-whatsapp me-1"></i>Apply to WhatsApp
                            </button>
                        @endcan
                    </form>
                </div>
            </div>
        </div>
    </div>

    {{-- ------------------------------------------------------------ --}}
    {{-- Extensions                                                     --}}
    {{-- ------------------------------------------------------------ --}}
    <div class="col-lg-6">
        <div class="card shadow-sm">
            <div class="card-header bg-white d-flex align-items-center">
                <span class="fw-semibold me-auto"><i class="bi bi-telephone-plus me-2"></i>SIP extensions</span>
                @can('telephony.create')
                    <button class="btn btn-sm btn-primary" id="btnAddExt"><i class="bi bi-plus-lg"></i></button>
                @endcan
            </div>

            <div class="table-responsive" style="max-height:640px;overflow-y:auto">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light sticky-top">
                        <tr><th>Ext</th><th>Agent</th><th>Registration</th><th class="text-end">Actions</th></tr>
                    </thead>
                    <tbody>
                        @forelse($extensions as $ext)
                            <tr>
                                <td>
                                    <span class="fw-semibold">{{ $ext->extension }}</span>
                                    <br><small class="text-muted">{{ $ext->technology }} · {{ strtoupper($ext->transport) }}</small>
                                </td>
                                <td>
                                    {{ $ext->user?->name ?? '—' }}
                                    @if($ext->center)<br><small class="text-muted">{{ $ext->center->name }}</small>@endif
                                    @unless($ext->is_active)<span class="badge bg-secondary ms-1">Disabled</span>@endunless
                                </td>
                                <td>
                                    <span class="badge bg-{{ $ext->registrationBadge() }}">
                                        {{ $ext->registration_status ?: 'Unknown' }}
                                    </span>
                                    @if($ext->last_registered_at)
                                        <br><small class="text-muted">{{ $ext->last_registered_at->diffForHumans() }}</small>
                                    @endif
                                </td>
                                <td class="text-end">
                                    <div class="btn-group btn-group-sm">
                                        <a href="{{ route('settings.telephony.extensions.microsip', $ext) }}"
                                           class="btn btn-outline-success" title="Download MicroSIP profile">
                                            <i class="bi bi-download"></i>
                                        </a>
                                        <button class="btn btn-outline-secondary js-pbx" data-id="{{ $ext->id }}"
                                                title="Show pjsip.conf block">
                                            <i class="bi bi-code-slash"></i>
                                        </button>
                                        @can('telephony.edit')
                                            <button class="btn btn-outline-primary js-edit-ext" data-id="{{ $ext->id }}" title="Edit">
                                                <i class="bi bi-pencil"></i>
                                            </button>
                                        @endcan
                                        @can('telephony.delete')
                                            <button class="btn btn-outline-danger js-del-ext" data-id="{{ $ext->id }}" title="Delete">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        @endcan
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="text-center text-muted py-4">
                                No extensions yet. Add one per telecaller so they can place calls.
                            </td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

{{-- Extension modal --}}
<div class="modal fade" id="extModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form id="extForm">
                <div class="modal-header">
                    <h5 class="modal-title" id="extModalTitle">Add SIP extension</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" id="extId">

                    <div class="row g-3 mb-3">
                        <div class="col-5">
                            <label class="form-label">Extension <span class="text-danger">*</span></label>
                            <input type="text" name="extension" class="form-control" required
                                   pattern="[0-9]+" placeholder="1001">
                        </div>
                        <div class="col-7">
                            <label class="form-label">Agent</label>
                            <select name="user_id" class="form-select">
                                <option value="">Unassigned</option>
                                @foreach($agents as $agent)
                                    <option value="{{ $agent->id }}">{{ $agent->name }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-6">
                            <label class="form-label">Centre</label>
                            <select name="center_id" class="form-select">
                                <option value="">All centres</option>
                                @foreach($centers as $center)
                                    <option value="{{ $center->id }}">{{ $center->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-6">
                            <label class="form-label">Caller ID</label>
                            <input type="text" name="caller_id" class="form-control" placeholder="Clinic front desk">
                        </div>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-6">
                            <label class="form-label">SIP secret</label>
                            <input type="text" name="password" class="form-control font-monospace"
                                   placeholder="Auto-generated if blank">
                        </div>
                        <div class="col-3">
                            <label class="form-label">Transport</label>
                            <select name="transport" class="form-select">
                                @foreach(['udp', 'tcp', 'tls', 'wss'] as $t)
                                    <option value="{{ $t }}">{{ strtoupper($t) }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-3">
                            <label class="form-label">Port</label>
                            <input type="number" name="port" class="form-control" value="5060">
                        </div>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-6">
                            <label class="form-label">SIP domain</label>
                            <input type="text" name="domain" class="form-control"
                                   placeholder="{{ $telephony['sip_domain'] ?? $telephony['ami_host'] ?? 'pbx host' }}">
                        </div>
                        <div class="col-6">
                            <label class="form-label">Context</label>
                            <input type="text" name="context" class="form-control"
                                   value="{{ $telephony['context'] ?? 'from-internal' }}">
                        </div>
                    </div>

                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" name="record_calls" value="1" id="extRecord" checked>
                        <label class="form-check-label" for="extRecord">Record this extension's calls</label>
                    </div>
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" name="webrtc" value="1" id="extWebrtc">
                        <label class="form-check-label" for="extWebrtc">
                            Browser softphone (WebRTC) instead of MicroSIP
                        </label>
                    </div>
                    <div class="form-check form-switch">
                        <input class="form-check-input" type="checkbox" name="is_active" value="1" id="extActive" checked>
                        <label class="form-check-label" for="extActive">Active</label>
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

{{-- PBX config modal --}}
<div class="modal fade" id="pbxModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">PBX configuration block</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="small text-muted">Paste into <code>pjsip.conf</code> (or <code>sip.conf</code>), then run <code>pjsip reload</code>.</p>
                <pre class="bg-light border rounded p-3 small" id="pbxConfig" style="max-height:400px;overflow:auto"></pre>
            </div>
            <div class="modal-footer">
                <button class="btn btn-outline-secondary" id="btnCopyPbx"><i class="bi bi-clipboard me-1"></i>Copy</button>
                <button class="btn btn-light" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
$(function () {
    // --- Save settings (Asterisk + Tata forms share one endpoint) ---------
    $('.js-telephony-form').on('submit', function (e) {
        e.preventDefault();

        const $btn = $(this).find('[type=submit]').prop('disabled', true);

        $.ajax({ url: @json(route('settings.telephony.update')), method: 'POST', data: $(this).serialize() })
            .done(res => { toastr.success(res.message); setTimeout(() => location.reload(), 800); })
            .fail(xhr => toastr.error(xhr.responseJSON?.message || 'Could not save.'))
            .always(() => $btn.prop('disabled', false));
    });

    // --- Test / sync -----------------------------------------------------
    $('#btnTest').on('click', function () {
        const label = $(this).html();
        const $btn = $(this).prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span>Testing…');
        $('#testResults').empty();

        $.post(@json(route('settings.telephony.test')), { provider: $(this).data('provider') })
            .done(res => {
                const rows = Object.entries(res.results).map(([k, r]) => `
                    <div class="d-flex align-items-start gap-2 py-1">
                        <i class="bi ${r.ok ? 'bi-check-circle-fill text-success' : 'bi-x-circle-fill text-danger'}"></i>
                        <div><strong class="text-capitalize">${k}:</strong> ${$('<div>').text(r.message).html()}</div>
                    </div>`).join('');

                $('#testResults').html(`<div class="card shadow-sm"><div class="card-body py-2">${rows}</div></div>`);
            })
            .fail(() => toastr.error('Test failed.'))
            .always(() => $btn.prop('disabled', false).html(label));
    });

    // --- Tata Smartflo helpers -------------------------------------------
    $('#btnCopyTataUrl').on('click', () => {
        navigator.clipboard.writeText($('#tataWebhookUrl').val());
        toastr.success('Webhook URL copied.');
    });

    $('#btnGenTataToken').on('click', function () {
        const bytes = new Uint8Array(24);
        crypto.getRandomValues(bytes);
        $('#tataWebhookToken').val(Array.from(bytes, b => b.toString(16).padStart(2, '0')).join(''));
        $('#tataWebhookUrl').val($('#tataWebhookUrl').data('base') + '/hangup?token=' + $('#tataWebhookToken').val());
        toastr.info('Token generated — save, then paste the webhook URL into the Smartflo portal.');
    });

    $('#btnTataAgents').on('click', function () {
        const $btn = $(this).prop('disabled', true);
        const $out = $('#tataAgentList').html('<div class="text-muted small py-2"><span class="spinner-border spinner-border-sm me-1"></span>Fetching agents from Smartflo…</div>');

        $.get(@json(route('settings.telephony.tata_agents')))
            .done(res => {
                if (!res.agents.length) return $out.html('<div class="text-muted small py-2">Smartflo returned no users.</div>');
                const esc = s => $('<div>').text(s ?? '').html();
                const rows = res.agents.map(a => `
                    <tr>
                        <td>${esc(a.name)}</td>
                        <td class="font-monospace">${esc(a.agent_number || '—')}</td>
                        <td class="font-monospace">${esc(a.extension || '—')}</td>
                        <td>${a.crm_user ? '<span class="badge bg-success-subtle text-success">' + esc(a.crm_user) + '</span>' : '<span class="text-muted">not mapped</span>'}</td>
                    </tr>`).join('');
                $out.html(`<div class="table-responsive"><table class="table table-sm align-middle mb-0">
                    <thead><tr><th>Smartflo user</th><th>Agent number</th><th>Extension</th><th>CRM user</th></tr></thead>
                    <tbody>${rows}</tbody></table></div>`);
            })
            .fail(xhr => $out.html('<div class="text-danger small py-2">' + $('<div>').text(xhr.responseJSON?.message || 'Could not fetch agents.').html() + '</div>'))
            .always(() => $btn.prop('disabled', false));
    });

    $('#btnSyncReg').on('click', function () {
        const $btn = $(this).prop('disabled', true);

        $.post(@json(route('settings.telephony.sync_registrations')))
            .done(res => { toastr.success(res.message); setTimeout(() => location.reload(), 800); })
            .fail(xhr => toastr.error(xhr.responseJSON?.message || 'Sync failed.'))
            .always(() => $btn.prop('disabled', false));
    });

    // --- Dialplan helpers -------------------------------------------------
    $('#btnCopyUrl').on('click', () => {
        navigator.clipboard.writeText($('#webhookUrl').val());
        toastr.success('Webhook URL copied.');
    });

    $('#btnGenToken').on('click', function () {
        const bytes = new Uint8Array(24);
        crypto.getRandomValues(bytes);
        $('#webhookToken').val(Array.from(bytes, b => b.toString(16).padStart(2, '0')).join(''));
        toastr.info('Token generated — save on the Asterisk tab, then copy it into the dial plan.');
    });

    // --- WhatsApp calling -------------------------------------------------
    $('#btnCheckWa').on('click', function () {
        const $btn = $(this).prop('disabled', true);

        $.get(@json(route('settings.telephony.whatsapp_status')))
            .done(res => {
                $('#waCallingStatus').html(`
                    <div class="alert alert-${res.sip ? 'success' : 'secondary'} small mb-0">
                        <i class="bi bi-${res.sip ? 'check-circle' : 'info-circle'} me-1"></i>
                        Calling status: <strong>${$('<div>').text(res.settings?.status || 'unknown').html()}</strong>
                        · SIP routing: <strong>${res.sip ? 'enabled' : 'disabled'}</strong>
                    </div>`);
            })
            .fail(xhr => $('#waCallingStatus').html(
                `<div class="alert alert-warning small mb-0">${$('<div>').text(xhr.responseJSON?.message || 'Could not read settings.').html()}</div>`
            ))
            .always(() => $btn.prop('disabled', false));
    });

    $('#waCallingForm').on('submit', function (e) {
        e.preventDefault();

        const $btn = $(this).find('[type=submit]').prop('disabled', true);

        $.post(@json(route('settings.telephony.whatsapp_enable')), $(this).serialize())
            .done(res => res.success ? toastr.success(res.message) : toastr.error(res.message))
            .fail(xhr => toastr.error(xhr.responseJSON?.message || 'Could not apply the settings.'))
            .always(() => $btn.prop('disabled', false));
    });

    // --- Extensions -------------------------------------------------------
    $('#btnAddExt').on('click', function () {
        $('#extForm')[0].reset();
        $('#extId').val('');
        $('#extModalTitle').text('Add SIP extension');
        new bootstrap.Modal('#extModal').show();
    });

    $('.js-edit-ext').on('click', function () {
        const id = $(this).data('id');

        $.get(`{{ url('settings/telephony/extensions') }}/${id}/edit`).done(e => {
            const $f = $('#extForm');
            $f[0].reset();
            $('#extId').val(e.id);
            $f.find('[name=extension]').val(e.extension);
            $f.find('[name=user_id]').val(e.user_id || '');
            $f.find('[name=center_id]').val(e.center_id || '');
            $f.find('[name=caller_id]').val(e.caller_id || '');
            $f.find('[name=domain]').val(e.domain || '');
            $f.find('[name=context]').val(e.context || '');
            $f.find('[name=transport]').val(e.transport || 'udp');
            $f.find('[name=port]').val(e.port || 5060);
            $f.find('[name=record_calls]').prop('checked', e.record_calls);
            $f.find('[name=webrtc]').prop('checked', e.webrtc);
            $f.find('[name=is_active]').prop('checked', e.is_active);
            $('#extModalTitle').text('Edit extension ' + e.extension);
            new bootstrap.Modal('#extModal').show();
        });
    });

    $('#extForm').on('submit', function (e) {
        e.preventDefault();

        const id = $('#extId').val();
        const data = $(this).serialize()
            + '&record_calls=' + ($('#extRecord').is(':checked') ? 1 : 0)
            + '&webrtc=' + ($('#extWebrtc').is(':checked') ? 1 : 0)
            + '&is_active=' + ($('#extActive').is(':checked') ? 1 : 0)
            + (id ? '&_method=PUT' : '');

        $.post(id ? `{{ url('settings/telephony/extensions') }}/${id}`
                  : @json(route('settings.telephony.extensions.store')), data)
            .done(res => { toastr.success(res.message); setTimeout(() => location.reload(), 700); })
            .fail(xhr => {
                const errors = xhr.responseJSON?.errors;
                if (errors) return Object.values(errors).flat().forEach(m => toastr.error(m));
                toastr.error(xhr.responseJSON?.message || 'Could not save the extension.');
            });
    });

    $('.js-del-ext').on('click', function () {
        const id = $(this).data('id');

        Swal.fire({
            title: 'Delete this extension?',
            text: 'The agent will no longer be able to place calls from the CRM.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#dc3545',
        }).then(result => {
            if (!result.isConfirmed) return;

            $.post(`{{ url('settings/telephony/extensions') }}/${id}`, { _method: 'DELETE' })
                .done(res => { toastr.success(res.message); setTimeout(() => location.reload(), 700); })
                .fail(() => toastr.error('Delete failed.'));
        });
    });

    $('.js-pbx').on('click', function () {
        const id = $(this).data('id');

        $.get(`{{ url('settings/telephony/extensions') }}/${id}/pbx-config`).done(res => {
            $('#pbxConfig').text(res.config);
            new bootstrap.Modal('#pbxModal').show();
        }).fail(() => toastr.error('Only administrators can view PBX configuration.'));
    });

    $('#btnCopyPbx').on('click', () => {
        navigator.clipboard.writeText($('#pbxConfig').text());
        toastr.success('Configuration copied.');
    });
});
</script>
@endpush
