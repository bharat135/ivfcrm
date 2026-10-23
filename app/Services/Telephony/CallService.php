<?php

namespace App\Services\Telephony;

use App\Models\CallEvent;
use App\Models\CallLog;
use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\Patient;
use App\Models\SipExtension;
use App\Models\User;
use App\Services\LeadService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Everything that creates or mutates a `call_logs` row.
 *
 * Asterisk (click-to-call, inbound), WhatsApp calling and manual logging all
 * funnel through here so a call is recorded, attributed to a lead/patient,
 * mirrored into the lead timeline and reflected in agent presence exactly once,
 * in one place.
 */
class CallService
{
    public function __construct(
        private AsteriskManager $ami,
        private SmartfloClient $tata,
        private LeadService $leads,
    ) {}

    // -----------------------------------------------------------------
    // Provider selection
    // -----------------------------------------------------------------

    /**
     * Which system places calls right now: `asterisk` or `tata`. The setting
     * wins over config so an admin can switch without a deploy.
     */
    public function provider(): string
    {
        $configured = app(\App\Support\SettingsRepository::class)->get('telephony', 'provider');

        $provider = strtolower((string) ($configured ?: config('telephony.provider', 'asterisk')));

        return in_array($provider, ['asterisk', 'tata'], true) ? $provider : 'asterisk';
    }

    public function usesTata(): bool
    {
        return $this->provider() === 'tata';
    }

    public function providerLabel(): string
    {
        return $this->usesTata() ? 'Tata Smartflo' : 'Asterisk';
    }

    /**
     * Every call must be dispositioned. When enabled (default), an agent with a
     * finished, un-dispositioned call cannot place the next one.
     */
    public function forceDisposition(): bool
    {
        $setting = app(\App\Support\SettingsRepository::class)->get('telephony', 'force_disposition');

        return $setting === null ? (bool) config('telephony.force_disposition', true) : (bool) $setting;
    }

    /** The agent's most recent finished call that still has no outcome. */
    public function pendingDisposition(User $agent): ?CallLog
    {
        return CallLog::query()
            ->where('user_id', $agent->id)
            ->whereNull('disposition_id')
            ->whereNotNull('ended_at')
            ->where('provider', '!=', 'manual')
            ->where('ended_at', '>=', now()->subHours(48))
            ->latest('ended_at')
            ->first();
    }

    private function assertNoPendingDisposition(User $agent): void
    {
        if (! $this->forceDisposition()) {
            return;
        }

        if ($pending = $this->pendingDisposition($agent)) {
            throw new TelephonyException(
                'Save the outcome of your last call (' . $pending->contactName() . ') before dialling again.',
                null,
                ['pending_call_id' => $pending->id],
            );
        }
    }

    /** Caller id for a Tata call: the agent's own DID, else their centre's, else the default DID, else the account setting. */
    public function tataCallerIdFor(User $agent): ?string
    {
        return \App\Models\TataNumber::callerIdFor($agent->center_id, $agent->id) ?: $this->tata->callerId();
    }

    /**
     * The number Smartflo rings for an agent: their mapped Tata agent number,
     * falling back to the mobile on their profile.
     */
    public function tataAgentNumber(User $agent): ?string
    {
        // When routing to the Smartflo softphone, ring the agent's Smartflo
        // extension / agent id (login-based calling delivers it to the app)
        // instead of their mobile.
        if ($this->dialVia() === 'softphone') {
            $tataAgent = $agent->relationLoaded('tataAgent') ? $agent->tataAgent : $agent->tataAgent()->first();
            $softphoneId = $tataAgent?->extension ?: $tataAgent?->agent_id;

            if ($softphoneId) {
                return $softphoneId;
            }
        }

        $number = $agent->tata_agent_number ?: $agent->phone;

        if (! $number) {
            return null;
        }

        // Agent ids (050…) and extensions (060…) are passed through untouched;
        // a mobile is reduced to digits so "+91-98…" and "98…" agree.
        return preg_match('/^0[56]0\d+$/', $number) ? $number : $this->normalize($number);
    }

    /** How Smartflo should reach the agent's first leg: `mobile` or `softphone`. */
    public function dialVia(): string
    {
        $value = app(\App\Support\SettingsRepository::class)->get('telephony', 'tata_dial_via')
            ?: config('telephony.tata.dial_via', 'mobile');

        return in_array($value, ['mobile', 'softphone'], true) ? $value : 'mobile';
    }

