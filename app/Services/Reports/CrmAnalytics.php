<?php

namespace App\Services\Reports;

use App\Models\Appointment;
use App\Models\CallLog;
use App\Models\Lead;
use App\Models\PipelineStage;
use App\Models\User;
use App\Support\AcquisitionChannel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * The CRM analytics page: everything is counted over the leads *created* in
 * the chosen date range, narrowed by the same filters, so each chart on the
 * page answers a question about the same group of people.
 *
 * Funnel membership is cumulative (a visited lead also counts as booked and
 * contacted), the same rule the marketing funnel uses, so the two never
 * disagree about a conversion rate.
 */
class CrmAnalytics
{
    public const STATUSES = ['new' => 'New', 'working' => 'Working', 'won' => 'Won', 'lost' => 'Lost', 'junk' => 'Junk'];

    /** The lead attributes a filter may narrow on, and the request key for each. */
    private const FILTERS = [
        'center_id'      => 'center_id',
        'assigned_to'    => 'assigned_to',
        'lead_source_id' => 'lead_source_id',
        'channel'        => 'channel',
        'campaign_id'    => 'campaign_id',
        'stage_id'       => 'stage_id',
        'status'         => 'status',
    ];

    /**
     * Normalised filters from the request: a date range (default: the last
     * 30 days) plus the optional narrowing keys.
     *
     * @return array<string,mixed>
     */
    public static function filters(array $input): array
    {
        $from = ! empty($input['from']) ? Carbon::parse($input['from']) : today()->subDays(29);
        $to   = ! empty($input['to']) ? Carbon::parse($input['to']) : today();

        if ($to->lt($from)) {
            [$from, $to] = [$to, $from];
        }

        $filters = ['from' => $from->toDateString(), 'to' => $to->toDateString()];

        foreach (self::FILTERS as $key => $column) {
            $value = $input[$key] ?? null;
            $filters[$key] = ($value === null || $value === '') ? null : (is_numeric($value) ? (int) $value : (string) $value);
        }

        return $filters;
    }

