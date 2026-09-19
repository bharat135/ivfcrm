<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\AssignmentRule;
use App\Models\Campaign;
use App\Models\Center;
use App\Models\LeadSource;
use App\Models\State;
use App\Models\User;
use App\Services\AssignmentEngine;
use App\Support\AcquisitionChannel;
use App\Support\SettingsRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Yajra\DataTables\Facades\DataTables;

/**
 * Settings → Lead Assignment.
 *
 * Where a manager decides who new enquiries go to: ordered rules (match on
 * center / source / campaign / channel / state / pincode, route into a pool
 * of agents) plus the fallback used when nothing matches. Every intake path
 * (Meta lead ad, landing page, import, API, front desk) runs through the
 * same AssignmentEngine, so this one screen governs all of them.
 */
class AssignmentRuleController extends Controller
{
    public function __construct(
        private SettingsRepository $settings,
        private AssignmentEngine $engine,
    ) {}

    public function index(Request $request)
    {
        if ($request->ajax() || $request->wantsJson()) {
            $users    = User::pluck('name', 'id');
            $lookups  = $this->lookups();

            $query = AssignmentRule::query()->orderBy('priority')->orderBy('id');

            return DataTables::of($query)
                ->addColumn('conditions_summary', fn (AssignmentRule $r) => $this->describeConditions($r, $lookups))
                ->addColumn('method_label', fn (AssignmentRule $r) => e(AssignmentEngine::METHODS[$r->method] ?? $r->method))
                ->addColumn('targets_summary', function (AssignmentRule $r) use ($users) {
                    $names = collect($r->userIds())->map(fn ($id) => $users[$id] ?? null)->filter();

                    if ($names->isEmpty()) {
                        return '<span class="text-danger small">No agents</span>';
                    }

                    return $names->map(fn ($n) => '<span class="badge bg-light text-dark border">' . e($n) . '</span>')->join(' ');
                })
                ->addColumn('status_badge', fn (AssignmentRule $r) => $r->is_active
                    ? '<span class="badge bg-success">Active</span>'
                    : '<span class="badge bg-secondary">Paused</span>')
                ->addColumn('action', fn (AssignmentRule $r) => view('settings.assignment-rules._actions', ['rule' => $r])->render())
                ->rawColumns(['conditions_summary', 'targets_summary', 'status_badge', 'action'])
                ->make(true);
        }

        return view('settings.assignment-rules.index', [
            'centers'   => Center::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'sources'   => LeadSource::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'campaigns' => Campaign::orderByDesc('id')->limit(300)->get(['id', 'name']),
            'states'    => State::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'channels'  => AcquisitionChannel::options(),
            'agents'    => $this->agents(),
            'methods'   => AssignmentEngine::METHODS,
            'fallbacks' => AssignmentEngine::FALLBACKS,
            'fallback'  => $this->engine->fallbackStrategy(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);

        AssignmentRule::create($data);
        AssignmentEngine::forgetCache();

        return response()->json(['success' => true, 'message' => 'Assignment rule created.']);
    }

    public function edit(AssignmentRule $rule): JsonResponse
    {
        return response()->json($rule);
    }

    public function update(Request $request, AssignmentRule $rule): JsonResponse
    {
        $rule->update($this->validated($request));
        AssignmentEngine::forgetCache();

        return response()->json(['success' => true, 'message' => 'Assignment rule updated.']);
    }

    public function toggle(AssignmentRule $rule): JsonResponse
    {
        $rule->update(['is_active' => ! $rule->is_active]);
        AssignmentEngine::forgetCache();

        return response()->json([
            'success' => true,
            'message' => $rule->is_active ? 'Rule activated.' : 'Rule paused.',
        ]);
    }

    public function destroy(AssignmentRule $rule): JsonResponse
    {
        $rule->delete();
        AssignmentEngine::forgetCache();

        return response()->json(['success' => true, 'message' => 'Assignment rule deleted.']);
    }

    /**
     * What happens when no rule matches.
     */
    public function updateFallback(Request $request): JsonResponse
    {
        $data = $request->validate([
            'fallback' => ['required', Rule::in(array_keys(AssignmentEngine::FALLBACKS))],
        ]);

        $this->settings->set('assignment', 'fallback', $data['fallback']);

        return response()->json(['success' => true, 'message' => 'Fallback strategy saved.']);
    }

    // -----------------------------------------------------------------

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name'                      => ['required', 'string', 'max:191'],
            'priority'                  => ['required', 'integer', 'min:0', 'max:999'],
            'method'                    => ['required', Rule::in(array_keys(AssignmentEngine::METHODS))],
            'is_active'                 => ['boolean'],
            'conditions'                => ['nullable', 'array'],
            'conditions.center_id'      => ['nullable', 'integer', 'exists:centers,id'],
            'conditions.lead_source_id' => ['nullable', 'integer', 'exists:lead_sources,id'],
            'conditions.campaign_id'    => ['nullable', 'integer', 'exists:campaigns,id'],
            'conditions.channel'        => ['nullable', 'string', Rule::in(array_keys(AcquisitionChannel::options()))],
            'conditions.state_id'       => ['nullable', 'integer', 'exists:states,id'],
            'conditions.pincode_prefix' => ['nullable', 'string', 'regex:/^\d{1,6}$/'],
            'user_ids'                  => ['required', 'array', 'min:1'],
            'user_ids.*'                => ['integer', 'exists:users,id'],
        ], [
            'user_ids.required'               => 'Pick at least one agent to route leads to.',
            'conditions.pincode_prefix.regex' => 'Pincode prefix must be 1 to 6 digits.',
        ]);

