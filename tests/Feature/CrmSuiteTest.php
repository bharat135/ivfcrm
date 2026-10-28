<?php

namespace Tests\Feature;

use App\Models\Appointment;
use App\Models\Center;
use App\Models\Disposition;
use App\Models\Doctor;
use App\Models\FollowUp;
use App\Models\Lead;
use App\Models\LeadSource;
use App\Models\Patient;
use App\Models\PipelineStage;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Whole-CRM regression sweep.
 *
 * Covers every module the application ships — auth, dashboard, leads, follow-ups,
 * calls, appointments, patients, campaigns, imports, reports, settings and the
 * public API — so a change in one area cannot quietly break another. Runs against
 * the real MySQL schema inside a transaction, with lazy-loading prevention on.
 */
class CrmSuiteTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;
    private User $telecaller;
    private Doctor $doctor;

    protected function refreshApplication(): void
    {
        parent::refreshApplication();

        Model::preventLazyLoading(true);

        $env = [];
        foreach (file(base_path('.env'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || ! str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            $env[trim($key)] = trim($value, " \t\"'");
        }

        config([
            'database.default' => 'mysql',
            'database.connections.mysql.host'     => $env['DB_HOST'] ?? '127.0.0.1',
            'database.connections.mysql.port'     => $env['DB_PORT'] ?? '3306',
            'database.connections.mysql.database' => $env['DB_DATABASE'] ?? 'ivfcrm',
            'database.connections.mysql.username' => $env['DB_USERNAME'] ?? 'root',
            'database.connections.mysql.password' => $env['DB_PASSWORD'] ?? '',
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::whereHas('roles', fn ($q) => $q->where('name', 'Super Admin'))->first() ?? User::first();

        $this->assertNotNull($this->admin, 'Run `php artisan db:seed` before this suite.');

        $this->telecaller = User::factory()->create([
            'name'      => 'Suite Telecaller',
            'is_active' => true,
            'center_id' => $this->admin->center_id ?: Center::value('id'),
        ]);
        $this->telecaller->assignRole('Telecaller');

        // The app ships no DoctorSeeder, so appointment coverage provides its
        // own rather than skipping — booking is the core of the funnel.
        $this->doctor = Doctor::first() ?: Doctor::create([
            'name'           => 'Dr Suite Fertility',
            'code'           => 'DOC' . random_int(1000, 9999),
            'specialization' => 'Reproductive Medicine',
            'center_id'      => Center::value('id'),
            'is_active'      => true,
        ]);
    }

    // =================================================================
    // Seed data integrity — everything else depends on this
    // =================================================================

    public function test_the_reference_data_is_seeded(): void
    {
        $this->assertGreaterThan(0, Center::count(), 'No centres seeded.');
        $this->assertGreaterThan(0, LeadSource::count(), 'No lead sources seeded.');
        $this->assertGreaterThan(0, PipelineStage::count(), 'No pipeline stages seeded.');
        $this->assertGreaterThan(0, Disposition::count(), 'No dispositions seeded.');
        $this->assertGreaterThan(0, \Spatie\Permission\Models\Role::count(), 'No roles seeded.');
    }

    public function test_every_role_has_at_least_one_permission(): void
    {
        $empty = \Spatie\Permission\Models\Role::withCount('permissions')
            ->having('permissions_count', '=', 0)
            ->pluck('name');

        $this->assertEmpty($empty, 'Roles without permissions: ' . $empty->join(', '));
    }

    public function test_the_pipeline_has_a_won_and_a_lost_stage(): void
    {
        $this->assertTrue(PipelineStage::where('is_won', true)->exists());
        $this->assertTrue(PipelineStage::where('is_lost', true)->exists());
    }

    // =================================================================
    // Auth
    // =================================================================

    public function test_the_login_screen_renders(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertSee('Sign in to IVF CRM', false)
            ->assertSee('name="email"', false)
            ->assertSee('name="password"', false);
    }

    public function test_bad_credentials_are_rejected(): void
    {
        $this->post(route('login.submit'), [
            'email'    => $this->admin->email,
            'password' => 'definitely-not-the-password',
        ])->assertSessionHasErrors();

        $this->assertGuest();
    }

    public function test_an_inactive_user_cannot_reach_the_dashboard(): void
    {
        $this->telecaller->update(['is_active' => false]);

        // Whether blocked at login or by middleware, they must not get a 200.
        $response = $this->actingAs($this->telecaller)->get(route('dashboard'));

        $this->assertNotSame(200, $response->getStatusCode(),
            'An inactive user reached the dashboard.');
    }

    public function test_logout_ends_the_session(): void
    {
        $this->actingAs($this->admin)->post(route('logout'))->assertRedirect();
        $this->assertGuest();
    }

    #[DataProvider('guardedRoutes')]
    public function test_guests_are_redirected_to_login(string $route): void
    {
        $this->get(route($route))->assertRedirect(route('login'));
    }

    public static function guardedRoutes(): array
    {
        return [
            'dashboard'    => ['dashboard'],
            'leads'        => ['leads.index'],
            'patients'     => ['patients.index'],
            'appointments' => ['appointments.index'],
            'call logs'    => ['call_logs.index'],
            'reports'      => ['reports.leads'],
        ];
    }

    // =================================================================
    // Every screen renders
    // =================================================================

    #[DataProvider('screens')]
    public function test_screens_render_for_an_admin(string $route): void
    {
        $this->actingAs($this->admin)->get(route($route))->assertOk();
    }

    public static function screens(): array
    {
        return [
            'dashboard'         => ['dashboard'],
            'pipeline kanban'   => ['dashboard.pipeline'],
            'leads list'        => ['leads.index'],
            'lead create'       => ['leads.create'],
            'follow-ups'        => ['followups.index'],
            'call logs'         => ['call_logs.index'],
            'call console'      => ['calls.console'],
            'appointments'      => ['appointments.index'],
            'patients'          => ['patients.index'],
            'campaigns (legacy)'=> ['campaigns.index'],
            'lead report'       => ['reports.leads'],
            'telecaller report' => ['reports.telecaller'],
            'source report'     => ['reports.source'],
            'imports'           => ['imports.index'],
            'settings users'    => ['settings.users.index'],
            'settings roles'    => ['settings.roles.index'],
            'settings dispositions' => ['settings.dispositions.index'],
            'settings centers'  => ['settings.centers.index'],
            'settings sources'  => ['settings.lead_sources.index'],
            'settings integrations' => ['settings.integrations.index'],
            'settings telephony'=> ['settings.telephony.index'],
            'whatsapp inbox'    => ['chat.index'],
            'meta campaigns'    => ['marketing.campaigns.index'],
            'landing pages'     => ['marketing.landing.index'],
            'ad analytics'      => ['marketing.analytics.index'],
            'conversion funnel' => ['marketing.analytics.funnel'],
        ];
    }

    #[DataProvider('dataTableEndpoints')]
    public function test_server_side_datatables_return_json(string $route): void
    {
        $response = $this->actingAs($this->admin)
            ->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
            ->getJson(route($route, ['draw' => 1, 'start' => 0, 'length' => 10]));

        $response->assertOk();

        $this->assertNull($response->json('error'), "DataTable [{$route}]: " . $response->json('error'));
        $this->assertIsArray($response->json('data'), "DataTable [{$route}] returned no data array.");
    }

    public static function dataTableEndpoints(): array
    {
        return [
            'leads'        => ['leads.index'],
            'follow-ups'   => ['followups.index'],
            'call logs'    => ['call_logs.index'],
            'appointments' => ['appointments.index'],
            'patients'     => ['patients.index'],
            'campaigns'    => ['campaigns.index'],
            'users'        => ['settings.users.index'],
            'centers'      => ['settings.centers.index'],
            'lead sources' => ['settings.lead_sources.index'],
        ];
    }

    // =================================================================
    // Leads
    // =================================================================

    public function test_a_lead_can_be_created_and_is_auto_assigned(): void
    {
        $this->actingAs($this->admin)
            ->post(route('leads.store'), [
                'name'      => 'Suite Lead',
                'mobile'    => '9791100001',
                'email'     => 'suite.lead@example.com',
                'gender'    => 'female',
                'age'       => 31,
                'center_id' => Center::value('id'),
            ])
            ->assertRedirect();

        $lead = Lead::where('mobile', '9791100001')->first();

        $this->assertNotNull($lead);
        $this->assertSame('Suite Lead', $lead->name);
        $this->assertNotEmpty($lead->uuid, 'Leads must get a UUID.');
        $this->assertNotEmpty($lead->dedup_hash, 'Leads must get a dedup hash.');
    }

    public function test_a_lead_requires_a_mobile_number(): void
    {
        $this->actingAs($this->admin)
            ->post(route('leads.store'), ['name' => 'No Number'])
            ->assertSessionHasErrors('mobile');
    }

    public function test_creating_a_duplicate_mobile_does_not_create_a_second_lead(): void
    {
        Lead::create(['name' => 'Original', 'mobile' => '9791100002', 'status' => 'new']);

        $before = Lead::where('mobile', '9791100002')->count();

        $this->actingAs($this->admin)->post(route('leads.store'), [
            'name'   => 'Duplicate Attempt',
            'mobile' => '9791100002',
        ]);

        $this->assertSame(
            $before,
            Lead::where('mobile', '9791100002')->count(),
            'Deduplication should have prevented a second lead.',
        );
    }

    /**
     * An enquiry and a patient are one person, so /leads/{id} renders the one
     * profile screen — the same tabs a patient sees, not a second view.
     */
    public function test_a_lead_detail_page_renders_the_person_profile(): void
    {
        $lead = Lead::create(['name' => 'Detail Lead', 'mobile' => '9791100003', 'status' => 'new']);

        $this->actingAs($this->admin)
            ->get(route('leads.show', $lead))
            ->assertOk()
            ->assertSee('Detail Lead')
            ->assertSee('profile-shell', false)
            ->assertSee('tabOverview', false)
            ->assertSee('tabTasks', false)
            ->assertSee('tabCalls', false)
            ->assertSee('tabMessages', false);
    }

    public function test_a_disposition_moves_the_lead_and_can_schedule_a_follow_up(): void
    {
        $lead = Lead::create(['name' => 'Disposition Lead', 'mobile' => '9791100004', 'status' => 'new']);
        $disposition = Disposition::whereNull('parent_id')->where('is_active', true)->first();

        $this->actingAs($this->admin)
            ->postJson(route('leads.disposition', $lead), [
                'disposition_id'    => $disposition->id,
                'remark'            => 'Spoke to the patient.',
                // Inside call hours whatever time of day the suite runs.
                'next_follow_up_at' => now()->addDay()->setTime(11, 0)->format('Y-m-d H:i:s'),
            ])
            ->assertOk();

        $lead->refresh();

        $this->assertSame($disposition->id, $lead->disposition_id);
        $this->assertSame('Spoke to the patient.', $lead->remark);
        $this->assertNotNull($lead->next_follow_up_at);
        $this->assertDatabaseHas('follow_ups', ['lead_id' => $lead->id, 'status' => 'pending']);
    }

    public function test_a_lead_can_be_reassigned_and_the_move_is_recorded(): void
    {
        $lead = Lead::create(['name' => 'Reassign Lead', 'mobile' => '9791100005', 'status' => 'new']);

        $this->actingAs($this->admin)
            ->postJson(route('leads.reassign', $lead), ['user_id' => $this->telecaller->id])
            ->assertOk();

        $this->assertSame($this->telecaller->id, $lead->fresh()->assigned_to);
        $this->assertDatabaseHas('lead_assignments', [
            'lead_id'     => $lead->id,
            'assigned_to' => $this->telecaller->id,
        ]);
    }

    public function test_leads_can_be_bulk_assigned(): void
    {
        $ids = collect(range(1, 3))->map(fn ($i) => Lead::create([
            'name'   => "Bulk Lead {$i}",
            'mobile' => '979110001' . $i,
            'status' => 'new',
        ])->id)->all();

        $this->actingAs($this->admin)
            ->postJson(route('leads.bulk_assign'), [
                'lead_ids' => $ids,
                'user_id'  => $this->telecaller->id,
            ])
            ->assertOk();

        $this->assertSame(3, Lead::whereIn('id', $ids)->where('assigned_to', $this->telecaller->id)->count());
    }

    public function test_a_lead_can_be_moved_between_pipeline_stages(): void
    {
        $lead  = Lead::create(['name' => 'Stage Lead', 'mobile' => '9791100020', 'status' => 'new']);
        $stage = PipelineStage::where('slug', 'interested')->first();

        $this->actingAs($this->admin)
            ->postJson(route('leads.stage', $lead), ['stage_id' => $stage->id])
            ->assertOk();

        $this->assertSame($stage->id, $lead->fresh()->stage_id);
    }

    public function test_a_telecaller_only_sees_their_own_leads(): void
    {
        $mine   = Lead::create(['name' => 'Mine', 'mobile' => '9791100021', 'status' => 'new', 'assigned_to' => $this->telecaller->id]);
        $theirs = Lead::create(['name' => 'Theirs', 'mobile' => '9791100022', 'status' => 'new', 'assigned_to' => $this->admin->id]);

        $visible = Lead::forUser($this->telecaller)->pluck('id');

        $this->assertTrue($visible->contains($mine->id));
        $this->assertFalse($visible->contains($theirs->id), 'A telecaller must not see another agent\'s leads.');
    }

    // =================================================================
    // Follow-ups
    // =================================================================

    public function test_a_follow_up_can_be_scheduled_and_completed(): void
    {
        $lead = Lead::create(['name' => 'Follow Up Lead', 'mobile' => '9791100006', 'status' => 'new']);

        $id = $this->actingAs($this->admin)
            ->postJson(route('followups.store'), [
                'lead_id'      => $lead->id,
                'type'         => 'call',
                // Inside call hours whatever time of day the suite runs.
                'scheduled_at' => now()->addDay()->setTime(11, 0)->format('Y-m-d H:i:s'),
                'remark'       => 'Call back tomorrow.',
            ])
            ->assertOk()
            ->json('id');

        $this->assertNotNull($lead->fresh()->next_follow_up_at);

        $this->actingAs($this->admin)
            ->postJson(route('followups.complete', $id), ['remark' => 'Done.'])
            ->assertOk();

        $this->assertSame('done', FollowUp::find($id)->status);
    }

    public function test_a_follow_up_can_be_cancelled(): void
    {
        $lead = Lead::create(['name' => 'Cancel Lead', 'mobile' => '9791100007', 'status' => 'new']);

        $followUp = FollowUp::create([
            'lead_id'      => $lead->id,
            'user_id'      => $this->admin->id,
            'type'         => 'call',
            'scheduled_at' => now()->addDay(),
            'status'       => 'pending',
        ]);

        $this->actingAs($this->admin)->postJson(route('followups.cancel', $followUp))->assertOk();

        $this->assertSame('cancelled', $followUp->fresh()->status);
    }

    // =================================================================
    // Appointments — the "booking" end of the funnel
    // =================================================================

    public function test_booking_an_appointment_moves_the_lead_to_consultation_booked(): void
    {
        $lead = Lead::create(['name' => 'Booking Lead', 'mobile' => '9791100008', 'status' => 'new']);

        $this->actingAs($this->admin)
            ->postJson(route('appointments.store'), [
                'lead_id'      => $lead->id,
                'doctor_id'    => $this->doctor->id,
                'center_id'    => Center::value('id'),
                'scheduled_at' => now()->addDays(2)->format('Y-m-d H:i:s'),
                'type'         => 'consultation',
            ])
            ->assertOk();

        $booked = PipelineStage::where('slug', 'consultation-booked')->first();

        $this->assertSame($booked->id, $lead->fresh()->stage_id,
            'Booking an appointment should advance the lead to Consultation Booked.');
    }

    public function test_an_appointment_can_be_marked_visited(): void
    {
        $appointment = Appointment::create([
            'doctor_id'    => $this->doctor->id,
            'center_id'    => Center::value('id'),
            'scheduled_at' => now()->subHour(),
            'type'         => 'consultation',
            'status'       => 'booked',
            'created_by'   => $this->admin->id,
        ]);

        $this->actingAs($this->admin)
            ->putJson(route('appointments.update', $appointment), ['status' => 'visited'])
            ->assertOk();

        $this->assertSame('visited', $appointment->fresh()->status);
    }

    public function test_the_appointment_calendar_returns_events(): void
    {
        $this->actingAs($this->admin)
            ->getJson(route('appointments.calendar', [
                'start' => now()->startOfMonth()->toDateString(),
                'end'   => now()->endOfMonth()->toDateString(),
            ]))
            ->assertOk();
    }

    // =================================================================
    // Patients
    // =================================================================

    public function test_a_patient_gets_a_generated_code_at_registration_and_a_detail_page(): void
    {
        $patient = Patient::create([
            'wife_name' => 'Suite Patient',
            'mobile'    => '9791100009',
            'center_id' => Center::value('id'),
            'status'    => 'active',
        ]);

        // The ID is issued at the first visit, not when the record is opened.
        $this->assertNull($patient->patient_code);
        $patient->register();
        $this->assertStringStartsWith('PAT-', $patient->patient_code);
        $this->assertSame('Suite Patient', $patient->name);

        $this->actingAs($this->admin)
            ->get(route('patients.show', $patient))
            ->assertOk()
            ->assertSee('Suite Patient');
    }

    public function test_converting_a_lead_creates_a_linked_patient(): void
    {
        $lead = Lead::create(['name' => 'Convert Lead', 'mobile' => '9791100010', 'status' => 'new']);

        $patient = app(\App\Services\LeadService::class)->convertToPatient($lead);

        $this->assertNotNull($patient);
        $this->assertSame($lead->id, $patient->lead_id);
        $this->assertSame($patient->id, $lead->fresh()->patient_id);
    }

    // =================================================================
    // Reports
    // =================================================================

    #[DataProvider('reportRoutes')]
    public function test_reports_render_with_a_date_filter(string $route): void
    {
        $this->actingAs($this->admin)
            ->get(route($route, [
                'from' => now()->subDays(30)->toDateString(),
                'to'   => now()->toDateString(),
            ]))
            ->assertOk();
    }

    public static function reportRoutes(): array
    {
        return [
            'leads'      => ['reports.leads'],
            'telecaller' => ['reports.telecaller'],
            'source'     => ['reports.source'],
        ];
    }

    public function test_the_dashboard_returns_metrics_without_error(): void
    {
        $this->actingAs($this->admin)->get(route('dashboard'))->assertOk();
        $this->actingAs($this->admin)->get(route('dashboard.pipeline'))->assertOk();
        $this->actingAs($this->admin)->getJson(route('dashboard.telecaller_stats'))->assertOk();
    }

    // =================================================================
    // Imports
    // =================================================================

    public function test_the_sample_import_file_downloads(): void
    {
        $this->actingAs($this->admin)
            ->get(route('imports.sample'))
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');
    }

    public function test_an_import_rejects_a_non_spreadsheet_upload(): void
    {
        $this->actingAs($this->admin)
            ->post(route('imports.store'), [
                'file' => \Illuminate\Http\UploadedFile::fake()->create('notes.txt', 10, 'text/plain'),
            ])
            ->assertSessionHasErrors();
    }

    // =================================================================
    // Settings
    // =================================================================

    public function test_an_admin_can_create_and_deactivate_a_user(): void
    {
        $this->actingAs($this->admin)
            ->postJson(route('settings.users.store'), [
                'name'      => 'Created By Suite',
                'email'     => 'suite.created@example.com',
                'password'  => 'Password@123',
                'password_confirmation' => 'Password@123',
                'role'      => 'Telecaller',
                'center_id' => Center::value('id'),
                'is_active' => true,
            ])
            ->assertOk();

        $user = User::where('email', 'suite.created@example.com')->first();

        $this->assertNotNull($user);
        $this->assertTrue($user->hasRole('Telecaller'));

        $this->actingAs($this->admin)
            ->patchJson(route('settings.users.toggle_status', $user))
            ->assertOk();

        $this->assertFalse($user->fresh()->is_active);
    }

    public function test_a_centre_with_leads_cannot_be_deleted(): void
    {
        $center = Center::create(['name' => 'Suite Centre', 'code' => 'SUITE' . random_int(100, 999)]);

        Lead::create(['name' => 'Centre Lead', 'mobile' => '9791100011', 'status' => 'new', 'center_id' => $center->id]);

        $this->actingAs($this->admin)
            ->deleteJson(route('settings.centers.destroy', $center))
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertDatabaseHas('centers', ['id' => $center->id, 'deleted_at' => null]);
    }

    public function test_a_telecaller_cannot_reach_user_administration(): void
    {
        $this->actingAs($this->telecaller)->get(route('settings.users.index'))->assertForbidden();
        $this->actingAs($this->telecaller)->get(route('settings.roles.index'))->assertForbidden();
    }

    // =================================================================
    // API + webhooks
    // =================================================================

    public function test_the_v1_api_rejects_an_unauthenticated_request(): void
    {
        $this->postJson('/api/v1/leads', ['mobile' => '9791100012'])->assertUnauthorized();
    }

    public function test_the_v1_api_creates_a_lead_with_a_token(): void
    {
        $token = $this->admin->createToken('suite-test')->plainTextToken;

        $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson('/api/v1/leads', [
                'name'   => 'API Lead',
                'mobile' => '9791100013',
            ])
            ->assertSuccessful();

        $this->assertDatabaseHas('leads', ['mobile' => '9791100013']);
    }

    public function test_the_generic_lead_webhook_captures_a_lead(): void
    {
        $this->postJson('/api/webhooks/website', [
            'name'   => 'Webhook Lead',
            'mobile' => '9791100014',
            'email'  => 'webhook@example.com',
        ])->assertOk();

        $this->assertDatabaseHas('leads', ['mobile' => '9791100014']);
    }

    public function test_the_health_endpoint_is_public(): void
    {
        $this->get('/up')->assertOk();
    }

    // =================================================================
    // Cross-module consistency
    // =================================================================

    public function test_call_disposition_flows_through_to_the_lead_pipeline(): void
    {
        $lead = Lead::create(['name' => 'Flow Lead', 'mobile' => '9791100015', 'status' => 'new']);

        $disposition = Disposition::whereNull('parent_id')
            ->whereNotNull('moves_to_stage_id')
            ->where('is_active', true)
            ->first();

        if (! $disposition) {
            $this->markTestSkipped('No disposition maps to a pipeline stage.');
        }

        $call = \App\Models\CallLog::create([
            'lead_id'      => $lead->id,
            'user_id'      => $this->admin->id,
            'direction'    => 'outbound',
            'status'       => 'completed',
            'talk_seconds' => 60,
            'started_at'   => now()->subMinutes(2),
            'ended_at'     => now(),
        ]);

        app(\App\Services\Telephony\CallService::class)->disposition($call, [
            'disposition_id' => $disposition->id,
            'remark'         => 'Wants to visit.',
        ]);

        $lead->refresh();

        $this->assertSame($disposition->moves_to_stage_id, $lead->stage_id,
            'A call disposition should move the lead to the mapped pipeline stage.');
    }

    public function test_lead_activity_is_recorded_across_channels(): void
    {
        $lead = Lead::create(['name' => 'Timeline Lead', 'mobile' => '9791100016', 'status' => 'new']);

        // A call...
        $call = \App\Models\CallLog::create([
            'lead_id'      => $lead->id,
            'user_id'      => $this->admin->id,
            'direction'    => 'outbound',
            'status'       => 'completed',
            'talk_seconds' => 30,
            'started_at'   => now()->subMinute(),
            'answered_at'  => now()->subMinute(),
        ]);
        app(\App\Services\Telephony\CallService::class)->finalize($call, ['status' => 'completed']);

        // ...and a WhatsApp message.
        $conversation = \App\Models\Conversation::create([
            'channel'            => 'whatsapp',
            'contact_identifier' => '919791100016',
            'lead_id'            => $lead->id,
            'status'             => 'open',
        ]);
        app(\App\Services\Chat\ConversationService::class)->recordInbound($conversation, [
            'type' => 'text',
            'body' => 'Hello',
            'provider_message_id' => 'wamid.SUITE001',
        ]);

        $types = \App\Models\LeadActivity::where('lead_id', $lead->id)->pluck('type');

        $this->assertTrue($types->contains('call'), 'Calls must appear on the lead timeline.');
        $this->assertTrue($types->contains('message'), 'Messages must appear on the lead timeline.');
    }

    public function test_the_funnel_counts_a_lead_that_booked_and_visited(): void
    {
        $visited = PipelineStage::where('slug', 'consultation-visited')->first();

        $lead = Lead::create([
            'name'     => 'Funnel Lead',
            'mobile'   => '9791100017',
            'status'   => 'working',
            'stage_id' => $visited->id,
            'first_contacted_at' => now(),
        ]);

        Appointment::create([
            'lead_id'      => $lead->id,
            'doctor_id'    => $this->doctor->id,
            'center_id'    => Center::value('id'),
            'scheduled_at' => now(),
            'type'         => 'consultation',
            'status'       => 'visited',
            'created_by'   => $this->admin->id,
        ]);

        $funnel = app(\App\Services\Marketing\FunnelService::class)->build([
            'from' => now()->subDay()->toDateString(),
            'to'   => now()->toDateString(),
        ]);

        $steps = collect($funnel['steps'])->keyBy('key');

        $this->assertGreaterThanOrEqual(1, $steps['leads']['count']);
        $this->assertGreaterThanOrEqual(1, $steps['booked']['count']);
        $this->assertGreaterThanOrEqual(1, $steps['visited']['count']);

        // The funnel must never widen as it descends.
        $counts = collect($funnel['steps'])->pluck('count')->all();
        foreach ($counts as $i => $count) {
            if ($i > 0) {
                $this->assertLessThanOrEqual($counts[$i - 1], $count, 'The funnel widened at step ' . $i);
            }
        }
    }

    public function test_soft_deleted_leads_disappear_from_listings(): void
    {
        $lead = Lead::create(['name' => 'Delete Me', 'mobile' => '9791100018', 'status' => 'new']);

        $this->actingAs($this->admin)->delete(route('leads.destroy', $lead))->assertRedirect();

        $this->assertSoftDeleted('leads', ['id' => $lead->id]);
        $this->assertNull(Lead::find($lead->id));
        $this->assertNotNull(Lead::withTrashed()->find($lead->id));
    }
}
