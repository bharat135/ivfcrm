<?php

namespace App\Services\Telephony\Tata;

use App\Models\CallLog;
use App\Models\Disposition;
use App\Models\TataAgent;
use App\Models\TataDepartment;
use App\Models\TataDisposition;
use App\Models\TataIvr;
use App\Models\TataNumber;
use App\Models\User;
use App\Services\Telephony\CallService;
use App\Services\Telephony\SmartfloClient;
use App\Services\Telephony\TataException;
use App\Support\SettingsRepository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Two-way configuration sync with Tata Smartflo.
 *
 * Agents:       Smartflo is the source of truth for agent numbers/extensions;
 *               the CRM can create (push) an agent for a user that has none.
 * Dispositions: the CRM is the source of truth; they are pushed into one
 *               Smartflo dialer disposition list, and anything created on the
 *               Smartflo side is pulled back so both catalogues match.
 * Numbers, departments, IVRs: pulled (read-only reference data).
 * Call records: pulled from the CDR API to backfill recordings and calls the
 *               webhook missed. Outcomes are pushed back as call notes.
 */
class TataSyncService
{
    public const DISPOSITION_LIST_NAME = 'IVF CRM';

    public function __construct(
        private SmartfloClient $tata,
        private CallService $calls,
        private SettingsRepository $settings,
    ) {}

    // -----------------------------------------------------------------
    // Agents
    // -----------------------------------------------------------------

    /**
     * Pull every Smartflo user, mirror it, and auto-link to CRM users by
     * login id / email, then by mobile.
     *
     * @return array{pulled:int, linked:int, unlinked:int}
     */
    public function pullAgents(): array
    {
        $rows = $this->tata->users();
        $linked = 0;
        $seen = [];

        foreach ($rows as $row) {
            $smartfloId = $row['id'] !== null ? (string) $row['id'] : null;
            $number = $this->digits($row['agent_number'] ?? null);

            $agent = TataAgent::query()
                ->when($smartfloId, fn ($q) => $q->where('smartflo_user_id', $smartfloId))
                ->when(! $smartfloId && $number, fn ($q) => $q->where('follow_me_number', $number))
                ->first() ?? new TataAgent();

            $agent->fill([
                'smartflo_user_id'       => $smartfloId,
                'agent_id'               => $row['agent_id'] ?? $agent->agent_id,
                'name'                   => $row['name'] ?: $agent->name,
                'login_id'               => $row['login_id'] ?? $agent->login_id,
                'email'                  => $row['email'] ?? $agent->email,
                'follow_me_number'       => $number ?: $agent->follow_me_number,
                'extension'              => $row['extension'] ?? $agent->extension,
                'is_login_based_calling' => (bool) ($row['is_login_based_calling'] ?? $row['login_based_calling'] ?? $agent->is_login_based_calling),
                'is_web_login'           => (bool) ($row['is_web_login'] ?? $agent->is_web_login),
                'is_active'              => (bool) ($row['active'] ?? true),
                'sync_direction'         => $agent->sync_direction ?: 'pulled',
                'last_synced_at'         => now(),
                'last_error'             => null,
                'raw'                    => $row,
            ]);

            if (! $agent->user_id && $user = $this->matchUser($agent)) {
                $agent->user_id = $user->id;
            }

            $agent->sync_status = $agent->user_id ? 'linked' : 'unlinked';
            $agent->save();

            if ($agent->user_id) {
                $linked++;
                $this->applyToUser($agent);
            }

            $seen[] = $agent->id;
        }

        // Agents that vanished from Smartflo are kept but flagged.
        TataAgent::whereNotIn('id', $seen)->whereNotNull('smartflo_user_id')->update(['is_active' => false]);

        return ['pulled' => count($rows), 'linked' => $linked, 'unlinked' => count($rows) - $linked];
    }

