<?php

namespace Tests\Feature;

use App\Models\Center;
use App\Models\Lead;
use App\Models\Patient;
use App\Models\User;
use App\Services\LeadService;
use App\Services\PatientLinker;
use App\Support\AcquisitionChannel;
use App\Support\PatientJourney;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\Concerns\UsesProjectDatabase;
use Tests\TestCase;

/**
 * Leads and patients are one person.
 *
 * These assertions are the contract: no intake path may create an enquiry
 * without a patient record, and the acquisition channel must survive from the
 * click that produced the lead all the way to the patient's profile.
 */
class UnifiedPeopleTest extends TestCase
{
    use DatabaseTransactions, UsesProjectDatabase;

    private function admin(): User
    {
        return User::whereHas('roles', fn ($q) => $q->where('name', 'Super Admin'))->first() ?? User::first();
    }

    public function test_creating_a_lead_opens_a_patient_record_immediately(): void
    {
        $lead = app(LeadService::class)->create([
            'name'        => 'Unified Test',
            'mobile'      => '9' . random_int(100000000, 999999999),
            'source_name' => 'phpunit',
        ], autoAssign: false);

        $this->assertNotNull($lead->patient_id, 'A lead was created without a patient record.');

        $patient = Patient::find($lead->patient_id);

        $this->assertSame($lead->id, $patient->lead_id, 'The patient is not linked back to the enquiry.');
        $this->assertSame(PatientJourney::ENQUIRY, $patient->journey_stage);
        $this->assertSame('Unified Test', $patient->wife_name);
    }

    public function test_a_google_ads_click_is_attributed_to_google_not_the_landing_page(): void
    {
        $lead = app(LeadService::class)->create([
            'name'       => 'Google Click',
            'mobile'     => '9' . random_int(100000000, 999999999),
            'gclid'      => 'Cj0KCQjw_TEST_CLICK_ID',
            'utm_source' => 'google',
            'utm_medium' => 'cpc',
        ], autoAssign: false);

        $this->assertSame(AcquisitionChannel::GOOGLE_ADS, $lead->channel);
        $this->assertSame(['network' => 'google', 'key' => 'gclid', 'value' => 'Cj0KCQjw_TEST_CLICK_ID'], $lead->clickIdentifier());
        $this->assertNotNull($lead->patient_id, 'A Google Ads lead must become a patient like any other.');
    }

    public function test_a_meta_lead_ad_is_attributed_to_meta(): void
    {
        $lead = app(LeadService::class)->create([
            'name'             => 'Meta Lead',
            'mobile'           => '9' . random_int(100000000, 999999999),
            'external_lead_id' => '1234567890',
        ], autoAssign: false);

        $this->assertSame(AcquisitionChannel::META_ADS, $lead->channel);
    }

    public function test_the_same_number_enquiring_twice_stays_one_patient(): void
    {
        $mobile = '9' . random_int(100000000, 999999999);
        $linker = app(PatientLinker::class);

        $first = Lead::create(['name' => 'First', 'mobile' => $mobile]);
        $one   = $linker->ensureFor($first);

        $second = Lead::create(['name' => 'Second', 'mobile' => $mobile]);
        $two    = $linker->ensureFor($second);

        $this->assertSame($one->id, $two->id, 'A second enquiry opened a duplicate patient file.');
    }

    public function test_the_journey_only_moves_forward(): void
    {
        $linker = app(PatientLinker::class);
        $lead   = Lead::create(['name' => 'Forward', 'mobile' => '9' . random_int(100000000, 999999999)]);
        $patient = $linker->ensureFor($lead);

        $linker->advance($patient, PatientJourney::TREATMENT);
        $this->assertSame(PatientJourney::TREATMENT, $patient->refresh()->journey_stage);

        // A late webhook must not demote a couple already in treatment.
        $linker->advance($patient, PatientJourney::CONTACTED);
        $this->assertSame(PatientJourney::TREATMENT, $patient->refresh()->journey_stage);
    }

