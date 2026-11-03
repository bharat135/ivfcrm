<?php

namespace App\Services\Reports;

use App\Models\Appointment;
use App\Models\CallLog;
use App\Models\Center;
use App\Models\Lead;
use App\Models\User;
use App\Support\AcquisitionChannel;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The telecalling scorecard, in the format management tracks it in their
 * sheet: one row per agent with dials, connects, missed calls, appointments
 * booked, visits (overall and per centre), and visits for the day (FTD) and
 * month to date (MTD). Feeds the dashboard team table and the daily email.
 */
class AgentPerformanceReport
{
    /** Roles whose users appear as rows. */
    public const AGENT_ROLES = ['Telecaller', 'Counsellor', 'Call Quality Analyst'];

    /**
     * Lead channels counted as digital-marketing (DM) campaign leads. Older
     * rows carry the raw provider names rather than the normalised channel.
     */
    private const DM_CHANNELS = ['meta', 'google', 'facebook'];

    /**
     * @param  Collection<int, User>|null  $agents  Rows to include; every active agent by default.
     * @return array{from: Carbon, to: Carbon, centers: Collection, rows: array<int, array>, totals: array, summary: array}
     */
    public function build(CarbonInterface $from, CarbonInterface $to, ?Collection $agents = null): array
    {
        $from = Carbon::instance($from)->startOfDay();
        $to   = Carbon::instance($to)->endOfDay();

        $agents ??= self::agents();
        $ids     = $agents->pluck('id')->all();
        $centers = Center::active()->orderBy('name')->get(['id', 'name', 'code']);

        // Outbound work: a dial is every outbound call; a connect is one that
        // was answered or carried any talk time.
        $callTime = DB::raw('COALESCE(started_at, created_at)');
        $calls = CallLog::query()
            ->where('direction', 'outbound')
            ->whereIn('user_id', $ids)
            ->whereBetween($callTime, [$from, $to])
            ->selectRaw("user_id, COUNT(*) AS dials, SUM(CASE WHEN status IN ('answered', 'completed') OR COALESCE(talk_seconds, 0) > 0 THEN 1 ELSE 0 END) AS connected")
            ->groupBy('user_id')
            ->get()
            ->keyBy('user_id');

        $missedCalls = CallLog::missed()
            ->whereIn('user_id', $ids)
            ->whereBetween($callTime, [$from, $to])
            ->selectRaw('user_id, COUNT(*) AS c')
            ->groupBy('user_id')
            ->pluck('c', 'user_id');

        // Appointments: booked counts by when the agent created them; visits
        // and missed visits by when the appointment was scheduled.
        $booked = Appointment::query()
            ->whereIn('created_by', $ids)
            ->whereBetween('created_at', [$from, $to])
            ->selectRaw('created_by, COUNT(*) AS c')
            ->groupBy('created_by')
            ->pluck('c', 'created_by');

        $visitedByCenter = Appointment::query()
            ->whereIn('created_by', $ids)
            ->where('status', 'visited')
            ->whereBetween('scheduled_at', [$from, $to])
            ->selectRaw('created_by, center_id, COUNT(*) AS c')
            ->groupBy('created_by', 'center_id')
            ->get()
            ->groupBy('created_by');

        $missedVisits = Appointment::query()
            ->whereIn('created_by', $ids)
            ->where('status', 'no_show')
            ->whereBetween('scheduled_at', [$from, $to])
            ->selectRaw('created_by, COUNT(*) AS c')
            ->groupBy('created_by')
            ->pluck('c', 'created_by');

        $visitsFtd = $this->visitsBetween($ids, $to->copy()->startOfDay(), $to);
        $visitsMtd = $this->visitsBetween($ids, $to->copy()->startOfMonth(), $to);

        $rows = $agents->map(function (User $agent) use ($calls, $missedCalls, $booked, $visitedByCenter, $missedVisits, $visitsFtd, $visitsMtd, $centers) {
            $call      = $calls->get($agent->id);
            $dials     = (int) ($call->dials ?? 0);
            $connected = (int) ($call->connected ?? 0);

            $perCenter = $centers->mapWithKeys(fn (Center $c) => [$c->id => 0])->all();
            foreach ($visitedByCenter->get($agent->id, collect()) as $visit) {
                if (array_key_exists($visit->center_id, $perCenter)) {
                    $perCenter[$visit->center_id] += (int) $visit->c;
                }
            }

            return [
                'agent_id'            => $agent->id,
                'agent'               => $agent->name,
                'dials'               => $dials,
                'connected'           => $connected,
                'not_connected'       => max($dials - $connected, 0),
                'missed_calls'        => (int) $missedCalls->get($agent->id, 0),
                'appointments_booked' => (int) $booked->get($agent->id, 0),
                'visited'             => array_sum($perCenter),
                'missed_visits'       => (int) $missedVisits->get($agent->id, 0),
                'visits_by_center'    => $perCenter,
                'visits_ftd'          => (int) $visitsFtd->get($agent->id, 0),
                'visits_mtd'          => (int) $visitsMtd->get($agent->id, 0),
            ];
        })->values()->all();

        $totals = [];
        foreach (['dials', 'connected', 'not_connected', 'missed_calls', 'appointments_booked', 'visited', 'missed_visits', 'visits_ftd', 'visits_mtd'] as $key) {
            $totals[$key] = array_sum(array_column($rows, $key));
        }
        $totals['visits_by_center'] = $centers->mapWithKeys(fn (Center $c) => [
            $c->id => array_sum(array_map(fn ($r) => $r['visits_by_center'][$c->id] ?? 0, $rows)),
        ])->all();

        $leads = Lead::query()->whereBetween('created_at', [$from, $to]);

        return [
            'from'    => $from,
            'to'      => $to,
            'centers' => $centers,
            'rows'    => $rows,
            'totals'  => $totals,
            'summary' => [
                'dm_camp_count' => (clone $leads)->whereIn('channel', array_merge(AcquisitionChannel::paid(), self::DM_CHANNELS))->count(),
                'total_leads'   => (clone $leads)->count(),
            ],
        ];
    }