    /**
     * Create a Smartflo user + agent for a CRM user that has none, and link them.
     */
    public function pushAgent(User $user, ?string $password = null): TataAgent
    {
        $existing = TataAgent::where('user_id', $user->id)->first();

        if ($existing?->smartflo_user_id) {
            return $this->updateAgent($user);
        }

        $mobile = $this->digits($user->tata_agent_number ?: $user->phone);

        if (! $mobile || strlen($mobile) < 10) {
            throw new TataException('The user needs a mobile number (Phone or Tata agent number) before an agent can be created in Smartflo.');
        }

        $password = $password ?: Str::password(14, symbols: false);

        $result = $this->tata->createUser([
            'name'     => $user->name,
            'login_id' => $this->loginIdFor($user),
            'password' => $password,
            'email'    => $user->email,
            'number'   => $mobile,
        ]);

        $agent = $existing ?: new TataAgent(['user_id' => $user->id]);
        $agent->fill([
            'smartflo_user_id' => $result['id'],
            'agent_id'         => $result['agent_id'],
            'extension'        => $result['agent_extension'],
            'name'             => $user->name,
            'login_id'         => $this->loginIdFor($user),
            'email'            => $user->email,
            'follow_me_number' => $mobile,
            'is_login_based_calling' => true,
            'is_active'        => true,
            'sync_status'      => 'linked',
            'sync_direction'   => 'pushed',
            'last_synced_at'   => now(),
            'last_error'       => null,
            'raw'              => $result['raw'],
        ])->save();

        $this->applyToUser($agent);

        return $agent;
    }

    /** Push name / mobile / active flag changes for a linked user to Smartflo. */
    public function updateAgent(User $user): TataAgent
    {
        $agent = TataAgent::where('user_id', $user->id)->firstOrFail();

        if (! $agent->smartflo_user_id) {
            throw new TataException('This user is not linked to a Smartflo user yet.');
        }

        $mobile = $this->digits($user->tata_agent_number ?: $user->phone) ?: $agent->follow_me_number;

        $this->tata->patchUser($agent->smartflo_user_id, array_filter([
            'name'        => $user->name,
            'number'      => $mobile,
            'email'       => $user->email,
            'block_agent' => ! $user->is_active,
        ], fn ($v) => $v !== null && $v !== ''));

        $agent->fill([
            'name'             => $user->name,
            'email'            => $user->email,
            'follow_me_number' => $mobile,
            'is_active'        => (bool) $user->is_active,
            'sync_status'      => 'linked',
            'last_synced_at'   => now(),
            'last_error'       => null,
        ])->save();

        $this->applyToUser($agent);

        return $agent;
    }

    /**
     * Create a brand-new Smartflo user + agent from a full attribute set (the
     * "Create Smartflo user" form), mirror it, and optionally link to a CRM user.
     */
    public function createSmartfloUser(array $attributes, ?int $linkUserId = null): TataAgent
    {
        $result = $this->tata->createUser($attributes);

        $agent = new TataAgent();
        $agent->fill([
            'smartflo_user_id'       => $result['id'],
            'agent_id'               => $result['agent_id'],
            'extension'              => $result['agent_extension'],
            'name'                   => $attributes['name'] ?? null,
            'login_id'               => $attributes['login_id'] ?? null,
            'email'                  => $attributes['email'] ?? null,
            'follow_me_number'       => $this->digits($attributes['number'] ?? null),
            'is_login_based_calling' => (bool) ($attributes['login_based_calling'] ?? true),
            'is_web_login'           => ! (bool) ($attributes['block_web_login'] ?? false),
            'is_active'              => (bool) ($attributes['status'] ?? true),
            'sync_status'            => $linkUserId ? 'linked' : 'unlinked',
            'sync_direction'         => 'pushed',
            'last_synced_at'         => now(),
            'last_error'             => null,
            'raw'                    => $result['raw'],
        ]);

        if ($linkUserId) {
            TataAgent::where('user_id', $linkUserId)->update(['user_id' => null, 'sync_status' => 'unlinked']);
            $agent->user_id = $linkUserId;
        }

        $agent->save();

        if ($agent->user_id) {
            $this->applyToUser($agent);
        }

        return $agent;
    }

