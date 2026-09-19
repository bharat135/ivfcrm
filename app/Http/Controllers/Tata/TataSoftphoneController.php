<?php

namespace App\Http\Controllers\Tata;

use App\Http\Controllers\Controller;
use App\Support\SettingsRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The in-browser softphone: a WebRTC (SIP over WSS) endpoint the agent
 * registers from the CRM itself, so no separate Smartflo softphone app is
 * needed. Registration credentials come from the agent's Smartflo extension;
 * Smartflo issues the SIP/WSS details, which an admin enters once here.
 *
 * Each agent stores only their own SIP auth password (encrypted, per-user,
 * under settings group `softphone`), so the browser can register.
 */
class TataSoftphoneController extends Controller
{
    public function __construct(private SettingsRepository $settings) {}

    public function index()
    {
        $user = auth()->user();

        return view('tata.softphone', [
            'server'   => $this->settings->get('telephony', 'sip_wss_server'),
            'port'     => $this->settings->get('telephony', 'sip_wss_port', 8089),
            'realm'    => $this->settings->get('telephony', 'sip_realm'),
            'stun'     => $this->settings->get('telephony', 'sip_stun'),
            'agent'    => $user->tataAgent,
            'authUser' => $user->tataAgent?->extension ?: $user->tata_agent_number,
            'hasSecret'=> $this->settings->has('softphone', 'sip_password_' . $user->id),
            'enabled'  => (bool) $this->settings->get('telephony', 'softphone_enabled', false),
        ]);
    }

    /** The agent saves their own SIP secret (never leaves the server otherwise). */
    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'sip_extension' => ['nullable', 'string', 'max:40'],
            'sip_password'  => ['nullable', 'string', 'max:120'],
        ]);

        $user = $request->user();

        if (! empty($data['sip_password'])) {
            // Stored under a per-user secret key so it is encrypted at rest.
            $this->settings->set('softphone', 'sip_password_' . $user->id, $data['sip_password']);
        }

        if (! empty($data['sip_extension'])) {
            $this->settings->set('softphone', 'sip_extension_' . $user->id, $data['sip_extension']);
        }

        return response()->json(['success' => true, 'message' => 'Softphone credentials saved.']);
    }

    /**
     * The current agent's own SIP registration details, delivered only to that
     * agent so the browser can register. The SIP secret never appears in page
     * source; it is fetched here over the authenticated session on demand.
     */
    public function credentials(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $this->settings->get('telephony', 'softphone_enabled', false)) {
            return response()->json(['success' => false, 'message' => 'The in-browser softphone is not enabled.'], 422);
        }

        $server = $this->settings->get('telephony', 'sip_wss_server');
        $ext    = $this->settings->get('softphone', 'sip_extension_' . $user->id) ?: $user->tataAgent?->extension ?: $user->tata_agent_number;
        $secret = $this->settings->get('softphone', 'sip_password_' . $user->id);

        if (! $server || ! $ext || ! $secret) {
            return response()->json([
                'success' => false,
                'message' => 'Softphone is not fully set up. Add the server (admin) and your SIP extension + password.',
            ], 422);
        }

        return response()->json([
            'success'   => true,
            'server'    => $server,
            'realm'     => $this->settings->get('telephony', 'sip_realm') ?: parse_url($server, PHP_URL_HOST),
            'stun'      => $this->settings->get('telephony', 'sip_stun'),
            'prefix'    => $this->settings->get('telephony', 'sip_dial_prefix', ''),
            'extension' => $ext,
            'password'  => $secret,
        ]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $user = $request->user();

        $this->settings->forget('softphone', 'sip_password_' . $user->id);
        $this->settings->forget('softphone', 'sip_extension_' . $user->id);

        return response()->json(['success' => true, 'message' => 'Softphone credentials removed from this device profile.']);
    }
}
