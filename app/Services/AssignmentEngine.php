<?php

namespace App\Services;

use App\Models\AssignmentRule;
use App\Models\Lead;
use App\Models\LeadAssignment;
use App\Models\User;
use App\Support\SettingsRepository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Decides who a new lead goes to.
 *
 * Rules are evaluated in priority order (Settings → Lead Assignment); the
 * first rule whose conditions all match picks a user from its pool. When no
 * rule matches, the configurable fallback applies.
 */
class AssignmentEngine
{
    public const CACHE_KEY = 'assignment_rules';

    /** Fallback when no rule matches. */
    public const FALLBACKS = [
        'center_round_robin' => 'Round-robin among available telecallers of the lead\'s center',
        'any_round_robin'    => 'Round-robin among all available telecallers',
        'none'               => 'Leave unassigned (a manager assigns manually)',
    ];

    public const DEFAULT_FALLBACK = 'center_round_robin';

    public const METHODS = [
        'round_robin' => 'Round-robin (fewest leads today)',
        'skill'       => 'Language match, then round-robin',
    ];

    public function __construct(private SettingsRepository $settings) {}

    public function assign(Lead $lead): ?User
    {
        $rules = Cache::remember(self::CACHE_KEY, 300, fn () => AssignmentRule::active()->get());

        foreach ($rules as $rule) {
            if ($this->matchesConditions($lead, $rule->conditions ?? [])) {
                $user = $this->pickUser($rule, $lead);
                if ($user) {
                    $this->doAssign($lead, $user, $rule->method, $rule);
                    return $user;
                }
            }
        }

        return $this->fallback($lead);
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
    }

    public function fallbackStrategy(): string
    {
        $value = $this->settings->get('assignment', 'fallback', self::DEFAULT_FALLBACK);

        return array_key_exists($value, self::FALLBACKS) ? $value : self::DEFAULT_FALLBACK;
    }

    public static function forgetCache(): void
    {
        Cache::forget(self::CACHE_KEY);
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

    private function pickUser(AssignmentRule $rule, Lead $lead): ?User
    {
        $userIds = $rule->userIds();

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
        // Pick the user with the fewest leads assigned today, among the pool.
        return $this->withTodayCount(User::whereIn('id', $userIds)->active()->available())->first();
    }

    private function skillBased(array $userIds, Lead $lead): ?User
    {
        // Skill match based on language for now; extend as needed.
        $language = $lead->extra['language'] ?? null;
        $query = User::whereIn('id', $userIds)->active()->available();

        if ($language) {
            $query->where('language', $language);
        }

        return $this->withTodayCount($query)->first() ?? $this->roundRobin($userIds);
    }

    private function fallback(Lead $lead): ?User
    {
        $strategy = $this->fallbackStrategy();

        if ($strategy === 'none') {
            return null;
        }

        $query = $this->withTodayCount(User::active()->available())
            ->whereHas('roles', fn ($q) => $q->where('name', 'Telecaller'));

        if ($strategy === 'center_round_robin' && $lead->center_id) {
            $query->where('center_id', $lead->center_id);
        }

        $user = $query->first();

        if ($user) {
            $this->doAssign($lead, $user, 'round_robin');
        }

        return $user;
    }

    private function withTodayCount($query)
    {
        return $query
            ->withCount(['assignedLeads as today_count' => fn ($q) => $q->whereDate('assigned_at', today())])
            ->orderBy('today_count')
            ->orderBy('id');
    }

    private function doAssign(Lead $lead, User $user, string $method, ?AssignmentRule $rule = null): void
    {
        DB::transaction(function () use ($lead, $user, $method) {
            $lead->update(['assigned_to' => $user->id, 'assigned_at' => now()]);

            LeadAssignment::create([
                'lead_id'     => $lead->id,
                'assigned_to' => $user->id,
                'assigned_by' => null,
                'method'      => $method,
                'assigned_at' => now(),
            ]);
        });

        if ($rule) {
            // Counter only; the rule cache stays warm.
            AssignmentRule::whereKey($rule->id)->increment('leads_count');
        }
    }
}