    /**
     * `agent_first` (Smartflo click-to-call: agent rings, answers, then the
     * customer is dialled) or `customer_first` (Click-to-Call Support API:
     * the customer is dialled first and the agent only rings once they answer).
     */
    public function callOrder(): string
    {
        $value = app(\App\Support\SettingsRepository::class)->get('telephony', 'tata_call_order')
            ?: config('telephony.tata.call_order', 'agent_first');

        return $value === 'customer_first' ? 'customer_first' : 'agent_first';
    }

    public function customerFirst(): bool
    {
        return $this->usesTata() && $this->callOrder() === 'customer_first';
    }

    /** The Click-to-Call Support key that rings this agent: theirs, else the account's. */
    public function c2cSupportKeyFor(User $agent): ?string
    {
        return $agent->tata_c2c_support_key ?: $this->tata->c2cSupportKey();
    }

    // -----------------------------------------------------------------
    // Number handling
    // -----------------------------------------------------------------

    /**
     * Reduce a number to digits and drop an Indian country code so CRM lookups
     * and PBX dialling agree on one representation.
     */
    /**
     * The clinic only calls Indian mobiles, so every outbound leg is checked
     * here regardless of which provider carries it. Returns the bare ten
     * digits Smartflo / Asterisk should dial.
     */
    private function dialableMobile(string $destination): string
    {
        $ten = \App\Support\PhoneNumber::mobile($destination);

        if ($ten === null) {
            throw new TelephonyException('Only 10-digit Indian mobile numbers can be dialled.');
        }

        return $ten;
    }

    public function normalize(string $number): string
    {
        $digits = preg_replace('/\D/', '', $number);

        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }

        // 91XXXXXXXXXX -> XXXXXXXXXX for a 10-digit Indian mobile.
        if (strlen($digits) === 12 && str_starts_with($digits, '91')) {
            $digits = substr($digits, 2);
        }

