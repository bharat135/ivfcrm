<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Center;
use App\Models\SipExtension;
use App\Models\User;
use App\Services\Meta\MetaException;
use App\Services\Meta\WhatsAppCallingService;
use App\Services\Telephony\AsteriskException;
use App\Services\Telephony\AsteriskManager;
use App\Services\Telephony\CallService;
use App\Services\Telephony\SmartfloClient;
use App\Services\Telephony\TataException;
use App\Support\SettingsRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * Settings → Telephony.
 *
 * Asterisk AMI credentials, per-agent SIP extensions, MicroSIP provisioning,
 * and WhatsApp calling configuration. Secrets go through SettingsRepository
 * (encrypted); SIP passwords are encrypted on the model.
 */
class TelephonyController extends Controller
{
    public function __construct(
        private SettingsRepository $settings,
        private AsteriskManager $ami,
        private SmartfloClient $tata,
        private CallService $calls,
        private WhatsAppCallingService $calling,
    ) {}

    public function index()
    {
        $extensions = SipExtension::with(['user:id,name,email', 'center:id,name'])
            ->orderBy('extension')
            ->get();

        return view('settings.telephony.index', [
            'telephony'   => $this->settings->masked('telephony'),
            'extensions'  => $extensions,
            'centers'     => Center::where('is_active', true)->orderBy('name')->get(),
            'agents'      => User::where('is_active', true)->orderBy('name')->get(['id', 'name', 'email']),
            'unassigned'  => User::where('is_active', true)->whereDoesntHave('sipExtension')->count(),
            'webhookUrl'  => url('/api/webhooks/asterisk'),
            'tataWebhookUrl' => url('/api/webhooks/tata'),
            'configured'  => $this->ami->isConfigured(),
            'tataConfigured' => $this->tata->isConfigured(),
            'provider'    => $this->calls->provider(),
            'tataAgents'  => User::where('is_active', true)->whereNotNull('tata_agent_number')->count(),
            'whatsappCallingEnabled' => (bool) config('telephony.whatsapp_calling.enabled'),
        ]);
    }

    // -----------------------------------------------------------------
    // Asterisk credentials
    // -----------------------------------------------------------------

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ami_host'        => ['nullable', 'string', 'max:191'],
            'ami_port'        => ['nullable', 'integer', 'min:1', 'max:65535'],
            'ami_username'    => ['nullable', 'string', 'max:120'],
            'ami_secret'      => ['nullable', 'string', 'max:255'],
            'context'         => ['nullable', 'string', 'max:60'],
            'technology'      => ['nullable', Rule::in(['PJSIP', 'SIP', 'IAX2'])],
            'trunk'           => ['nullable', 'string', 'max:120'],
            'outbound_prefix' => ['nullable', 'string', 'max:10'],
            'caller_id'       => ['nullable', 'string', 'max:120'],
            'webhook_token'   => ['nullable', 'string', 'max:255'],
            'sip_domain'      => ['nullable', 'string', 'max:191'],
            'recording_path'  => ['nullable', 'string', 'max:191'],

