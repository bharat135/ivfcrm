<?php

namespace App\Services\Telephony;

use App\Support\SettingsRepository;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Tata Tele Business Services — Smartflo cloud telephony REST client.
 *
 * Smartflo is a hosted PBX: there is no socket to hold and no dialplan to
 * write. Click-to-call rings the agent's registered mobile / Smartflo
 * extension first, then bridges the customer, and every subsequent event
 * (answered, hangup, recording) arrives on the CRM webhook that the admin
 * configures in the Smartflo portal under API Connect → Webhook.
 *
 * Credentials live in `settings` (group `telephony`, keys `tata_*`) and are
 * encrypted by SettingsRepository because they end in `_password` /
 * `_access_token`. Two auth modes are supported:
 *   - a long-lived API token generated in the Smartflo portal (preferred), or
 *   - login id + password, exchanged for a one-hour bearer token that is cached.
 */
class SmartfloClient
{
    private const TOKEN_CACHE_KEY = 'telephony.tata.access_token';

    public function __construct(private SettingsRepository $settings) {}

    // -----------------------------------------------------------------
    // Configuration
    // -----------------------------------------------------------------

    public function credential(string $key, mixed $default = null): mixed
    {
        $value = $this->settings->get('telephony', $key);

        return ($value === null || $value === '') ? $default : $value;
    }

    public function baseUrl(): string
    {
        return rtrim((string) $this->credential('tata_base_url', config('telephony.tata.base_url')), '/');
    }

    public function loginId(): ?string
    {
        return $this->credential('tata_login_id', config('telephony.tata.login_id'));
    }

    public function password(): ?string
    {
        return $this->credential('tata_password', config('telephony.tata.password'));
    }

    /** A long-lived token minted in the Smartflo portal; skips the login call. */
    public function apiToken(): ?string
    {
        return $this->credential('tata_access_token', config('telephony.tata.access_token'));
    }

    /** The DID shown to the customer. Must be assigned to the Smartflo account. */
    public function callerId(): ?string
    {
        return $this->credential('tata_caller_id', config('telephony.tata.caller_id'));
    }

    public function webhookToken(): ?string
    {
        return $this->credential('tata_webhook_token', config('telephony.tata.webhook_token'));
    }

    public function callTimeout(): int
    {
        return (int) $this->credential('tata_call_timeout', config('telephony.tata.call_timeout'));
    }

    public function isConfigured(): bool
    {
        return (bool) ($this->apiToken() || ($this->loginId() && $this->password()));
    }

    // -----------------------------------------------------------------
    // Authentication
    // -----------------------------------------------------------------

    /**
     * Bearer token for API calls: the portal token if one is set, otherwise a
     * cached login token refreshed shortly before Smartflo expires it.
     */
    public function accessToken(bool $fresh = false): string
    {
        $stored  = $this->apiToken();
        $expires = $this->tokenExpiresAt();
        $hasCreds = $this->loginId() && $this->password();

        // A stored token with no recorded expiry is treated as a long-lived
        // portal API token. One with an expiry is used until it lapses.
        if ($stored && ! $fresh && ($expires === null || now()->lt($expires))) {
            return $stored;
        }

        // Expired (or forced) and we have credentials — mint a fresh one and
        // persist it, so the panel and the scheduled refresher stay in sync.
        if ($hasCreds) {
            return $this->refreshAccessToken()['access_token'];
        }

        if ($stored) {
            return $stored; // best effort; may be expired
        }

        throw new TataException(
            'Tata Smartflo is not configured. Add the API token, or the login id and password, under Settings → Telephony.'
        );
    }

