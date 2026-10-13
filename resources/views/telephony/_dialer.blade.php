{{--
    Global softphone widget + call handlers.

    Included once from the app layout so that:
      - any .js-click-to-call button anywhere in the CRM works,
      - a live call is always visible in the topbar,
      - the call-outcome modal can be opened from any "Add outcome" button.
        It is never forced open: outcomes are logged on the lead's profile.
--}}
@php
    $callService    = app(\App\Services\Telephony\CallService::class);
    $usesTata       = $callService->usesTata();
    $agentExtension = auth()->user()->sipExtension;
    $dialIdentity   = auth()->user()->dialIdentity();
    $telephonyReady = auth()->user()->canPlaceCalls();

    // Built here rather than inline: Blade's @json argument parser cannot span
    // a multi-line closure (it compiles, then fatals at render).
    $dispositionTree = \App\Models\Disposition::query()
        ->with(['children' => fn ($q) => $q->where('is_active', true)->orderBy('sort_order')])
        ->whereNull('parent_id')
        ->where('is_active', true)
        ->orderBy('sort_order')
        ->get(['id', 'name'])
        ->map(fn ($d) => [
            'id'       => $d->id,
            'name'     => $d->name,
            'children' => $d->children->map(fn ($c) => ['id' => $c->id, 'name' => $c->name])->values(),
        ])
        ->values();
@endphp

{{-- The visible topbar dialer widget was removed on request; the modals and
     click-to-call / call-outcome / recording handlers below stay active. --}}

{{-- ================= Disposition modal ================= --}}
<div class="modal fade" id="dispositionModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-clipboard-check me-2"></i>Call outcome</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="callOutcomeForm">
                <div class="modal-body">
                    <input type="hidden" id="dispCallId">

                    <div class="alert alert-light border small d-flex justify-content-between">
                        <span id="dispContact">—</span>
                        <span class="text-muted" id="dispDuration">—</span>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Outcome <span class="text-danger">*</span></label>
                        <select name="disposition_id" class="form-select" id="dispSelect" required>
                            <option value="">Choose an outcome…</option>
                        </select>
                    </div>

                    <div class="mb-3 d-none" id="subDispWrap">
                        <label class="form-label">Sub-outcome</label>
                        <select name="sub_disposition_id" class="form-select" id="subDispSelect">
                            <option value="">—</option>
                        </select>
                    </div>

                    {{-- Live transcript summary becomes the note. One line only,
                         and editable because speech-to-text is never perfect. --}}
                    <div class="mb-3">
                        <div class="d-flex align-items-center justify-content-between">
                            <label class="form-label mb-1">Call note <span class="text-danger">*</span></label>
                            <button type="button" class="btn btn-sm btn-link p-0 d-none" id="dispRegen">
                                <i class="bi bi-arrow-repeat me-1"></i>Regenerate from transcript
                            </button>
                        </div>
                        <input type="text" name="remark" id="dispRemark" class="form-control" maxlength="255"
                               placeholder="One-line summary of the call" autocomplete="off">
                        <div class="form-text d-flex justify-content-between">
                            <span id="dispNoteHint">A summary is drafted from the live transcript — edit it before saving.</span>
                            <span class="text-muted" id="dispRemarkCount">0/255</span>
                        </div>
                    </div>

                    <div class="mb-3 d-none" id="dispTranscriptWrap">
                        <label class="form-label mb-1">
                            <i class="bi bi-mic me-1"></i>Live transcript
                            <span class="badge bg-secondary-subtle text-secondary ms-1" id="dispTranscriptSrc"></span>
                        </label>
                        <div class="border rounded p-2 bg-light small" id="dispTranscript"
                             style="max-height:140px;overflow-y:auto;white-space:pre-wrap"></div>
                    </div>

                    <div class="mb-0">
                        <label class="form-label">Schedule next follow-up</label>
                        <input type="datetime-local" name="next_follow_up_at" class="form-control">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Later</button>
                    <button type="submit" class="btn btn-primary">Save outcome</button>
                </div>
            </form>
        </div>
    </div>
</div>

{{-- ================= Transfer modal ================= --}}
<div class="modal fade" id="transferModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Transfer call</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <select class="form-select" id="transferTarget">
                    <option value="">Choose an agent…</option>
                </select>
            </div>
            <div class="modal-footer">
                <button class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                <button class="btn btn-primary" id="btnConfirmTransfer">Transfer</button>
            </div>
        </div>
    </div>
</div>

{{-- Hidden player used by the "play recording" buttons in tables. --}}
<audio id="globalRecordingPlayer" class="d-none" controls></audio>

