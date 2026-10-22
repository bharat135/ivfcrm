<?php

namespace Tests\Feature;

use App\Models\CallLog;
use App\Models\Disposition;
use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Concerns\UsesProjectDatabase;
use Tests\TestCase;

class DispositionLogTest extends TestCase
{
    use DatabaseTransactions, UsesProjectDatabase;

    public function test_saving_a_disposition_logs_it_as_added_with_the_next_follow_up(): void
    {
        $telecaller = User::factory()->create(['is_active' => true]);
        $telecaller->assignRole('Telecaller');

        $lead = Lead::create([
            'name' => 'Log Couple', 'mobile' => '98765' . random_int(10000, 99999),
            'status' => 'new', 'assigned_to' => $telecaller->id,
        ]);

        $disposition = Disposition::active()->primary()->where('requires_followup', true)->first()
            ?? Disposition::active()->primary()->first();
        $this->assertNotNull($disposition, 'Seed dispositions first.');

        $followUp = now()->addDay()->setTime(11, 0);

        $this->actingAs($telecaller)->postJson(route('leads.disposition', $lead), [
            'disposition_id'    => $disposition->id,
            'remark'            => 'Call after lunch',
            'next_follow_up_at' => $followUp->format('Y-m-d H:i'),
        ])->assertOk();

        $entry = LeadActivity::where('lead_id', $lead->id)->where('type', 'status')->latest('id')->first();

        $this->assertSame('Disposition added', $entry->title);
        $this->assertSame(
            $disposition->name . ': Call after lunch · Next follow-up ' . $followUp->format('d-m-Y h:i A'),
            $entry->description
        );
    }

    public function test_the_profile_log_leaves_calls_to_the_calls_tab(): void
    {
        $admin = User::whereHas('roles', fn ($q) => $q->where('name', 'Super Admin'))->first();
        $lead  = Lead::create(['name' => 'Call Couple', 'mobile' => '98765' . random_int(10000, 99999), 'status' => 'new']);

        LeadActivity::create(['lead_id' => $lead->id, 'user_id' => $admin->id, 'type' => 'call', 'title' => 'Outbound call', 'description' => 'Cancelled']);
        LeadActivity::create(['lead_id' => $lead->id, 'user_id' => $admin->id, 'type' => 'status', 'title' => 'Disposition added', 'description' => 'Ringing: no answer']);

        $this->actingAs($admin)->get(route('leads.show', $lead))->assertOk()
            ->assertSee('Disposition added')
            ->assertDontSee('Outbound call');
    }
}
