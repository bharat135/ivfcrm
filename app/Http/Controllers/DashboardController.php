<?php

namespace App\Http\Controllers;

use App\Services\DashboardService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class DashboardController extends Controller
{
    public function __construct(private DashboardService $service) {}

    public function index(Request $request)
    {
        $request->validate([
            'from' => ['nullable', 'date'],
            'to'   => ['nullable', 'date'],
        ]);

        // The period the figures cover: today unless a range is picked. A
        // range given backwards is simply swapped.
        $from = $request->filled('from') ? \Illuminate\Support\Carbon::parse($request->from)->startOfDay() : today();
        $to   = $request->filled('to') ? \Illuminate\Support\Carbon::parse($request->to)->startOfDay() : $from->copy();
        if ($to->lt($from)) {
            [$from, $to] = [$to, $from];
        }

        $user    = Auth::user();
        $metrics = $this->service->getMetrics($user, $from, $to);
        $team    = $this->service->getTeamPerformance($user, $from, $to);
        $sourceChart = $this->service->getSourceChart($user->center_id);
        $dailyTrend  = $this->service->getDailyLeadTrend($user->center_id);

        return view('dashboard.index', compact('user', 'metrics', 'team', 'from', 'to', 'sourceChart', 'dailyTrend'));
    }

    public function pipeline(Request $request)
    {
        $user = Auth::user();
        $kanban = $this->service->getPipelineKanban($user->center_id);
        return view('dashboard.pipeline', compact('kanban'));
    }

    public function telecallerStats(Request $request)
    {
        $user  = Auth::user();
        $stats = $this->service->getTelecallerStats($user->id);
        return response()->json($stats);
    }
}
