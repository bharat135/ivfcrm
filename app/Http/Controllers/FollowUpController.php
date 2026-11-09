<?php

namespace App\Http\Controllers;

use App\Models\FollowUp;
use App\Models\Lead;
use App\Repositories\FollowUpRepository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Yajra\DataTables\Facades\DataTables;

class FollowUpController extends Controller
{
    public function __construct(private FollowUpRepository $repo) {}

    public function index(Request $request)
    {
        $user = Auth::user();

        // Admins, management and centre managers see the team's follow-ups,
        // not only their own: FollowUp::scopeVisibleTo.
        $seesTeam = FollowUp::supervises($user) || ($user->hasRole('Center Manager') && $user->center_id);

        if ($request->ajax() || $request->wantsJson()) {
            $query = FollowUp::with(['lead:id,name,mobile,status', 'user:id,name', 'delegate:id,name', 'disposition:id,name,color'])
                ->select('follow_ups.*')
                ->visibleTo($user);

            // Narrow a team list to one agent: theirs, and the ones they cover.
            if ($seesTeam && ($agentId = (int) $request->get('agent_id'))) {
                $query->where(fn ($q) => $q->where('user_id', $agentId)->orWhere('delegated_to', $agentId));
            }

            if ($filter = $request->get('status')) {
                $query->where('status', $filter);
            }
            if ($request->get('today')) {
                $query->whereDate('scheduled_at', today());
            }
            if ($request->get('overdue')) {
                $query->where('status', 'pending')->where('scheduled_at', '<', now());
            }

            // Filter bar: type, disposition, date range and the important flag.
            if ($type = $request->get('type')) {
                $query->where('type', $type);
            }
            if ($dispositionId = $request->get('disposition_id')) {
                $query->where('disposition_id', $dispositionId);
            }
            if ($from = $request->get('from')) {
                $query->whereDate('scheduled_at', '>=', $from);
            }
            if ($to = $request->get('to')) {
                $query->whereDate('scheduled_at', '<=', $to);
            }
            if ($request->get('important') === '1') {
                $query->where('is_important', true);
            } elseif ($request->get('important') === '0') {
                $query->where('is_important', false);
            }

            return DataTables::of($query)
                ->addColumn('lead_mobile', fn ($f) => \App\Support\PhoneNumber::display($f->lead?->mobile))
                ->addColumn('agent', fn ($f) => $f->user?->name ?? '—')
                ->addColumn('cover', fn ($f) => $f->delegated_to
                    ? '<span class="badge bg-info text-dark" title="' . e($f->delegated_reason) . '">Cover: ' . e($f->delegate?->name) . '</span>'
                    : '')
                ->addColumn('action', fn($f) => view('followups._actions', ['followUp' => $f])->render())
                ->rawColumns(['action', 'cover'])
                ->make(true);
        }

        $todayCount     = FollowUp::visibleTo($user)->whereDate('scheduled_at', today())->count();
        $overdueCount   = FollowUp::visibleTo($user)->where('status', 'pending')->where('scheduled_at', '<', now())->count();
        $importantCount = FollowUp::visibleTo($user)->pending()->important()->count();

        $dispositions = \App\Models\Disposition::active()->primary()->orderBy('sort_order')->get(['id', 'name']);

        // The agent filter, for those whose list spans more than themselves.
        $agents = $seesTeam
            ? \App\Models\User::whereIn('id', FollowUp::visibleTo($user)->select('user_id'))->orderBy('name')->get(['id', 'name'])
            : collect();

        return view('followups.index', compact('todayCount', 'overdueCount', 'importantCount', 'dispositions', 'agents', 'seesTeam'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'lead_id'        => ['required', 'exists:leads,id'],
            'type'           => ['required', 'in:call,whatsapp,email,visit'],
            'scheduled_at'   => ['required', 'date', new \App\Rules\WithinCallHours()],
            'remark'         => ['nullable', 'string'],
            'disposition_id' => ['nullable', 'exists:dispositions,id'],
            'is_important'   => ['nullable', 'boolean'],
        ]);

        $data['user_id']      = Auth::id();
        $data['status']       = 'pending';
        $data['is_important'] = $request->boolean('is_important');

        $followUp = FollowUp::create($data);

        Lead::where('id', $data['lead_id'])->update(['next_follow_up_at' => $data['scheduled_at']]);

        \App\Models\LeadActivity::create([
            'lead_id'     => $followUp->lead_id,
            'user_id'     => Auth::id(),
            'type'        => 'followup',
            'title'       => ucfirst($data['type']) . ' follow-up scheduled' . ($data['is_important'] ? ' (important)' : ''),
            'description' => 'For ' . \Illuminate\Support\Carbon::parse($data['scheduled_at'])->format('d-m-Y h:i A') . ($data['remark'] ?? null ? ' — ' . $data['remark'] : ''),
            'properties'  => ['follow_up_id' => $followUp->id],
        ]);

        return response()->json(['success' => true, 'id' => $followUp->id]);
    }

    public function complete(Request $request, FollowUp $followUp)
    {
        $request->validate(['remark' => ['nullable', 'string']]);

        $followUp->update([
            'status'       => 'done',
            'completed_at' => now(),
            'remark'       => $request->remark ?? $followUp->remark,
        ]);

        \App\Models\LeadActivity::create([
            'lead_id'     => $followUp->lead_id,
            'user_id'     => Auth::id(),
            'type'        => 'followup',
            'title'       => 'Follow-up completed',
            'description' => 'Was due ' . $followUp->scheduled_at->format('d-m-Y h:i A') . ($followUp->remark ? ' — ' . $followUp->remark : ''),
            'properties'  => ['follow_up_id' => $followUp->id],
        ]);

        return response()->json(['success' => true]);
    }

    public function cancel(FollowUp $followUp)
    {
        $followUp->update(['status' => 'cancelled']);
        return response()->json(['success' => true]);
    }
}