    public function test_the_unified_list_renders_and_serves_rows(): void
    {
        $this->actingAs($this->admin())->get(route('patients.index'))->assertOk()->assertSee('Patients');

        $json = $this->actingAs($this->admin())
            ->getJson(route('patients.index') . '?draw=1&start=0&length=10')
            ->assertOk()
            ->json();

        $this->assertArrayHasKey('data', $json);
        $this->assertArrayHasKey('recordsTotal', $json);
    }

    public function test_a_patient_profile_shows_where_the_person_came_from(): void
    {
        $lead = app(LeadService::class)->create([
            'name'       => 'Profile Person',
            'mobile'     => '9' . random_int(100000000, 999999999),
            'gclid'      => 'GCLID_PROFILE',
            'utm_source' => 'google',
            'utm_medium' => 'cpc',
        ], autoAssign: false);

        $this->actingAs($this->admin())
            ->get(route('patients.show', $lead->patient_id))
            ->assertOk()
            // The detail rail must name the channel that paid for this person
            // and the click id a conversion upload will need.
            ->assertSee('Came from')
            ->assertSee('Google Ads')
            ->assertSee('GCLID_PROFILE');
    }

    public function test_the_enquiry_screen_shows_the_patient_file_and_the_same_funnel(): void
    {
        $lead = app(LeadService::class)->create([
            'name'       => 'Enquiry Screen',
            'mobile'     => '9' . random_int(100000000, 999999999),
            'gclid'      => 'GCLID_ENQUIRY_SCREEN',
            'utm_source' => 'google',
            'utm_medium' => 'cpc',
        ], autoAssign: false);

        $patient = Patient::find($lead->patient_id);

        $this->actingAs($this->admin())
            ->get(route('leads.show', $lead))
            ->assertOk()
            // The enquiry screen must name the patient record it belongs to...
            ->assertSee($patient->patient_code)
            // ...show the one shared funnel...
            ->assertSee('journey-stepper', false)
            // ...and carry the same attribution as the patient screen, because
            // /leads/{id} and /patients/{id} render the one profile.
            ->assertSee('Came from')
            ->assertSee('Google Ads')
            ->assertSee('GCLID_ENQUIRY_SCREEN');
    }

    public function test_the_leads_worklist_shows_and_filters_by_channel(): void
    {
        $lead = app(LeadService::class)->create([
            'name'       => 'Worklist Person',
            'mobile'     => '9' . random_int(100000000, 999999999),
            'gclid'      => 'GCLID_WORKLIST',
            'utm_source' => 'google',
            'utm_medium' => 'cpc',
        ], autoAssign: false);

        $this->actingAs($this->admin())->get(route('leads.index'))->assertOk()->assertSee('All channels');

        $rows = $this->actingAs($this->admin())
            ->getJson(route('leads.index') . '?draw=1&start=0&length=50&channel=' . AcquisitionChannel::GOOGLE_ADS)
            ->assertOk()
            ->json('data');

        $ids = array_column($rows, 'id');
        $this->assertContains($lead->id, $ids, 'A Google Ads lead is missing from the google_ads filter.');

        foreach ($rows as $row) {
            $this->assertStringContainsString('Google Ads', $row['origin']);
        }

        // ...and it must not leak into another channel's worklist.
        $other = $this->actingAs($this->admin())
            ->getJson(route('leads.index') . '?draw=1&start=0&length=50&channel=' . AcquisitionChannel::META_ADS)
            ->assertOk()
            ->json('data');

        $this->assertNotContains($lead->id, array_column($other, 'id'));
    }

    // -----------------------------------------------------------------
    // The one profile screen
    // -----------------------------------------------------------------

    public function test_both_urls_render_the_same_profile(): void
    {
        $lead = app(LeadService::class)->create([
            'name'   => 'Same Screen',
            'mobile' => '9' . random_int(100000000, 999999999),
        ], autoAssign: false);

        $viaPatient = $this->actingAs($this->admin())
            ->get(route('patients.show', $lead->patient_id))->assertOk()->getContent();

        $viaLead = $this->actingAs($this->admin())
            ->get(route('leads.show', $lead))->assertOk()->getContent();

        // Both must carry the profile shell and the same identity.
        foreach ([$viaPatient, $viaLead] as $html) {
            $this->assertStringContainsString('profile-shell', $html);
            $this->assertStringContainsString('profile-rail', $html);
            $this->assertStringContainsString('Same Screen', $html);
        }
    }

