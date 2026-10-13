<?php

/*
|--------------------------------------------------------------------------
| Telephony
|--------------------------------------------------------------------------
| Asterisk (AMI/ARI) for PSTN + internal calling with MicroSIP as the agent
| softphone, plus WhatsApp Business Calling.
|
| Only non-secret defaults live here. The AMI/ARI credentials are entered by
| the user under Settings → Telephony and stored encrypted in `settings`
| (group `telephony`). The .env values are optional local-dev fallbacks.
*/

return [

    // Which system places and reports calls: `asterisk` (self-hosted PBX +
    // MicroSIP) or `tata` (Tata Tele Business Services Smartflo cloud
    // telephony). Overridable at runtime from Settings → Telephony.
    'provider' => env('TELEPHONY_PROVIDER', 'asterisk'),

    // Tata Tele Business Services — Smartflo REST API. Credentials are entered
    // under Settings → Telephony (keys `tata_*`, encrypted); .env is a fallback.
    'tata' => [
        'base_url'        => env('TATA_SMARTFLO_BASE_URL', 'https://api-smartflo.tatateleservices.com'),
        'login_id'        => env('TATA_SMARTFLO_LOGIN_ID'),
        'password'        => env('TATA_SMARTFLO_PASSWORD'),
        'access_token'    => env('TATA_SMARTFLO_ACCESS_TOKEN'),
        'caller_id'       => env('TATA_SMARTFLO_CALLER_ID'),
        'webhook_token'   => env('TATA_SMARTFLO_WEBHOOK_TOKEN'),
        // Seconds Smartflo lets a click-to-call run before auto-disconnecting (0 = no limit).
        'call_timeout'    => (int) env('TATA_SMARTFLO_CALL_TIMEOUT', 0),
        // Ring the agent's first leg on their `mobile` (follow-me number) or the
        // Smartflo `softphone` (extension / agent id via login-based calling).
        'dial_via'        => env('TATA_SMARTFLO_DIAL_VIA', 'mobile'),
        // Who Smartflo dials first. `agent_first` is the ordinary click-to-call:
        // the agent's leg rings, they answer, then the customer is dialled.
        // `customer_first` uses the Click-to-Call Support API: the customer is
        // dialled first and the agent's leg only rings once they have answered,
        // so agents never sit through ringing or no-answers.
        'call_order'      => env('TATA_SMARTFLO_CALL_ORDER', 'agent_first'),
        // Click-to-Call Support API key (portal: API Connect → Click to Call
        // Support). Its destination is fixed in the portal; a per-user key on
        // the agent's profile overrides this account-wide one.
        'c2c_support_key' => env('TATA_SMARTFLO_C2C_SUPPORT_KEY'),
        // CDR window: the API caps each request at 1 month; the profile
        // recordings view walks back this many 1-month windows.
        'cdr_months'      => (int) env('TATA_SMARTFLO_CDR_MONTHS', 3),
        // Login tokens last an hour; refreshed a minute early.
        'token_ttl'       => (int) env('TATA_SMARTFLO_TOKEN_TTL', 3600),
        // The scheduled refresher renews the token when it is within this many
        // seconds of expiry (default 50 min, so a 1h token is always renewed).
        'token_refresh_before' => (int) env('TATA_SMARTFLO_TOKEN_REFRESH_BEFORE', 3000),
        'timeout'         => (int) env('TATA_SMARTFLO_TIMEOUT', 15),
        'connect_timeout' => (int) env('TATA_SMARTFLO_CONNECT_TIMEOUT', 5),
        // Smartflo webhooks/CDRs send IST wall-clock unless the webhook is set to UTC/epoch.
        'webhook_timezone' => env('TATA_SMARTFLO_WEBHOOK_TZ', 'Asia/Kolkata'),
    ],

    'asterisk' => [
        'enabled' => env('ASTERISK_ENABLED', true),

        'host' => env('ASTERISK_HOST', '127.0.0.1'),

        // Asterisk Manager Interface — used for Originate/Hangup/Transfer.
        'ami' => [
            'port'     => (int) env('ASTERISK_AMI_PORT', 5038),
            'username' => env('ASTERISK_AMI_USERNAME'),
            'secret'   => env('ASTERISK_AMI_SECRET'),
            // Socket timeouts in seconds. AMI actions are short; keep these low
            // so a dead PBX cannot hang a web request.
            'connect_timeout' => (int) env('ASTERISK_AMI_CONNECT_TIMEOUT', 3),
            'read_timeout'    => (int) env('ASTERISK_AMI_READ_TIMEOUT', 5),
        ],

        // Asterisk REST Interface — optional, used for richer channel data.
        'ari' => [
            'enabled'  => env('ASTERISK_ARI_ENABLED', false),
            'url'      => env('ASTERISK_ARI_URL', 'http://127.0.0.1:8088/ari'),
            'username' => env('ASTERISK_ARI_USERNAME'),
            'password' => env('ASTERISK_ARI_PASSWORD'),
        ],

        // Dialplan wiring.
        'context'         => env('ASTERISK_CONTEXT', 'from-internal'),
        'technology'      => env('ASTERISK_TECHNOLOGY', 'PJSIP'),
        'trunk'           => env('ASTERISK_TRUNK', ''),
        'outbound_prefix' => env('ASTERISK_OUTBOUND_PREFIX', ''),
        'caller_id'       => env('ASTERISK_CALLER_ID', 'IVF CRM'),

        // Seconds Asterisk rings the agent's extension before giving up.
        'originate_timeout' => (int) env('ASTERISK_ORIGINATE_TIMEOUT', 30),

        // How the outbound leg is dialled. `agent_first` rings the agent, then
        // bridges to the customer — the usual click-to-call behaviour, and the
        // only mode that works when agents are on softphones.
        'originate_mode' => env('ASTERISK_ORIGINATE_MODE', 'agent_first'),

        // Where Asterisk writes MixMonitor files, and how the CRM reaches them.
        'recording' => [
            'enabled'    => env('ASTERISK_RECORDING_ENABLED', true),
            'disk'       => env('ASTERISK_RECORDING_DISK', 'local'),
            'path'       => env('ASTERISK_RECORDING_PATH', 'call-recordings'),
            // Absolute path on the Asterisk box, for reference in the setup UI.
            'server_path' => env('ASTERISK_RECORDING_SERVER_PATH', '/var/spool/asterisk/monitor'),
            'format'     => env('ASTERISK_RECORDING_FORMAT', 'wav'),
        ],

        // Shared secret the dialplan sends with every webhook call.
        'webhook_token' => env('ASTERISK_WEBHOOK_TOKEN'),
    ],

    // MicroSIP provisioning: the CRM generates a ready-to-import .ini so an
    // agent never has to type SIP settings by hand.
    'microsip' => [
        'download_enabled' => true,
        'defaults' => [
            'srtp'              => 'disabled',
            'publish_presence'  => 0,
            'register_refresh'  => 300,
            'keep_alive'        => 15,
            'voicemail_number'  => '*97',
            'dtmf_method'       => 0, // RFC2833
        ],
        'stun_server' => env('MICROSIP_STUN_SERVER', ''),
    ],

    'whatsapp_calling' => [
        'enabled' => env('WHATSAPP_CALLING_ENABLED', true),

        // Meta requires an approved, time-boxed permission before a business
        // may call a user. Permissions last 7 days / 5 calls by default.
        'permission_ttl_days' => 7,

        // Business hours during which WhatsApp shows the call button.
        'call_hours' => [
            'status'   => env('WHATSAPP_CALL_HOURS_STATUS', 'ENABLED'),
            'timezone' => env('WHATSAPP_CALL_HOURS_TZ', 'Asia/Kolkata'),
        ],

        // A WhatsApp call is WebRTC. To carry audio it must terminate on a
        // WebRTC-capable endpoint; this names the Asterisk endpoint used to
        // bridge Meta's media to the agent's softphone.
        'bridge' => [
            'enabled'  => env('WHATSAPP_CALL_BRIDGE_ENABLED', false),
            'endpoint' => env('WHATSAPP_CALL_BRIDGE_ENDPOINT', 'whatsapp-gateway'),
            'context'  => env('WHATSAPP_CALL_BRIDGE_CONTEXT', 'from-whatsapp'),
        ],
    ],

    // Seconds an agent stays in `wrapup` after a call before returning to idle.
    'wrapup_seconds' => (int) env('TELEPHONY_WRAPUP_SECONDS', 30),

    // Follow-ups can only be scheduled during working hours (24h clock).
    'followup_hours' => [
        'start' => (int) env('FOLLOWUP_HOURS_START', 9),
        'end'   => (int) env('FOLLOWUP_HOURS_END', 20),
    ],

    // Every call must be dispositioned before the agent can dial again.
    // Overridable at runtime from Tata Teleservices → Configuration.
    'force_disposition' => env('TELEPHONY_FORCE_DISPOSITION', true),

    // Live transcript in the agent's browser (Web Speech API) + one-line summary.
    'transcription' => [
        'enabled'  => env('TELEPHONY_TRANSCRIPTION', true),
        // BCP-47 tag Web Speech should listen in. en-IN handles Hinglish reasonably.
        'language' => env('TELEPHONY_TRANSCRIPTION_LANG', 'en-IN'),
    ],

    // Hangup causes worth surfacing distinctly in reports.
    'hangup_causes' => [
        16 => 'Normal clearing',
        17 => 'User busy',
        18 => 'No user response',
        19 => 'No answer',
        20 => 'Subscriber absent',
        21 => 'Call rejected',
        27 => 'Destination out of order',
        28 => 'Invalid number',
        31 => 'Normal, unspecified',
        34 => 'No circuit available',
        38 => 'Network out of order',
    ],
];
