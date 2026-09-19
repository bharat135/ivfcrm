<?php

use App\Jobs\SendFollowUpRemindersJob;
use App\Jobs\SyncMetaLeadFormsJob;
use App\Jobs\SyncGoogleAdsSpendJob;
use App\Jobs\SyncMetaAccountJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Send follow-up reminders every 15 minutes
Schedule::job(new SendFollowUpRemindersJob)->everyFifteenMinutes();

// Lead Ads backstop: the leadgen webhook delivers new submissions live, but
// only once the Page is subscribed to the app. Poll every auto-sync form so a
// missed webhook (unsubscribed page, app in dev mode, downtime) still lands
// in the CRM within minutes. Dedup on external_lead_id makes overlap harmless.
Schedule::job(new SyncMetaLeadFormsJob)
    ->everyFifteenMinutes()
    ->name('sync-meta-lead-forms')
    ->withoutOverlapping();

// Meta ad structure + lead forms: hourly is plenty, since edits are rare and
// new leads arrive via the webhook rather than this sync.
Schedule::job(new SyncMetaAccountJob(['structure', 'forms']))
    ->hourly()
    ->name('sync-meta-structure')
    ->withoutOverlapping();

// Insights are restated by Meta for up to 28 days, so re-pull a rolling window
// every three hours rather than only yesterday.
Schedule::job(new SyncMetaAccountJob(['insights']))
    ->everyThreeHours()
    ->name('sync-meta-insights')
    ->withoutOverlapping();

// Google restates recent spend as invalid clicks are filtered out, so the
// window overlaps rather than pulling yesterday alone. Nothing happens until
// the credentials are entered under Settings -> Integrations -> Google Ads.
Schedule::job(new SyncGoogleAdsSpendJob)
    ->everySixHours()
    ->name('sync-google-ads-spend')
    ->withoutOverlapping();

// Template approval states change out of band; refresh nightly as a backstop
// for the message_template_status_update webhook.
Schedule::job(new SyncMetaAccountJob(['templates']))
    ->dailyAt('03:00')
    ->name('sync-whatsapp-templates');

// Close WhatsApp threads that have been silent for a week so the inbox reflects
// live work rather than history.
Schedule::call(function () {
    \App\Models\Conversation::where('channel', 'whatsapp')
        ->where('status', 'open')
        ->where('unread_count', 0)
        ->where('last_message_at', '<', now()->subDays(7))
        ->update(['status' => 'closed']);
})->dailyAt('01:00')->name('auto-close-stale-conversations');

// Mark overdue follow-ups as missed at midnight
Schedule::call(function () {
    \App\Models\FollowUp::where('status', 'pending')
        ->where('scheduled_at', '<', now()->subHours(24))
        ->update(['status' => 'missed']);
})->dailyAt('00:30')->name('mark-overdue-followups');

// Presence-aware assignment: leads that arrived while nobody was online are
// handed out the minute an agent appears, and an enquiry its owner has not
// called within the idle window moves to another online agent.
Schedule::job(new \App\Jobs\AssignPendingLeadsJob)->everyMinute()->name('assign-pending-leads')->withoutOverlapping();
Schedule::job(new \App\Jobs\ReassignIdleLeadsJob)->everyFiveMinutes()->name('reassign-idle-leads')->withoutOverlapping();

// Keep the Tata Smartflo access token fresh so panel calls never hit an expired
// token. Runs often; the command itself only re-mints when near expiry.
Schedule::command('tata:refresh-token')->everyThirtyMinutes()->withoutOverlapping();