        // Keep only the conditions that constrain something, and only known keys.
        $conditions = [];
        foreach (AssignmentRule::CONDITION_KEYS as $key) {
            $value = $data['conditions'][$key] ?? null;
            if ($value !== null && $value !== '') {
                $conditions[$key] = is_numeric($value) && $key !== 'pincode_prefix' ? (int) $value : (string) $value;
            }
        }

        return [
            'name'       => $data['name'],
            'priority'   => (int) $data['priority'],
            'method'     => $data['method'],
            'is_active'  => $request->boolean('is_active', true),
            'conditions' => $conditions,
            'targets'    => ['user_ids' => array_values(array_unique(array_map('intval', $data['user_ids'])))],
        ];
    }

    /**
     * Users who can receive leads: any active user with a front-line role.
     */
    private function agents()
    {
        return User::where('is_active', true)
            ->whereHas('roles', fn ($q) => $q->whereIn('name', ['Telecaller', 'Counsellor', 'Center Manager', 'Receptionist']))
            ->with('center:id,name')
            ->orderBy('name')
            ->get(['id', 'name', 'center_id', 'is_available']);
    }

    private function lookups(): array
    {
        return [
            'center_id'      => Center::pluck('name', 'id'),
            'lead_source_id' => LeadSource::pluck('name', 'id'),
            'campaign_id'    => Campaign::pluck('name', 'id'),
            'state_id'       => State::pluck('name', 'id'),
            'channel'        => collect(AcquisitionChannel::options()),
        ];
    }

    private function describeConditions(AssignmentRule $rule, array $lookups): string
    {
        $labels = [
            'center_id'      => 'Center',
            'lead_source_id' => 'Source',
            'campaign_id'    => 'Campaign',
            'channel'        => 'Channel',
            'state_id'       => 'State',
            'pincode_prefix' => 'Pincode starts with',
        ];

        $parts = [];
        foreach ($rule->activeConditions() as $key => $value) {
            $display = isset($lookups[$key]) ? ($lookups[$key][$value] ?? $value) : $value;
            $parts[] = '<span class="badge bg-info-subtle text-dark border">' . e($labels[$key] ?? $key) . ': ' . e($display) . '</span>';
        }

        return $parts ? implode(' ', $parts) : '<span class="text-muted small">Every lead</span>';
    }
}