    /** When the stored token expires (null = unknown/long-lived portal token). */
    public function tokenExpiresAt(): ?\Illuminate\Support\Carbon
    {
        $raw = $this->credential('tata_token_expires_at');

        if (! $raw) {
            return null;
        }

        try {
            return \Illuminate\Support\Carbon::parse($raw);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Log in with the saved credentials, store the new token and its expiry,
     * and return the login result.
     */
    public function refreshAccessToken(): array
    {
        $result = $this->login();
        $this->storeToken($result);

        return $result;
    }

    /**
     * Persist a login result as the active token + expiry. A safety margin is
     * subtracted so the token is refreshed before Smartflo actually expires it.
     */
    public function storeToken(array $result): void
    {
        $ttl = max((int) ($result['expires_in'] ?? 3600) - 60, 60);

        $this->settings->set('telephony', 'tata_access_token', (string) $result['access_token']);
        $this->settings->set('telephony', 'tata_token_expires_at', now()->addSeconds($ttl)->toDateTimeString());

        Cache::forget(self::TOKEN_CACHE_KEY);
    }

    /**
     * Does the token need refreshing now? True when credentials exist and the
     * token is missing or lapses within $within seconds. Used by the scheduler.
     */
    public function tokenNeedsRefresh(int $within): bool
    {
        if (! $this->canLogin()) {
            return false; // nothing to refresh with
        }

        $expires = $this->tokenExpiresAt();

        // No token, or a token whose expiry was never recorded (pasted by hand,
        // or minted before expiry tracking existed): mint one we can track.
        // Login tokens only last about an hour, so an untracked one is far more
        // likely to be dead than long-lived.
        if (! $this->apiToken() || $expires === null) {
            return true;
        }

        return now()->addSeconds($within)->gte($expires);
    }

    /** Login id + password are saved, so a token can be minted on demand. */
    public function canLogin(): bool
    {
        return (bool) ($this->loginId() && $this->password());
    }

    /**
     * POST /v1/auth/login — exchange the portal login for a bearer token.
     *
     * @return array{access_token:string, expires_in:int}
     */
    public function login(): array
    {
        return $this->loginWith((string) $this->loginId(), (string) $this->password());
    }

    /**
     * POST /v1/auth/login with an explicit email + password, used by the
     * "Generate token" action so a token can be minted before credentials are
     * saved.
     *
     * @return array{access_token:string, token_type:string, expires_in:int, number_of_days_left:?int}
     */
    public function loginWith(string $email, string $password): array
    {
        if ($email === '' || $password === '') {
            throw new TataException('Enter both the Smartflo login email and password to generate a token.');
        }

        $response = $this->send(fn () => $this->http(withAuth: false)->post('/v1/auth/login', [
            'email'    => $email,
            'password' => $password,
        ]), 'Could not sign in to Tata Smartflo.');

        $body = $response->json() ?: [];

        if (empty($body['access_token'])) {
            throw TataException::fromResponse('Tata Smartflo rejected the login email or password.', $body, $response->status());
        }

        return [
            'access_token'        => (string) $body['access_token'],
            'token_type'          => (string) ($body['token_type'] ?? 'bearer'),
            'expires_in'          => (int) ($body['expires_in'] ?? 3600),
            'number_of_days_left' => isset($body['number_of_days_left']) ? (int) $body['number_of_days_left'] : null,
        ];
    }

    public function forgetToken(): void
    {
        Cache::forget(self::TOKEN_CACHE_KEY);
        $this->settings->forget('telephony', 'tata_token_expires_at');
    }

    // -----------------------------------------------------------------
    // Calls
    // -----------------------------------------------------------------

    /**
     * POST /v1/click_to_call — ring the agent, then bridge the customer.
     *
     * @param  array<string,string>  $custom  Echoed back on every webhook as
     *                                        `custom_identifier`; used to
     *                                        correlate the call to its log row.
     * @return array{success:bool, message:string, ref_id:?string}
     */
    public function clickToCall(
        string $agentNumber,
        string $destination,
        ?string $callerId = null,
        array $custom = [],
        ?int $timeout = null,
    ): array {
        $payload = array_filter([
            'agent_number'       => $agentNumber,
            'destination_number' => $destination,
            'caller_id'          => $callerId ?: $this->callerId(),
            'async'              => 1,
            'call_timeout'       => $timeout ?: ($this->callTimeout() ?: null),
            'custom_identifier'  => $custom ?: null,
        ], fn ($v) => $v !== null && $v !== '');

        $response = $this->request('POST', '/v1/click_to_call', $payload, 'Tata Smartflo could not place the call.');

        $body = $response->json() ?: [];

        if (! ($body['success'] ?? false)) {
            throw TataException::fromResponse('Tata Smartflo refused the click-to-call request.', $body, $response->status());
        }

        return [
            'success' => true,
            'message' => (string) ($body['message'] ?? 'Call originated successfully.'),
            'ref_id'  => isset($body['ref_id']) ? (string) $body['ref_id'] : ($body['call_id'] ?? null),
        ];
    }

    /**
     * POST /v1/call/hangup — end a live call by its Smartflo call id.
     */
    public function hangup(string $callId): array
    {
        $response = $this->request('POST', '/v1/call/hangup', ['call_id' => $callId], 'Tata Smartflo could not hang up the call.');

        return $response->json() ?: [];
    }

    /**
     * GET /v1/live_calls — calls in progress on the account right now.
     */
    public function liveCalls(): array
    {
        $response = $this->request('GET', '/v1/live_calls', [], 'Tata Smartflo could not list live calls.');

        $body = $response->json() ?: [];

        return $body['results'] ?? $body['data'] ?? (array_is_list($body) ? $body : []);
    }

    /**
     * GET /v1/call/records — call detail records for a window.
     */
    public function callRecords(array $filters = []): array
    {
        $response = $this->request('GET', '/v1/call/records', $filters, 'Tata Smartflo could not fetch call records.');

        return $response->json() ?: [];
    }

    // -----------------------------------------------------------------
    // Account
    // -----------------------------------------------------------------

    /**
     * GET /v1/users — every Smartflo user with their agent number/extension,
     * so an admin can map CRM agents to the numbers Smartflo will ring.
     *
     * @return list<array{id:int|string, name:string, agent_number:?string, extension:?string, login_id:?string, active:bool}>
     */
    public function users(): array
    {
        $out = [];
        $lastSeen = null;

        do {
            $query = array_filter(['limit' => 100, 'last_seen_id' => $lastSeen], fn ($v) => $v !== null);

            $response = $this->request('GET', '/v1/users', $query, 'Tata Smartflo could not list users.');
            $body = $response->json() ?: [];
            $rows = $body['results'] ?? $body['data'] ?? (array_is_list($body) ? $body : []);

            foreach ($rows as $row) {
                $out[] = [
                    'id'           => $row['id'] ?? null,
                    'name'         => (string) ($row['name'] ?? $row['agent']['name'] ?? ''),
                    'agent_number' => $row['agent']['follow_me_number'] ?? $row['follow_me_number'] ?? null,
                    'agent_id'     => $row['agent']['id'] ?? null,
                    'extension'    => $row['extension'] ?? null,
                    'login_id'     => $row['login_id'] ?? null,
                    'active'       => (int) ($row['user_status'] ?? $row['agent']['status'] ?? 1) === 1,
                ];
            }

            $lastSeen = ($body['has_more'] ?? false) ? ($body['last_seen_id'] ?? null) : null;
        } while ($lastSeen !== null && count($out) < 2000);

        return $out;
    }

    /**
     * A cheap authenticated call that proves the credentials work.
     */
    public function ping(): array
    {
        $users = $this->users();

        return [
            'ok'     => true,
            'users'  => count($users),
            'agents' => count(array_filter($users, fn ($u) => ! empty($u['agent_number']) || ! empty($u['extension']))),
        ];
    }

    // -----------------------------------------------------------------
    // Users / agents (write side)
    // -----------------------------------------------------------------

    /**
     * POST /v1/user — create a Smartflo user with an agent (and extension).
     *
     * @return array{id:?string, agent_id:?string, agent_extension:?string, raw:array}
     */
    public function createUser(array $attributes): array
    {
        $payload = array_merge([
            'create_agent'        => true,
            'status'              => true,
            'login_based_calling' => true,
            'assign_extension'    => true,
            'block_web_login'     => false,
            'user_for_cdr'        => ['type' => 'agent', 'value' => []],
        ], $attributes);

        $response = $this->request('POST', '/v1/user', $payload, 'Tata Smartflo could not create the user.');
        $body = $response->json() ?: [];

        if (($body['success'] ?? true) === false) {
            throw TataException::fromResponse('Tata Smartflo refused to create the user.', $body, $response->status());
        }

        return [
            'id'              => isset($body['id']) ? (string) $body['id'] : (isset($body['user_id']) ? (string) $body['user_id'] : null),
            'agent_id'        => isset($body['agent_id']) ? (string) $body['agent_id'] : null,
            'agent_extension' => isset($body['agent_extension']) ? (string) $body['agent_extension'] : null,
            'raw'             => $body,
        ];
    }

    /** PATCH /v1/user/{id} — update name, number, status, calling flags. */
    public function patchUser(string $smartfloUserId, array $attributes): array
    {
        $response = $this->request('PATCH', '/v1/user/' . rawurlencode($smartfloUserId), $attributes, 'Tata Smartflo could not update the user.');
        $body = $response->json() ?: [];

        if (($body['success'] ?? true) === false) {
            throw TataException::fromResponse('Tata Smartflo refused the user update.', $body, $response->status());
        }

        return $body;
    }

    /** GET /v1/user/{id} */
    public function user(string $smartfloUserId): array
    {
        $response = $this->request('GET', '/v1/user/' . rawurlencode($smartfloUserId), [], 'Tata Smartflo could not fetch the user.');

        return $response->json() ?: [];
    }

    // -----------------------------------------------------------------
    // Dispositions (dialer)
    // -----------------------------------------------------------------

    /** GET /v1/dialer/disposition_list */
    public function dispositionLists(): array
    {
        $response = $this->request('GET', '/v1/dialer/disposition_list', [], 'Tata Smartflo could not list disposition lists.');

        return $this->rows($response->json() ?: []);
    }

    /** POST /v1/dialer/disposition_list */
    public function createDispositionList(string $name, string $description = ''): array
    {
        $response = $this->request('POST', '/v1/dialer/disposition_list', [
            'name'        => $name,
            'description' => $description ?: $name,
            'status'      => 1,
        ], 'Tata Smartflo could not create the disposition list.');

        $body = $response->json() ?: [];

        if (($body['success'] ?? true) === false) {
            throw TataException::fromResponse('Tata Smartflo refused to create the disposition list.', $body, $response->status());
        }

        return $body;
    }

    /**
     * GET /v1/dialer/disposition_status — all statuses, optionally for one list.
     * Rows are normalised to id / name / code / parent_id / list_id.
     */
    public function dispositionStatuses(?string $listId = null): array
    {
        // Smartflo lists statuses per list, with the list id in the PATH:
        // GET /v1/dialer/disposition_status/{listId} -> { success, data:[...] }.
        $listIds = $listId !== null
            ? [(string) $listId]
            : collect($this->dispositionLists())->pluck('id')->filter()->map(fn ($v) => (string) $v)->all();

        $out = [];

        foreach ($listIds as $lid) {
            if ($lid === '') {
                continue;
            }

            $response = $this->request('GET', '/v1/dialer/disposition_status/' . rawurlencode($lid), [], 'Tata Smartflo could not list dispositions.');
            $body = $response->json() ?: [];
            $rows = $body['data'] ?? $body['results'] ?? (array_is_list($body) ? $body : []);

            foreach ($rows as $row) {
                if (! is_array($row)) {
                    continue;
                }

                $parent = $row['parent_disposition'] ?? $row['parentDisposition'] ?? $row['parent_id'] ?? $row['parent'] ?? null;
                $parent = is_array($parent) ? ($parent['id'] ?? null) : $parent;

                $out[] = [
                    'id'        => isset($row['id']) ? (string) $row['id'] : null,
                    'name'      => (string) ($row['name'] ?? ''),
                    'code'      => isset($row['code']) ? (string) $row['code'] : null,
                    'parent_id' => $parent !== null && $parent !== '' ? (string) $parent : null,
                    'list_id'   => $lid,
                    'raw'       => $row,
                ];
            }
        }

        return $out;
    }

    /** POST /v1/dialer/disposition_status */
    public function createDispositionStatus(string $name, string $code, ?string $listId = null, ?string $parentId = null): array
    {
        $response = $this->request('POST', '/v1/dialer/disposition_status', array_filter([
            'name'              => $name,
            'code'              => $code,
            'dispositionList'   => $listId,
            'parentDisposition' => $parentId,
        ], fn ($v) => $v !== null && $v !== ''), 'Tata Smartflo could not create the disposition.');

        $body = $response->json() ?: [];

        if (($body['success'] ?? true) === false) {
            throw TataException::fromResponse('Tata Smartflo refused to create the disposition.', $body, $response->status());
        }

        return $body;
    }

    /** POST /v1/dialer/disposition_status/bulk */
    public function bulkDispositionStatuses(string $listId, array $rows): array
    {
        $response = $this->request('POST', '/v1/dialer/disposition_status/bulk', [
            'dispositionList' => $listId,
            'data'            => array_values($rows),
        ], 'Tata Smartflo could not create the dispositions.');

        $body = $response->json() ?: [];

        if (($body['success'] ?? true) === false) {
            throw TataException::fromResponse('Tata Smartflo refused the bulk disposition create.', $body, $response->status());
        }

        return $body;
    }

    /** POST /v1/dialer/store-disposition — outcome of a dialer call. */
    public function storeDialerDisposition(string $uniqueId, string $dispositionStatusId, ?string $subStatusId = null, ?string $note = null): array
    {
        $response = $this->request('POST', '/v1/dialer/store-disposition', array_filter([
            'unique_id'              => $uniqueId,
            'disposition_status'     => $dispositionStatusId,
            'sub_disposition_status' => $subStatusId,
            'disposition_note'       => $note,
        ], fn ($v) => $v !== null && $v !== ''), 'Tata Smartflo could not store the disposition.');

        return $response->json() ?: [];
    }

    /** POST /v1/call/note/{id} — attach the CRM outcome to the Smartflo call record. */
    public function addCallNote(string $callId, string $message, ?string $agentDisposition = null): array
    {
        $response = $this->request('POST', '/v1/call/note/' . rawurlencode($callId), array_filter([
            'message'           => $message,
            'agent_disposition' => $agentDisposition,
        ], fn ($v) => $v !== null && $v !== ''), 'Tata Smartflo could not save the call note.');

        $body = $response->json() ?: [];

        if (($body['success'] ?? true) === false) {
            throw TataException::fromResponse('Tata Smartflo refused the call note.', $body, $response->status());
        }

        return $body;
    }

    // -----------------------------------------------------------------
    // Account reference data
    // -----------------------------------------------------------------

    /** GET /v1/my_number — DIDs on the account. */
    public function myNumbers(): array
    {
        $response = $this->request('GET', '/v1/my_number', [], 'Tata Smartflo could not list your numbers.');

        return $this->rows($response->json() ?: []);
    }

    /** GET /v1/departments */
    public function departments(): array
    {
        $response = $this->request('GET', '/v1/departments', [], 'Tata Smartflo could not list departments.');

        return $this->rows($response->json() ?: []);
    }

    /** GET /v1/ivrs */
    public function ivrs(): array
    {
        $response = $this->request('GET', '/v1/ivrs', [], 'Tata Smartflo could not list IVRs.');

        return $this->rows($response->json() ?: []);
    }

    // -----------------------------------------------------------------
    // Call operations (live)
    // -----------------------------------------------------------------

    /**
     * POST /v1/call/options — 1 monitor, 2 whisper, 3 barge, 4 transfer.
     */
    public function callOperation(int $type, array $params): array
    {
        $response = $this->request('POST', '/v1/call/options', ['type' => $type] + $params, 'Tata Smartflo could not perform the call operation.');

        $body = $response->json() ?: [];

        if (($body['success'] ?? true) === false) {
            throw TataException::fromResponse('Tata Smartflo refused the call operation.', $body, $response->status());
        }

        return $body;
    }

    public function transferCall(string $callIdOrRef, ?string $agentId = null, ?string $intercom = null, bool $isRef = false): array
    {
        return $this->callOperation(4, array_filter([
            $isRef ? 'ref_id' : 'call_id' => $callIdOrRef,
            'agent_id' => $agentId,
            'intercom' => $intercom,
        ], fn ($v) => $v !== null && $v !== ''));
    }

    public function monitorCall(string $callId, string $supervisorAgentId, string $mode = 'listen'): array
    {
        $type = match ($mode) {
            'whisper' => 2,
            'barge'   => 3,
            default   => 1,
        };

        return $this->callOperation($type, ['call_id' => $callId, 'agent_id' => $supervisorAgentId]);
    }

    // -----------------------------------------------------------------
    // Broadcasts + lead lists
    // -----------------------------------------------------------------

    /** POST /v1/broadcast/list — a lead list to attach a broadcast to. */
    public function createLeadList(string $name, string $description, array $fields = []): array
    {
        $response = $this->request('POST', '/v1/broadcast/list', array_filter([
            'name'        => $name,
            'description' => $description ?: $name,
            'field'       => $fields ?: null,
        ], fn ($v) => $v !== null), 'Tata Smartflo could not create the lead list.');

        $body = $response->json() ?: [];

        if (($body['success'] ?? true) === false) {
            throw TataException::fromResponse('Tata Smartflo refused to create the lead list.', $body, $response->status());
        }

        return $body;
    }

    /** GET /v1/broadcast/lists (best effort — used to find a list id by name). */
    public function leadLists(): array
    {
        $response = $this->send(fn () => $this->http()->get('/v1/broadcast/lists'), 'Tata Smartflo could not list lead lists.');

        if (! $response->successful()) {
            return [];
        }

        return $this->rows($response->json() ?: []);
    }

    /** POST /v1/broadcast/leads/{listId} — bulk add numbers to a list. */
    public function addLeads(string $listId, array $rows, string $duplicateOption = 'skip'): array
    {
        $response = $this->request('POST', '/v1/broadcast/leads/' . rawurlencode($listId), [
            'data'             => array_values($rows),
            'duplicate_option' => $duplicateOption,
        ], 'Tata Smartflo could not add leads to the list.');

        $body = $response->json() ?: [];

        if (($body['success'] ?? true) === false) {
            throw TataException::fromResponse('Tata Smartflo refused the leads.', $body, $response->status());
        }

        return $body;
    }

    /** POST /v1/broadcast */
    public function createBroadcast(array $payload): array
    {
        $response = $this->request('POST', '/v1/broadcast', $payload, 'Tata Smartflo could not create the broadcast.');

        $body = $response->json() ?: [];

        if (($body['success'] ?? true) === false) {
            throw TataException::fromResponse('Tata Smartflo refused to create the broadcast.', $body, $response->status());
        }

        return $body;
    }

    /** GET /v2/broadcasts */
    public function broadcasts(int $page = 1, int $limit = 100, ?string $broadcastId = null): array
    {
        $response = $this->request('GET', '/v2/broadcasts', array_filter([
            'page'         => $page,
            'limit'        => $limit,
            'broadcast_id' => $broadcastId,
        ]), 'Tata Smartflo could not list broadcasts.');

        return $this->rows($response->json() ?: []);
    }

    /** GET /v1/broadcast/{id} */
    public function broadcast(string $id): array
    {
        $response = $this->request('GET', '/v1/broadcast/' . rawurlencode($id), [], 'Tata Smartflo could not fetch the broadcast.');

        $body = $response->json() ?: [];
        $rows = $this->rows($body);

        return $rows[0] ?? $body;
    }

    /** POST /v1/broadcast/{start|pause|resume|end}/{id} */
    public function controlBroadcast(string $id, string $action): array
    {
        if (! in_array($action, ['start', 'pause', 'resume', 'end'], true)) {
            throw new TataException('Unknown broadcast action: ' . $action);
        }

        $response = $this->request('POST', '/v1/broadcast/' . $action . '/' . rawurlencode($id), [], "Tata Smartflo could not {$action} the broadcast.");

        $body = $response->json() ?: [];

        if (($body['success'] ?? true) === false) {
            throw TataException::fromResponse("Tata Smartflo refused to {$action} the broadcast.", $body, $response->status());
        }

        return $body;
    }

    /** GET /v1/broadcast/batch_status/{batchId} */
    public function broadcastBatchStatus(string $batchId): array
    {
        $response = $this->request('GET', '/v1/broadcast/batch_status/' . rawurlencode($batchId), [], 'Tata Smartflo could not fetch the batch status.');

        return $response->json() ?: [];
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    /** Smartflo wraps lists inconsistently: bare array, `results`, `data`, or a keyed object. */
    private function rows(array $body): array
    {
        if (array_is_list($body)) {
            return $body;
        }

        foreach (['results', 'data', 'items', 'list'] as $key) {
            if (isset($body[$key]) && is_array($body[$key])) {
                return array_values($body[$key]);
            }
        }

        return [];
    }

    // -----------------------------------------------------------------
    // Transport
    // -----------------------------------------------------------------

    private function http(bool $withAuth = true): PendingRequest
    {
        $request = Http::baseUrl($this->baseUrl())
            ->acceptJson()
            ->asJson()
            ->timeout((int) config('telephony.tata.timeout'))
            ->connectTimeout((int) config('telephony.tata.connect_timeout'));

        return $withAuth ? $request->withToken($this->accessToken()) : $request;
    }

    /**
     * Issue an authenticated request, transparently re-logging in once when a
     * cached login token has been revoked or expired early.
     */
    private function request(string $method, string $path, array $data, string $failure): Response
    {
        $call = fn () => match (strtoupper($method)) {
            'GET'    => $this->http()->get($path, $data),
            'PATCH'  => $this->http()->patch($path, $data),
            'PUT'    => $this->http()->put($path, $data),
            'DELETE' => $this->http()->delete($path, $data),
            default  => $this->http()->post($path, $data),
        };

        $response = $this->send($call, $failure);

        // Smartflo invalidates a login token early (another sign-in with the
        // same account, a password change, a portal-side logout), so a stored
        // expiry is not proof the token still works. Whenever we hold login
        // credentials, a rejected token is re-minted once and the call retried.
        if (in_array($response->status(), [401, 403], true) && $this->canLogin()) {
            Log::info('Smartflo rejected the access token; re-logging in', ['path' => $path, 'status' => $response->status()]);

            $this->forgetToken();
            $this->accessToken(fresh: true);
            $response = $this->send($call, $failure);
        }

        if ($response->status() === 401 || $response->status() === 403) {
            throw TataException::fromResponse(
                'Tata Smartflo rejected the credentials. Check the API token or login under Settings → Telephony.',
                $response->json() ?: [],
                $response->status(),
            );
        }

        if ($response->status() === 429) {
            throw new TataException('Tata Smartflo is rate-limiting requests. Try again in a moment.', 'Rate limited by Smartflo', $response->json() ?: []);
        }

        if ($response->serverError()) {
            Log::warning('Smartflo server error', ['path' => $path, 'status' => $response->status(), 'body' => $response->body()]);

            throw TataException::fromResponse($failure, $response->json() ?: [], $response->status());
        }

        return $response;
    }

    private function send(callable $call, string $failure): Response
    {
        try {
            return $call();
        } catch (ConnectionException $e) {
            Log::warning('Smartflo unreachable', ['error' => $e->getMessage()]);

            throw new TataException($failure . ' The Smartflo API could not be reached.');
        }
    }

    private function loginTtl(): int
    {
        // Refresh a minute before Smartflo's own expiry so a token is never
        // used in its final seconds.
        return max((int) config('telephony.tata.token_ttl') - 60, 60);
    }
}