            // Provider switch + Tata Tele Business Services (Smartflo)
            'provider'           => ['nullable', Rule::in(['asterisk', 'tata'])],
            'tata_login_id'      => ['nullable', 'string', 'max:191'],
            'tata_password'      => ['nullable', 'string', 'max:255'],
            'tata_access_token'  => ['nullable', 'string', 'max:4096'],
            'tata_caller_id'     => ['nullable', 'string', 'max:20', 'regex:/^\+?[0-9]{6,15}$/'],
            'tata_webhook_token' => ['nullable', 'string', 'max:255'],
            'tata_call_timeout'  => ['nullable', 'integer', 'min:0', 'max:7200'],
        ], [
            'tata_caller_id.regex' => 'The caller ID must be a DID number assigned to your Smartflo account, digits only.',
        ]);

        // A masked value coming back unchanged must not overwrite the real one.
        foreach ($data as $key => $value) {
            if (is_string($value) && str_starts_with($value, '••••')) {
                unset($data[$key]);
            }
        }

        $this->settings->setMany($data, 'telephony');

        // New credentials invalidate any cached Smartflo login token.
        if (array_intersect_key($data, array_flip(['tata_login_id', 'tata_password', 'tata_access_token']))) {
            $this->tata->forgetToken();
        }

        return response()->json(['success' => true, 'message' => 'Telephony settings saved.']);
    }

    /**
     * Probe the active provider and report what actually works.
     */
    public function test(Request $request): JsonResponse
    {
        if (($request->input('provider') ?: $this->calls->provider()) === 'tata') {
            return $this->testTata();
        }

        $results = [];

        try {
            $ping = $this->ami->ping();

            $results['ami'] = [
                'ok'      => true,
                'message' => 'Connected to Asterisk ' . $ping['version'],
            ];
        } catch (AsteriskException $e) {
            return response()->json([
                'success' => true,
                'results' => ['ami' => ['ok' => false, 'message' => $e->displayMessage()]],
            ]);
        }

        try {
            $channels = $this->ami->activeChannels();
            $results['channels'] = [
                'ok'      => true,
                'message' => count($channels) . ' live channel(s) right now.',
            ];
        } catch (AsteriskException $e) {
            $results['channels'] = ['ok' => false, 'message' => $e->displayMessage()];
        }

        try {
            $states = $this->ami->endpointStates();
            $registered = collect($states)->filter(fn ($s) => ! str_contains(strtolower($s['status']), 'unavailable'))->count();

            $results['endpoints'] = [
                'ok'      => true,
                'message' => "{$registered} of " . count($states) . ' endpoint(s) reachable.',
            ];
        } catch (AsteriskException $e) {
            $results['endpoints'] = ['ok' => false, 'message' => $e->displayMessage()];
        }

        if (! $this->ami->webhookToken()) {
            $results['webhook'] = [
                'ok'      => false,
                'message' => 'No webhook token set — the dialplan cannot post call events, so calls will not be logged automatically.',
            ];
        }

        return response()->json(['success' => true, 'results' => $results]);
    }

    private function testTata(): JsonResponse
    {
        $results = [];

        if (! $this->tata->isConfigured()) {
            return response()->json([
                'success' => true,
                'results' => ['smartflo' => ['ok' => false, 'message' => 'Enter a Smartflo API token, or the login id and password, then save.']],
            ]);
        }

        try {
            $ping = $this->tata->ping();

            $results['smartflo'] = [
                'ok'      => true,
                'message' => "Connected. {$ping['users']} Smartflo user(s), {$ping['agents']} with an agent number or extension.",
            ];
        } catch (TataException $e) {
            return response()->json([
                'success' => true,
                'results' => ['smartflo' => ['ok' => false, 'message' => $e->displayMessage()]],
            ]);
        }

        $results['caller_id'] = $this->tata->callerId()
            ? ['ok' => true, 'message' => 'Calls will show ' . $this->tata->callerId() . ' to the customer.']
            : ['ok' => false, 'message' => 'No caller ID (DID) set — Smartflo will use the account pilot number.'];

        $results['webhook'] = $this->tata->webhookToken()
            ? ['ok' => true, 'message' => 'Webhook token set. Point the Smartflo webhooks at ' . url('/api/webhooks/tata') . '?token=…']
            : ['ok' => false, 'message' => 'No webhook token — Smartflo call events will be rejected, so answered/hangup/recordings will not be logged.'];

        $mapped = User::where('is_active', true)->whereNotNull('tata_agent_number')->count();
        $results['agents'] = [
            'ok'      => $mapped > 0,
            'message' => $mapped > 0
                ? "{$mapped} CRM user(s) have a Tata agent number."
                : 'No CRM user has a Tata agent number yet — set one under Settings → Users (falls back to the profile phone).',
        ];

        return response()->json(['success' => true, 'results' => $results]);
    }

    /**
     * The agents Smartflo knows about, so an admin can copy the right number
     * onto each CRM user.
     */
    public function tataAgents(): JsonResponse
    {
        try {
            $agents = $this->tata->users();
        } catch (TataException $e) {
            return response()->json(['success' => false, 'message' => $e->displayMessage()], 422);
        }

        $byNumber = User::whereNotNull('tata_agent_number')->get(['id', 'name', 'tata_agent_number'])
            ->keyBy(fn (User $u) => preg_replace('/\D/', '', (string) $u->tata_agent_number));

        return response()->json([
            'success' => true,
            'agents'  => collect($agents)->map(function (array $a) use ($byNumber) {
                $digits = preg_replace('/\D/', '', (string) ($a['agent_number'] ?? ''));
                $match  = $digits !== '' ? ($byNumber[$digits] ?? $byNumber[substr($digits, -10)] ?? null) : null;

                return $a + ['crm_user' => $match?->name];
            })->values(),
        ]);
    }

    /**
     * Pull live registration state into the extension list.
     */
    public function syncRegistrations(): JsonResponse
    {
        try {
            $states = $this->ami->endpointStates();
        } catch (AsteriskException $e) {
            return response()->json(['success' => false, 'message' => $e->displayMessage()], 422);
        }

        $updated = 0;

        foreach ($states as $extension => $state) {
            $updated += SipExtension::where('extension', $extension)->update([
                'registration_status' => $state['status'],
                'last_registered_at'  => str_contains(strtolower($state['status']), 'unavailable') ? null : now(),
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => "Registration state refreshed for {$updated} extension(s).",
        ]);
    }

    // -----------------------------------------------------------------
    // SIP extensions
    // -----------------------------------------------------------------

    public function storeExtension(Request $request): JsonResponse
    {
        $data = $this->extensionRules($request);

        $extension = SipExtension::create($data);

        return response()->json([
            'success' => true,
            'message' => 'Extension ' . $extension->extension . ' created.',
            'id'      => $extension->id,
        ]);
    }

    public function editExtension(SipExtension $extension): JsonResponse
    {
        return response()->json([
            'id'           => $extension->id,
            'user_id'      => $extension->user_id,
            'center_id'    => $extension->center_id,
            'extension'    => $extension->extension,
            'username'     => $extension->username,
            'domain'       => $extension->domain,
            'port'         => $extension->port,
            'transport'    => $extension->transport,
            'context'      => $extension->context,
            'caller_id'    => $extension->caller_id,
            'technology'   => $extension->technology,
            'webrtc'       => $extension->webrtc,
            'record_calls' => $extension->record_calls,
            'is_active'    => $extension->is_active,
        ]);
    }

    public function updateExtension(Request $request, SipExtension $extension): JsonResponse
    {
        $data = $this->extensionRules($request, $extension);

        // Blank password means "keep the existing secret".
        if (empty($data['password'])) {
            unset($data['password']);
        }

        $extension->update($data);

        return response()->json(['success' => true, 'message' => 'Extension updated.']);
    }

    public function destroyExtension(SipExtension $extension): JsonResponse
    {
        $extension->delete();

        return response()->json(['success' => true, 'message' => 'Extension removed.']);
    }

    /**
     * Rotate the SIP secret and hand back the new one once.
     */
    public function regenerateSecret(SipExtension $extension): JsonResponse
    {
        $secret = SipExtension::generateSecret();

        $extension->update(['password' => $secret]);

        return response()->json([
            'success'  => true,
            'message'  => 'New secret generated. Re-download the MicroSIP profile — the old one will stop registering.',
            'password' => $secret,
        ]);
    }

    /**
     * Reveal the SIP secret to an administrator (for manual configuration).
     */
    public function revealSecret(Request $request, SipExtension $extension): JsonResponse
    {
        abort_unless($request->user()->hasRole(['Super Admin', 'Admin']), 403);

        return response()->json([
            'success'  => true,
            'password' => $extension->password,
            'server'   => $extension->domain ?: $this->ami->host(),
            'uri'      => $extension->sipUri(),
        ]);
    }

    // -----------------------------------------------------------------
    // MicroSIP provisioning
    // -----------------------------------------------------------------

    /**
     * Download a ready-to-import MicroSIP profile.
     *
     * Agents may only fetch their own; administrators may fetch any.
     */
    public function microsipConfig(Request $request, ?SipExtension $extension = null): Response
    {
        $user = $request->user();

        $extension = $extension ?: $user->sipExtension;

        abort_unless($extension, 404, 'No SIP extension is assigned to you yet.');

        if ($extension->user_id !== $user->id) {
            abort_unless($user->hasRole(['Super Admin', 'Admin', 'Center Manager']), 403);
        }

        // Fall back to the PBX host so a half-filled extension still works.
        if (! $extension->domain) {
            $extension->domain = $this->settings->get('telephony', 'sip_domain') ?: $this->ami->host();
        }

        $filename = 'microsip-' . $extension->extension . '.ini';

        return response($extension->toMicroSipIni(), 200, [
            'Content-Type'        => 'text/plain; charset=windows-1252',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }

    /**
     * The pjsip.conf / sip.conf block for this extension, so an administrator
     * can paste it straight into the PBX.
     */
    public function pbxConfig(Request $request, SipExtension $extension): JsonResponse
    {
        abort_unless($request->user()->hasRole(['Super Admin', 'Admin']), 403);

        $config = $extension->technology === 'PJSIP'
            ? $this->pjsipBlock($extension)
            : $this->chanSipBlock($extension);

        return response()->json(['success' => true, 'config' => $config]);
    }

    private function pjsipBlock(SipExtension $extension): string
    {
        $e = $extension->extension;
        $context = $extension->context;
        $transport = $extension->webrtc ? 'transport-wss' : 'transport-' . strtolower($extension->transport);

        $lines = [
            "[{$e}]",
            'type=endpoint',
            "context={$context}",
            'disallow=all',
            'allow=ulaw,alaw,opus',
            "auth={$e}",
            "aors={$e}",
            "transport={$transport}",
            'direct_media=no',
            'force_rport=yes',
            'rewrite_contact=yes',
            'rtp_symmetric=yes',
            "callerid=" . ($extension->caller_id ?: $e) . " <{$e}>",
        ];

        if ($extension->webrtc) {
            array_push(
                $lines,
                'webrtc=yes',
                'use_avpf=yes',
                'media_encryption=dtls',
                'dtls_verify=fingerprint',
                'dtls_setup=actpass',
                'ice_support=yes',
                'media_use_received_transport=yes',
            );
        }

        $lines = array_merge($lines, [
            '',
            "[{$e}]",
            'type=auth',
            'auth_type=userpass',
            "username={$extension->username}",
            "password={$extension->password}",
            '',
            "[{$e}]",
            'type=aor',
            'max_contacts=1',
            'remove_existing=yes',
            'qualify_frequency=60',
        ]);

        return implode("\n", $lines);
    }

    private function chanSipBlock(SipExtension $extension): string
    {
        return implode("\n", [
            "[{$extension->extension}]",
            'type=friend',
            "secret={$extension->password}",
            "context={$extension->context}",
            'host=dynamic',
            'disallow=all',
            'allow=ulaw,alaw',
            'nat=force_rport,comedia',
            'qualify=yes',
            "callerid=" . ($extension->caller_id ?: $extension->extension) . " <{$extension->extension}>",
        ]);
    }

    // -----------------------------------------------------------------
    // WhatsApp calling
    // -----------------------------------------------------------------

    public function whatsappCallingStatus(): JsonResponse
    {
        if (! $this->calling->isEnabled()) {
            return response()->json([
                'success' => false,
                'message' => 'Connect WhatsApp under Settings → Integrations first.',
            ], 422);
        }

        try {
            $settings = $this->calling->callingSettings();
        } catch (MetaException $e) {
            return response()->json(['success' => false, 'message' => $e->displayMessage()], 422);
        }

        return response()->json([
            'success'  => true,
            'settings' => $settings,
            'sip'      => strtoupper((string) ($settings['sip']['status'] ?? 'DISABLED')) === 'ENABLED',
        ]);
    }

    public function enableWhatsappCalling(Request $request): JsonResponse
    {
        $data = $request->validate([
            'status'        => ['nullable', Rule::in(['ENABLED', 'DISABLED'])],
            'call_hours'    => ['nullable', 'boolean'],
            'open_time'     => ['nullable', 'date_format:H:i'],
            'close_time'    => ['nullable', 'date_format:H:i'],
            'sip_hostname'  => ['nullable', 'string', 'max:191'],
            'sip_port'      => ['nullable', 'integer', 'min:1', 'max:65535'],
        ]);

        try {
            if (! empty($data['sip_hostname'])) {
                // Preferred mode: Meta routes calls straight to our Asterisk.
                $response = $this->calling->configureSipServer(
                    $data['sip_hostname'],
                    (int) ($data['sip_port'] ?? 5061),
                );

                $this->settings->setMany([
                    'whatsapp_sip_hostname' => $data['sip_hostname'],
                    'whatsapp_sip_port'     => $data['sip_port'] ?? 5061,
                ], 'telephony');
            } else {
                $options = ['status' => $data['status'] ?? 'ENABLED'];

                if ($request->boolean('call_hours')) {
                    $options['call_hours'] = $this->calling->defaultCallHours(
                        $data['open_time'] ?? '09:00',
                        $data['close_time'] ?? '19:00',
                    );
                }

                $response = $this->calling->enableCalling($options);
            }
        } catch (MetaException $e) {
            return response()->json(['success' => false, 'message' => $e->displayMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'message' => ! empty($data['sip_hostname'])
                ? 'WhatsApp calls will now be routed to your Asterisk server over SIP.'
                : 'WhatsApp calling updated.',
            'response' => $response,
        ]);
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    private function extensionRules(Request $request, ?SipExtension $extension = null): array
    {
        $data = $request->validate([
            'user_id'      => ['nullable', 'exists:users,id',
                               Rule::unique('sip_extensions', 'user_id')->ignore($extension?->id)->whereNotNull('user_id')],
            'center_id'    => ['nullable', 'exists:centers,id'],
            'extension'    => ['required', 'string', 'max:20', 'regex:/^[0-9]+$/',
                               Rule::unique('sip_extensions', 'extension')->ignore($extension?->id)],
            'username'     => ['nullable', 'string', 'max:80'],
            'password'     => ['nullable', 'string', 'min:8', 'max:64'],
            'domain'       => ['nullable', 'string', 'max:191'],
            'port'         => ['nullable', 'integer', 'min:1', 'max:65535'],
            'transport'    => ['nullable', Rule::in(['udp', 'tcp', 'tls', 'ws', 'wss'])],
            'context'      => ['nullable', 'string', 'max:60'],
            'caller_id'    => ['nullable', 'string', 'max:60'],
            'technology'   => ['nullable', Rule::in(['PJSIP', 'SIP', 'IAX2'])],
            'webrtc'       => ['boolean'],
            'record_calls' => ['boolean'],
            'is_active'    => ['boolean'],
        ], [
            'extension.regex'      => 'An extension must be numeric, e.g. 1001.',
            'user_id.unique'       => 'That agent already has an extension.',
        ]);

        $data['webrtc']       = $request->boolean('webrtc');
        $data['record_calls'] = $request->boolean('record_calls', true);
        $data['is_active']    = $request->boolean('is_active', true);
        $data['domain']       = $data['domain'] ?? ($this->settings->get('telephony', 'sip_domain') ?: $this->ami->host());
        $data['context']      = $data['context'] ?? $this->ami->context();
        $data['technology']   = $data['technology'] ?? $this->ami->technology();

        return $data;
    }
}
