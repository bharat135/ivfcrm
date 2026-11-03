<?php

namespace App\Mail;

use App\Services\Reports\AgentPerformanceReport;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Attachment;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The day's telecalling scorecard, as management keeps it in their sheet.
 * The table is in the body and attached as CSV for anyone who wants to paste
 * it into the master sheet.
 */
class DailyTelecallingReport extends Mailable
{
    use Queueable, SerializesModels;

    /** @param array $report Output of AgentPerformanceReport::build(). */
    public function __construct(public array $report) {}

    public function envelope(): Envelope
    {
        $day = $this->report['to']->format('d-m-Y');
        $span = $this->report['from']->isSameDay($this->report['to'])
            ? $day
            : $this->report['from']->format('d-m-Y') . ' to ' . $day;

        return new Envelope(subject: 'Telecalling report · ' . $span);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.daily-telecalling-report', with: ['report' => $this->report]);
    }

    /** @return array<int, Attachment> */
    public function attachments(): array
    {
        $csv  = app(AgentPerformanceReport::class)->toCsv($this->report);
        $name = 'telecalling-report-' . $this->report['to']->format('Y-m-d') . '.csv';

        return [Attachment::fromData(fn () => $csv, $name)->withMime('text/csv')];
    }
}