    /**
     * @return array<string,mixed>  Every widget's data, keyed by widget.
     */
    public function build(User $user, array $filters): array
    {
        $key = 'crm_analytics_' . $user->id . '_' . md5(json_encode($filters));

        return Cache::remember($key, 60, function () use ($user, $filters) {
            [$from, $to] = $this->range($filters);

            $totals   = $this->totals($user, $filters, $from, $to);
            // Whole days: the range is start-of-day to end-of-day, so the
            // difference is n days minus a second, which rounds up to n.
            $days     = (int) round($from->diffInSeconds($to) / 86400);
            $previous = $this->totals(
                $user, $filters,
                $from->copy()->subDays($days)->startOfDay(),
                $from->copy()->subDay()->endOfDay(),
            );

            return [
                'range'    => ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'days' => $days],
                'kpis'     => $this->kpis($totals, $previous),
                'funnel'   => $this->funnel($totals),
                'channels' => $this->channels($user, $filters, $from, $to),
                'sources'  => $this->sources($user, $filters, $from, $to),
                'trend'    => $this->trend($user, $filters, $from, $to),
                'stages'   => $this->stages($user, $filters, $from, $to),
                'agents'   => $this->agents($user, $filters, $from, $to),
                'heatmap'  => $this->heatmap($user, $filters, $from, $to),
                'centers'  => $this->centers($user, $filters, $from, $to),
                'lost'     => $this->lostReasons($user, $filters, $from, $to),
                'calls'    => $this->callOutcomes($user, $filters, $from, $to),
            ];
        });
    }

    // -----------------------------------------------------------------
    // Widgets
    // -----------------------------------------------------------------

    /**
     * One pass over the leads for every headline count. Booked and visited
     * also look at the appointments table so an appointment booked from the
     * profile counts even when the pipeline stage was never moved.
     */
    private function totals(User $user, array $filters, Carbon $from, Carbon $to): array
    {
        $booked    = $this->stageList('consultation-booked');
        $visited   = $this->stageList('consultation-visited');
        $interest  = $this->stageList('interested');
        $treatment = $this->stageList('investigation');

        $row = $this->base($user, $filters, $from, $to)
            ->selectRaw('COUNT(*) AS leads')
            ->selectRaw('SUM(leads.first_contacted_at IS NOT NULL OR leads.contact_attempts > 0) AS contacted')
            ->selectRaw("SUM(leads.stage_id IN ($interest)) AS interested")
            ->selectRaw("SUM(leads.stage_id IN ($booked) OR EXISTS (SELECT 1 FROM appointments a WHERE a.lead_id = leads.id AND a.deleted_at IS NULL AND a.status <> 'cancelled')) AS booked")
            ->selectRaw("SUM(leads.stage_id IN ($visited) OR EXISTS (SELECT 1 FROM appointments a WHERE a.lead_id = leads.id AND a.deleted_at IS NULL AND a.status = 'visited')) AS visited")
            ->selectRaw("SUM(leads.stage_id IN ($treatment)) AS treatment")
            ->selectRaw("SUM(leads.status = 'won') AS won")
            ->selectRaw("SUM(leads.status IN ('lost', 'junk')) AS lost")
            ->first();

        return array_map('intval', [
            'leads'      => $row->leads ?? 0,
            'contacted'  => $row->contacted ?? 0,
            'interested' => $row->interested ?? 0,
            'booked'     => $row->booked ?? 0,
            'visited'    => $row->visited ?? 0,
            'treatment'  => $row->treatment ?? 0,
            'won'        => $row->won ?? 0,
            'lost'       => $row->lost ?? 0,
        ]);
    }

    private function kpis(array $now, array $before): array
    {
        $rate = fn (int $part, int $whole) => $whole > 0 ? round($part / $whole * 100, 1) : 0.0;

        $cards = [
            ['key' => 'leads',     'label' => 'Leads created',        'value' => $now['leads'],   'previous' => $before['leads']],
            ['key' => 'contacted', 'label' => 'Contacted',            'value' => $now['contacted'], 'previous' => $before['contacted'],
                'rate' => $rate($now['contacted'], $now['leads'])],
            ['key' => 'booked',    'label' => 'Consultations booked', 'value' => $now['booked'],  'previous' => $before['booked'],
                'rate' => $rate($now['booked'], $now['leads'])],
            ['key' => 'visited',   'label' => 'Visited',              'value' => $now['visited'], 'previous' => $before['visited'],
                'rate' => $rate($now['visited'], $now['leads'])],
            ['key' => 'won',       'label' => 'Converted',            'value' => $now['won'],     'previous' => $before['won'],
                'rate' => $rate($now['won'], $now['leads'])],
            ['key' => 'lost',      'label' => 'Lost / junk',          'value' => $now['lost'],    'previous' => $before['lost'],
                'rate' => $rate($now['lost'], $now['leads']), 'inverse' => true],
        ];

        foreach ($cards as &$card) {
            $card['change'] = $card['previous'] > 0
                ? round(($card['value'] - $card['previous']) / $card['previous'] * 100, 1)
                : null;
        }

        return $cards;
    }

    private function funnel(array $totals): array
    {
        $steps = [
            'leads'      => 'Leads',
            'contacted'  => 'Contacted',
            'interested' => 'Interested',
            'booked'     => 'Consultation booked',
            'visited'    => 'Visited',
            'won'        => 'Converted',
        ];

        $top = max($totals['leads'], 1);
        $previous = null;
        $out = [];

        foreach ($steps as $key => $label) {
            $count = $totals[$key];
            $out[] = [
                'key'       => $key,
                'label'     => $label,
                'count'     => $count,
                'rate'      => round($count / $top * 100, 1),
                'step_rate' => $previous === null ? 100.0 : ($previous > 0 ? round($count / $previous * 100, 1) : 0.0),
                'dropped'   => $previous === null ? 0 : max($previous - $count, 0),
            ];
            $previous = $count;
        }

        return $out;
    }

    /**
     * Older rows carry the raw provider name ("meta", "google", "walk-in")
     * rather than the normalised channel, so those are folded into it here
     * instead of each showing up as its own "Unknown" slice.
     */
    private const LEGACY_CHANNELS = [
        'meta' => AcquisitionChannel::META_ADS, 'facebook' => AcquisitionChannel::META_ADS, 'fb' => AcquisitionChannel::META_ADS,
        'google' => AcquisitionChannel::GOOGLE_ADS, 'adwords' => AcquisitionChannel::GOOGLE_ADS,
        'walk-in' => AcquisitionChannel::WALK_IN, 'walkin' => AcquisitionChannel::WALK_IN, 'offline' => AcquisitionChannel::WALK_IN,
        'website' => AcquisitionChannel::LANDING_PAGE, 'csv' => AcquisitionChannel::IMPORT,
    ];

    private function channels(User $user, array $filters, Carbon $from, Carbon $to): array
    {
        $rows = $this->base($user, $filters, $from, $to)
            ->selectRaw('COALESCE(leads.channel, "manual") AS channel, COUNT(*) AS c')
            ->groupBy('channel')
            ->get();

        $counts = [];
        foreach ($rows as $r) {
            $key = strtolower(trim($r->channel));
            $key = self::LEGACY_CHANNELS[$key] ?? $key;
            $counts[$key] = ($counts[$key] ?? 0) + (int) $r->c;
        }
        arsort($counts);

        $out = [];
        foreach ($counts as $key => $count) {
            $known = array_key_exists($key, AcquisitionChannel::options());
            $out[] = [
                'key'   => $key,
                'label' => $known ? AcquisitionChannel::label($key) : ucfirst(str_replace(['_', '-'], ' ', $key)),
                'color' => $known ? AcquisitionChannel::color($key) : '#94a3b8',
                'count' => $count,
            ];
        }

        return $out;
    }

    private function sources(User $user, array $filters, Carbon $from, Carbon $to): array
    {
        return $this->base($user, $filters, $from, $to)
            ->leftJoin('lead_sources', 'lead_sources.id', '=', 'leads.lead_source_id')
            ->selectRaw('COALESCE(lead_sources.name, "No source") AS label, COUNT(*) AS c')
            ->groupBy('label')
            ->orderByDesc('c')
            ->limit(8)
            ->get()
            ->map(fn ($r) => ['label' => $r->label, 'count' => (int) $r->c])
            ->all();
    }

    /**
     * Over time: leads created, and the consultations booked and visited for
     * those leads (by booking date and by visit date). Bucketed by day for up
     * to two months, by week up to about a year, by month beyond that, so a
     * long range reads as a trend rather than a comb of daily spikes.
     */
    private function trend(User $user, array $filters, Carbon $from, Carbon $to): array
    {
        $days = (int) round($from->diffInSeconds($to) / 86400);
        $unit = $days <= 62 ? 'day' : ($days <= 400 ? 'week' : 'month');

        $leads = $this->base($user, $filters, $from, $to)
            ->selectRaw('DATE(leads.created_at) AS d, COUNT(*) AS c')
            ->groupBy('d')
            ->pluck('c', 'd');

        $leadIds = $this->base($user, $filters, $from, $to)->select('leads.id');

        $booked = Appointment::query()
            ->whereIn('lead_id', $leadIds)
            ->where('status', '<>', 'cancelled')
            ->whereBetween('created_at', [$from, $to])
            ->selectRaw('DATE(created_at) AS d, COUNT(*) AS c')
            ->groupBy('d')
            ->pluck('c', 'd');

        $visited = Appointment::query()
            ->whereIn('lead_id', $leadIds)
            ->where('status', 'visited')
            ->whereBetween('scheduled_at', [$from, $to])
            ->selectRaw('DATE(scheduled_at) AS d, COUNT(*) AS c')
            ->groupBy('d')
            ->pluck('c', 'd');

        // The bucket a day falls in, and the label that bucket shows.
        $bucket = fn (Carbon $d) => match ($unit) {
            'day'   => $d->toDateString(),
            'week'  => $d->copy()->startOfWeek()->toDateString(),
            default => $d->format('Y-m'),
        };
        $label = fn (Carbon $d) => match ($unit) {
            'day'   => $d->format('d M'),
            'week'  => $d->copy()->startOfWeek()->format('d M'),
            default => $d->format('M Y'),
        };

        $labels = [];
        $series = ['leads' => [], 'booked' => [], 'visited' => []];
        $index  = [];

        for ($date = $from->copy(); $date->lte($to); $date->addDay()) {
            $key = $bucket($date);
            if (! isset($index[$key])) {
                $index[$key] = count($labels);
                $labels[] = $label($date);
                foreach ($series as &$s) {
                    $s[] = 0;
                }
                unset($s);
            }
            $i   = $index[$key];
            $day = $date->toDateString();
            $series['leads'][$i]   += (int) ($leads[$day] ?? 0);
            $series['booked'][$i]  += (int) ($booked[$day] ?? 0);
            $series['visited'][$i] += (int) ($visited[$day] ?? 0);
        }

        return ['unit' => $unit, 'labels' => $labels, 'series' => $series];
    }

    private function stages(User $user, array $filters, Carbon $from, Carbon $to): array
    {
        $counts = $this->base($user, $filters, $from, $to)
            ->selectRaw('leads.stage_id, COUNT(*) AS c')
            ->groupBy('leads.stage_id')
            ->pluck('c', 'stage_id');

        $rows = PipelineStage::orderBy('sort_order')->get(['id', 'name', 'color'])
            ->map(fn (PipelineStage $s) => [
                'label' => $s->name,
                'color' => $s->color,
                'count' => (int) ($counts[$s->id] ?? 0),
            ])
            ->filter(fn ($r) => $r['count'] > 0)
            ->values()
            ->all();

        if ($none = (int) ($counts[''] ?? $counts[null] ?? 0)) {
            $rows[] = ['label' => 'No stage', 'color' => '#adb5bd', 'count' => $none];
        }

        return $rows;
    }

    /**
     * Agents ranked by the visits they produced from the leads assigned to
     * them in the range. Dials and connects are their calls in the same window.
     */
    private function agents(User $user, array $filters, Carbon $from, Carbon $to): array
    {
        $rows = $this->base($user, $filters, $from, $to)
            ->whereNotNull('leads.assigned_to')
            ->join('users', 'users.id', '=', 'leads.assigned_to')
            ->selectRaw('leads.assigned_to AS user_id, users.name, COUNT(*) AS leads')
            ->selectRaw('SUM(leads.first_contacted_at IS NOT NULL OR leads.contact_attempts > 0) AS contacted')
            ->selectRaw("SUM(EXISTS (SELECT 1 FROM appointments a WHERE a.lead_id = leads.id AND a.deleted_at IS NULL AND a.status <> 'cancelled')) AS booked")
            ->selectRaw("SUM(EXISTS (SELECT 1 FROM appointments a WHERE a.lead_id = leads.id AND a.deleted_at IS NULL AND a.status = 'visited')) AS visited")
            // A booking is a couple who went on to treatment: a cycle opened,
            // the patient file in treatment, or the lead closed as won.
            ->selectRaw("SUM(leads.status = 'won' OR EXISTS (SELECT 1 FROM patients p WHERE p.id = leads.patient_id AND p.deleted_at IS NULL AND (p.journey_stage IN ('treatment', 'closed') OR EXISTS (SELECT 1 FROM treatment_cycles t WHERE t.patient_id = p.id)))) AS bookings")
            ->groupBy('leads.assigned_to', 'users.name')
            ->orderByDesc('visited')
            ->orderByDesc('booked')
            ->orderByDesc('leads')
            ->limit(15)
            ->get();

        $ids = $rows->pluck('user_id')->all();

        $calls = $ids === [] ? collect() : CallLog::query()
            ->whereIn('user_id', $ids)
            ->where('direction', 'outbound')
            ->whereBetween(DB::raw('COALESCE(started_at, created_at)'), [$from, $to])
            ->selectRaw("user_id, COUNT(*) AS dials, SUM(CASE WHEN status IN ('answered', 'completed') OR COALESCE(talk_seconds, 0) > 0 THEN 1 ELSE 0 END) AS connected")
            ->groupBy('user_id')
            ->get()
            ->keyBy('user_id');

        return $rows->values()->map(function ($r, $i) use ($calls) {
            $leads = (int) $r->leads;
            $call  = $calls->get($r->user_id);

            return [
                'rank'      => $i + 1,
                'user_id'   => (int) $r->user_id,
                'name'      => $r->name,
                'leads'     => $leads,
                'contacted' => (int) $r->contacted,
                'dials'     => (int) ($call->dials ?? 0),
                'connected' => (int) ($call->connected ?? 0),
                'appointments' => (int) $r->booked,
                'visited'   => (int) $r->visited,
                'bookings'  => (int) $r->bookings,
                'visit_rate' => $leads > 0 ? round((int) $r->visited / $leads * 100, 1) : 0.0,
            ];
        })->all();
    }

    /**
     * Leads created by weekday and hour, Monday first, for the heat map.
     *
     * @return array<int,array{day:string,hours:array<int,int>}>
     */
    private function heatmap(User $user, array $filters, Carbon $from, Carbon $to): array
    {
        // MySQL DAYOFWEEK: 1 = Sunday … 7 = Saturday.
        $rows = $this->base($user, $filters, $from, $to)
            ->selectRaw('DAYOFWEEK(leads.created_at) AS dow, HOUR(leads.created_at) AS h, COUNT(*) AS c')
            ->groupBy('dow', 'h')
            ->get();

        $days  = [2 => 'Mon', 3 => 'Tue', 4 => 'Wed', 5 => 'Thu', 6 => 'Fri', 7 => 'Sat', 1 => 'Sun'];
        $grid  = [];

        foreach ($days as $dow => $label) {
            $grid[$dow] = ['day' => $label, 'hours' => array_fill(0, 24, 0)];
        }

        foreach ($rows as $r) {
            if (isset($grid[(int) $r->dow])) {
                $grid[(int) $r->dow]['hours'][(int) $r->h] = (int) $r->c;
            }
        }

        return array_values($grid);
    }

    private function centers(User $user, array $filters, Carbon $from, Carbon $to): array
    {
        return $this->base($user, $filters, $from, $to)
            ->leftJoin('centers', 'centers.id', '=', 'leads.center_id')
            ->selectRaw('COALESCE(centers.name, "No centre") AS label, COUNT(*) AS leads')
            ->selectRaw("SUM(EXISTS (SELECT 1 FROM appointments a WHERE a.lead_id = leads.id AND a.deleted_at IS NULL AND a.status <> 'cancelled')) AS booked")
            ->selectRaw("SUM(EXISTS (SELECT 1 FROM appointments a WHERE a.lead_id = leads.id AND a.deleted_at IS NULL AND a.status = 'visited')) AS visited")
            ->selectRaw("SUM(leads.status = 'won') AS won")
            ->groupBy('label')
            ->orderByDesc('leads')
            ->get()
            ->map(fn ($r) => [
                'label'   => $r->label,
                'leads'   => (int) $r->leads,
                'booked'  => (int) $r->booked,
                'visited' => (int) $r->visited,
                'won'     => (int) $r->won,
            ])
            ->all();
    }

    /** Why leads were lost: the reason (sub-disposition) when one was given, else the disposition. */
    private function lostReasons(User $user, array $filters, Carbon $from, Carbon $to): array
    {
        return $this->base($user, $filters, $from, $to)
            ->whereIn('leads.status', ['lost', 'junk'])
            ->leftJoin('dispositions AS reason', 'reason.id', '=', DB::raw('COALESCE(leads.sub_disposition_id, leads.disposition_id)'))
            ->selectRaw('COALESCE(reason.name, "No reason recorded") AS label, COUNT(*) AS c')
            ->groupBy('label')
            ->orderByDesc('c')
            ->limit(8)
            ->get()
            ->map(fn ($r) => ['label' => $r->label, 'count' => (int) $r->c])
            ->all();
    }

    /** Outcomes of the calls made to these leads in the range, by disposition. */
    private function callOutcomes(User $user, array $filters, Carbon $from, Carbon $to): array
    {
        $leadIds = $this->base($user, $filters, $from, $to)->select('leads.id');

        $rows = CallLog::query()
            ->whereIn('call_logs.lead_id', $leadIds)
            ->whereBetween(DB::raw('COALESCE(call_logs.started_at, call_logs.created_at)'), [$from, $to])
            ->leftJoin('dispositions', 'dispositions.id', '=', 'call_logs.disposition_id')
            ->selectRaw('COALESCE(dispositions.name, "No outcome") AS label, dispositions.color, COUNT(*) AS c')
            ->groupBy('label', 'dispositions.color')
            ->orderByDesc('c')
            ->get();

        $total = (int) $rows->sum('c');
        $top   = $rows->take(7)->map(fn ($r) => ['label' => $r->label, 'color' => $r->color, 'count' => (int) $r->c])->values();
        $rest  = (int) $rows->skip(7)->sum('c');

        if ($rest > 0) {
            $top->push(['label' => 'Other outcomes', 'color' => '#adb5bd', 'count' => $rest]);
        }

        return ['total' => $total, 'items' => $top->all()];
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    /**
     * The leads every widget is counted over: created in the range, not merged
     * away, visible to this user, narrowed by the filters.
     */
    private function base(User $user, array $filters, Carbon $from, Carbon $to): Builder
    {
        return Lead::query()
            ->forUser($user)
            ->whereNull('leads.merged_into_id')
            ->whereBetween('leads.created_at', [$from, $to])
            ->when($filters['center_id'] ?? null, fn ($q, $v) => $q->where('leads.center_id', $v))
            ->when($filters['assigned_to'] ?? null, fn ($q, $v) => $q->where('leads.assigned_to', $v))
            ->when($filters['lead_source_id'] ?? null, fn ($q, $v) => $q->where('leads.lead_source_id', $v))
            ->when($filters['channel'] ?? null, fn ($q, $v) => $q->where('leads.channel', $v))
            ->when($filters['campaign_id'] ?? null, fn ($q, $v) => $q->where('leads.campaign_id', $v))
            ->when($filters['stage_id'] ?? null, fn ($q, $v) => $q->where('leads.stage_id', $v))
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('leads.status', $v));
    }

    /**
     * Every stage at or beyond the slug, as a SQL list, so the funnel stays
     * cumulative. "0" when the stage is missing: IN (0) matches nothing.
     */
    private function stageList(string $slug): string
    {
        static $cache = [];

        if (! isset($cache[$slug])) {
            $order = PipelineStage::where('slug', $slug)->value('sort_order');
            $ids   = $order === null ? [] : PipelineStage::where('sort_order', '>=', $order)->where('is_lost', false)->pluck('id')->all();
            $cache[$slug] = $ids === [] ? '0' : implode(',', array_map('intval', $ids));
        }

        return $cache[$slug];
    }

    /** @return array{0:Carbon,1:Carbon} */
    private function range(array $filters): array
    {
        return [Carbon::parse($filters['from'])->startOfDay(), Carbon::parse($filters['to'])->endOfDay()];
    }
}