@push('scripts')
<script>
(function () {
    'use strict';

    // The dialer lives inside #topbar (position:fixed, z-index:1030), which is a
    // stacking context BELOW Bootstrap's body-level backdrop (z-index:1050).
    // A modal left inside it renders behind its own backdrop, so the screen
    // greys out with nothing clickable. Move the dialer's modals to <body> so
    // the backdrop and modal stack correctly.
    ['dispositionModal', 'transferModal'].forEach(function (id) {
        var el = document.getElementById(id);
        if (el && el.parentElement !== document.body) document.body.appendChild(el);
    });

    const routes = {
        current:     @json(route('calls.current')),
        recent:      @json(route('calls.recent')),
        dial:        @json(route('calls.dial')),
        dialWhatsapp:@json(route('calls.dial_whatsapp')),
        permission:  @json(route('calls.request_permission')),
        status:      @json(route('calls.status')),
        board:       @json(route('calls.board')),
        transcriptionConfig: @json(route('calls.transcription_config')),
        call:        id => `{{ url('calls') }}/${id}`,
    };

    let activeCall = null;
    let timerHandle = null;

    // ------------------------------------------------------------------
    // Live transcript (Web Speech API, in the agent's browser)
    // ------------------------------------------------------------------
    const Speech = window.SpeechRecognition || window.webkitSpeechRecognition;
    const transcription = { enabled: false, language: 'en-IN' };
    let recog = null, recogCallId = null, recogWantOn = false, segSeq = 0, pending = [], flushTimer = null;
    const lastSummary = {}; // callId -> { summary, source, transcript }

    $.get(routes.transcriptionConfig).done(cfg => { transcription.enabled = !!cfg.enabled && !!Speech; transcription.language = cfg.language || 'en-IN'; });

    function flushSegments() {
        if (!recogCallId || !pending.length) return;
        const batch = pending.splice(0, pending.length);
        $.ajax({ url: routes.call(recogCallId) + '/transcript', method: 'POST',
                 data: { _token: @json(csrf_token()), language: transcription.language, segments: batch } });
    }

    function startTranscript(call) {
        if (!transcription.enabled || !Speech) return;
        if (recog && recogCallId === call.id) return;
        stopTranscript(false);

        recogCallId = call.id; recogWantOn = true; segSeq = 0; pending = [];
        recog = new Speech();
        recog.lang = transcription.language;
        recog.continuous = true; recog.interimResults = true;

        recog.onresult = (e) => {
            for (let i = e.resultIndex; i < e.results.length; i++) {
                const r = e.results[i];
                const text = r[0].transcript.trim();
                if (!text) continue;
                if (r.isFinal) {
                    pending.push({ id: 'a' + (segSeq++), t: Date.now() / 1000, speaker: 'agent', text, final: true });
                }
            }
        };
        recog.onerror = () => {};
        recog.onend = () => { if (recogWantOn) { try { recog.start(); } catch (_) {} } };
        try { recog.start(); } catch (_) {}
        flushTimer = setInterval(flushSegments, 4000);
    }

    function stopTranscript(flush = true) {
        recogWantOn = false;
        if (flushTimer) { clearInterval(flushTimer); flushTimer = null; }
        if (flush) flushSegments();
        if (recog) { try { recog.stop(); } catch (_) {} recog = null; }
        recogCallId = null;
    }

    // ------------------------------------------------------------------
    // Poll for the agent's current call
    // ------------------------------------------------------------------
    function poll() {
        if (document.hidden) return;

        $.get(routes.current).done(res => {
            $('#dialerLabel').text(res.label);
            $('#dialerIcon').attr('class', 'bi ' + (res.call?.is_live ? 'bi-telephone-fill text-success' : 'bi-headset'));
            $('#dialerDot').toggleClass('d-none', res.status === 'offline');

            const call = res.call;

            syncHangupButtons(call && call.is_live ? call : null);

            if (call && call.is_live) {
                showLiveCall(call);
            } else {
                hideLiveCall();

                // The call just ended: close out the browser transcript so the
                // final segments reach the server before we summarise.
                if (recogCallId) stopTranscript(true);

                // No outcome pop-up here: the agent records the outcome on the
                // lead's profile ("Log a call"), which also stamps the call.
                // The modal stays reachable from the "Add outcome" buttons on
                // call lists for calls that have no lead.
            }

            activeCall = call;
        });
    }

    function showLiveCall(call) {
        $('#liveCallPane').removeClass('d-none');
        $('#dialPane').addClass('d-none');
        startTranscript(call);
        $('#liveContact').text(call.contact || 'Unknown');
        $('#liveNumber').text(call.number || '');
        $('#btnOpenLead').toggleClass('d-none', !call.lead_url).attr('href', call.lead_url || '#');

        if (!timerHandle) {
            const started = call.started_at ? new Date(call.started_at) : new Date();
            timerHandle = setInterval(() => {
                const s = Math.max(Math.floor((Date.now() - started.getTime()) / 1000), 0);
                $('#liveTimer').text(
                    String(Math.floor(s / 60)).padStart(2, '0') + ':' + String(s % 60).padStart(2, '0')
                );
            }, 1000);
        }
    }

    function hideLiveCall() {
        $('#liveCallPane').addClass('d-none');
        $('#dialPane').removeClass('d-none');

        if (timerHandle) {
            clearInterval(timerHandle);
            timerHandle = null;
        }
    }

    // ------------------------------------------------------------------
    // Placing calls
    // ------------------------------------------------------------------
    function dial(payload, $button) {
        // When the in-browser softphone is registered, place the call from the
        // browser itself: the customer's phone rings and there is no agent leg
        // to answer first. Falls back to server click-to-call otherwise.
        if (window.CrmSoftphone && window.CrmSoftphone.isRegistered()) {
            return window.CrmSoftphone.call(payload, $button);
        }

        $button?.prop('disabled', true);

        $.post(routes.dial, payload)
            .done(res => {
                if (!res.success) return toastr.error(res.message);
                toastr.success(res.message);
                activeCall = res.call;
                poll();
            })
            .fail(xhr => toastr.error(xhr.responseJSON?.message || 'Could not place the call.'))
            .always(() => $button?.prop('disabled', false));
    }

    // Works for any .js-click-to-call button anywhere in the CRM.
    $(document).on('click', '.js-click-to-call', function () {
        const $b = $(this);
        dial({
            number:     $b.data('number'),
            lead_id:    $b.data('lead') || null,
            patient_id: $b.data('patient') || null,
        }, $b);
    });

    $(document).on('click', '.js-callback', function () {
        const $b = $(this);
        dial({ lead_id: $b.data('lead') }, $b);
    });

    // ------------------------------------------------------------------
    // Disconnect from the profile
    // ------------------------------------------------------------------
    // While a call is live, the Call button of the contact on the line turns
    // into Disconnect. Matched by lead or patient id so a call started from
    // the leads list still shows up on that lead's profile.
    let liveCallId = null;

    function syncHangupButtons(call) {
        liveCallId = call ? call.id : null;

        $('.js-hangup-call').each(function () {
            const $h = $(this);
            const mine = !!call && (
                (call.lead_id && String($h.data('lead')) === String(call.lead_id)) ||
                (call.patient_id && String($h.data('patient')) === String(call.patient_id))
            );
            $h.toggleClass('d-none', !mine).prop('disabled', false);
            $h.siblings('.js-click-to-call').toggleClass('d-none', mine);
        });
    }

    $(document).on('click', '.js-hangup-call', function () {
        const $b = $(this).prop('disabled', true);

        // A browser (WebRTC) call is ended by the softphone itself, which then
        // closes the call log; a Smartflo / Asterisk leg is dropped server-side.
        if (window.CrmSoftphone && window.CrmSoftphone.inCall && window.CrmSoftphone.inCall()) {
            window.CrmSoftphone.hangup();
            return;
        }
        if (!liveCallId) { syncHangupButtons(null); return; }

        $.post(routes.call(liveCallId) + '/hangup')
            .done(res => { toastr.info(res.message || 'Call ended.'); syncHangupButtons(null); poll(); })
            .fail(xhr => { toastr.error(xhr.responseJSON?.message || 'Could not end the call.'); $b.prop('disabled', false); });
    });

    $('#btnDial').on('click', function () {
        const number = $('#dialInput').val().trim();
        if (!number) return;
        dial({ number }, $(this));
    });

    $('#dialInput').on('keydown', e => {
        if (e.key === 'Enter') { e.preventDefault(); $('#btnDial').click(); }
    });

    // WhatsApp voice call — needs an approved call permission.
    $(document).on('click', '.js-whatsapp-call', function () {
        const $b = $(this).prop('disabled', true);

        $.post(routes.dialWhatsapp, {
            number:          $b.data('number'),
            lead_id:         $b.data('lead') || null,
            conversation_id: $b.data('conversation') || null,
        })
        .done(res => {
            if (!res.success) return toastr.error(res.message);
            toastr.success(res.message);
            poll();
        })
        .fail(xhr => {
            const body = xhr.responseJSON || {};

            if (body.needs_permission && $b.data('conversation')) {
                return Swal.fire({
                    title: 'Call permission needed',
                    text: body.message + ' Send a permission request in the chat now?',
                    icon: 'question',
                    showCancelButton: true,
                    confirmButtonText: 'Send request',
                }).then(result => {
                    if (!result.isConfirmed) return;

                    $.post(routes.permission, { conversation_id: $b.data('conversation') })
                        .done(r => toastr.success(r.message))
                        .fail(x => toastr.error(x.responseJSON?.message || 'Request failed.'));
                });
            }

            toastr.error(body.message || 'Could not place the WhatsApp call.');
        })
        .always(() => $b.prop('disabled', false));
    });

    // ------------------------------------------------------------------
    // In-call control
    // ------------------------------------------------------------------
    $('#btnHangup').on('click', function () {
        if (!activeCall) return;

        $.post(routes.call(activeCall.id) + '/hangup')
            .done(() => { toastr.info('Call ended.'); poll(); })
            .fail(() => toastr.error('Could not hang up.'));
    });

    $('#btnTransfer').on('click', function () {
        if (!activeCall) return;
        loadAgents().then(() => new bootstrap.Modal('#transferModal').show());
    });

    let agentsLoaded = false;

    function loadAgents() {
        if (agentsLoaded) return $.Deferred().resolve().promise();

        return $.get(routes.board).done(res => {
            $('#transferTarget').html(
                '<option value="">Choose an agent…</option>' +
                res.agents
                    .filter(a => a.status === 'idle')
                    .map(a => `<option value="${a.id}">${$('<div>').text(a.name).html()} (${a.extension})</option>`)
                    .join('')
            );
            agentsLoaded = true;
        });
    }

    $('#btnConfirmTransfer').on('click', function () {
        const target = $('#transferTarget').val();
        if (!target || !activeCall) return;

        $.post(routes.call(activeCall.id) + '/transfer', { user_id: target })
            .done(res => {
                if (!res.success) return toastr.error(res.message);
                toastr.success(res.message);
                bootstrap.Modal.getInstance(document.getElementById('transferModal')).hide();
                poll();
            })
            .fail(xhr => toastr.error(xhr.responseJSON?.message || 'Transfer failed.'));
    });

    $(document).on('click', '.js-hangup', function () {
        $.post(routes.call($(this).data('id')) + '/hangup')
            .done(() => { toastr.info('Call ended.'); poll(); });
    });

    // ------------------------------------------------------------------
    // Presence
    // ------------------------------------------------------------------
    $(document).on('click', '.js-presence', function () {
        const status = $(this).data('status');

        $.post(routes.status, { status })
            .done(res => { toastr.success(res.message); poll(); })
            .fail(() => toastr.error('Could not change your status.'));
    });

    // ------------------------------------------------------------------
    // Disposition
    // ------------------------------------------------------------------
    // Dispositions are inlined by the layout, so the modal needs no fetch.
    const dispositionTree = @json($dispositionTree);

    $('#dispSelect').html(
        '<option value="">Choose an outcome…</option>' +
        dispositionTree.map(d => `<option value="${d.id}">${$('<div>').text(d.name).html()}</option>`).join('')
    );

    $('#dispSelect').on('change', function () {
        const parent = dispositionTree.find(d => String(d.id) === String(this.value));
        const children = parent?.children || [];

        $('#subDispWrap').toggleClass('d-none', children.length === 0);
        $('#subDispSelect').html(
            '<option value="">—</option>' +
            children.map(c => `<option value="${c.id}">${$('<div>').text(c.name).html()}</option>`).join('')
        );
    });

    function openDisposition(call) {
        $('#dispCallId').val(call.id);
        $('#dispContact').text(call.contact + ' · ' + (call.number || ''));
        $('#dispDuration').text(call.duration + ' · ' + call.status);
        $('#callOutcomeForm')[0].reset();
        $('#subDispWrap').addClass('d-none');
        $('#dispRemark').val(''); updateRemarkCount();
        $('#dispTranscriptWrap').addClass('d-none');
        $('#dispTranscript').text(''); $('#dispRegen').addClass('d-none');
        new bootstrap.Modal('#dispositionModal').show();

        loadSummary(call.id);
    }

    function loadSummary(callId) {
        // Cached from a previous open?
        if (lastSummary[callId]) return applySummary(callId, lastSummary[callId]);

        $('#dispNoteHint').html('<span class="spinner-border spinner-border-sm me-1"></span>Summarising the call…');

        $.post(routes.call(callId) + '/summarize', { _token: @json(csrf_token()) })
            .done(res => { lastSummary[callId] = res; applySummary(callId, res); })
            .fail(() => $('#dispNoteHint').text('Type a one-line summary of the call.'));
    }

    function applySummary(callId, res) {
        if (res.summary && !$('#dispRemark').val()) {
            $('#dispRemark').val(res.summary); updateRemarkCount();
        }
        if (res.transcript) {
            $('#dispTranscript').text(res.transcript);
            $('#dispTranscriptSrc').text((res.source === 'ai' ? 'AI summary' : 'auto summary') + (res.words ? ' · ' + res.words + ' words' : ''));
            $('#dispTranscriptWrap').removeClass('d-none');
            $('#dispRegen').removeClass('d-none');
        }
        $('#dispNoteHint').text(res.summary ? 'Drafted from the transcript — edit before saving.' : 'Type a one-line summary of the call.');
    }

    function updateRemarkCount() {
        // A note is one line: strip newlines defensively (paste), cap length.
        const el = document.getElementById('dispRemark');
        if (el.value.includes('\n')) el.value = el.value.replace(/\s*\n\s*/g, ' ');
        $('#dispRemarkCount').text(el.value.length + '/255');
    }

    $('#dispRemark').on('input', updateRemarkCount);
    $('#dispRegen').on('click', function () {
        const id = $('#dispCallId').val();
        delete lastSummary[id];
        $('#dispRemark').val('');
        loadSummary(id);
    });

    $(document).on('click', '.js-disposition', function () {
        const id = $(this).data('id');
        openDisposition({ id, contact: 'Call #' + id, number: '', duration: '', status: '' });
    });

    $('#callOutcomeForm').on('submit', function (e) {
        e.preventDefault();

        const note = $('#dispRemark').val().trim();
        if (!note) { $('#dispRemark').focus(); return toastr.warning('Add a one-line call note before saving.'); }
        $('#dispRemark').val(note.replace(/\s*\n\s*/g, ' '));

        const id = $('#dispCallId').val();
        const $btn = $(this).find('[type=submit]').prop('disabled', true);

        $.post(routes.call(id) + '/disposition', $(this).serialize())
            .done(res => {
                if (!res.success) return toastr.error(res.message);
                toastr.success(res.message);
                bootstrap.Modal.getInstance(document.getElementById('dispositionModal')).hide();
                delete lastSummary[id];
                activeCall = null;
                poll();
                // On a lead / patient profile, flow straight on to the next lead.
                if (res.next_url && /^\/(leads|patients)\/\d+/.test(window.location.pathname)) {
                    window.location.href = res.next_url;
                    return;
                }
                // Refresh any DataTable on the page so the outcome shows up.
                if (window.jQuery && $.fn.DataTable) {
                    $.fn.DataTable.tables({ api: true }).ajax.reload(null, false);
                }
            })
            .fail(xhr => toastr.error(xhr.responseJSON?.message || 'Could not save the outcome.'))
            .always(() => $btn.prop('disabled', false));
    });

    // ------------------------------------------------------------------
    // Recording playback from list tables
    // ------------------------------------------------------------------
    $(document).on('click', '.js-play', function () {
        const player = document.getElementById('globalRecordingPlayer');
        player.src = $(this).data('src');
        player.classList.remove('d-none');
        player.play();
    });

    // ------------------------------------------------------------------
    // Recent calls
    // ------------------------------------------------------------------
    function loadRecent() {
        $.get(routes.recent).done(res => {
            if (!res.data.length) {
                return $('#recentCalls').html('<div class="text-center text-muted small py-3">No calls yet.</div>');
            }

            $('#recentCalls').html(res.data.slice(0, 10).map(c => `
                <a href="${c.lead_url || '#'}" class="d-flex align-items-center gap-2 px-3 py-2 text-decoration-none border-bottom">
                    <i class="bi bi-telephone-${c.direction === 'inbound' ? 'inbound text-success' : 'outbound text-primary'}"></i>
                    <div class="flex-grow-1 min-w-0">
                        <div class="small text-truncate text-dark">${$('<div>').text(c.contact).html()}</div>
                        <small class="text-muted">${c.duration} · ${c.started_human || ''} ago</small>
                    </div>
                    ${c.needs_disposition ? '<i class="bi bi-exclamation-circle text-warning" title="Needs an outcome"></i>' : ''}
                </a>`).join(''));
        });
    }

    $('#dialerWidget').on('shown.bs.dropdown', loadRecent);

    // ------------------------------------------------------------------
    // Boot
    // ------------------------------------------------------------------
    // The web softphone widget refreshes the live-call pane through poll()
    // when a browser call ends, and reuses the dial route as its fallback.
    window.CrmDialer = { poll, routes };

    poll();
    setInterval(poll, 5000);
})();
</script>
@endpush
