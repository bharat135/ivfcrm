<?php

namespace Tests\Feature;

use App\Mail\DailyTelecallingReport;
use App\Models\Appointment;
use App\Models\CallLog;
use App\Models\Center;
use App\Models\Disposition;
use App\Models\Doctor;
use App\Models\Lead;
use App\Models\PipelineStage;
use App\Models\User;
use App\Services\Reports\AgentPerformanceReport;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\UsesProjectDatabase;
use Tests\TestCase;

class DailyReportAndDashboardTest extends TestCase
{
    use DatabaseTransactions, UsesProjectDatabase;

    private function telecaller(?int $centerId = null): User
    {
        $user = User::factory()->create(['is_active' => true, 'center_id' => $centerId]);
        $user->assignRole('Telecaller');

        return $user;
    }

    private function lead(User $agent): Lead
    {
        return Lead::create([
            'name' => 'Report Couple', 'mobile' => '98765' . random_int(10000, 99999),
            'status' => 'new', 'assigned_to' => $agent->id,
        ]);
    }

    private function appointment(User $agent, Lead $lead, Center $center, string $status, $scheduledAt): Appointment
    {
        $doctor = Doctor::where('center_id', $center->id)->first() ?? Doctor::first();

        return Appointment::create([
            'doctor_id' => $doctor?->id, 'center_id' => $center->id, 'lead_id' => $lead->id,
            'patient_id' => $lead->patient?->id, 'created_by' => $agent->id, 'type' => 'consultation',
            'scheduled_at' => $scheduledAt, 'duration_minutes' => 30, 'status' => $status,
        ]);
    }

    public function test_the_scorecard_counts_dials_connects_missed_calls_appointments_and_visits_per_centre(): void
    {
        $center = Center::active()->first();
        $this->assertNotNull($center, 'Seed centres first.');
        $agent = $this->telecaller($center->id);
        $lead  = $this->lead($agent);

        foreach ([['answered', 60], ['no_answer', 0], ['busy', 0]] as [$status, $talk]) {
            CallLog::create([
                'lead_id' => $lead->id, 'user_id' => $agent->id, 'direction' => 'outbound', 'provider' => 'tata',
                'status' => $status, 'talk_seconds' => $talk, 'started_at' => now(), 'ended_at' => now(),
            ]);
        }
        CallLog::create([
            'lead_id' => $lead->id, 'user_id' => $agent->id, 'direction' => 'inbound', 'provider' => 'tata',
            'status' => 'no_answer', 'talk_seconds' => 0, 'started_at' => now(), 'ended_at' => now(),
        ]);

        $this->appointment($agent, $lead, $center, 'visited', now()->setTime(10, 0));
        $this->appointment($agent, $lead, $center, 'no_show', now()->setTime(11, 0));
        $this->appointment($agent, $lead, $center, 'booked', now()->addDay()->setTime(10, 0));
        // Earlier this month: counts towards MTD only.
        if (today()->day > 1) {
            $this->appointment($agent, $lead, $center, 'visited', today()->startOfMonth()->setTime(10, 0));
        }

        $report = app(AgentPerformanceReport::class)->build(today(), today(), collect([$agent]));
        $row    = $report['rows'][0];

        $this->assertSame($agent->name, $row['agent']);
        $this->assertSame(3, $row['dials']);
        $this->assertSame(1, $row['connected']);
        $this->assertSame(2, $row['not_connected']);
        $this->assertSame(1, $row['missed_calls']);
        // Every appointment above was created now, including the one scheduled earlier this month.
        $this->assertSame(today()->day > 1 ? 4 : 3, $row['appointments_booked']);
        $this->assertSame(1, $row['visited']);
        $this->assertSame(1, $row['missed_visits']);
        $this->assertSame(1, $row['visits_by_center'][$center->id]);
        $this->assertSame(1, $row['visits_ftd']);
        $this->assertSame(today()->day > 1 ? 2 : 1, $row['visits_mtd']);
        $this->assertSame(3, $report['totals']['dials']);
        $this->assertStringContainsString('Agent Name', app(AgentPerformanceReport::class)->toCsv($report));
    }

