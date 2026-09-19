<?php

namespace App\Http\Controllers\Tata;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Telephony\CallService;
use App\Services\Telephony\SmartfloClient;
use App\Services\Telephony\Tata\CallSummaryService;
use App\Services\Telephony\TataException;
use App\Support\SettingsRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Tata Teleservices → Configuration: credentials, provider switch, webhook,
 * mandatory disposition, live transcript + AI note.
 */
class TataConfigController extends Controller
{
    public function __construct(
        private SettingsRepository $settings,
        private SmartfloClient $tata,
        private CallService $calls,
        private CallSummaryService $summaries,
    ) {}

    public function index()
    {
        $telephony = $this->settings->masked('telephony');

        return view('tata.config', [
            'telephony'      => $telephony,
            'ai'             => $this->settings->masked('ai'),
            'provider'       => $this->calls->provider(),
            'configured'     => $this->tata->isConfigured(),
            'webhookUrl'     => url('/api/webhooks/tata'),
            'forceDisposition' => $this->calls->forceDisposition(),
            'transcription'  => [
                'enabled'  => $this->settings->get('telephony', 'transcription_enabled', config('telephony.transcription.enabled')),
                'language' => $this->settings->get('telephony', 'transcription_language', config('telephony.transcription.language')),
            ],
            'aiEnabled'      => $this->summaries->aiEnabled(),
            'aiKeySet'       => (bool) $this->summaries->apiKey(),
            'aiModel'        => config('services.anthropic.model'),
            'tokenExpiresAt' => $this->tata->tokenExpiresAt(),
            'passwordDaysLeft' => $this->settings->get('telephony', 'tata_password_days_left'),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'provider'               => ['nullable', Rule::in(['asterisk', 'tata'])],
            'tata_login_id'          => ['nullable', 'string', 'max:191'],
            'tata_password'          => ['nullable', 'string', 'max:255'],
            'tata_access_token'      => ['nullable', 'string', 'max:4096'],
            'tata_caller_id'         => ['nullable', 'string', 'max:20', 'regex:/^\+?[0-9]{6,15}$/'],
            'tata_webhook_token'     => ['nullable', 'string', 'max:255'],
            'tata_call_timeout'      => ['nullable', 'integer', 'min:0', 'max:7200'],
            'tata_dial_via'          => ['nullable', Rule::in(['mobile', 'softphone'])],
            'force_disposition'      => ['nullable', 'boolean'],
            'transcription_enabled'  => ['nullable', 'boolean'],
            'transcription_language' => ['nullable', 'string', 'max:12', 'regex:/^[a-z]{2,3}(-[A-Za-z]{2,4})?$/'],
            'ai_summary_enabled'     => ['nullable', 'boolean'],
            'anthropic_api_key'      => ['nullable', 'string', 'max:255'],
            'softphone_enabled'      => ['nullable', 'boolean'],
            'sip_wss_server'         => ['nullable', 'string', 'max:255'],
            'sip_realm'              => ['nullable', 'string', 'max:191'],
            'sip_stun'               => ['nullable', 'string', 'max:255'],
            'sip_dial_prefix'        => ['nullable', 'string', 'max:10'],
        ], [
            'tata_caller_id.regex'         => 'The caller ID must be a DID assigned to your Smartflo account, digits only.',
            'transcription_language.regex' => 'Use a language tag such as en-IN, hi-IN or mr-IN.',
        ]);

        foreach ($data as $key => $value) {
            if (is_string($value) && str_starts_with($value, '••••')) {
                unset($data[$key]);
            }
        }

        // Checkboxes: absent means off, but only when the form actually carried them.
        foreach (['force_disposition', 'transcription_enabled', 'ai_summary_enabled', 'softphone_enabled'] as $flag) {
            if ($request->has($flag . '_present')) {
                $data[$flag] = $request->boolean($flag);
            }
        }

        $aiKey = $data['anthropic_api_key'] ?? null;
        unset($data['anthropic_api_key']);

        $this->settings->setMany($data, 'telephony');

        if ($aiKey) {
            $this->settings->set('ai', 'anthropic_api_key', $aiKey);
        }

        if (array_intersect_key($data, array_flip(['tata_login_id', 'tata_password', 'tata_access_token']))) {
            $this->tata->forgetToken();
        }

        return response()->json(['success' => true, 'message' => 'Tata Teleservices configuration saved.']);
    }

