<?php

namespace App\Services;

use App\Models\AssignmentRule;
use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\LeadAssignment;
use App\Models\User;
use App\Support\SettingsRepository;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Decides who a new lead goes to.
 *
 * Rules are evaluated in priority order (Settings → Lead Assignment); the
 * first rule whose conditions all match picks a user from its pool. When no
 * rule matches, the configurable fallback applies.
 *
 * Only agents who are ONLINE (seen in the last few minutes) receive leads.
 * If nobody is online the lead stays unassigned and AssignPendingLeadsJob
 * hands it out the moment someone appears. An owner who does not call an
 * enquiry within the idle window loses it to another online agent
 * (ReassignIdleLeadsJob).
 */
class AssignmentEngine
{
    public const CACHE_KEY = 'assignment_rules';

    /** Fallback when no rule matches. */
    public const FALLBACKS = [
        'center_round_robin' => 'Round-robin among online telecallers of the lead\'s center',
        'any_round_robin'    => 'Round-robin among all online telecallers',
        'none'               => 'Leave unassigned (a manager assigns manually)',
    ];

    public const DEFAULT_FALLBACK = 'center_round_robin';

    public const METHODS = [
        'round_robin' => 'Round-robin (fewest leads today)',
        'skill'       => 'Language match, then round-robin',
    ];

    public const DEFAULT_ONLINE_MINUTES = 5;
    public const DEFAULT_IDLE_MINUTES = 30;
    public const DEFAULT_IDLE_MAX_AGE_HOURS = 72;

    public function __construct(private SettingsRepository $settings) {}

    // -----------------------------------------------------------------
    // Settings
    // -----------------------------------------------------------------

    public function fallbackStrategy(): string
    {
        $value = $this->settings->get('assignment', 'fallback', self::DEFAULT_FALLBACK);

        return array_key_exists($value, self::FALLBACKS) ? $value : self::DEFAULT_FALLBACK;
    }

    /** An agent counts as online when seen within this many minutes. */
    public function onlineMinutes(): int
    {
        return max(1, (int) $this->settings->get('assignment', 'online_minutes', self::DEFAULT_ONLINE_MINUTES));
    }

    /** Minutes an owner gets to call a new lead before it is handed on. */
    public function idleMinutes(): int
    {
        return max(5, (int) $this->settings->get('assignment', 'idle_minutes', self::DEFAULT_IDLE_MINUTES));
    }

    /** Only enquiries received within this many hours are re-routed when idle. */
    public function idleMaxAgeHours(): int
    {
        return max(1, (int) $this->settings->get('assignment', 'idle_max_age_hours', self::DEFAULT_IDLE_MAX_AGE_HOURS));
    }

    public static function forgetCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    // -----------------------------------------------------------------
    // Assignment
    // -----------------------------------------------------------------

    public function assign(Lead $lead, ?int $exceptUserId = null, ?string $method = null): ?User
    {
        $rules = Cache::remember(self::CACHE_KEY, 300, fn () => AssignmentRule::active()->get());

        foreach ($rules as $rule) {
            if ($this->matchesConditions($lead, $rule->conditions ?? [])) {
                $user = $this->pickUser($rule, $lead, $exceptUserId);
                if ($user) {
                    $this->doAssign($lead, $user, $method ?: $rule->method, $rule);

                    return $user;
                }
            }
        }

        return $this->fallback($lead, $exceptUserId, $method);
    }

    /**
     * The owner has not called within the idle window: give the lead to a
     * different online agent and say why on the timeline.
     */
    public function reassignIdle(Lead $lead): ?User
    {
        $previous = $lead->assigned_to;
        $user     = $this->assign($lead, $previous, 'idle_reassign');

        if ($user && $user->id !== $previous) {
            $from = $previous ? User::find($previous)?->name : null;

            LeadActivity::create([
                'lead_id'     => $lead->id,
                'user_id'     => null,
                'type'        => 'system',
                'title'       => 'Reassigned (not called in time)',
                'description' => ($from ? "{$from} did not call within " : 'Not called within ')
                    . $this->idleMinutes() . " minutes; handed to {$user->name}.",
            ]);
        }

        return $user;
    }

    /**
     * A lead created with an explicit assignee skips the rules, but the
     * assignment must still be stamped and logged like any other.
     */
    public function recordManual(Lead $lead, ?int $assignedBy = null): void
    {
        if (! $lead->assigned_to) {
            return;
        }

        DB::transaction(function () use ($lead, $assignedBy) {
            if (! $lead->assigned_at) {
                $lead->update(['assigned_at' => now()]);
            }

            LeadAssignment::create([
                'lead_id'     => $lead->id,
                'assigned_to' => $lead->assigned_to,
                'assigned_by' => $assignedBy,
                'method'      => 'manual',
                'assigned_at' => now(),
            ]);
        });

        $owner = User::find($lead->assigned_to);
        $by    = $assignedBy ? User::find($assignedBy) : null;

        LeadActivity::create([
            'lead_id'     => $lead->id,
            'user_id'     => $assignedBy,
            'type'        => 'assignment',
            'title'       => 'Assigned to ' . ($owner?->name ?? '#' . $lead->assigned_to),
            'description' => $by ? "Chosen by {$by->name} when the lead was entered." : 'Set when the lead was created.',
            'properties'  => ['to' => $lead->assigned_to, 'method' => 'manual'],
        ]);
    }

