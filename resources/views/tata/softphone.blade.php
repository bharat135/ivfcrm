@extends('layouts.app')

@section('title', 'Web Softphone')

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">Dashboard</a></li>
    <li class="breadcrumb-item active">Web Softphone</li>
@endsection

@section('content')
<div class="row g-3">
    <div class="col-lg-5">
        <div class="card shadow-sm">
            <div class="card-header bg-white fw-semibold"><i class="bi bi-telephone-inbound me-2"></i>In-browser softphone</div>
            <div class="card-body">
                @unless($enabled && $server)
                    <div class="alert alert-info small mb-3">
                        <i class="bi bi-info-circle me-1"></i>
                        In-browser calling needs a Smartflo WebRTC (SIP-over-WSS) endpoint. An administrator enables it
                        and sets the server under Tata Teleservices → Configuration. Until then, the Call buttons ring
                        your registered mobile first (Smartflo click-to-call).
                    </div>
                @endunless

                <div class="text-center py-3">
                    <div class="display-6 mb-1" id="spStatusIcon"><i class="bi bi-telephone-x text-muted"></i></div>
                    <div class="fw-semibold" id="spStatus">Not registered</div>
                    <div class="small text-muted" id="spDetail">{{ $authUser ? 'Extension ' . $authUser : 'No extension' }}</div>
                    <div class="small text-success fw-semibold mt-1 d-none" id="spTimer">00:00</div>
                </div>

                <div class="input-group mb-2">
                    <span class="input-group-text"><i class="bi bi-telephone"></i></span>
                    <input type="tel" id="spNumber" class="form-control" placeholder="Number to dial">
                    <button class="btn btn-success" id="spCall" disabled><i class="bi bi-telephone-outbound"></i></button>
                </div>
                <div class="d-flex gap-2">
                    <button class="btn btn-outline-danger flex-grow-1 d-none" id="spHangup"><i class="bi bi-telephone-x me-1"></i>Hang up</button>
                    <button class="btn btn-success flex-grow-1 d-none" id="spAnswer"><i class="bi bi-telephone-inbound me-1"></i>Answer</button>
                    <button class="btn btn-outline-secondary" id="spMute" title="Mute" disabled><i class="bi bi-mic-mute"></i></button>
                </div>
                @if($enabled && $server)
                    <div class="d-grid mt-2">
                        <button class="btn btn-sm btn-outline-primary" id="spRegister"><i class="bi bi-box-arrow-in-right me-1"></i>Register softphone</button>
                    </div>
                @endif
                <audio id="spRemote" autoplay></audio>
            </div>
        </div>
    </div>

    <div class="col-lg-7">
        <div class="card shadow-sm">
            <div class="card-header bg-white fw-semibold">Your SIP credentials</div>
            <div class="card-body">
                <p class="small text-muted">
                    These register your browser directly with Smartflo, so you take and place calls here without a
                    separate app or your mobile ringing first. Get the extension and SIP password from Smartflo
                    (Users → your agent → extension) or your administrator. The password is stored encrypted.
                </p>
                <form id="spCreds">
                    @csrf
                    @method('PUT')
                    <div class="row g-2">
                        <div class="col-md-5"><label class="form-label">SIP extension</label>
                            <input type="text" name="sip_extension" class="form-control font-monospace" value="{{ $authUser }}" placeholder="060XXXXXX">
                        </div>
                        <div class="col-md-7"><label class="form-label">SIP password</label>
                            <input type="password" name="sip_password" class="form-control font-monospace" autocomplete="new-password"
                                   placeholder="{{ $hasSecret ? '•••••••• (saved)' : 'From Smartflo' }}">
                        </div>
                    </div>
                    <div class="mt-3 d-flex gap-2">
                        <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i>Save</button>
                        @if($hasSecret)<button type="button" class="btn btn-outline-danger" id="spForget">Remove</button>@endif
                    </div>
                </form>
                <hr>
                <dl class="row small mb-0">
                    <dt class="col-4 text-muted">WSS server</dt><dd class="col-8 font-monospace">{{ $server ?: 'Not set by admin' }}</dd>
                    <dt class="col-4 text-muted">SIP domain</dt><dd class="col-8 font-monospace">{{ $realm ?: '—' }}</dd>
                    <dt class="col-4 text-muted">STUN/TURN</dt><dd class="col-8 font-monospace">{{ $stun ?: '—' }}</dd>
                </dl>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
