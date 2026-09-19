<?php

namespace App\Http\Controllers;

use App\Models\Campaign;
use App\Models\Center;
use App\Models\Lead;
use App\Models\LeadSource;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Yajra\DataTables\Facades\DataTables;

class CampaignController extends Controller
{
    public function index(Request $request)
    {
        if ($request->ajax() || $request->wantsJson()) {
            $query = Campaign::with(['source:id,name,channel', 'center:id,name'])
                ->withCount('leads');

            return DataTables::of($query)
                ->addColumn('roi', function ($c) {
                    $spend = max($c->spend, 0.01);
                    return round(($c->leads_count / $spend) * 100, 2);
                })
                ->addColumn('cpl', function ($c) {
                    if (!$c->leads_count) return '—';
                    return '₹' . number_format($c->spend / $c->leads_count, 2);
                })
                ->addColumn('action', fn($c) => view('campaigns._actions', ['campaign' => $c])->render())
                ->rawColumns(['action'])
                ->make(true);
        }

        $sources = LeadSource::active()->get();
        $centers = Center::active()->get();

        return view('campaigns.index', compact('sources', 'centers'));
    }

    public function analytics(Request $request)
    {
        $centerId = $request->get('center_id', Auth::user()->center_id);
        $days     = (int) $request->get('days', 30);

        $data = Campaign::withCount(['leads as total_leads'])
            ->withCount(['leads as consultations' => fn($q) =>
                $q->whereHas('stage', fn($s) => $s->where('slug', 'consultation-visited'))
            ])
            ->withCount(['leads as conversions' => fn($q) => $q->where('status', 'won')])
            ->when($centerId, fn($q) => $q->where('center_id', $centerId))
            ->whereHas('leads', fn($q) => $q->whereDate('created_at', '>=', now()->subDays($days)))
            ->get();

        return response()->json($data);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'lead_source_id' => ['required', 'exists:lead_sources,id'],
            'center_id'      => ['nullable', 'exists:centers,id'],
            'name'           => ['required', 'string', 'max:191'],
            'platform'       => ['nullable', 'string'],
            'budget'         => ['nullable', 'numeric'],
            'start_date'     => ['nullable', 'date'],
            'end_date'       => ['nullable', 'date', 'after_or_equal:start_date'],
            'utm_source'     => ['nullable', 'string'],
            'utm_medium'     => ['nullable', 'string'],
            'utm_campaign'   => ['nullable', 'string'],
        ]);

        $campaign = Campaign::create(array_merge($data, ['is_active' => true]));

        return response()->json(['success' => true, 'id' => $campaign->id]);
    }
}
