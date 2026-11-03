@php
    /** @var array $report Output of AgentPerformanceReport::build(). */
    $centers = $report['centers'];
    $from    = $report['from'];
    $to      = $report['to'];
    $span    = $from->isSameDay($to) ? $to->format('l, d F Y') : $from->format('d M Y') . ' to ' . $to->format('d M Y');
    $cell    = 'border:1px solid #cfd4da;padding:6px 8px;font-size:13px;text-align:center;white-space:nowrap;';
    $head    = $cell . 'background:#f1f3f5;font-weight:600;';
    $rows    = $report['rows'];
    $totals  = $report['totals'];
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Telecalling report</title>
</head>
<body style="margin:0;padding:24px;background:#f6f7f9;font-family:Segoe UI,Arial,sans-serif;color:#212529;">
    <div style="max-width:1100px;margin:0 auto;background:#fff;border:1px solid #e3e6ea;border-radius:8px;padding:24px;">
        <h2 style="margin:0 0 4px;font-size:18px;">Telecalling report</h2>
        <p style="margin:0 0 16px;color:#6c757d;font-size:13px;">{{ $span }} · generated {{ now()->format('d-m-Y h:i A') }}</p>

        <table cellspacing="0" cellpadding="0" style="border-collapse:collapse;margin-bottom:16px;">
            <tr>
                <td style="{{ $head }}">DM Camp Count</td>
                <td style="{{ $cell }}font-weight:600;">{{ number_format($report['summary']['dm_camp_count']) }}</td>
                <td style="{{ $head }}">Total Leads count</td>
                <td style="{{ $cell }}font-weight:600;">{{ number_format($report['summary']['total_leads']) }}</td>
            </tr>
        </table>

        <div style="overflow-x:auto;">
            <table cellspacing="0" cellpadding="0" style="border-collapse:collapse;width:100%;">
                <thead>
                    <tr>
                        <th rowspan="2" style="{{ $head }}text-align:left;">Agent Name</th>
                        <th rowspan="2" style="{{ $head }}">Total Dial count</th>
                        <th rowspan="2" style="{{ $head }}">Total Connected</th>
                        <th rowspan="2" style="{{ $head }}">Not connected</th>
                        <th rowspan="2" style="{{ $head }}">Missed calls</th>
                        <th rowspan="2" style="{{ $head }}">Appointments booked</th>
                        <th rowspan="2" style="{{ $head }}">Visited</th>
                        <th rowspan="2" style="{{ $head }}">Missed visit</th>
                        <th colspan="{{ max($centers->count(), 1) }}" style="{{ $head }}">Center wise visit</th>
                        <th rowspan="2" style="{{ $head }}" title="Visits for the day">FTD</th>
                        <th rowspan="2" style="{{ $head }}" title="Visits month to date">MTD</th>
                    </tr>
                    <tr>
                        @forelse($centers as $center)
                            <th style="{{ $head }}">{{ $center->name }}</th>
                        @empty
                            <th style="{{ $head }}">—</th>
                        @endforelse
                    </tr>
                </thead>
                <tbody>
                    @forelse($rows as $row)
                        <tr>
                            <td style="{{ $cell }}text-align:left;">{{ $row['agent'] }}</td>
                            <td style="{{ $cell }}">{{ $row['dials'] }}</td>
                            <td style="{{ $cell }}">{{ $row['connected'] }}</td>
                            <td style="{{ $cell }}">{{ $row['not_connected'] }}</td>
                            <td style="{{ $cell }}">{{ $row['missed_calls'] }}</td>
                            <td style="{{ $cell }}">{{ $row['appointments_booked'] }}</td>
                            <td style="{{ $cell }}">{{ $row['visited'] }}</td>
                            <td style="{{ $cell }}">{{ $row['missed_visits'] }}</td>
                            @forelse($centers as $center)
                                <td style="{{ $cell }}">{{ $row['visits_by_center'][$center->id] ?? 0 }}</td>
                            @empty
                                <td style="{{ $cell }}">—</td>
                            @endforelse
                            <td style="{{ $cell }}">{{ $row['visits_ftd'] }}</td>
                            <td style="{{ $cell }}">{{ $row['visits_mtd'] }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="{{ 10 + max($centers->count(), 1) }}" style="{{ $cell }}color:#6c757d;">No active telecallers.</td></tr>
                    @endforelse
                </tbody>
                <tfoot>
                    <tr>
                        <td style="{{ $head }}text-align:left;">Total</td>
                        <td style="{{ $head }}">{{ $totals['dials'] }}</td>
                        <td style="{{ $head }}">{{ $totals['connected'] }}</td>
                        <td style="{{ $head }}">{{ $totals['not_connected'] }}</td>
                        <td style="{{ $head }}">{{ $totals['missed_calls'] }}</td>
                        <td style="{{ $head }}">{{ $totals['appointments_booked'] }}</td>
                        <td style="{{ $head }}">{{ $totals['visited'] }}</td>
                        <td style="{{ $head }}">{{ $totals['missed_visits'] }}</td>
                        @forelse($centers as $center)
                            <td style="{{ $head }}">{{ $totals['visits_by_center'][$center->id] ?? 0 }}</td>
                        @empty
                            <td style="{{ $head }}">—</td>
                        @endforelse
                        <td style="{{ $head }}">{{ $totals['visits_ftd'] }}</td>
                        <td style="{{ $head }}">{{ $totals['visits_mtd'] }}</td>
                    </tr>
                </tfoot>
            </table>
        </div>

        <p style="margin:16px 0 0;color:#6c757d;font-size:12px;">
            Dials are outbound calls; connected means answered or with talk time. Missed calls are inbound calls the agent did not answer.
            Appointments booked counts bookings made by the agent in the period; Visited and Missed visit count appointments scheduled in the period.
            FTD is visits on {{ $to->format('d-m-Y') }}, MTD is visits from {{ $to->copy()->startOfMonth()->format('d-m-Y') }} to {{ $to->format('d-m-Y') }}.
            The same table is attached as CSV. Live figures: <a href="{{ route('dashboard', ['from' => $from->toDateString(), 'to' => $to->toDateString()]) }}">{{ config('app.name') }} dashboard</a>.
        </p>
    </div>
</body>
</html>