    public function test_the_daily_report_is_emailed_to_the_configured_recipients_with_a_csv(): void
    {
        Mail::fake();
        config(['reports.daily.recipients' => ['ceo@example.test', 'ops@example.test']]);
        $agent = $this->telecaller();

        $this->artisan('reports:daily-telecalling')->assertSuccessful();

        Mail::assertSent(DailyTelecallingReport::class, function (DailyTelecallingReport $mail) use ($agent) {
            $this->assertTrue($mail->hasTo('ceo@example.test'));
            $this->assertTrue($mail->hasTo('ops@example.test'));
            $this->assertStringContainsString('Telecalling report', $mail->envelope()->subject);
            $html = $mail->render();
            $this->assertStringContainsString('Center wise visit', $html);
            $this->assertStringContainsString($agent->name, $html);
            $this->assertStringContainsString('DM Camp Count', $html);
            $this->assertCount(1, $mail->attachments());

            return true;
        });
    }

    public function test_the_default_recipients_are_the_four_management_addresses(): void
    {
        $this->assertSame(
            ['clinical.operations@prevoirivf.com', 'CEO@prevoirivf.com', 'CCO@prevoirivf.com', 'crm.qaqc@prevoirivf.com'],
            config('reports.daily.recipients')
        );
    }

    public function test_the_dashboard_takes_a_date_range_and_shows_the_team_table(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole('Super Admin');
        $agent = $this->telecaller();

        $this->actingAs($admin)
            ->get(route('dashboard', ['from' => today()->subDays(6)->toDateString(), 'to' => today()->toDateString()]))
            ->assertOk()
            ->assertSee('Team Performance')
            ->assertSee('Center wise visit')
            ->assertSee($agent->name)
            ->assertSee(today()->subDays(6)->format('d-m-Y') . ' to ' . today()->format('d-m-Y'));

        // A telecaller sees only their own row.
        $other = $this->telecaller();
        $this->actingAs($agent)->get(route('dashboard'))
            ->assertOk()
            ->assertSee($agent->name)
            ->assertDontSee($other->name);
    }

    public function test_the_navbar_counts_scheduled_appointments_for_the_viewer(): void
    {
        $center = Center::active()->first();
        $agent  = $this->telecaller($center->id);
        $other  = $this->telecaller($center->id);
        $admin  = User::factory()->create(['is_active' => true]);
        $admin->assignRole('Super Admin');

        $this->appointment($agent, $this->lead($agent), $center, 'booked', now()->addDay()->setTime(10, 0));
        $this->appointment($other, $this->lead($other), $center, 'confirmed', now()->addDays(2)->setTime(10, 0));
        $this->appointment($other, $this->lead($other), $center, 'visited', now()->subDay()->setTime(10, 0));

        $this->actingAs($agent)->get(route('dashboard'))
            ->assertSee('id="navScheduledAppointments">1<', false);

        $this->actingAs($admin)->get(route('dashboard'))
            ->assertSee('id="navScheduledAppointments">2<', false);
    }

    public function test_visit_dispositions_need_no_next_follow_up_time(): void
    {
        $agent = $this->telecaller();
        $lead  = $this->lead($agent);

        $visited = Disposition::active()->primary()->get()->first(fn ($d) => $d->isVisitOutcome());
        $this->assertNotNull($visited, 'A "Visit done" disposition (moving to consultation-visited) must exist.');
        $this->assertFalse($visited->needsFollowUpTime());

        $this->actingAs($agent)->postJson(route('leads.disposition', $lead), [
            'disposition_id' => $visited->id,
            'remark'         => 'Came in for the consultation',
        ])->assertOk();

        $this->assertSame(
            PipelineStage::where('slug', 'consultation-visited')->value('id'),
            $lead->fresh()->stage_id
        );

        // The profile marks these options so the form hides the date field.
        $this->actingAs($agent)->get(route('leads.show', $lead))
            ->assertOk()
            ->assertSee('value="' . $visited->id . '" data-stop-call="0" data-visit="1"', false);

        // A callback outcome still needs the time.
        $callback = Disposition::active()->primary()->where('is_lost', false)->get()->first(fn ($d) => ! $d->isVisitOutcome());
        $this->actingAs($agent)->postJson(route('leads.disposition', $lead), [
            'disposition_id' => $callback->id,
            'remark'         => 'Will call back',
        ])->assertStatus(422)->assertJsonValidationErrors('next_follow_up_at');
    }
}