    public function onlineAgentsExist(): bool
    {
        return User::query()->active()->available()->online($this->onlineMinutes())->exists();
    }

    /**
     * Every configured condition must hold; blank conditions are ignored.
     */
    public function matchesConditions(Lead $lead, array $conditions): bool
    {
        foreach ($conditions as $key => $value) {
            if ($value === null || $value === '' || $value === []) {
                continue;
            }

            $ok = match ($key) {
                'center_id'      => (int) $lead->center_id === (int) $value,
                'state_id'       => (int) $lead->state_id === (int) $value,
                'lead_source_id' => (int) $lead->lead_source_id === (int) $value,
                'campaign_id'    => (int) $lead->campaign_id === (int) $value,
                'channel'        => $lead->channel === $value,
                'pincode'        => (string) $lead->pincode === (string) $value,
                'pincode_prefix' => $lead->pincode !== null && str_starts_with((string) $lead->pincode, (string) $value),
                default          => true,
            };

            if (! $ok) {
                return false;
            }
        }

        return true;
    }

    // -----------------------------------------------------------------
    // Picking
    // -----------------------------------------------------------------

    private function pickUser(AssignmentRule $rule, Lead $lead, ?int $exceptUserId): ?User
    {
        $userIds = array_values(array_diff($rule->userIds(), array_filter([$exceptUserId])));

        if (empty($userIds)) {
            return null;
        }

        return match ($rule->method) {
            'skill' => $this->skillBased($userIds, $lead),
            default => $this->roundRobin($userIds),
        };
    }

    private function roundRobin(array $userIds): ?User
    {
        return $this->candidates(User::whereIn('id', $userIds))->first();
    }

    private function skillBased(array $userIds, Lead $lead): ?User
    {
        $language = $lead->extra['language'] ?? null;
        $query    = User::whereIn('id', $userIds);

        if ($language) {
            $query->where('language', $language);
        }

        return $this->candidates($query)->first() ?? $this->roundRobin($userIds);
    }

    private function fallback(Lead $lead, ?int $exceptUserId, ?string $method): ?User
    {
        $strategy = $this->fallbackStrategy();

        if ($strategy === 'none') {
            return null;
        }

        $query = User::query()->whereHas('roles', fn ($q) => $q->where('name', 'Telecaller'));

        if ($exceptUserId) {
            $query->where('id', '!=', $exceptUserId);
        }

        if ($strategy === 'center_round_robin' && $lead->center_id) {
            $query->where('center_id', $lead->center_id);
        }

        $user = $this->candidates($query)->first();

        if ($user) {
            $this->doAssign($lead, $user, $method ?: 'round_robin');
        }

        return $user;
    }

    /**
     * Active, marked available AND online, least loaded today first.
     */
    private function candidates(Builder $query): Builder
    {
        return $query
            ->active()
            ->available()
            ->online($this->onlineMinutes())
            ->notOnLeave()
            ->withCount(['assignedLeads as today_count' => fn ($q) => $q->whereDate('assigned_at', today())])
            ->orderBy('today_count')
            ->orderBy('id');
    }

    /**
     * Who covers a follow-up while its owner is away: a present agent in the
     * owner's centre if possible, otherwise any present agent, least loaded.
     */
    public function pickCover(?User $owner, \Illuminate\Support\Carbon|string $date): ?User
    {
        $base = fn () => User::query()
            ->active()
            ->available()
            ->notOnLeave($date)
            ->whereHas('roles', fn ($q) => $q->whereIn('name', ['Telecaller', 'Counsellor']))
            ->when($owner, fn ($q) => $q->where('id', '!=', $owner->id))
            ->withCount(['assignedLeads as today_count' => fn ($q) => $q->whereDate('assigned_at', today())])
            ->orderBy('today_count')
            ->orderBy('id');

        if ($owner?->center_id) {
            $sameCentre = $base()->where('center_id', $owner->center_id)->first();
            if ($sameCentre) {
                return $sameCentre;
            }
        }

        return $base()->first();
    }

    private function doAssign(Lead $lead, User $user, string $method, ?AssignmentRule $rule = null): void
    {
        $previous = $lead->assigned_to ? User::find($lead->assigned_to) : null;

        DB::transaction(function () use ($lead, $user, $method) {
            $lead->update(['assigned_to' => $user->id, 'assigned_at' => now()]);

            LeadAssignment::create([
                'lead_id'     => $lead->id,
                'assigned_to' => $user->id,
                'assigned_by' => \Illuminate\Support\Facades\Auth::id(),
                'method'      => $method,
                'assigned_at' => now(),
            ]);
        });

        if ($rule) {
            AssignmentRule::whereKey($rule->id)->increment('leads_count');
        }

        $how = match ($method) {
            'idle_reassign' => 'not called in time',
            'skill'         => 'language match' . ($rule ? " (rule \"{$rule->name}\")" : ''),
            default         => $rule ? "rule \"{$rule->name}\"" : 'round-robin fallback',
        };

        LeadActivity::create([
            'lead_id'     => $lead->id,
            'user_id'     => \Illuminate\Support\Facades\Auth::id(),
            'type'        => 'assignment',
            'title'       => ($previous ? 'Owner changed to ' : 'Assigned to ') . $user->name,
            'description' => ($previous ? "From {$previous->name}. " : '') . 'Automatic: ' . $how . '.',
            'properties'  => ['from' => $previous?->id, 'to' => $user->id, 'method' => $method, 'rule_id' => $rule?->id],
        ]);
    }
}
