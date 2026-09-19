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
        if ($request->ajax() || $request->wantsJson()) {
            $query = FollowUp::with(['lead:id,name,mobile,status', 'user:id,name', 'delegate:id,name', 'disposition:id,name,color'])
                ->forAgent(Auth::id());

            if ($filter = $request->get('status')) {
                $query->where('status', $filter);
            }
            if ($request->get('today')) {
                $query->whereDate('scheduled_at', today());
            }
            if ($request->get('overdue')) {
                $query->where('status', 'pending')->where('scheduled_at', '<', now());
            }

            return DataTables::of($query)
                ->addColumn('lead_mobile', fn ($f) => \App\Support\PhoneNumber::display($f->lead?->mobile))
                ->addColumn('cover', fn ($f) => $f->delegated_to
                    ? '<span class="badge bg-info text-dark" title="' . e($f->delegated_reason) . '">Cover: ' . e($f->delegate?->name) . '</span>'
                    : '')
                ->addColumn('action', fn($f) => view('followups._actions', ['followUp' => $f])->render())
                ->rawColumns(['action', 'cover'])
                ->make(true);
        }

        $todayCount   = FollowUp::forAgent(Auth::id())->whereDate('scheduled_at', today())->count();
        $overdueCount = FollowUp::forAgent(Auth::id())->where('status', 'pending')->where('scheduled_at', '<', now())->count();

        return view('followups.index', compact('todayCount', 'overdueCount'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'lead_id'        => ['required', 'exists:leads,id'],
            'type'           => ['required', 'in:call,whatsapp,email,visit'],
            'scheduled_at'   => ['required', 'date', new \App\Rules\WithinCallHours()],
            'remark'         => ['nullable', 'string'],
            'disposition_id' => ['nullable', 'exists:dispositions,id'],
        ]);

        $data['user_id'] = Auth::id();
        $data['status']  = 'pending';

        $followUp = FollowUp::create($data);

        Lead::where('id', $data['lead_id'])->update(['next_follow_up_at' => $data['scheduled_at']]);

        \App\Models\LeadActivity::create([
            'lead_id'     => $followUp->lead_id,
            'user_id'     => Auth::id(),
            'type'        => 'followup',
            'title'       => ucfirst($data['type']) . ' follow-up scheduled',
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