    /** Every active agent, in name order. */
    public static function agents(): Collection
    {
        return User::active()
            ->whereHas('roles', fn ($q) => $q->whereIn('name', self::AGENT_ROLES))
            ->orderBy('name')
            ->get(['id', 'name', 'center_id']);
    }

    private function visitsBetween(array $agentIds, Carbon $from, Carbon $to): Collection
    {
        return Appointment::query()
            ->whereIn('created_by', $agentIds)
            ->where('status', 'visited')
            ->whereBetween('scheduled_at', [$from, $to])
            ->selectRaw('created_by, COUNT(*) AS c')
            ->groupBy('created_by')
            ->pluck('c', 'created_by');
    }

    /** The report as CSV rows (header first), for the email attachment. */
    public function toCsv(array $report): string
    {
        $centers = $report['centers'];
        $header  = array_merge(
            ['Agent Name', 'Total Dial count', 'Total Connected', 'Not connected', 'Missed calls', 'Appointments booked', 'Visited', 'Missed visit'],
            $centers->map(fn ($c) => $c->name . ' visits')->all(),
            ['Visits FTD', 'Visits MTD'],
        );

        $lines = [
            ['DM Camp Count', $report['summary']['dm_camp_count'], 'Total Leads count', $report['summary']['total_leads']],
            [],
            $header,
        ];
        foreach (array_merge($report['rows'], [['agent' => 'Total'] + $report['totals']]) as $row) {
            $lines[] = array_merge(
                [$row['agent'], $row['dials'], $row['connected'], $row['not_connected'], $row['missed_calls'], $row['appointments_booked'], $row['visited'], $row['missed_visits']],
                $centers->map(fn ($c) => $row['visits_by_center'][$c->id] ?? 0)->all(),
                [$row['visits_ftd'], $row['visits_mtd']],
            );
        }

        $out = fopen('php://temp', 'r+');
        foreach ($lines as $line) {
            fputcsv($out, $line);
        }
        rewind($out);
        $csv = stream_get_contents($out);
        fclose($out);

        return $csv;
    }
}