@if($enabled && $server)
<script src="https://cdn.jsdelivr.net/npm/jssip@3/dist/jssip.min.js"></script>
@endif
<script>
$(function () {
    // Save / remove credentials
    $('#spCreds').on('submit', function (e) {
        e.preventDefault();
        $.ajax({ url: @json(route('tata.softphone.update')), method: 'POST', data: $(this).serialize() })
            .done(res => { toastr.success(res.message); $('[name=sip_password]').val(''); })
            .fail(xhr => toastr.error(xhr.responseJSON?.message || 'Could not save.'));
    });
    $('#spForget').on('click', function () {
        $.ajax({ url: @json(route('tata.softphone.destroy')), method: 'POST', data: { _method: 'DELETE', _token: @json(csrf_token()) } })
            .done(res => { toastr.success(res.message); setTimeout(() => location.reload(), 800); });
    });

    @if($enabled && $server)
    if (typeof JsSIP === 'undefined') { toastr.error('Softphone library failed to load.'); return; }

    let ua = null, session = null, timer = null;
    const remote = document.getElementById('spRemote');

    function setStatus(icon, text, detail) {
        $('#spStatusIcon').html(icon); $('#spStatus').text(text);
        if (detail !== undefined) $('#spDetail').text(detail);
    }
    function startTimer() {
        const t0 = Date.now();
        $('#spTimer').removeClass('d-none');
        timer = setInterval(() => {
            const s = Math.floor((Date.now() - t0) / 1000);
            $('#spTimer').text(String(Math.floor(s/60)).padStart(2,'0') + ':' + String(s%60).padStart(2,'0'));
        }, 1000);
    }
    function stopTimer() { clearInterval(timer); timer = null; $('#spTimer').addClass('d-none').text('00:00'); }
    function inCall(on) {
        $('#spHangup').toggleClass('d-none', !on);
        $('#spMute').prop('disabled', !on);
        $('#spCall').prop('disabled', on || !ua);
        $('#spAnswer').addClass('d-none');
    }

    function register() {
        setStatus('<i class="bi bi-arrow-repeat text-warning"></i>', 'Connecting…');
        $.get(@json(route('tata.softphone.credentials')))
            .done(cfg => connect(cfg))
            .fail(xhr => { setStatus('<i class="bi bi-telephone-x text-danger"></i>', 'Not registered', xhr.responseJSON?.message || 'Setup incomplete'); toastr.error(xhr.responseJSON?.message || 'Softphone setup incomplete.'); });
    }

    function connect(cfg) {
        try {
            const socket = new JsSIP.WebSocketInterface(cfg.server);
            ua = new JsSIP.UA({
                sockets: [socket],
                uri: 'sip:' + cfg.extension + '@' + cfg.realm,
                password: cfg.password,
                register: true,
                session_timers: false,
            });
            window.__sipPrefix = cfg.prefix || '';
            window.__sipRealm = cfg.realm;
            window.__sipStun = cfg.stun;

            ua.on('registered',   () => { setStatus('<i class="bi bi-telephone-fill text-success"></i>', 'Registered', 'Extension ' + cfg.extension); $('#spCall').prop('disabled', false); });
            ua.on('unregistered', () => setStatus('<i class="bi bi-telephone-x text-muted"></i>', 'Not registered'));
            ua.on('registrationFailed', e => { setStatus('<i class="bi bi-telephone-x text-danger"></i>', 'Registration failed', (e && e.cause) || ''); toastr.error('Registration failed: ' + ((e && e.cause) || 'check credentials')); });

            ua.on('newRTCSession', (data) => {
                session = data.session;
                if (data.originator === 'remote') {
                    setStatus('<i class="bi bi-telephone-inbound text-success"></i>', 'Incoming call', session.remote_identity?.uri?.user || '');
                    $('#spAnswer').removeClass('d-none');
                }
                session.on('accepted', () => { inCall(true); startTimer(); setStatus('<i class="bi bi-telephone-fill text-success"></i>', 'On call'); });
                session.on('ended',    () => { inCall(false); stopTimer(); session = null; setStatus('<i class="bi bi-telephone-fill text-success"></i>', 'Registered'); });
                session.on('failed',   e => { inCall(false); stopTimer(); session = null; setStatus('<i class="bi bi-telephone-fill text-success"></i>', 'Registered'); toastr.warning('Call failed: ' + ((e && e.cause) || '')); });
                session.on('peerconnection', (e) => {
                    e.peerconnection.addEventListener('track', ev => { remote.srcObject = ev.streams[0]; });
                });
            });

            ua.start();
        } catch (err) {
            toastr.error('Could not start the softphone: ' + err.message);
        }
    }

    function iceServers() {
        return window.__sipStun ? [{ urls: window.__sipStun }] : [];
    }

    function call(number) {
        if (!ua) return toastr.info('Register the softphone first.');
        const target = 'sip:' + (window.__sipPrefix || '') + number + '@' + window.__sipRealm;
        ua.call(target, {
            mediaConstraints: { audio: true, video: false },
            pcConfig: { iceServers: iceServers() },
        });
    }

    $('#spRegister').on('click', register);
    $('#spCall').on('click', () => { const n = $('#spNumber').val().trim(); if (n) call(n); });
    $('#spNumber').on('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); $('#spCall').click(); } });
    $('#spAnswer').on('click', () => { if (session) { session.answer({ mediaConstraints: { audio: true, video: false }, pcConfig: { iceServers: iceServers() } }); $('#spAnswer').addClass('d-none'); } });
    $('#spHangup').on('click', () => { if (session) session.terminate(); });
    $('#spMute').on('click', function () {
        if (!session) return;
        const muted = session.isMuted().audio;
        muted ? session.unmute({ audio: true }) : session.mute({ audio: true });
        $(this).toggleClass('btn-outline-secondary btn-warning').find('i').toggleClass('bi-mic-mute bi-mic');
    });

    // Auto-register on page load if credentials are already saved.
    @if($hasSecret)
        register();
    @endif
    @endif
});
</script>
@endpush
