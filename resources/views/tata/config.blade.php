@extends('layouts.app')

@section('title', 'Tata Configuration')

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">Dashboard</a></li>
    <li class="breadcrumb-item"><a href="{{ route('tata.index') }}">Tata Teleservices</a></li>
    <li class="breadcrumb-item active">Configuration</li>
@endsection

@section('content')
<div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
    <h5 class="mb-0">Configuration</h5>
    <button class="btn btn-sm btn-outline-primary" id="btnTest"><i class="bi bi-plug me-1"></i>Test connection</button>
</div>

@include('tata._nav')

<div id="testResults" class="mb-3"></div>

<form id="tataConfigForm">
    @csrf
    @method('PUT')
    <div class="row g-3">
        {{-- Credentials --}}
        <div class="col-lg-6">
            <div class="card shadow-sm h-100">
                <div class="card-header bg-white fw-semibold">Smartflo API</div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label d-block">Active calling provider</label>
                        <div class="btn-group" role="group">
                            <input type="radio" class="btn-check" name="provider" id="pA" value="asterisk" @checked($provider !== 'tata')>
                            <label class="btn btn-outline-secondary btn-sm" for="pA">Asterisk</label>
                            <input type="radio" class="btn-check" name="provider" id="pT" value="tata" @checked($provider === 'tata')>
                            <label class="btn btn-outline-secondary btn-sm" for="pT">Tata Smartflo</label>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">API token <span class="text-muted">(recommended)</span></label>
                        <input type="password" name="tata_access_token" class="form-control font-monospace" autocomplete="new-password"
                               placeholder="{{ ($telephony['tata_access_token'] ?? '') ?: 'Smartflo → API Connect → API Token' }}">
                        <div class="form-text">Encrypted. Leave blank to keep the saved token.</div>
                    </div>
                    <div class="row g-2 mb-2">
                        <div class="col-6">
                            <label class="form-label">Login id / email</label>
                            <input type="text" name="tata_login_id" id="tataEmail" class="form-control" value="{{ $telephony['tata_login_id'] ?? '' }}" placeholder="Smartflo login id">
                        </div>
                        <div class="col-6">
                            <label class="form-label">Password</label>
                            <input type="password" name="tata_password" id="tataPassword" class="form-control" autocomplete="new-password"
                                   placeholder="{{ ($telephony['tata_password'] ?? '') ?: 'Smartflo password' }}">
                        </div>
                    </div>
                    <div class="d-flex align-items-center gap-2 mb-3">
                        <button type="button" class="btn btn-sm btn-outline-primary" id="btnGenTokenLogin">
                            <i class="bi bi-key me-1"></i>Generate token from email &amp; password
                        </button>
                        <span class="small text-muted" id="tokenGenHint">
                            @if($telephony['tata_access_token'] ?? false)
                                Token saved{{ $tokenExpiresAt ? ', renews automatically (expires ' . $tokenExpiresAt->diffForHumans() . ')' : '' }}.
                                {{ $passwordDaysLeft !== null ? 'Password valid for ' . $passwordDaysLeft . ' more day(s).' : '' }}
                            @else No token yet.@endif
                        </span>
                    </div>
                    <div class="row g-2">
                        <div class="col-7">
                            <label class="form-label">Default caller ID (DID)</label>
                            <input type="text" name="tata_caller_id" class="form-control font-monospace" value="{{ $telephony['tata_caller_id'] ?? '' }}" placeholder="02240000000">
                            <div class="form-text">Per-centre DIDs on the Numbers tab override this.</div>
                        </div>
                        <div class="col-5">
                            <label class="form-label">Max call (sec)</label>
                            <input type="number" name="tata_call_timeout" class="form-control" min="0" max="7200" value="{{ $telephony['tata_call_timeout'] ?? 0 }}">
                        </div>
                    </div>
                    <div class="mt-3">
                        <label class="form-label d-block">Ring the agent's first leg on</label>
                        <div class="btn-group" role="group">
                            <input type="radio" class="btn-check" name="tata_dial_via" id="dvMobile" value="mobile" @checked(($telephony['tata_dial_via'] ?? 'mobile') !== 'softphone')>
                            <label class="btn btn-outline-secondary btn-sm" for="dvMobile"><i class="bi bi-phone me-1"></i>Mobile</label>
                            <input type="radio" class="btn-check" name="tata_dial_via" id="dvSoft" value="softphone" @checked(($telephony['tata_dial_via'] ?? 'mobile') === 'softphone')>
                            <label class="btn btn-outline-secondary btn-sm" for="dvSoft"><i class="bi bi-headset me-1"></i>Smartflo softphone</label>
                        </div>
                        <div class="form-text">Softphone rings the agent's Smartflo extension (login-based calling) instead of their mobile.</div>
                    </div>
                    @php $callOrder = $telephony['tata_call_order'] ?? 'agent_first'; @endphp
                    <div class="mt-3">
                        <label class="form-label d-block">Who is dialled first</label>
                        <div class="btn-group" role="group">
                            <input type="radio" class="btn-check" name="tata_call_order" id="coAgent" value="agent_first" @checked($callOrder !== 'customer_first')>
                            <label class="btn btn-outline-secondary btn-sm" for="coAgent"><i class="bi bi-headset me-1"></i>Agent first</label>
                            <input type="radio" class="btn-check" name="tata_call_order" id="coCustomer" value="customer_first" @checked($callOrder === 'customer_first')>
                            <label class="btn btn-outline-secondary btn-sm" for="coCustomer"><i class="bi bi-person-check me-1"></i>Customer first</label>
                        </div>
                        <div class="form-text">
                            <strong>Agent first</strong> is Smartflo's ordinary click-to-call: the agent's phone rings, they pick up, then the customer is dialled.
                            <strong>Customer first</strong> dials the customer straight away and only rings the agent once the customer has answered, so nobody waits through ringing or no-answers.
                            To skip the pick-up entirely, turn on <em>Auto Answer</em> under Preferences → Permissions in the Smartflo softphone app.
                        </div>
                    </div>
                    <div class="mt-2" id="c2cSupportKeyWrap">
                        <label class="form-label">Click-to-Call Support API key <span class="text-muted fw-normal">(customer first)</span></label>
                        <input type="text" name="tata_c2c_support_key" class="form-control font-monospace" value="{{ $telephony['tata_c2c_support_key'] ?? '' }}" placeholder="From Smartflo → API Connect → Click to Call Support" autocomplete="off">
                        <div class="form-text">
                            Create it in the Smartflo portal with the destination set to the agent, department or IVR that should ring after the customer answers.
                            A key saved on an agent's profile (Settings → Users) overrides this account-wide one, so each agent can be their own destination.
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- Webhook + behaviour --}}
        <div class="col-lg-6">
            <div class="card shadow-sm mb-3">
                <div class="card-header bg-white fw-semibold">Call events (webhook)</div>
                <div class="card-body">
                    <div class="mb-3">
                        <label class="form-label">Webhook token</label>
                        <div class="input-group">
                            <input type="text" name="tata_webhook_token" class="form-control font-monospace" id="hookToken"
                                   placeholder="{{ ($telephony['tata_webhook_token'] ?? '') ?: 'Generate a token' }}">
                            <button class="btn btn-outline-secondary" id="btnGenTok" type="button">Generate</button>
                        </div>
                    </div>
                    <label class="form-label">Webhook URL (paste into Smartflo → API Connect → Webhook)</label>
                    <div class="input-group mb-2">
                        <input type="text" class="form-control font-monospace" id="hookUrl" value="{{ $webhookUrl }}/hangup?token=…" readonly>
                        <button class="btn btn-outline-secondary" id="btnCopyHook" type="button"><i class="bi bi-clipboard"></i></button>
                    </div>
                    <div class="form-text">
                        Create webhooks for <code>Call hangup (Missed or Answered)</code> → <code>{{ $webhookUrl }}/hangup</code>,
                        <code>Call answered by Agent</code> → <code>{{ $webhookUrl }}/answered</code>,
                        and <code>Call received on Server</code> → <code>{{ $webhookUrl }}/inbound</code>. POST, JSON, IST.
                    </div>
                </div>
            </div>

            <div class="card shadow-sm">
                <div class="card-header bg-white fw-semibold">Call handling</div>
                <div class="card-body">
                    <div class="form-check form-switch mb-3">
                        <input type="hidden" name="force_disposition_present" value="1">
                        <input class="form-check-input" type="checkbox" name="force_disposition" id="fd" value="1" @checked($forceDisposition)>
                        <label class="form-check-label" for="fd">Require an outcome on every call before the agent can dial again</label>
                    </div>
                    <div class="form-check form-switch mb-3">
                        <input type="hidden" name="transcription_enabled_present" value="1">
                        <input class="form-check-input" type="checkbox" name="transcription_enabled" id="tr" value="1" @checked($transcription['enabled'])>
                        <label class="form-check-label" for="tr">Live transcript during the call (agent's browser)</label>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Transcript language</label>
                        <input type="text" name="transcription_language" class="form-control" style="max-width:160px" value="{{ $transcription['language'] }}" placeholder="en-IN">
                        <div class="form-text">BCP-47 tag. en-IN handles Hinglish; hi-IN, mr-IN, etc.</div>
                    </div>
                    <div class="form-check form-switch mb-3">
                        <input type="hidden" name="ai_summary_enabled_present" value="1">
                        <input class="form-check-input" type="checkbox" name="ai_summary_enabled" id="ai" value="1" @checked($aiEnabled)>
                        <label class="form-check-label" for="ai">Draft the call note from the transcript with Claude</label>
                    </div>
                    <div class="mb-0">
                        <label class="form-label">Anthropic API key</label>
                        <input type="password" name="anthropic_api_key" class="form-control font-monospace" autocomplete="new-password"
                               placeholder="{{ $aiKeySet ? '••••••••  (saved)' : 'sk-ant-… (optional)' }}">
                        <div class="form-text">Without a key the note falls back to an extractive summary. Model: {{ $aiModel }}.</div>
                    </div>
                </div>
            </div>

            <div class="card shadow-sm mt-3">
                <div class="card-header bg-white fw-semibold">In-browser calling (WebRTC softphone)</div>
                <div class="card-body">
                    <div class="alert alert-light border small mb-3">
                        By default Smartflo click-to-call rings the agent's phone first, then the customer.
                        To place calls entirely in the browser, enable this and enter the Smartflo WebRTC (SIP-over-WSS)
                        endpoint. Ask Smartflo support to enable WebRTC / browser calling and give you the WSS server,
                        SIP domain and the agent extension credentials.
                    </div>
                    <div class="form-check form-switch mb-3">
                        <input type="hidden" name="softphone_enabled_present" value="1">
                        <input class="form-check-input" type="checkbox" name="softphone_enabled" id="sp" value="1" @checked($telephony['softphone_enabled'] ?? false)>
                        <label class="form-check-label" for="sp">Enable the in-browser softphone (agents dial from the web panel)</label>
                    </div>
                    <div class="row g-2">
                        <div class="col-md-7">
                            <label class="form-label">SIP WSS server</label>
                            <input type="text" name="sip_wss_server" class="form-control font-monospace" value="{{ $telephony['sip_wss_server'] ?? '' }}" placeholder="wss://webrtc.smartflo.example:8089/ws">
                        </div>
                        <div class="col-md-5">
                            <label class="form-label">SIP domain / realm</label>
                            <input type="text" name="sip_realm" class="form-control font-monospace" value="{{ $telephony['sip_realm'] ?? '' }}" placeholder="webrtc.smartflo.example">
                        </div>
                        <div class="col-md-7">
                            <label class="form-label">STUN / TURN server</label>
                            <input type="text" name="sip_stun" class="form-control font-monospace" value="{{ $telephony['sip_stun'] ?? '' }}" placeholder="stun:stun.l.google.com:19302">
                        </div>
                        <div class="col-md-5">
                            <label class="form-label">Outbound prefix (optional)</label>
                            <input type="text" name="sip_dial_prefix" class="form-control font-monospace" value="{{ $telephony['sip_dial_prefix'] ?? '' }}" placeholder="e.g. 0">
                        </div>
                    </div>
                    <div class="form-text mt-2">
                        Each agent then saves their own SIP extension + password on the
                        <a href="{{ route('tata.softphone.index') }}">Web Softphone</a> page.
                    </div>
                </div>
            </div>
        </div>
    </div>

    @can('tata.edit')
        <div class="mt-3"><button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i>Save configuration</button></div>
    @endcan
</form>
@endsection

@push('scripts')
<script>
$(function () {
    $('#tataConfigForm').on('submit', function (e) {
        e.preventDefault();
        const $b = $(this).find('[type=submit]').prop('disabled', true);
        $.ajax({ url: @json(route('tata.config.update')), method: 'POST', data: $(this).serialize() })
            .done(res => { toastr.success(res.message); setTimeout(() => location.reload(), 900); })
            .fail(xhr => {
                const err = xhr.responseJSON?.errors;
                toastr.error(err ? Object.values(err)[0][0] : (xhr.responseJSON?.message || 'Could not save.'));
            })
            .always(() => $b.prop('disabled', false));
    });

    $('#btnGenTok').on('click', function () {
        const b = new Uint8Array(24); crypto.getRandomValues(b);
        const tok = Array.from(b, x => x.toString(16).padStart(2, '0')).join('');
        $('#hookToken').val(tok);
        $('#hookUrl').val(@json($webhookUrl) + '/hangup?token=' + tok);
        toastr.info('Token generated — save, then copy the URL into Smartflo.');
    });
    $('#btnCopyHook').on('click', () => { navigator.clipboard.writeText($('#hookUrl').val()); toastr.success('Copied.'); });

    $('#btnGenTokenLogin').on('click', function () {
        const $b = $(this).prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span>Generating…');
        $.post(@json(route('tata.config.generate_token')), {
            _token: @json(csrf_token()),
            tata_login_id: $('#tataEmail').val(),
            tata_password: $('#tataPassword').val(),
        })
        .done(res => {
            toastr.success(res.message);
            $('#tokenGenHint').text('Token saved (' + (res.token_hint || 'set') + ').');
            // Reflect the saved token in the API-token field placeholder and clear the typed password.
            $('input[name=tata_access_token]').attr('placeholder', res.token_hint || 'saved').val('');
            $('#tataPassword').val('');
        })
        .fail(xhr => toastr.error(xhr.responseJSON?.message || 'Could not generate a token.'))
        .always(() => $b.prop('disabled', false).html('<i class="bi bi-key me-1"></i>Generate token from email &amp; password'));
    });

    $('#btnTest').on('click', function () {
        const $b = $(this).prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span>Testing…');
        $('#testResults').empty();
        $.post(@json(route('tata.config.test')))
            .done(res => {
                const rows = Object.entries(res.results).map(([k, r]) => `
                    <div class="d-flex align-items-start gap-2 py-1">
                        <i class="bi ${r.ok ? 'bi-check-circle-fill text-success' : 'bi-x-circle-fill text-danger'}"></i>
                        <div><strong class="text-capitalize">${k.replace('_',' ')}:</strong> ${$('<div>').text(r.message).html()}</div>
                    </div>`).join('');
                $('#testResults').html(`<div class="card shadow-sm"><div class="card-body py-2">${rows}</div></div>`);
            })
            .fail(() => toastr.error('Test failed.'))
            .always(() => $b.prop('disabled', false).html('<i class="bi bi-plug me-1"></i>Test connection'));
    });
});
</script>
@endpush