    /**
     * Modify an existing Smartflo user (the "Modify Smartflo user" form) and
     * update the local mirror.
     */
    public function modifySmartfloUser(TataAgent $agent, array $attributes): TataAgent
    {
        if (! $agent->smartflo_user_id) {
            throw new TataException('This row is not a Smartflo user, so it cannot be modified.');
        }

        $payload = array_filter($attributes, fn ($v) => $v !== null && $v !== '');

        if ($payload) {
            $this->tata->patchUser($agent->smartflo_user_id, $payload);
        }

        $agent->fill(array_filter([
            'name'                   => $attributes['name'] ?? null,
            'email'                  => $attributes['email'] ?? null,
            'follow_me_number'       => isset($attributes['number']) ? $this->digits($attributes['number']) : null,
            'login_id'               => $attributes['login_id'] ?? null,
        ], fn ($v) => $v !== null && $v !== ''));

        if (array_key_exists('login_based_calling', $attributes)) {
            $agent->is_login_based_calling = (bool) $attributes['login_based_calling'];
        }
        if (array_key_exists('block_web_login', $attributes)) {
            $agent->is_web_login = ! (bool) $attributes['block_web_login'];
        }
        if (array_key_exists('block_agent', $attributes)) {
            $agent->is_active = ! (bool) $attributes['block_agent'];
        }

        $agent->last_synced_at = now();
        $agent->last_error = null;
        $agent->save();

        if ($agent->user_id) {
            $this->applyToUser($agent);
        }

        return $agent;
    }

    /**
     * Turn on login-based calling for the given (or all linked) Smartflo agents,
     * so click-to-call rings the Smartflo softphone app instead of the mobile.
     * The agent must also be signed into the softphone for the leg to land there.
     *
     * @return array{updated:int, failed:int}
     */
    public function enableSoftphoneCalling(?TataAgent $only = null): array
    {
        $agents = $only
            ? collect([$only])
            : TataAgent::whereNotNull('smartflo_user_id')->where('is_login_based_calling', false)->get();

        $updated = 0;
        $failed = 0;

        foreach ($agents as $agent) {
            if (! $agent->smartflo_user_id) {
                continue;
            }

            try {
                $this->tata->patchUser($agent->smartflo_user_id, ['login_based_calling' => true]);
                $agent->forceFill(['is_login_based_calling' => true, 'last_synced_at' => now(), 'last_error' => null])->save();
                $updated++;
            } catch (TataException $e) {
                $agent->forceFill(['last_error' => Str::limit($e->displayMessage(), 480)])->save();
                $failed++;
            }
        }

        return ['updated' => $updated, 'failed' => $failed];
    }

    /** Manually link a mirrored Smartflo agent to a CRM user. */
    public function linkAgent(TataAgent $agent, User $user): TataAgent
    {
        TataAgent::where('user_id', $user->id)->where('id', '!=', $agent->id)->update(['user_id' => null, 'sync_status' => 'unlinked']);

        $agent->forceFill(['user_id' => $user->id, 'sync_status' => 'linked', 'last_error' => null])->save();
        $this->applyToUser($agent);

        return $agent;
    }

    public function unlinkAgent(TataAgent $agent): void
    {
        if ($agent->user) {
            $agent->user->forceFill(['tata_agent_number' => null])->save();
        }

        $agent->forceFill(['user_id' => null, 'sync_status' => 'unlinked'])->save();
    }

    private function applyToUser(TataAgent $agent): void
    {
        $user = $agent->user;

        if (! $user) {
            return;
        }

        $identity = $agent->follow_me_number ?: $agent->extension ?: $agent->agent_id;

        if ($identity && $user->tata_agent_number !== $identity) {
            $user->forceFill(['tata_agent_number' => $identity])->save();
        }
    }

    private function matchUser(TataAgent $agent): ?User
    {
        $candidates = array_filter([$agent->login_id, $agent->email]);

        foreach ($candidates as $candidate) {
            if (str_contains($candidate, '@') && $user = User::where('email', $candidate)->first()) {
                return $user;
            }
        }

        $tail = substr((string) $agent->follow_me_number, -10);

        if (strlen($tail) === 10) {
            return User::where('is_active', true)
                ->where(fn ($q) => $q->where('tata_agent_number', 'like', '%' . $tail)->orWhere('phone', 'like', '%' . $tail))
                ->whereDoesntHave('tataAgent')
                ->first();
        }

        return null;
    }