        return $digits;
    }

    /**
     * Find the CRM record behind a phone number, matching on the last 10 digits
     * so stored formats do not matter.
     */
    public function resolveContact(string $number): array
    {
        $tail = substr($this->normalize($number), -10);

        if (strlen($tail) < 10) {
            return ['lead' => null, 'patient' => null];
        }

        $lead = Lead::whereNull('merged_into_id')
            ->where(fn (Builder $q) => $q->where('mobile', 'like', '%' . $tail)->orWhere('alt_mobile', 'like', '%' . $tail))
            ->orderByDesc('created_at')
            ->first();

        $patient = Patient::where(fn (Builder $q) => $q->where('mobile', 'like', '%' . $tail)->orWhere('alt_mobile', 'like', '%' . $tail))
            ->first();

        return ['lead' => $lead, 'patient' => $patient];
    }

    // -----------------------------------------------------------------
    // Outbound: click-to-call
    // -----------------------------------------------------------------

    /**
     * Place a call by ringing the agent's softphone, then dialling the customer.
     *
     * The CallLog row is created *before* the Originate so a PBX failure is
     * still visible in the agent's history with its error, rather than silently
     * disappearing.
     */
    public function clickToCall(
        User $agent,
        string $destination,
        ?Lead $lead = null,
        ?Patient $patient = null,
    ): CallLog {
        $this->assertNoPendingDisposition($agent);

        if ($this->usesTata()) {
            return $this->clickToCallViaTata($agent, $destination, $lead, $patient);
        }

        $extension = $agent->sipExtension;

        if (! $extension) {
            throw new AsteriskException(
                'No SIP extension is assigned to your account. Ask an administrator to create one under Settings → Telephony.'
            );
        }

        if (! $extension->is_active) {
            throw new AsteriskException('Your SIP extension is disabled. Contact an administrator.');
        }

        $number = $this->dialableMobile($destination);

        // Fall back to whatever the number resolves to when no record was passed.
        if (! $lead && ! $patient) {
            ['lead' => $lead, 'patient' => $patient] = $this->resolveContact($number);
        }

        $call = CallLog::create([
            'lead_id'     => $lead?->id,
            'patient_id'  => $patient?->id,
            'user_id'     => $agent->id,
            'center_id'   => $agent->center_id,
            'provider'    => 'asterisk',
            'channel'     => 'pstn',
            'direction'   => 'outbound',
            'from_number' => $extension->caller_id ?: $extension->extension,
            'to_number'   => $number,
            'extension'   => $extension->extension,
            'status'      => 'ringing',
            'started_at'  => now(),
            'is_recorded' => $extension->record_calls,
        ]);

        // Correlates the AMI Originate with the dialplan's webhook callbacks.
        $token = (string) Str::uuid();

        $call->update(['meta' => ['originate_token' => $token]]);

        try {
            $response = $this->ami->originate(
                extension: $extension->extension,
                destination: $number,
                variables: [
                    'CRM_CALL_ID'  => $call->id,
                    'CRM_TOKEN'    => $token,
                    'CRM_LEAD_ID'  => $lead?->id ?? '',
                    'CRM_AGENT_ID' => $agent->id,
                    'CRM_RECORD'   => $extension->record_calls ? '1' : '0',
                ],
                callerId: $extension->caller_id ?: null,
            );

            $this->recordEvent($call, 'asterisk', 'originate', $token, $response);
        } catch (AsteriskException $e) {
            $call->update([
                'status'       => 'failed',
                'hangup_cause' => 'originate_failed',
                'remark'       => $e->displayMessage(),
                'ended_at'     => now(),
            ]);

            Log::warning('Asterisk originate failed', [
                'call_log_id' => $call->id,
                'error'       => $e->getMessage(),
            ]);

            throw $e;
        }

        $this->setAgentStatus($agent, 'ringing', $call);
        $this->touchLead($lead);

        return $call->fresh();
    }

    /**
     * Click-to-call through Tata Smartflo: the cloud PBX rings the agent's
     * registered number first, then bridges the customer. Everything after the
     * request (answered, hangup, recording) arrives on the Tata webhook.
     */
    public function clickToCallViaTata(
        User $agent,
        string $destination,
        ?Lead $lead = null,
        ?Patient $patient = null,
    ): CallLog {
        if (! $this->tata->isConfigured()) {
            throw new TataException(
                'Tata Smartflo is not configured. Add the API credentials under Settings → Telephony.'
            );
        }

        $agentNumber = $this->tataAgentNumber($agent);

        if (! $agentNumber) {
            throw new TataException(
                'No Tata Smartflo agent number is set on your account. Ask an administrator to add it under Settings → Users.'
            );
        }

        $customerFirst = $this->customerFirst();
        $supportKey    = $customerFirst ? $this->c2cSupportKeyFor($agent) : null;

        if ($customerFirst && ! $supportKey) {
            throw new TataException(
                'Customer-first calling needs a Smartflo Click-to-Call Support API key. Add one under Tata → Configuration, or on your user profile.'
            );
        }

        $number = $this->dialableMobile($destination);

        if (! $lead && ! $patient) {
            ['lead' => $lead, 'patient' => $patient] = $this->resolveContact($number);
        }

        $call = CallLog::create([
            'lead_id'     => $lead?->id,
            'patient_id'  => $patient?->id,
            'user_id'     => $agent->id,
            'center_id'   => $agent->center_id,
            'provider'    => 'tata',
            'channel'     => 'pstn',
            'direction'   => 'outbound',
            'from_number' => $callerId = $this->tataCallerIdFor($agent),
            'to_number'   => $number,
            'extension'   => $agentNumber,
            'status'      => 'ringing',
            'started_at'  => now(),
            'is_recorded' => true,
        ]);

        // Smartflo echoes custom_identifier on every webhook for this call,
        // which is how the hangup event finds its way back to this row.
        $custom = ['crm_call_id' => (string) $call->id];

        $call->update(['meta' => [
            'custom_identifier' => $custom,
            'agent_number'      => $agentNumber,
            'call_order'        => $customerFirst ? 'customer_first' : 'agent_first',
        ]]);

        try {
            $response = $customerFirst
                ? $this->tata->clickToCallSupport(
                    customerNumber: $number,
                    apiKey: $supportKey,
                    callerId: $callerId,
                    custom: $custom,
                )
                : $this->tata->clickToCall(
                    agentNumber: $agentNumber,
                    destination: $number,
                    callerId: $callerId,
                    custom: $custom,
                );
        } catch (TataException $e) {
            $call->update([
                'status'       => 'failed',
                'hangup_cause' => 'originate_failed',
                'remark'       => $e->displayMessage(),
                'ended_at'     => now(),
            ]);

            Log::warning('Smartflo click-to-call failed', [
                'call_log_id' => $call->id,
                'error'       => $e->getMessage(),
            ]);

            throw $e;
        }

        $call->update([
            'provider_call_id' => $response['ref_id'] ?: null,
            'meta'             => array_merge($call->meta ?? [], ['ref_id' => $response['ref_id']]),
        ]);

        $this->recordEvent($call, 'tata', 'originate', $response['ref_id'], $response);
        $this->setAgentStatus($agent, 'ringing', $call);
        $this->touchLead($lead);

        return $call->fresh();
    }

    /**
     * Open a call log for a call the agent's in-browser softphone places
     * itself (WebRTC/SIP straight to Smartflo), so the customer's phone rings
     * with no agent leg to answer. No Smartflo REST call is made here — the
     * browser originates the audio; this only records it so disposition,
     * history and the bulk-call queue behave exactly as for click-to-call.
     */
    public function openWebrtcCall(User $agent, string $destination, ?Lead $lead = null, ?Patient $patient = null): CallLog
    {
        $this->assertNoPendingDisposition($agent);

        $number = $this->dialableMobile($destination);

        if (! $lead && ! $patient) {
            ['lead' => $lead, 'patient' => $patient] = $this->resolveContact($number);
        }

        $extension = $this->tataAgentNumber($agent);

        $call = CallLog::create([
            'lead_id'     => $lead?->id,
            'patient_id'  => $patient?->id,
            'user_id'     => $agent->id,
            'center_id'   => $agent->center_id,
            'provider'    => 'tata',
            'channel'     => 'webrtc',
            'direction'   => 'outbound',
            'from_number' => $this->tataCallerIdFor($agent),
            'to_number'   => $number,
            'extension'   => $extension,
            'status'      => 'ringing',
            'started_at'  => now(),
            'is_recorded' => true,
            'meta'        => ['origin' => 'webrtc'],
        ]);

        $this->recordEvent($call, 'webrtc', 'originate', null, ['destination' => $number]);
        $this->setAgentStatus($agent, 'ringing', $call);
        $this->touchLead($lead);

        return $call->fresh();
    }

    /**
     * Hang up the agent's live channel. Falls back to looking the channel up on
     * the PBX when the CRM never learned its name.
     */
    public function hangup(CallLog $call): void
    {
        if ($call->provider === 'tata') {
            $this->hangupViaTata($call);

            return;
        }

        $channel = $call->meta['channel'] ?? null;

        if (! $channel && $call->extension) {
            $channel = $this->ami->findChannelForExtension($call->extension)['channel'] ?? null;
        }

        if ($channel) {
            $this->ami->hangup($channel);
        }

        // Do not wait for the Hangup webhook — the agent expects instant feedback.
        $this->finalize($call, [
            'status'     => $call->answered_at ? 'completed' : 'cancelled',
            'hangup_by'  => 'agent',
        ]);
    }

    private function hangupViaTata(CallLog $call): void
    {
        // Smartflo only knows the call by its own call id, which normally
        // arrives with the first webhook. When no webhook has come yet (or the
        // webhooks point elsewhere), look the call up among the account's
        // live calls so Disconnect still drops the real leg.
        $callId = $call->channel_id ?: $call->linked_id ?: $this->findLiveTataCallId($call);

        if ($callId) {
            try {
                $this->tata->hangup($callId);
            } catch (TataException $e) {
                Log::info('Smartflo hangup ignored', ['call_log_id' => $call->id, 'error' => $e->getMessage()]);
            }
        }

        $this->finalize($call, [
            'status'    => $call->answered_at ? 'completed' : 'cancelled',
            'hangup_by' => 'agent',
        ]);
    }

    /**
     * Find our outbound call among Smartflo's live calls: by the echoed
     * custom_identifier when present, else by the customer number together
     * with the agent leg. Null when it is not (or no longer) live.
     */
    private function findLiveTataCallId(CallLog $call): ?string
    {
        try {
            $live = $this->tata->liveCalls();
        } catch (TataException $e) {
            Log::info('Smartflo live-call lookup skipped', ['call_log_id' => $call->id, 'error' => $e->getMessage()]);

            return null;
        }

        $digits = fn ($v) => substr(preg_replace('/\D+/', '', (string) $v), -10);
        $customer = $digits($call->to_number);
        $agent    = $digits($call->meta['agent_number'] ?? $call->extension);

        foreach ($live as $row) {
            if (! is_array($row)) {
                continue;
            }

            $id = $row['call_id'] ?? $row['id'] ?? $row['uuid'] ?? null;
            if (! $id) {
                continue;
            }

            $custom = $row['custom_identifier'] ?? null;
            if (is_string($custom) && str_contains($custom, '{')) {
                $custom = json_decode($custom, true);
            }
            if (is_array($custom) && (string) ($custom['crm_call_id'] ?? '') === (string) $call->id) {
                return (string) $id;
            }

            $rowCustomer = $digits($row['client_number'] ?? $row['customer_number'] ?? $row['call_to_number'] ?? $row['destination'] ?? '');
            $rowAgent    = $digits($row['agent_number'] ?? $row['caller_id_number'] ?? $row['from'] ?? '');

            if ($customer && $rowCustomer === $customer && (! $agent || ! $rowAgent || $rowAgent === $agent)) {
                return (string) $id;
            }
        }

        return null;
    }

    /**
     * Blind-transfer a live call to another agent's extension.
     */
    public function transfer(CallLog $call, User $target): CallLog
    {
        if ($call->provider === 'tata') {
            throw new TataException('Transfers for Tata Smartflo calls are done from the Smartflo agent panel or the handset.');
        }

        $targetExtension = $target->sipExtension;

        if (! $targetExtension) {
            throw new AsteriskException($target->name . ' does not have a SIP extension.');
        }

        $channel = $call->meta['bridged_channel'] ?? $call->meta['channel'] ?? null;

        if (! $channel) {
            throw new AsteriskException('This call has no live channel to transfer.');
        }

        $this->ami->redirect($channel, $targetExtension->extension);

        $call->update(['transferred_to' => $target->id]);
        $this->recordEvent($call, 'crm', 'transfer', $call->channel_id, [
            'to_user'      => $target->id,
            'to_extension' => $targetExtension->extension,
        ]);

        // The transferred leg is a new row so each agent's talk time is its own.
        $child = CallLog::create([
            'lead_id'            => $call->lead_id,
            'patient_id'         => $call->patient_id,
            'user_id'            => $target->id,
            'center_id'          => $target->center_id,
            'provider'           => 'asterisk',
            'channel'            => $call->channel,
            'direction'          => $call->direction,
            'from_number'        => $call->from_number,
            'to_number'          => $call->to_number,
            'extension'          => $targetExtension->extension,
            'status'             => 'ringing',
            'started_at'         => now(),
            'parent_call_log_id' => $call->id,
            'linked_id'          => $call->linked_id,
        ]);

        $this->setAgentStatus($target, 'ringing', $child);

        return $child;
    }

    // -----------------------------------------------------------------
    // Inbound + lifecycle (driven by dialplan webhooks)
    // -----------------------------------------------------------------

    /**
     * Open a log row for an inbound call the PBX just received.
     */
    public function recordInbound(array $data): CallLog
    {
        return $this->recordExternal(['direction' => 'inbound'] + $data);
    }

    /**
     * Open a log row for a call the CRM did not originate: an inbound call, or
     * an outbound call an agent dialled from the Smartflo panel / handset.
     */
    public function recordExternal(array $data): CallLog
    {
        $direction = ($data['direction'] ?? 'inbound') === 'outbound' ? 'outbound' : 'inbound';
        $provider  = $data['provider'] ?? 'asterisk';

        $customer = $this->normalize((string) ($direction === 'inbound'
            ? ($data['from_number'] ?? '')
            : ($data['to_number'] ?? '')));

        ['lead' => $lead, 'patient' => $patient] = $this->resolveContact($customer);

        $agent = null;
        if (! empty($data['agent_id'])) {
            $agent = User::find($data['agent_id']);
        }
        if (! $agent && ! empty($data['extension']) && $provider === 'asterisk') {
            $agent = SipExtension::where('extension', $data['extension'])->first()?->user;
        }
        if (! $agent && ! empty($data['agent_number'])) {
            $agent = $this->userForTataNumber((string) $data['agent_number']);
        }

        // An unrecognised caller who matches a lead goes to that lead's owner.
        if (! $agent && $lead?->assigned_to) {
            $agent = $lead->assignedTo;
        }

        $startedAt = isset($data['started_at'])
            ? \Illuminate\Support\Carbon::parse($data['started_at'])
            : now();

        $call = CallLog::create([
            'lead_id'          => $lead?->id,
            'patient_id'       => $patient?->id,
            'user_id'          => $agent?->id,
            'center_id'        => $agent?->center_id ?? $lead?->center_id,
            'provider'         => $provider,
            'channel'          => $data['channel'] ?? 'pstn',
            'direction'        => $direction,
            'from_number'      => $direction === 'inbound' ? $customer : ($data['from_number'] ?? null),
            'to_number'        => $direction === 'inbound' ? ($data['to_number'] ?? null) : $customer,
            'extension'        => isset($data['extension']) || isset($data['agent_number'])
                ? mb_substr((string) ($data['extension'] ?? $data['agent_number']), 0, 64)
                : null,
            'queue'            => $data['queue'] ?? null,
            'provider_call_id' => $data['provider_call_id'] ?? null,
            'channel_id'       => $data['channel_id'] ?? null,
            'linked_id'        => $data['linked_id'] ?? null,
            'status'           => 'ringing',
            'started_at'       => $startedAt,
            'is_recorded'      => $provider === 'tata',
        ]);

        if ($agent) {
            $this->setAgentStatus($agent, 'ringing', $call);
        }

        $this->touchLead($lead);

        return $call;
    }

    /**
     * Correlate a dialplan event back to its CallLog row.
     */
    public function locate(array $data): ?CallLog
    {
        foreach ([
            ['provider_call_id', $data['provider_call_id'] ?? null],
            ['channel_id', $data['channel_id'] ?? null],
            ['linked_id', $data['linked_id'] ?? null],
            ['provider_call_id', $data['channel_id'] ?? null],
        ] as [$column, $value]) {
            if ($value && $call = CallLog::where($column, $value)->latest('id')->first()) {
                return $call;
            }
        }

        if (! empty($data['crm_call_id']) && $call = CallLog::find($data['crm_call_id'])) {
            return $call;
        }

        // Originate correlation token, set as a channel variable.
        if (! empty($data['token'])) {
            return CallLog::where('meta->originate_token', $data['token'])->latest('id')->first();
        }

        return null;
    }

    public function markRinging(CallLog $call, array $data = []): CallLog
    {
        $call->update(array_filter([
            'status'     => 'ringing',
            'channel_id' => $data['channel_id'] ?? $call->channel_id,
            'linked_id'  => $data['linked_id'] ?? $call->linked_id,
            'meta'       => array_merge($call->meta ?? [], array_filter([
                'channel' => $data['channel'] ?? null,
            ])),
        ]));

        return $call;
    }

    public function markAnswered(CallLog $call, array $data = []): CallLog
    {
        $answeredAt = isset($data['timestamp'])
            ? \Illuminate\Support\Carbon::parse($data['timestamp'])
            : now();

        $call->update([
            'status'       => 'answered',
            'answered_at'  => $call->answered_at ?: $answeredAt,
            // Carbon 3 diffs are signed and read as (argument - receiver),
            // so the earlier instant must be the receiver.
            'ring_seconds' => $call->started_at ? max($call->started_at->diffInSeconds($answeredAt), 0) : 0,
            'channel_id'   => $data['channel_id'] ?? $call->channel_id,
            'linked_id'    => $data['linked_id'] ?? $call->linked_id,
            'meta'         => array_merge($call->meta ?? [], array_filter([
                'channel'         => $data['channel'] ?? null,
                'bridged_channel' => $data['bridged_channel'] ?? null,
            ])),
        ]);

        if ($call->user) {
            $this->setAgentStatus($call->user, 'on_call', $call);
        }

        return $call;
    }

    /**
     * Close a call out: durations, hangup cause, agent presence, lead timeline.
     */
    public function finalize(CallLog $call, array $data = []): CallLog
    {
        return DB::transaction(function () use ($call, $data) {
            $endedAt = isset($data['timestamp'])
                ? \Illuminate\Support\Carbon::parse($data['timestamp'])
                : now();

            $answeredAt = $call->answered_at;

            // Signed Carbon 3 diffs: earlier instant is always the receiver.
            $talk = $answeredAt ? max($answeredAt->diffInSeconds($endedAt), 0) : 0;
            $total = $call->started_at ? max($call->started_at->diffInSeconds($endedAt), 0) : $talk;

            // Trust the PBX's own duration when it sends one.
            $talk  = isset($data['talk_seconds']) ? (int) $data['talk_seconds'] : $talk;
            $total = isset($data['duration_seconds']) ? (int) $data['duration_seconds'] : $total;

            $status = $data['status'] ?? ($answeredAt ? 'completed' : 'no-answer');

            $call->update(array_filter([
                'status'           => $status,
                'ended_at'         => $endedAt,
                'talk_seconds'     => $talk,
                'duration_seconds' => $total,
                'ring_seconds'     => $call->ring_seconds ?: ($answeredAt && $call->started_at
                    ? max($call->started_at->diffInSeconds($answeredAt), 0) : $total),
                'hangup_cause'     => $data['hangup_cause'] ?? $call->hangup_cause,
                'hangup_by'        => $data['hangup_by'] ?? $call->hangup_by,
                'recording_path'   => $data['recording_path'] ?? $call->recording_path,
                'recording_disk'   => $data['recording_path'] ?? null
                    ? config('telephony.asterisk.recording.disk') : $call->recording_disk,
                'recording_url'    => $data['recording_url'] ?? $call->recording_url,
                'is_recorded'      => ! empty($data['recording_url']) || ! empty($data['recording_path']) ? true : null,
            ], fn ($v) => $v !== null));

            if ($call->user) {
                // With mandatory outcomes, wrap-up holds the agent until the
                // call is dispositioned. Otherwise they are free straight away:
                // the outcome is logged from the lead's profile whenever they
                // get to it.
                $this->setAgentStatus($call->user, $this->forceDisposition() ? 'wrapup' : 'idle', null);
            }

            $this->logToLead($call);
            $this->touchLead($call->lead);

            return $call->fresh();
        });
    }

    // -----------------------------------------------------------------
    // Disposition
    // -----------------------------------------------------------------

    /**
     * Attach an outcome to a finished call and push it through to the lead's
     * pipeline stage via the existing LeadService rules.
     */
    public function disposition(CallLog $call, array $data, bool $syncLead = true): CallLog
    {
        // A call note is one line: the transcript summary is offered as a
        // draft and whatever the agent saves is kept to a single line too.
        $remark = isset($data['remark']) ? trim(preg_replace('/\s+/', ' ', (string) $data['remark'])) : null;

        $call->update([
            'disposition_id'     => $data['disposition_id'] ?? null,
            'sub_disposition_id' => $data['sub_disposition_id'] ?? null,
            'remark'             => $remark !== null && $remark !== '' ? Str::limit($remark, 255, '') : $call->remark,
            'wrap_seconds'       => $call->ended_at ? max($call->ended_at->diffInSeconds(now()), 0) : 0,
        ]);

        if ($call->provider === 'tata') {
            // Mirror the outcome onto the Smartflo call record. Best effort;
            // a Smartflo hiccup must never block the agent.
            try {
                app(\App\Services\Telephony\Tata\TataSyncService::class)->pushCallOutcome($call->fresh());
            } catch (\Throwable $e) {
                Log::info('Outcome push to Smartflo skipped', ['call_log_id' => $call->id, 'error' => $e->getMessage()]);
            }
        }

        if ($syncLead && $call->lead && ! empty($data['disposition_id'])) {
            $this->leads->updateDisposition(
                $call->lead,
                (int) $data['disposition_id'],
                $data['sub_disposition_id'] ?? null,
                $data['remark'] ?? '',
                $data['next_follow_up_at'] ?? null,
            );
        }

        if ($call->user) {
            $this->setAgentStatus($call->user, 'idle', null);
        }

        return $call->fresh();
    }

    // -----------------------------------------------------------------
    // Agent presence
    // -----------------------------------------------------------------

    public function setAgentStatus(User $agent, string $status, ?CallLog $call = null): void
    {
        $agent->forceFill([
            'call_status'            => $status,
            'call_status_changed_at' => now(),
            'active_call_log_id'     => $call?->id,
        ])->save();
    }

    /**
     * Live snapshot for the supervisor wallboard.
     */
    public function agentBoard(?int $centerId = null): array
    {
        $agents = User::query()
            ->with(['sipExtension:id,user_id,extension,registration_status', 'activeCall:id,to_number,from_number,status,started_at,lead_id', 'activeCall.lead:id,name'])
            ->where('is_active', true)
            ->where(fn ($q) => $q->whereHas('sipExtension')->orWhereNotNull('tata_agent_number'))
            ->when($centerId, fn ($q) => $q->where('center_id', $centerId))
            ->orderBy('name')
            ->get();

        return $agents->map(fn (User $agent) => [
            'id'         => $agent->id,
            'name'       => $agent->name,
            'extension'  => $agent->sipExtension?->extension ?: $agent->tata_agent_number,
            'registered' => $agent->sipExtension?->registration_status,
            'status'     => $agent->call_status,
            'label'      => $agent->callStatusLabel(),
            'badge'      => $agent->callStatusBadge(),
            'since'      => $agent->call_status_changed_at?->diffForHumans(null, true),
            'call'       => $agent->activeCall ? [
                'id'      => $agent->activeCall->id,
                'number'  => $agent->activeCall->contactNumber(),
                'contact' => $agent->activeCall->lead?->name,
                'started' => $agent->activeCall->started_at?->diffForHumans(null, true),
            ] : null,
        ])->all();
    }

    // -----------------------------------------------------------------
    // Shared bookkeeping
    // -----------------------------------------------------------------

    /**
     * The CRM user behind a Smartflo agent number, matching on the last 10
     * digits so "+91 98…", "9198…" and "98…" all resolve to the same agent.
     */
    public function userForTataNumber(string $number): ?User
    {
        $number = trim($number);

        if ($number === '') {
            return null;
        }

        if ($user = User::where('tata_agent_number', $number)->first()) {
            return $user;
        }

        $tail = substr($this->normalize($number), -10);

        if (strlen($tail) < 10) {
            return null;
        }

        return User::where('is_active', true)
            ->where(fn (Builder $q) => $q->where('tata_agent_number', 'like', '%' . $tail)
                ->orWhere('phone', 'like', '%' . $tail))
            ->orderByRaw('tata_agent_number IS NULL')
            ->first();
    }

    public function recordEvent(?CallLog $call, string $source, string $event, ?string $correlationId, array $payload = []): CallEvent
    {
        return CallEvent::create([
            'call_log_id'    => $call?->id,
            'source'         => $source,
            'event'          => $event,
            'correlation_id' => $correlationId,
            'payload'        => $payload ?: null,
            'occurred_at'    => now(),
        ]);
    }

    /**
     * Mirror the call into the lead timeline so telecallers see one history.
     */
    private function logToLead(CallLog $call): void
    {
        if (! $call->lead_id) {
            return;
        }

        $outcome = $call->isConnected()
            ? 'Connected · ' . $call->duration_formatted
            : ucfirst(str_replace('-', ' ', (string) $call->status));

        LeadActivity::create([
            'lead_id'     => $call->lead_id,
            'user_id'     => $call->user_id,
            'type'        => 'call',
            'title'       => ucfirst($call->direction) . ' ' . ($call->channel === 'whatsapp' ? 'WhatsApp call' : 'call'),
            'description' => $outcome . ($call->remark ? ' — ' . $call->remark : ''),
            'properties'  => [
                'call_log_id' => $call->id,
                'channel'     => $call->channel,
                'direction'   => $call->direction,
                'duration'    => $call->talk_seconds,
                'status'      => $call->status,
            ],
        ]);
    }

    private function touchLead(?Lead $lead): void
    {
        if (! $lead) {
            return;
        }

        $lead->forceFill([
            'contact_attempts'   => $lead->contact_attempts + 1,
            'last_activity_at'   => now(),
            'first_contacted_at' => $lead->first_contacted_at ?: now(),
        ])->save();
    }
}
