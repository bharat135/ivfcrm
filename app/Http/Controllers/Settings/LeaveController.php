<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Jobs\DelegateLeaveFollowUpsJob;
use App\Models\AgentLeave;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Yajra\DataTables\Facades\DataTables;

/**
 * Settings → Holidays & Leaves.
 *
 * Mark a telecaller's leave, or a clinic-wide holiday. While someone is on
 * leave their follow-up calls are covered by another present agent (the lead
 * itself keeps its owner) and no new leads are assigned to them.
 */
class LeaveController extends Controller
{
    public function index(Request $request)
    {
        if ($request->ajax() || $request->wantsJson()) {
            $query = AgentLeave::with(['user:id,name', 'creator:id,name'])
                ->orderByDesc('starts_on');

            if ($request->boolean('upcoming', true)) {
                $query->whereDate('ends_on', '>=', today()->subDays(30));
            }

            return DataTables::of($query)
                ->addColumn('who', fn (AgentLeave $l) => $l->user
                    ? e($l->user->name)
                    : '<span class="badge bg-warning text-dark">Whole clinic</span>')
                ->addColumn('period', fn (AgentLeave $l) => $l->starts_on->isSameDay($l->ends_on)
                    ? $l->starts_on->format('d-m-Y')
                    : $l->starts_on->format('d-m-Y') . ' → ' . $l->ends_on->format('d-m-Y'))
                ->addColumn('type_badge', fn (AgentLeave $l) => $l->isHoliday()
                    ? '<span class="badge bg-warning text-dark">Holiday</span>'
                    : '<span class="badge bg-info text-dark">Leave</span>')
                ->addColumn('state', function (AgentLeave $l) {
                    if ($l->ends_on->isPast() && ! $l->ends_on->isToday()) {
                        return '<span class="badge bg-secondary">Over</span>';
                    }

                    return $l->starts_on->isFuture()
                        ? '<span class="badge bg-light text-dark border">Upcoming</span>'
                        : '<span class="badge bg-success">On now</span>';
                })
                ->addColumn('by', fn (AgentLeave $l) => e($l->creator?->name ?? '—'))
                ->addColumn('action', fn (AgentLeave $l) => view('settings.leaves._actions', ['leave' => $l])->render())
                ->rawColumns(['who', 'type_badge', 'state', 'action'])
                ->make(true);
        }

        return view('settings.leaves.index', [
            'agents' => User::where('is_active', true)
                ->whereHas('roles', fn ($q) => $q->whereIn('name', ['Telecaller', 'Counsellor', 'Center Manager', 'Receptionist']))
                ->with('center:id,name')
                ->orderBy('name')
                ->get(['id', 'name', 'center_id']),
            'types'  => AgentLeave::TYPES,
            'onLeaveToday' => AgentLeave::with('user:id,name')->onDate(today())->get(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);

        $leave = AgentLeave::create($data + ['created_by' => $request->user()->id]);

        DelegateLeaveFollowUpsJob::dispatch();

        return response()->json([
            'success' => true,
            'message' => $leave->user_id
                ? 'Leave saved. Follow-ups on those days will be covered by another agent.'
                : 'Holiday saved for the whole clinic.',
        ]);
    }

    public function edit(AgentLeave $leave): JsonResponse
    {
        return response()->json(array_merge($leave->toArray(), [
            'starts_on' => $leave->starts_on->toDateString(),
            'ends_on'   => $leave->ends_on->toDateString(),
        ]));
    }

    public function update(Request $request, AgentLeave $leave): JsonResponse
    {
        $leave->update($this->validated($request));

        DelegateLeaveFollowUpsJob::dispatch();

        return response()->json(['success' => true, 'message' => 'Updated.']);
    }

    public function destroy(AgentLeave $leave): JsonResponse
    {
        $leave->delete();

        // Cover is withdrawn on the next run: the owner is back.
        DelegateLeaveFollowUpsJob::dispatch();

        return response()->json(['success' => true, 'message' => 'Removed.']);
    }

    private function validated(Request $request): array
    {
        $data = $request->validate([
            'type'      => ['required', Rule::in(array_keys(AgentLeave::TYPES))],
            'user_id'   => ['nullable', 'exists:users,id', Rule::requiredIf(fn () => $request->input('type') === 'leave')],
            'starts_on' => ['required', 'date'],
            'ends_on'   => ['required', 'date', 'after_or_equal:starts_on'],
            'reason'    => ['nullable', 'string', 'max:191'],
        ], [
            'user_id.required' => 'Pick the agent who is on leave.',
        ]);

        if ($data['type'] === 'holiday') {
            $data['user_id'] = null;
        }

        return $data;
    }
}