    public function test_the_profile_carries_every_tab(): void
    {
        $lead = app(LeadService::class)->create([
            'name'   => 'Tabbed Person',
            'mobile' => '9' . random_int(100000000, 999999999),
        ], autoAssign: false);

        $response = $this->actingAs($this->admin())
            ->get(route('patients.show', $lead->patient_id))
            ->assertOk();

        foreach (['Overview', 'Tasks', 'Appointments', 'Calls', 'Messages', 'Files', 'Treatment', 'Notes'] as $tab) {
            $response->assertSee($tab);
        }
    }

    public function test_a_requested_tab_opens_active(): void
    {
        $lead = app(LeadService::class)->create([
            'name'   => 'Deep Link',
            'mobile' => '9' . random_int(100000000, 999999999),
        ], autoAssign: false);

        $html = $this->actingAs($this->admin())
            ->get(route('patients.show', ['patient' => $lead->patient_id, 'tab' => 'tasks']))
            ->assertOk()
            ->getContent();

        // The Tasks button is the active one, not Overview.
        $this->assertMatchesRegularExpression(
            '/class="nav-link active"[^>]*data-bs-target="#tabTasks"/',
            $html,
            'The ?tab= parameter did not open that tab.',
        );
    }

    public function test_the_task_board_groups_follow_ups_by_what_is_actually_due(): void
    {
        $lead = app(LeadService::class)->create([
            'name'   => 'Board Person',
            'mobile' => '9' . random_int(100000000, 999999999),
        ], autoAssign: false);

        \App\Models\FollowUp::create([
            'lead_id'      => $lead->id,
            'user_id'      => $this->admin()->id,
            'type'         => 'call',
            'scheduled_at' => now()->subDays(3),
            'status'       => 'pending',
            'remark'       => 'Overdue callback',
        ]);

        \App\Models\FollowUp::create([
            'lead_id'      => $lead->id,
            'user_id'      => $this->admin()->id,
            'type'         => 'whatsapp',
            'scheduled_at' => now()->addDays(3),
            'status'       => 'pending',
            'remark'       => 'Future nudge',
        ]);

        $profile = app(\App\Services\PatientProfile::class)->forLead($lead->refresh());
        $tasks   = $profile['tasks'];

        // The same status, but only one of them is a job for today.
        $this->assertSame(1, $tasks['overdue']['items']->count());
        $this->assertSame('Overdue callback', $tasks['overdue']['items']->first()->remark);

        $this->assertSame(1, $tasks['todo']['items']->count());
        $this->assertSame('Future nudge', $tasks['todo']['items']->first()->remark);

        // An empty Missed column would be noise, so it is dropped entirely.
        $this->assertArrayNotHasKey('missed', $tasks);

        $this->actingAs($this->admin())
            ->get(route('patients.show', ['patient' => $lead->patient_id, 'tab' => 'tasks']))
            ->assertOk()
            ->assertSee('Due now')
            ->assertSee('Overdue callback')
            ->assertSee('Future nudge');
    }

    public function test_the_rail_shows_the_treatment_card_when_a_cycle_exists(): void
    {
        $lead = app(LeadService::class)->create([
            'name'   => 'Cycle Person',
            'mobile' => '9' . random_int(100000000, 999999999),
        ], autoAssign: false);

        \App\Models\TreatmentCycle::create([
            'patient_id'     => $lead->patient_id,
            'cycle_code'     => 'CYC-TEST-01',
            'type'           => 'IVF',
            'current_stage'  => 'Stimulation',
            'status'         => 'ongoing',
            'started_on'     => now()->subWeeks(2),
            'package_amount' => 200000,
            'paid_amount'    => 100000,
        ]);

        $this->actingAs($this->admin())
            ->get(route('patients.show', $lead->patient_id))
            ->assertOk()
            ->assertSee('CYC-TEST-01')
            ->assertSee('Stimulation')
            ->assertSee('Package value')
            // Half paid, computed rather than stored.
            ->assertSee('50%');
    }
}
