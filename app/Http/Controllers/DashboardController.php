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
        $user    = Auth::user();
        $metrics = $this->service->getMetrics($user);
        $sourceChart = $this->service->getSourceChart($user->center_id);
        $dailyTrend  = $this->service->getDailyLeadTrend($user->center_id);

        return view('dashboard.index', compact('user', 'metrics', 'sourceChart', 'dailyTrend'));
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