    private function loginIdFor(User $user): string
    {
        return $user->email;
    }

    // -----------------------------------------------------------------
    // Dispositions
    // -----------------------------------------------------------------

    /**
     * Find or create the CRM's disposition list on Smartflo.
     */
    public function dispositionListId(bool $create = true): ?string
    {
        if ($cached = $this->settings->get('telephony', 'tata_disposition_list_id')) {
            return (string) $cached;
        }

        foreach ($this->tata->dispositionLists() as $list) {
            if (strcasecmp((string) ($list['name'] ?? ''), self::DISPOSITION_LIST_NAME) === 0 && isset($list['id'])) {
                $this->settings->set('telephony', 'tata_disposition_list_id', (string) $list['id']);

                return (string) $list['id'];
            }
        }

        if (! $create) {
            return null;
        }

        $this->tata->createDispositionList(self::DISPOSITION_LIST_NAME, 'Call outcomes managed by the IVF CRM');

        foreach ($this->tata->dispositionLists() as $list) {
            if (strcasecmp((string) ($list['name'] ?? ''), self::DISPOSITION_LIST_NAME) === 0 && isset($list['id'])) {
                $this->settings->set('telephony', 'tata_disposition_list_id', (string) $list['id']);

                return (string) $list['id'];
            }
        }

        throw new TataException('Created the "' . self::DISPOSITION_LIST_NAME . '" disposition list but Smartflo did not return it.');
    }

    /**
     * Full two-way sync: pull Smartflo statuses (creating CRM dispositions for
     * unknown ones), then push CRM dispositions Smartflo does not have.
     *
     * @return array{pulled:int, created_in_crm:int, pushed:int, list_id:string}
     */
    public function syncDispositions(): array
    {
        $listId = $this->dispositionListId();

        $pull = $this->pullDispositions($listId);
        $push = $this->pushDispositions($listId);

        return ['list_id' => $listId] + $pull + $push;
    }

    /** @return array{pulled:int, created_in_crm:int} */
    public function pullDispositions(?string $listId = null): array
    {
        $listId = $listId ?: $this->dispositionListId();
        $remote = $this->tata->dispositionStatuses($listId);
        $created = 0;

        // Parents first so children can resolve them.
        usort($remote, fn ($a, $b) => ($a['parent_id'] !== null) <=> ($b['parent_id'] !== null));

        $byRemoteId = [];

        foreach ($remote as $row) {
            $code = $row['code'] ?: Str::slug($row['name']);

            $link = TataDisposition::firstOrNew(['smartflo_list_id' => $listId, 'code' => $code]);

            $disposition = $link->disposition_id ? Disposition::find($link->disposition_id) : null;

            if (! $disposition) {
                $disposition = Disposition::where('slug', $code)->first();
            }

            if (! $disposition) {
                $parentLocal = $row['parent_id'] ? ($byRemoteId[$row['parent_id']] ?? null) : null;

                $disposition = Disposition::create([
                    'parent_id'  => $parentLocal?->id,
                    'name'       => $row['name'],
                    'slug'       => $code,
                    'sort_order' => (int) Disposition::max('sort_order') + 1,
                    'is_active'  => true,
                ]);
                $created++;
            }

            $link->fill([
                'disposition_id'     => $disposition->id,
                'smartflo_status_id' => $row['id'],
                'smartflo_parent_id' => $row['parent_id'],
                'name'               => $row['name'],
                'sync_status'        => 'synced',
                'sync_direction'     => $link->exists ? $link->sync_direction : 'pulled',
                'last_synced_at'     => now(),
                'last_error'         => null,
                'raw'                => $row['raw'],
            ])->save();

            if ($row['id']) {
                $byRemoteId[$row['id']] = $disposition;
            }
        }

        return ['pulled' => count($remote), 'created_in_crm' => $created];
    }

