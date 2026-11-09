<?php

namespace App\Http\Controllers;

use App\Models\Campaign;
use App\Models\Center;
use App\Models\LeadSource;
use App\Models\PipelineStage;
use App\Models\User;
use App\Services\Reports\AgentPerformanceReport;
use App\Services\Reports\CrmAnalytics;
use App\Support\AcquisitionChannel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Reports → Analytics: the whole CRM in charts, over the leads created in one
 * date range. The page renders the filter bar; every number comes from data().
 */
class CrmAnalyticsController extends Controller
{
    public function __construct(private CrmAnalytics $analytics) {}

    public function index(Request $request)
    {
        $user = $request->user();

        return view('analytics.index', [
            'filters'   => CrmAnalytics::filters($request->all()),
            'centers'   => Center::active()->orderBy('name')->get(['id', 'name']),
            // Supervisors pick any agent; an agent's own list is only ever theirs.
            'agents'    => $user->can('view_all_leads') || $user->hasRole(['Super Admin', 'Management', 'Center Manager'])
                ? User::active()->whereHas('roles', fn ($q) => $q->whereIn('name', AgentPerformanceReport::AGENT_ROLES))->orderBy('name')->get(['id', 'name'])
                : collect(),
            'sources'   => LeadSource::active()->orderBy('name')->get(['id', 'name']),
            'campaigns' => Campaign::orderByDesc('id')->get(['id', 'name']),
            'stages'    => PipelineStage::active()->orderBy('sort_order')->get(['id', 'name']),
            'channelOptions' => AcquisitionChannel::options(),
            'statusOptions'  => CrmAnalytics::STATUSES,
        ]);
    }

    public function data(Request $request): JsonResponse
    {
        $request->validate([
            'from' => ['nullable', 'date'],
            'to'   => ['nullable', 'date'],
        ]);

        return response()->json(
            $this->analytics->build($request->user(), CrmAnalytics::filters($request->all()))
        );
    }
}
