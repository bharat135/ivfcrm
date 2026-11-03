<?php

namespace App\Console\Commands;

use App\Mail\DailyTelecallingReport;
use App\Services\Reports\AgentPerformanceReport;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;

/**
 * Email the day's telecalling scorecard to management. Scheduled every
 * evening (config reports.daily.send_at); can also be run by hand for any day
 * or range, e.g. after fixing data.
 */
class SendDailyTelecallingReport extends Command
{
    protected $signature = 'reports:daily-telecalling
        {--date= : The day to report (Y-m-d); defaults to today}
        {--from= : Start of a range (Y-m-d); with --to, replaces --date}
        {--to= : End of a range (Y-m-d)}
        {--recipient=* : Send to these addresses instead of config reports.daily.recipients}';

    protected $description = 'Email the daily telecalling report (dials, connects, appointments, visits per centre, FTD/MTD)';

    public function handle(AgentPerformanceReport $report): int
    {
        $to   = Carbon::parse($this->option('to') ?: $this->option('date') ?: today()->toDateString());
        $from = $this->option('from') ? Carbon::parse($this->option('from')) : $to->copy();

        $recipients = $this->option('recipient') ?: config('reports.daily.recipients', []);
        if (empty($recipients)) {
            $this->warn('No recipients configured (reports.daily.recipients / DAILY_REPORT_RECIPIENTS). Nothing sent.');

            return self::SUCCESS;
        }

        $data = $report->build($from, $to);

        Mail::to($recipients)->send(new DailyTelecallingReport($data));

        $this->info(sprintf(
            'Telecalling report for %s sent to %s (%d agents, %d dials, %d visits).',
            $from->isSameDay($to) ? $to->format('d-m-Y') : $from->format('d-m-Y') . ' to ' . $to->format('d-m-Y'),
            implode(', ', $recipients),
            count($data['rows']),
            $data['totals']['dials'],
            $data['totals']['visited'],
        ));

        return self::SUCCESS;
    }
}