    /** @return array{pushed:int} */
    public function pushDispositions(?string $listId = null): array
    {
        $listId = $listId ?: $this->dispositionListId();

        $synced = TataDisposition::where('smartflo_list_id', $listId)
            ->whereNotNull('smartflo_status_id')
            ->get()
            ->keyBy('disposition_id');

        $pushed = 0;

        // Parents, then children (children need the parent's Smartflo id).
        $parents = Disposition::whereNull('parent_id')->where('is_active', true)->orderBy('sort_order')->get();

        foreach ($parents as $parent) {
            if (! $synced->has($parent->id)) {
                $this->pushOne($parent, $listId, null);
                $pushed++;
            }
        }

        // Refresh ids after creating parents.
        $this->pullDispositions($listId);
        $synced = TataDisposition::where('smartflo_list_id', $listId)->whereNotNull('smartflo_status_id')->get()->keyBy('disposition_id');

        $children = Disposition::whereNotNull('parent_id')->where('is_active', true)->orderBy('sort_order')->get();

        foreach ($children as $child) {
            if ($synced->has($child->id)) {
                continue;
            }

            $parentLink = $synced->get($child->parent_id);
            $this->pushOne($child, $listId, $parentLink?->smartflo_status_id);
            $pushed++;
        }

        if ($pushed > 0) {
            $this->pullDispositions($listId);
        }

        return ['pushed' => $pushed];
    }

    private function pushOne(Disposition $disposition, string $listId, ?string $parentRemoteId): void
    {
        $link = TataDisposition::firstOrNew(['smartflo_list_id' => $listId, 'code' => $disposition->slug]);

        try {
            $this->tata->createDispositionStatus($disposition->name, $disposition->slug, $listId, $parentRemoteId);

            $link->fill([
                'disposition_id'     => $disposition->id,
                'smartflo_parent_id' => $parentRemoteId,
                'name'               => $disposition->name,
                'sync_status'        => 'synced',
                'sync_direction'     => 'pushed',
                'last_synced_at'     => now(),
                'last_error'         => null,
            ])->save();
        } catch (TataException $e) {
            $link->fill([
                'disposition_id' => $disposition->id,
                'name'           => $disposition->name,
                'sync_status'    => 'error',
                'sync_direction' => 'pushed',
                'last_error'     => Str::limit($e->displayMessage(), 480),
            ])->save();

            Log::warning('Smartflo disposition push failed', ['disposition' => $disposition->slug, 'error' => $e->getMessage()]);
        }
    }

    /** Smartflo status id for a CRM disposition, if synced. */
    public function smartfloStatusId(?int $dispositionId): ?string
    {
        if (! $dispositionId) {
            return null;
        }

        return TataDisposition::where('disposition_id', $dispositionId)->whereNotNull('smartflo_status_id')->value('smartflo_status_id');
    }

    // -----------------------------------------------------------------
    // Numbers, departments, IVRs (pull only)
    // -----------------------------------------------------------------

    public function pullNumbers(): int
    {
        $rows = $this->tata->myNumbers();

        foreach ($rows as $row) {
            $did = $this->digits($row['did'] ?? $row['number'] ?? null);

            if (! $did) {
                continue;
            }

            $number = TataNumber::query()
                ->when(isset($row['id']), fn ($q) => $q->where('smartflo_id', (string) $row['id']))
                ->when(! isset($row['id']), fn ($q) => $q->where('did', $did))
                ->first() ?? new TataNumber();

            $number->fill([
                'smartflo_id'      => isset($row['id']) ? (string) $row['id'] : $number->smartflo_id,
                'did'              => $did,
                'name'             => $row['name'] ?? null,
                'description'      => $row['description'] ?? null,
                'alias'            => $row['alias'] ?? null,
                'destination'      => $row['destination'] ?? null,
                'destination_name' => $row['destination_name'] ?? null,
                'synced_at'        => now(),
                'raw'              => $row,
            ])->save();
        }

        // First DID becomes the default caller id when none is chosen yet.
        if (! TataNumber::where('is_default', true)->exists() && $first = TataNumber::orderBy('id')->first()) {
            $first->update(['is_default' => true]);
            $this->settings->set('telephony', 'tata_caller_id', $first->did);
        }

        return count($rows);
    }

