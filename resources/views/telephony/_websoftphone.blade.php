{{--
    Global in-browser softphone (WebRTC / SIP over WSS to Smartflo).

    When the agent has saved their SIP credentials and an admin has enabled the
    softphone, this registers the browser directly with Smartflo and exposes
    window.CrmSoftphone. Any .js-click-to-call (single or bulk) then places the
    call FROM the browser: the customer's phone rings and there is no agent leg
    to answer first. If it is not registered, the CRM falls back to
    server-side click-to-call, so nothing here breaks the existing flow.

    Included from the layout for users who can place calls. It needs the SIP
    extension password, entered once at Enquiries → Web Softphone.
--}}
@php
    $spUser     = auth()->user();
    $spSettings = app(\App\Support\SettingsRepository::class);
    $spEnabled  = (bool) $spSettings->get('telephony', 'softphone_enabled', false)
        && $spSettings->get('telephony', 'sip_wss_server')
        && $spSettings->has('softphone', 'sip_password_' . $spUser->id)
        && $spUser->can('call_logs.create');
@endphp

@if($spEnabled)
    {{-- A small status pill, bottom-left, out of the way of the dialer. --}}
    <div id="crmSoftphone" class="position-fixed bottom-0 start-0 m-3 d-flex align-items-center gap-2 px-3 py-2 rounded-pill shadow-sm bg-white border"
         style="z-index:1040;font-size:.8rem;cursor:default">
        <span id="csDot" class="rounded-circle" style="width:9px;height:9px;background:#adb5bd"></span>
        <i class="bi bi-headset"></i>
        <span id="csText" class="fw-semibold text-muted">Softphone off</span>
        <span id="csTimer" class="text-success fw-semibold d-none">00:00</span>
        <button type="button" id="csHangup" class="btn btn-sm btn-outline-danger py-0 px-2 d-none" title="Hang up"><i class="bi bi-telephone-x"></i></button>
    </div>
    <audio id="csRemote" autoplay></audio>

    @push('scripts')
    <script src="https://cdn.jsdelivr.net/npm/jssip@3/dist/jssip.min.js"></script>
    <script>
    (function () {
        'use strict';
        if (typeof JsSIP === 'undefined') { return; }

        const credUrl  = @json(route('tata.softphone.credentials'));
        const startUrl = @json(route('calls.webrtc_start'));
        const eventUrl = id => `{{ url('calls') }}/${id}/webrtc-event`;
        const token    = @json(csrf_token());

        let ua = null, cfg = null, registered = false;
        let session = null, activeCallId = null, answered = false, t0 = 0, timer = null;

        const $dot = $('#csDot'), $text = $('#csText'), $timer = $('#csTimer'), $hangup = $('#csHangup');

        function paint(state, text) {
            const colors = { off: '#adb5bd', connecting: '#f0ad4e', ready: '#28a745', call: '#0d6efd', fail: '#dc3545' };
            $dot.css('background', colors[state] || '#adb5bd');
            $text.text(text).toggleClass('text-muted', state === 'off' || state === 'fail');
        }
        function startTimer() {
            t0 = Date.now(); $timer.removeClass('d-none');
            timer = setInterval(() => {
                const s = Math.floor((Date.now() - t0) / 1000);
                $timer.text(String(Math.floor(s/60)).padStart(2,'0') + ':' + String(s%60).padStart(2,'0'));
            }, 1000);
        }
        function stopTimer() {
            clearInterval(timer); timer = null; $timer.addClass('d-none').text('00:00');
            return t0 ? Math.floor((Date.now() - t0) / 1000) : 0;
        }

        function register() {
            paint('connecting', 'Connecting…');
            $.get(credUrl).done(c => connect(c)).fail(xhr => {
                paint('fail', 'Softphone setup incomplete');
            });
        }

        function connect(c) {
            cfg = c;
            try {
                const socket = new JsSIP.WebSocketInterface(c.server);
                ua = new JsSIP.UA({
                    sockets: [socket],
                    uri: 'sip:' + c.extension + '@' + c.realm,
                    password: c.password,
                    register: true,
                    session_timers: false,
                });
                ua.on('registered',   () => { registered = true;  paint('ready', 'Softphone ready'); });
                ua.on('unregistered', () => { registered = false; paint('off', 'Softphone off'); });
                ua.on('registrationFailed', e => { registered = false; paint('fail', 'Registration failed'); });
                ua.on('newRTCSession', ev => {
                    if (ev.originator !== 'remote') bindSession(ev.session);
                });
                ua.start();
            } catch (e) {
                paint('fail', 'Softphone error');
            }
        }

        function bindSession(s) {
            session = s;
            answered = false;
            $hangup.removeClass('d-none');
            paint('call', 'Ringing…');

            s.on('peerconnection', e => {
                e.peerconnection.addEventListener('track', ev => {
                    document.getElementById('csRemote').srcObject = ev.streams[0];
                });
            });
            s.on('accepted', () => {
                answered = true;
                paint('call', 'On call'); startTimer();
                if (activeCallId) $.post(eventUrl(activeCallId), { _token: token, event: 'answered' });
            });
            s.on('ended',  () => finishCall());
            s.on('failed', () => finishCall());
        }

        function finishCall() {
            const secs = stopTimer();
            $hangup.addClass('d-none');
            paint(registered ? 'ready' : 'off', registered ? 'Softphone ready' : 'Softphone off');

            const id = activeCallId;
            session = null; activeCallId = null;

            if (id) {
                $.post(eventUrl(id), { _token: token, event: 'hangup', talk_seconds: answered ? secs : 0 })
                    .always(() => { if (window.CrmDialer) window.CrmDialer.poll(); });
            }
            answered = false;
        }

        $hangup.on('click', () => { if (session) try { session.terminate(); } catch (e) {} });

        // The public API the dialer calls in place of server click-to-call.
        window.CrmSoftphone = {
            isRegistered: () => registered && !!ua,
            call: function (payload, $button) {
                if (session) { toastr.warning('Finish the current call first.'); return; }
                $button && $button.prop('disabled', true);

                $.post(startUrl, {
                    _token: token,
                    number: payload.number || null,
                    lead_id: payload.lead_id || null,
                    patient_id: payload.patient_id || null,
                })
                .done(res => {
                    if (!res.success) { toastr.error(res.message); return; }
                    activeCallId = res.call.id;
                    const target = 'sip:' + (cfg.prefix || '') + res.dial + '@' + cfg.realm;
                    try {
                        ua.call(target, {
                            mediaConstraints: { audio: true, video: false },
                            pcConfig: { iceServers: cfg.stun ? [{ urls: cfg.stun }] : [] },
                        });
                        toastr.success(res.message);
                        if (window.CrmDialer) window.CrmDialer.poll();
                    } catch (e) {
                        toastr.error('Could not start the browser call: ' + e.message);
                        // Close the log we just opened so the gate does not stick.
                        $.post(eventUrl(activeCallId), { _token: token, event: 'hangup', talk_seconds: 0 });
                        activeCallId = null;
                    }
                })
                .fail(xhr => toastr.error(xhr.responseJSON?.message || 'Could not place the call.'))
                .always(() => $button && $button.prop('disabled', false));
            },
        };

        register();
    })();
    </script>
    @endpush
@endif
