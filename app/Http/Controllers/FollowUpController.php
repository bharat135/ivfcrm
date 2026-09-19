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
            $query = FollowUp::with(['lead:id,name,mobile,status', 'user:id,name', 'disposition:id,name,color'])
                ->where('user_id', Auth::id());

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
                ->addColumn('action', fn($f) => view('followups._actions', ['followUp' => $f])->render())
                ->rawColumns(['action'])
                ->make(true);
        }

        $todayCount   = FollowUp::where('user_id', Auth::id())->whereDate('scheduled_at', today())->count();
        $overdueCount = FollowUp::where('user_id', Auth::id())->where('status', 'pending')->where('scheduled_at', '<', now())->count();

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

        return response()->json(['success' => true]);
    }

    public function cancel(FollowUp $followUp)
    {
        $followUp->update(['status' => 'cancelled']);
        return response()->json(['success' => true]);
    }
}