    public function pullDepartments(): int
    {
        $rows = $this->tata->departments();

        foreach ($rows as $row) {
            if (! isset($row['id'])) {
                continue;
            }

            TataDepartment::updateOrCreate(['smartflo_id' => (string) $row['id']], [
                'name'          => (string) ($row['name'] ?? 'Department ' . $row['id']),
                'ring_strategy' => $row['ring_strategy'] ?? null,
                'agent_count'   => (int) ($row['agent_count'] ?? count($row['agents'] ?? [])),
                'agents'        => $row['agents'] ?? null,
                'synced_at'     => now(),
                'raw'           => $row,
            ]);
        }

        return count($rows);
    }

    public function pullIvrs(): int
    {
        $rows = $this->tata->ivrs();

        foreach ($rows as $row) {
            if (! isset($row['id'])) {
                continue;
            }

            TataIvr::updateOrCreate(['smartflo_id' => (string) $row['id']], [
                'name'      => (string) ($row['name'] ?? 'IVR ' . $row['id']),
                'menu'      => $row['ivr_menu'] ?? $row['menu'] ?? null,
                'synced_at' => now(),
                'raw'       => $row,
            ]);
        }

        return count($rows);
    }

    /** Everything, in dependency order. Errors are collected, not thrown. */
    public function syncAll(): array
    {
        $report = [];

        foreach ([
            'agents'      => fn () => $this->pullAgents(),
            'numbers'     => fn () => ['pulled' => $this->pullNumbers()],
            'departments' => fn () => ['pulled' => $this->pullDepartments()],
            'ivrs'        => fn () => ['pulled' => $this->pullIvrs()],
            'dispositions'=> fn () => $this->syncDispositions(),
        ] as $key => $step) {
            try {
                $report[$key] = ['ok' => true] + $step();
            } catch (TataException $e) {
                $report[$key] = ['ok' => false, 'error' => $e->displayMessage()];
            }
        }

        $this->settings->set('telephony', 'tata_last_sync_at', now()->toDateTimeString());

        return $report;
    }

    // -----------------------------------------------------------------
    // Call records (CDR backfill)
    // -----------------------------------------------------------------

    /**
     * Pull call detail records for a window and reconcile them with call_logs:
     * missing calls are created, missing recordings/durations are filled in.
     *
     * @return array{fetched:int, created:int, updated:int}
     */
    public function pullCallRecords(Carbon $from, Carbon $to, int $maxPages = 20): array
    {
        $tz = config('telephony.tata.webhook_timezone', 'Asia/Kolkata');
        $fetched = $created = $updated = 0;

        for ($page = 1; $page <= $maxPages; $page++) {
            $body = $this->tata->callRecords([
                'from_date' => $from->copy()->setTimezone($tz)->format('Y-m-d H:i:s'),
                'to_date'   => $to->copy()->setTimezone($tz)->format('Y-m-d H:i:s'),
                'page'      => $page,
                'limit'     => 100,
            ]);

            $rows = $body['results'] ?? $body['data'] ?? [];

            if (! $rows) {
                break;
            }

            foreach ($rows as $row) {
                $fetched++;
                $result = $this->reconcileRecord($row, $tz);
                $created += $result === 'created' ? 1 : 0;
                $updated += $result === 'updated' ? 1 : 0;
            }

            if (count($rows) < 100) {
                break;
            }
        }

        $this->settings->set('telephony', 'tata_last_cdr_sync_at', now()->toDateTimeString());

        return ['fetched' => $fetched, 'created' => $created, 'updated' => $updated];
    }

