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

    public function test_a_follow_up_time_is_required_unless_the_disposition_stops_calling(): void
    {
        $telecaller = User::factory()->create(['is_active' => true]);
        $telecaller->assignRole('Telecaller');
        $lead = Lead::create(['name' => 'Rule Couple', 'mobile' => '98765' . random_int(10000, 99999), 'status' => 'new', 'assigned_to' => $telecaller->id]);

        $callback = Disposition::active()->primary()->where('is_lost', false)->first();
        $stop     = Disposition::active()->primary()->where('is_lost', true)->first();
        $this->assertNotNull($callback);
        $this->assertNotNull($stop);

        $this->actingAs($telecaller)->postJson(route('leads.disposition', $lead), [
            'disposition_id' => $callback->id, 'remark' => 'No time given',
        ])->assertUnprocessable()->assertJsonValidationErrors('next_follow_up_at');

        $this->actingAs($telecaller)->postJson(route('leads.disposition', $lead), [
            'disposition_id' => $stop->id, 'remark' => 'Do not call again',
        ])->assertOk();
    }

    public function test_the_name_can_be_changed_from_the_record_card(): void
    {
        $telecaller = User::factory()->create(['is_active' => true]);
        $telecaller->assignRole('Telecaller');
        $lead = Lead::create(['name' => 'unknown', 'mobile' => '98765' . random_int(10000, 99999), 'status' => 'new', 'assigned_to' => $telecaller->id]);
        app(\App\Services\PatientLinker::class)->ensureFor($lead);

        $this->actingAs($telecaller)->patchJson(route('leads.details', $lead), ['name' => 'Sunita Patil'])->assertOk();

        $this->assertSame('Sunita Patil', $lead->fresh()->name);
        $this->assertSame('Sunita Patil', $lead->fresh()->patient->wife_name);
        $this->assertDatabaseHas('lead_activities', ['lead_id' => $lead->id, 'title' => 'Details updated']);
    }

    public function test_the_profile_updates_show_calls_dispositions_and_edits_as_boxes(): void
    {
        $admin = User::whereHas('roles', fn ($q) => $q->where('name', 'Super Admin'))->first();
        $lead  = Lead::create(['name' => 'Call Couple', 'mobile' => '98765' . random_int(10000, 99999), 'status' => 'new']);

        LeadActivity::create(['lead_id' => $lead->id, 'user_id' => $admin->id, 'type' => 'call', 'title' => 'Outbound call', 'description' => 'Cancelled']);
        LeadActivity::create(['lead_id' => $lead->id, 'user_id' => $admin->id, 'type' => 'status', 'title' => 'Disposition added', 'description' => 'Ringing: no answer']);

        $this->actingAs($admin)->get(route('leads.show', $lead))->assertOk()
            ->assertSee('Updates')
            ->assertSee('update-box', false)
            ->assertSee('Disposition added')
            ->assertSee('Outbound call')
            // The log card opens with nothing chosen and no remark carried over.
            ->assertSee('<option value="">Select</option>', false)
            ->assertSee('Save and stay');
    }

    public function test_booking_an_appointment_is_logged_as_an_update(): void
    {
        $admin  = User::whereHas('roles', fn ($q) => $q->where('name', 'Super Admin'))->first();
        $lead   = Lead::create(['name' => 'Booked Couple', 'mobile' => '98765' . random_int(10000, 99999), 'status' => 'new']);
        $doctor = \App\Models\Doctor::first() ?? \App\Models\Doctor::create(['name' => 'Dr Log', 'code' => 'DL' . random_int(1000, 9999), 'is_active' => true]);

        $this->actingAs($admin)->postJson(route('appointments.store'), [
            'lead_id'      => $lead->id,
            'doctor_id'    => $doctor->id,
            'center_id'    => \App\Models\Center::value('id'),
            'scheduled_at' => now()->addDay()->setTime(12, 0)->format('Y-m-d H:i:s'),
            'type'         => 'consultation',
        ])->assertOk();

        $entry = LeadActivity::where('lead_id', $lead->id)->where('type', 'appointment')->first();
        $this->assertNotNull($entry);
        $this->assertSame('Appointment booked', $entry->title);
        $this->assertStringContainsString('Dr. ' . $doctor->name, $entry->description);
    }
}
