{{--
    Settings → Telephony → Tata Smartflo tab.

    Tata Tele Business Services' hosted PBX. Click-to-call rings the agent's
    registered mobile / Smartflo extension first, then bridges the customer;
    call events come back on /api/webhooks/tata configured in the Smartflo
    portal (API Connect → Webhook).
--}}
@php
    $tataTokenSet = ! empty($telephony['tata_webhook_token']);
    $tataHookBase = $tataWebhookUrl;
@endphp

<form id="tataForm" class="js-telephony-form">
    @csrf
    @method('PUT')

    <div class="alert alert-light border small">
        Log in to the Smartflo portal, open <strong>API Connect → API Token</strong> and generate a token for this CRM,
        or use a Smartflo login id and password (the CRM signs in and refreshes the one-hour token itself).
        Agents are rung on the number saved as their <em>Tata Smartflo agent number</em> under Settings → Users
        (their profile phone is used when that is blank).
    </div>

    <div class="mb-3">
        <label class="form-label d-block">Active calling provider</label>
        <div class="btn-group" role="group">
            <input type="radio" class="btn-check" name="provider" id="provAsterisk" value="asterisk" @checked($provider !== 'tata')>
            <label class="btn btn-outline-secondary btn-sm" for="provAsterisk"><i class="bi bi-hdd-network me-1"></i>Asterisk</label>

            <input type="radio" class="btn-check" name="provider" id="provTata" value="tata" @checked($provider === 'tata')>
            <label class="btn btn-outline-secondary btn-sm" for="provTata"><i class="bi bi-cloud me-1"></i>Tata Smartflo</label>
        </div>
        <div class="form-text">Applies to every click-to-call button and the dialer immediately after saving.</div>
    </div>

    <div class="mb-3">
        <label class="form-label">API token (recommended)</label>
        <input type="password" name="tata_access_token" class="form-control font-monospace" autocomplete="new-password"
               placeholder="{{ ($telephony['tata_access_token'] ?? '') ?: 'Paste the token generated in Smartflo → API Connect' }}">
        <div class="form-text">Stored encrypted; leave blank to keep the saved token.</div>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-6">
            <label class="form-label">Login id / email (alternative)</label>
            <input type="text" name="tata_login_id" class="form-control"
                   value="{{ $telephony['tata_login_id'] ?? '' }}" placeholder="Smartflo login id">
        </div>
        <div class="col-6">
            <label class="form-label">Password</label>
            <input type="password" name="tata_password" class="form-control" autocomplete="new-password"
                   placeholder="{{ ($telephony['tata_password'] ?? '') ?: 'Smartflo password' }}">
            <div class="form-text">Only needed when no API token is set.</div>
        </div>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-7">
            <label class="form-label">Caller ID (DID)</label>
            <input type="text" name="tata_caller_id" class="form-control font-monospace"
                   value="{{ $telephony['tata_caller_id'] ?? '' }}" placeholder="e.g. 02240000000">
            <div class="form-text">The number the customer sees. Must be a DID assigned to your Smartflo account.</div>
        </div>
        <div class="col-5">
            <label class="form-label">Max call length (seconds)</label>
            <input type="number" name="tata_call_timeout" class="form-control" min="0" max="7200"
                   value="{{ $telephony['tata_call_timeout'] ?? 0 }}">
            <div class="form-text">0 = no limit.</div>
        </div>
    </div>

    <hr>

    <h6 class="mb-2">Call events (webhook)</h6>

    <div class="mb-3">
        <label class="form-label">Webhook token</label>
        <div class="input-group">
            <input type="text" name="tata_webhook_token" class="form-control font-monospace" id="tataWebhookToken"
                   placeholder="{{ $tataTokenSet ? $telephony['tata_webhook_token'] : 'Generate a token' }}">
            <button class="btn btn-outline-secondary" id="btnGenTataToken" type="button">Generate</button>
        </div>
        <div class="form-text">Stored encrypted. Smartflo must send it back in the URL (<code>?token=</code>) or an <code>X-Webhook-Token</code> header.</div>
    </div>

    <div class="mb-3">
        <label class="form-label">Webhook URL for the Smartflo portal</label>
        <div class="input-group">
            <input type="text" class="form-control font-monospace" id="tataWebhookUrl" data-base="{{ $tataHookBase }}"
                   value="{{ $tataHookBase }}/hangup?token={{ $tataTokenSet ? '<your token>' : '<generate a token first>' }}" readonly>
            <button class="btn btn-outline-secondary" id="btnCopyTataUrl" type="button"><i class="bi bi-clipboard"></i></button>
        </div>
    </div>

    <details class="mb-3 small">
        <summary class="fw-semibold">How to configure the webhooks in Smartflo</summary>
        <ol class="mt-2 mb-2 ps-3">
            <li>Smartflo portal → <strong>API Connect → Webhook → Add</strong>.</li>
            <li>Method <strong>POST</strong>, content type <strong>application/json</strong>, time zone <strong>IST</strong>, date format <strong>Default</strong>.</li>
            <li>Create one webhook per trigger, each with the URL below and your token:
                <ul class="mb-1">
                    <li><code>Call hangup (Missed or Answered)</code> → <code>{{ $tataHookBase }}/hangup?token=…</code> (call type: both)</li>
                    <li><code>Call answered by Agent</code> / <code>Call answered by Customer (Click to Call)</code> → <code>{{ $tataHookBase }}/answered?token=…</code></li>
                    <li><code>Call received on Server</code> (inbound) → <code>{{ $tataHookBase }}/inbound?token=…</code></li>
                </ul>
            </li>
            <li>Add these variables to the body (the CRM ignores extras):
                <div class="font-monospace bg-light border rounded p-2 mt-1" style="white-space:normal">
                    $call_id $uuid $ref_id $direction $call_status $call_to_number $caller_id_number $customer_number
                    $answered_agent_number $agent_number $start_stamp $answer_stamp $end_stamp $duration $billsec
                    $hangup_cause $recording_url $custom_identifier $did_number
                </div>
            </li>
        </ol>
        <div class="text-muted">The hangup webhook alone is enough for call history, durations and recordings; the others add live status in the dialer.</div>
    </details>

    <div class="d-flex align-items-center gap-2 mb-3">
        @can('telephony.edit')
            <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i>Save</button>
        @endcan
        <button type="button" class="btn btn-outline-secondary" id="btnTataAgents" @disabled(! $tataConfigured)>
            <i class="bi bi-people me-1"></i>Show Smartflo agents
        </button>
        <span class="small text-muted ms-auto">
            {{ $tataAgents }} CRM user(s) mapped to a Tata agent number
        </span>
    </div>

    <div id="tataAgentList"></div>
</form>