    /** @return 'created'|'updated'|'unchanged' */
    private function reconcileRecord(array $row, string $tz): string
    {
        $callId   = isset($row['call_id']) ? (string) $row['call_id'] : null;
        $uuid     = isset($row['uuid']) ? (string) $row['uuid'] : null;
        $direction = str_contains(strtolower((string) ($row['direction'] ?? '')), 'in') ? 'inbound' : 'outbound';
        $answered = in_array(strtolower((string) ($row['status'] ?? '')), ['answered', 'connected', 'completed'], true);

        $start = $this->stamp(trim(($row['date'] ?? '') . ' ' . ($row['time'] ?? '')), $tz);
        $end   = $this->stamp($row['end_stamp'] ?? null, $tz);

        $duration = (int) ($row['call_duration'] ?? $row['duration'] ?? 0);
        $talk     = (int) ($row['answered_seconds'] ?? $row['billsec'] ?? 0);

        $data = [
            'channel_id'   => $callId,
            'linked_id'    => $uuid,
            'provider'     => 'tata',
            'direction'    => $direction,
            'from_number'  => $direction === 'inbound' ? ($row['client_number'] ?? null) : ($row['did_number'] ?? null),
            'to_number'    => $direction === 'inbound' ? ($row['did_number'] ?? null) : ($row['client_number'] ?? null),
            'agent_number' => $row['agent_number'] ?? null,
            'started_at'   => $start,
        ];

        $call = $this->calls->locate($data);
        $status = 'updated';

        if (! $call) {
            $customer = $direction === 'inbound' ? $data['from_number'] : $data['to_number'];

            if (! $customer) {
                return 'unchanged';
            }

            $call = $this->calls->recordExternal($data);
            $status = 'created';
        } elseif ($call->ended_at && $call->recording_url && $call->talk_seconds) {
            return 'unchanged';
        }

        if ($answered && ! $call->answered_at) {
            $this->calls->markAnswered($call, ['timestamp' => $start, 'channel_id' => $callId, 'linked_id' => $uuid]);
            $call->refresh();
        }

        if (! $call->ended_at || ! $call->recording_url) {
            $this->calls->finalize($call, array_filter([
                'timestamp'        => $end ?: ($start ? Carbon::parse($start)->addSeconds($duration)->toDateTimeString() : null),
                'talk_seconds'     => $talk,
                'duration_seconds' => $duration ?: null,
                'hangup_cause'     => $row['hangup_cause'] ?? null,
                'status'           => $answered ? 'completed' : 'no-answer',
                'recording_url'    => $row['recording_url'] ?? null,
            ], fn ($v) => $v !== null));
        }

        return $status;
    }

    // -----------------------------------------------------------------
    // Live call records (read-through — NOT stored)
    // -----------------------------------------------------------------

    /**
     * Fetch call records for a set of customer numbers straight from the
     * Smartflo CDR API and return only those with a recording. Nothing is
     * written to the CRM database — this is a live read-through.
     *
     * @param  list<string>  $numbers  Customer numbers (any format)
     * @return list<array{call_id:?string, direction:string, number:?string, agent:?string, when:?string, duration:string, recording_url:string}>
     */
    public function liveRecordings(array $numbers, int $months = 6): array
    {
        if (! $this->tata->isConfigured() || ! $numbers) {
            return [];
        }

        $tz = config('app.timezone');

        // The Smartflo CDR API allows at most a 1-month window per request, so
        // walk back month by month and merge.
        $months = max(1, min($months, (int) config('telephony.tata.cdr_months', 3)));
        $windows = [];
        for ($i = 0; $i < $months; $i++) {
            $windows[] = [
                'from_date' => now()->subMonths($i + 1)->addSecond()->format('Y-m-d H:i:s'),
                'to_date'   => now()->subMonths($i)->format('Y-m-d H:i:s'),
                'limit'     => 100,
                'page'      => 1,
            ];
        }

        $rows = [];

        foreach ($numbers as $number) {
            $digits = preg_replace('/\D/', '', (string) $number);

            if (strlen($digits) < 10) {
                continue;
            }

            $tail = substr($digits, -10);

            // callerid is the client's number; destination catches the dialled
            // customer on outbound. Query both across each month window.
            foreach ($windows as $window) {
            foreach (['callerid', 'destination'] as $field) {
                try {
                    $body = $this->tata->callRecords([$field => $tail] + $window);
                } catch (TataException $e) {
                    Log::info('Smartflo CDR fetch failed', ['field' => $field, 'error' => $e->getMessage()]);
                    continue;
                }

                foreach (($body['results'] ?? $body['data'] ?? []) as $r) {
                    $url = trim((string) ($r['recording_url'] ?? ''));

                    if ($url === '') {
                        continue;
                    }

                    $key = (string) ($r['call_id'] ?? $r['uuid'] ?? $r['id'] ?? $url);

                    $when = trim(($r['date'] ?? '') . ' ' . ($r['time'] ?? ''));

                    $rows[$key] = [
                        'call_id'       => isset($r['call_id']) ? (string) $r['call_id'] : null,
                        'direction'     => str_contains(strtolower((string) ($r['direction'] ?? '')), 'in') ? 'inbound' : 'outbound',
                        'number'        => $r['client_number'] ?? $r['callerid'] ?? $tail,
                        'agent'         => $r['agent_name'] ?? $r['agent_number'] ?? null,
                        'status'        => $r['status'] ?? null,
                        'when'          => $this->displayStamp($when, $tz),
                        'duration'      => $this->hhmmss((int) ($r['answered_seconds'] ?? $r['call_duration'] ?? 0)),
                        'recording_url' => $url,
                    ];
                }
            }
            }
        }

        // Newest first.
        usort($rows, fn ($a, $b) => strcmp((string) $b['when'], (string) $a['when']));

        return array_values($rows);
    }

