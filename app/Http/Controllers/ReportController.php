<?php

namespace App\Http\Controllers;

use App\Models\Appointment;
use App\Models\CallLog;
use App\Models\Center;
use App\Models\FollowUp;
use App\Models\Lead;
use App\Models\LeadSource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Maatwebsite\Excel\Facades\Excel;

class ReportController extends Controller
{
    public function leadsReport(Request $request)
    {
        $filters = $request->only(['from', 'to', 'center_id', 'source_id', 'status', 'assigned_to']);

        $query = Lead::with(['source:id,name', 'stage:id,name', 'assignedTo:id,name', 'center:id,name'])
            ->when($filters['from'] ?? null, fn($q, $v) => $q->whereDate('created_at', '>=', $v))
            ->when($filters['to'] ?? null, fn($q, $v) => $q->whereDate('created_at', '<=', $v))
            ->when($filters['center_id'] ?? null, fn($q, $v) => $q->where('center_id', $v))
            ->when($filters['source_id'] ?? null, fn($q, $v) => $q->where('lead_source_id', $v))
            ->when($filters['status'] ?? null, fn($q, $v) => $q->where('status', $v))
            ->when($filters['assigned_to'] ?? null, fn($q, $v) => $q->where('assigned_to', $v));

        if ($request->get('export') === 'csv') {
            return $this->exportCsv($query->get(), 'leads_report');
        }

        $leads = $query->paginate(50);

        $sources  = LeadSource::active()->get();
        $centers  = Center::active()->get();
        $users    = User::active()->get();

        return view('reports.leads', compact('leads', 'filters', 'sources', 'centers', 'users'));
    }

    public function telecallerReport(Request $request)
    {
        $from     = $request->get('from', today()->toDateString());
        $to       = $request->get('to', today()->toDateString());
        $centerId = $request->get('center_id', Auth::user()->center_id);

        $data = User::whereHas('roles', fn($q) => $q->where('name', 'Telecaller'))
            ->when($centerId, fn($q) => $q->where('center_id', $centerId))
            ->withCount([
                'callLogs as total_calls'     => fn($q) => $q->whereBetween('created_at', [$from . ' 00:00:00', $to . ' 23:59:59']),
                'callLogs as connected_calls' => fn($q) => $q->whereBetween('created_at', [$from . ' 00:00:00', $to . ' 23:59:59'])->where('status', 'completed'),
                'assignedLeads as total_leads' => fn($q) => $q->whereBetween('assigned_at', [$from . ' 00:00:00', $to . ' 23:59:59']),
            ])
            ->get();

        $centers = Center::active()->get();

        return view('reports.telecaller', compact('data', 'from', 'to', 'centers'));
    }

    public function sourceReport(Request $request)
    {
        $from     = $request->get('from', now()->startOfMonth()->toDateString());
        $to       = $request->get('to', today()->toDateString());
        $centerId = $request->get('center_id');

        $data = LeadSource::withCount([
            'leads as total_leads'   => fn($q) => $q->whereBetween('created_at', [$from . ' 00:00:00', $to . ' 23:59:59'])
                ->when($centerId, fn($q2) => $q2->where('center_id', $centerId)),
            'leads as won_leads'     => fn($q) => $q->whereBetween('created_at', [$from . ' 00:00:00', $to . ' 23:59:59'])
                ->where('status', 'won')
                ->when($centerId, fn($q2) => $q2->where('center_id', $centerId)),
        ])->get();

        $centers = Center::active()->get();

        return view('reports.source', compact('data', 'from', 'to', 'centers'));
    }

    private function exportCsv($collection, string $filename)
    {
        $headers = [
            'Content-Type'        => 'text/csv',
            'Content-Disposition' => "attachment; filename={$filename}.csv",
        ];

        $callback = function () use ($collection) {
            $file = fopen('php://output', 'w');
            if ($collection->isNotEmpty()) {
                fputcsv($file, array_keys($collection->first()->toArray()));
                foreach ($collection as $row) {
                    fputcsv($file, $row->toArray());
                }
            }
            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }
}