    /**
     * Generate a Smartflo access token from an email + password and store it as
     * `tata_access_token`, so later API calls use the token instead of logging
     * in every hour. The password entered here is used only for this exchange
     * unless the admin also saves it.
     */
    public function generateToken(Request $request): JsonResponse
    {
        $data = $request->validate([
            'tata_login_id' => ['nullable', 'string', 'max:191'],
            'tata_password' => ['nullable', 'string', 'max:255'],
        ]);

        // Fall back to already-saved credentials when the field is left blank
        // (or shows the masked placeholder).
        $email = $data['tata_login_id'] ?? null;
        if (! $email || str_starts_with($email, '••••')) {
            $email = $this->settings->get('telephony', 'tata_login_id');
        }

        $password = $data['tata_password'] ?? null;
        if (! $password || str_starts_with($password, '••••')) {
            $password = $this->settings->get('telephony', 'tata_password');
        }

        if (! $email || ! $password) {
            return response()->json(['success' => false, 'message' => 'Enter the Smartflo login email and password first.'], 422);
        }

        try {
            $result = $this->tata->loginWith((string) $email, (string) $password);
        } catch (TataException $e) {
            return response()->json(['success' => false, 'message' => $e->displayMessage()], 422);
        }

        // Remember the login so the scheduled refresher can mint new tokens
        // unattended, then store the token with its expiry.
        $this->settings->setMany([
            'tata_login_id' => $email,
            'tata_password' => $password,
        ], 'telephony');
        $this->tata->storeToken($result);

        $days = $result['number_of_days_left'];

        if ($days !== null) {
            $this->settings->set('telephony', 'tata_password_days_left', $days);
        }

        return response()->json([
            'success'     => true,
            'message'     => 'Token generated and saved. It is refreshed automatically before it expires.'
                . ($days !== null ? ' Password valid for ' . $days . ' more day(s).' : ''),
            'token_hint'  => '••••••••' . substr($result['access_token'], -4),
            'expires_in'  => $result['expires_in'],
            'days_left'   => $days,
        ]);
    }

    public function test(): JsonResponse
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
            $results['smartflo'] = ['ok' => true, 'message' => "Connected. {$ping['users']} Smartflo user(s), {$ping['agents']} with an agent number or extension."];
        } catch (TataException $e) {
            return response()->json(['success' => true, 'results' => ['smartflo' => ['ok' => false, 'message' => $e->displayMessage()]]]);
        }

        $results['caller_id'] = $this->tata->callerId()
            ? ['ok' => true, 'message' => 'Default caller ID ' . $this->tata->callerId() . ' (per-centre DIDs under Numbers override it).']
            : ['ok' => false, 'message' => 'No caller ID set — Smartflo will use the account pilot number.'];

        $results['webhook'] = $this->tata->webhookToken()
            ? ['ok' => true, 'message' => 'Webhook token set. Smartflo must post to ' . url('/api/webhooks/tata') . '/hangup?token=…']
            : ['ok' => false, 'message' => 'No webhook token — answered/hangup/recording events will be rejected.'];

        $mapped = User::where('is_active', true)->whereNotNull('tata_agent_number')->count();
        $results['agents'] = [
            'ok'      => $mapped > 0,
            'message' => $mapped > 0 ? "{$mapped} CRM user(s) have a Tata agent number." : 'No CRM user has a Tata agent number yet — sync or push agents.',
        ];

        $results['ai_note'] = $this->summaries->aiEnabled()
            ? ['ok' => true, 'message' => 'Call notes will be drafted by Claude (' . config('services.anthropic.model') . ').']
            : ['ok' => false, 'message' => 'No Anthropic API key — notes fall back to an extractive summary of the transcript.'];

        return response()->json(['success' => true, 'results' => $results]);
    }
}
