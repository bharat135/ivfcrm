<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\CallLog;
use App\Models\FollowUp;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

class DashboardService
{
    public function getMetrics(User $user): array
    {
        $cacheKey = "dashboard_metrics_{$user->id}";

        return Cache::remember($cacheKey, 120, function () use ($user) {
            $isAdmin = $user->hasRole(['Super Admin', 'Management', 'Marketing Head']);
            $centerId = $user->center_id;

            $leadQuery = Lead::query();
            $followQuery = FollowUp::query();
            $callQuery = CallLog::query();

            if (!$isAdmin) {
                if ($user->hasRole('Center Manager')) {
                    $leadQuery->where('center_id', $centerId);
                    $followQuery->whereHas('lead', fn($q) => $q->where('center_id', $centerId));
                    $callQuery->where('center_id', $centerId);
                } else {
                    $leadQuery->where('assigned_to', $user->id);
                    $followQuery->where('user_id', $user->id);
                    $callQuery->where('user_id', $user->id);
                }
            }

            $todayLeads   = (clone $leadQuery)->whereDate('created_at', today())->count();
            $totalLeads   = (clone $leadQuery)->count();
            $activeLeads  = (clone $leadQuery)->whereNotIn('status', ['won', 'lost', 'junk'])->count();
            $todayCalls   = (clone $callQuery)->whereDate('created_at', today())->count();
            $todayFollows = (clone $followQuery)->whereDate('scheduled_at', today())->count();
            $overdueFollows = (clone $followQuery)->where('status', 'pending')->where('scheduled_at', '<', now())->count();

            $todayAppointments = Appointment::whereDate('scheduled_at', today())
                ->when(!$isAdmin && $centerId, fn($q) => $q->where('center_id', $centerId))
                ->count();

            $monthlyConversions = (clone $leadQuery)
                ->whereMonth('created_at', now()->month)
                ->where('status', 'won')
                ->count();

            return compact(
                'todayLeads', 'totalLeads', 'activeLeads',
                'todayCalls', 'todayFollows', 'overdueFollows',
                'todayAppointments', 'monthlyConversions'
            );
        });
    }

    public function getSourceChart(?int $centerId = null, int $days = 30): array
    {
        return Lead::selectRaw('lead_source_id, COUNT(*) as total')
            ->with('source:id,name,channel')
            ->whereDate('created_at', '>=', now()->subDays($days))
            ->when($centerId, fn($q) => $q->where('center_id', $centerId))
            ->groupBy('lead_source_id')
            ->orderByDesc('total')
            ->get()
            ->map(fn($row) => [
                'source' => $row->source?->name ?? 'Unknown',
                'total'  => $row->total,
            ])
            ->toArray();
    }

    public function getDailyLeadTrend(?int $centerId = null, int $days = 14): array
    {
        return Lead::selectRaw('DATE(created_at) as date, COUNT(*) as total')
            ->whereDate('created_at', '>=', now()->subDays($days))
            ->when($centerId, fn($q) => $q->where('center_id', $centerId))
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->map(fn($row) => ['date' => $row->date, 'total' => $row->total])
            ->toArray();
    }

    public function getPipelineKanban(?int $centerId = null): array
    {
        $stages = \App\Models\PipelineStage::active()->get();
        $leads = Lead::with('assignedTo:id,name')
            ->whereNotIn('status', ['lost', 'junk'])
            ->when($centerId, fn($q) => $q->where('center_id', $centerId))
            ->select('id', 'name', 'mobile', 'stage_id', 'assigned_to', 'score_band')
            ->get()
            ->each(fn ($l) => $l->setAttribute('mobile', \App\Support\PhoneNumber::display($l->mobile)))
            ->groupBy('stage_id');

        return $stages->map(fn($stage) => [
            'stage' => $stage,
            'leads' => $leads->get($stage->id, collect()),
            'count' => $leads->get($stage->id, collect())->count(),
        ])->toArray();
    }

    public function getTelecallerStats(int $userId, string $date = null): array
    {
        $date = $date ?? today()->toDateString();

        $calls = CallLog::where('user_id', $userId)->whereDate('created_at', $date);

        return [
            'total_calls'      => (clone $calls)->count(),
            'connected_calls'  => (clone $calls)->where('status', 'completed')->count(),
            'total_duration'   => (clone $calls)->sum('duration_seconds'),
            'dispositions'     => (clone $calls)
                ->selectRaw('disposition_id, COUNT(*) as cnt')
                ->groupBy('disposition_id')
                ->with('disposition:id,name,color')
                ->get(),
        ];
    }
}