    private function displayStamp(string $value, string $tz): ?string
    {
        $value = trim($value);

        if ($value === '') {
            return null;
        }

        try {
            return \Illuminate\Support\Carbon::parse($value, config('telephony.tata.webhook_timezone', 'Asia/Kolkata'))
                ->setTimezone($tz)->format('d-m-Y h:i A');
        } catch (\Throwable) {
            return $value;
        }
    }

    private function hhmmss(int $seconds): string
    {
        $seconds = max($seconds, 0);

        return $seconds >= 3600
            ? sprintf('%d:%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60)
            : sprintf('%02d:%02d', intdiv($seconds, 60), $seconds % 60);
    }

    // -----------------------------------------------------------------
    // Outcome push-back
    // -----------------------------------------------------------------

    /**
     * Write the CRM outcome (disposition + one-line note) onto the Smartflo
     * call record, so both systems show the same result for the call.
     */
    public function pushCallOutcome(CallLog $call): bool
    {
        if ($call->provider !== 'tata') {
            return false;
        }

        $smartfloCallId = $call->channel_id ?: $call->linked_id;

        if (! $smartfloCallId) {
            $call->forceFill(['tata_sync_error' => 'Smartflo call id not known yet (no webhook received).'])->save();

            return false;
        }

        $call->loadMissing(['disposition:id,name', 'subDisposition:id,name']);

        $labels = array_filter([$call->disposition?->name, $call->subDisposition?->name]);
        $message = trim(implode(' › ', $labels) . ($call->remark ? ' — ' . $call->remark : ''));

        if ($message === '') {
            return false;
        }

        try {
            $this->tata->addCallNote($smartfloCallId, Str::limit($message, 500), $labels ? implode('|', $labels) : null);

            // Dialer calls also take a structured disposition.
            if (! empty($call->meta['dialer']) && $statusId = $this->smartfloStatusId($call->disposition_id)) {
                $this->tata->storeDialerDisposition(
                    $smartfloCallId,
                    $statusId,
                    $this->smartfloStatusId($call->sub_disposition_id),
                    $call->remark,
                );
            }

            $call->forceFill(['tata_synced_at' => now(), 'tata_sync_error' => null])->save();

            return true;
        } catch (TataException $e) {
            $call->forceFill(['tata_sync_error' => Str::limit($e->displayMessage(), 480)])->save();
            Log::info('Smartflo outcome push failed', ['call_log_id' => $call->id, 'error' => $e->getMessage()]);

            return false;
        }
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    private function digits(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $digits = preg_replace('/\D/', '', $value);

        if ($digits === '') {
            return null;
        }

        // Keep Smartflo agent ids / extensions intact, strip 91 from mobiles.
        if (preg_match('/^0[56]0\d+$/', $digits)) {
            return $digits;
        }

        if (strlen($digits) === 12 && str_starts_with($digits, '91')) {
            $digits = substr($digits, 2);
        }

        return $digits;
    }

    private function stamp(?string $value, string $tz): ?string
    {
        $value = trim((string) $value);

        if ($value === '' || $value === '0000-00-00 00:00:00') {
            return null;
        }

        try {
            return Carbon::parse($value, $tz)->setTimezone(config('app.timezone'))->toDateTimeString();
        } catch (\Throwable) {
            return null;
        }
    }
}
