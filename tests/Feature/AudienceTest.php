<?php

namespace Tests\Feature;

use App\Models\Patient;
use App\Models\User;
use App\Services\LeadService;
use App\Services\Meta\AudienceSyncService;
use App\Services\Meta\MetaAdsService;
use App\Services\PatientLinker;
use App\Support\PatientJourney;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\UsesProjectDatabase;
use Tests\TestCase;

/**
 * Custom audiences built from the patient list.
 *
 * The segments read across both halves of the same person — where the enquiry
 * came from and how far into treatment they got — which is only expressible
 * because a lead and a patient are one record. These tests pin the segment
 * definitions and, more importantly, that nothing readable is ever uploaded.
 */
class AudienceTest extends TestCase
{
    use DatabaseTransactions, UsesProjectDatabase;

    private function admin(): User
    {
        return User::whereHas('roles', fn ($q) => $q->where('name', 'Super Admin'))->first() ?? User::first();
    }

    /**
     * These tests delete settings rows to prove the unconfigured path. The rows
     * come back on transaction rollback, but SettingsRepository caches by group,
     * so the cache has to be dropped or the developer's own app would keep
     * serving the deleted state after the suite finishes.
     */
    protected function tearDown(): void
    {
        $repository = app(\App\Support\SettingsRepository::class);
        $repository->flush('meta');
        $repository->flush('google_ads');

        parent::tearDown();
    }

    private function mobile(): string
    {
        return '9' . random_int(100000000, 999999999);
    }

    public function test_a_hashed_row_never_carries_readable_identity(): void
    {
        $row = app(MetaAdsService::class)->hashAudienceRow([
            'email'      => 'Test.Person@Example.com ',
            'phone'      => '+91 98765 43210',
            'first_name' => 'Anita',
            'last_name'  => 'Sharma',
            'city'       => 'New Delhi',
            'state'      => 'Delhi',
            'zip'        => '110001',
        ]);

        $encoded = implode('|', $row);

        foreach (['test.person@example.com', '9876543210', 'Anita', 'Sharma', '110001'] as $secret) {
            $this->assertStringNotContainsString($secret, $encoded, "Raw value [{$secret}] was about to be uploaded.");
        }

        // Normalisation happens before hashing, or Meta's side will never match.
        $this->assertSame(hash('sha256', 'test.person@example.com'), $row[0]);
        $this->assertSame(hash('sha256', '919876543210'), $row[1]);
        $this->assertSame(hash('sha256', 'anita'), $row[2]);
        $this->assertSame(hash('sha256', 'newdelhi'), $row[4]);
    }

    public function test_a_blank_field_stays_blank_rather_than_hashing_to_a_shared_value(): void
    {
        // Hashing an empty string would give every incomplete record the same
        // value, which Meta would treat as a match between unrelated people.
        $row = app(MetaAdsService::class)->hashAudienceRow(['phone' => '9876543210']);

        $this->assertSame('', $row[0], 'An absent email was hashed instead of left blank.');
        $this->assertSame('', $row[2]);
        $this->assertNotSame(hash('sha256', ''), $row[0]);
    }

    public function test_the_schema_and_a_row_line_up(): void
    {
        $row = app(MetaAdsService::class)->hashAudienceRow(['phone' => '9876543210']);

        $this->assertCount(count(MetaAdsService::AUDIENCE_SCHEMA), $row,
            'The row and the schema Meta is told to expect are different lengths.');
    }

    public function test_a_couple_in_treatment_is_in_the_exclusion_segment_not_the_retargeting_one(): void
    {
        $lead = app(LeadService::class)->create([
            'name'   => 'In Treatment',
            'mobile' => $this->mobile(),
            'gclid'  => 'SEGMENT_TEST',
        ], autoAssign: false);

        $patient = Patient::find($lead->patient_id);
        app(PatientLinker::class)->advance($patient, PatientJourney::TREATMENT);

        $audiences = app(AudienceSyncService::class);

        $this->assertTrue(
            $audiences->query('patients')->whereKey($patient->id)->exists(),
            'A couple in treatment is missing from the exclusion audience.',
        );

        $this->assertFalse(
            $audiences->query('dropped_off')->whereKey($patient->id)->exists(),
            'A couple already in treatment would be retargeted as a stalled enquiry.',
        );
    }

    public function test_a_stalled_paid_enquiry_is_in_the_retargeting_segment(): void
    {
        $lead = app(LeadService::class)->create([
            'name'   => 'Stalled Person',
            'mobile' => $this->mobile(),
            'gclid'  => 'STALLED_TEST',
        ], autoAssign: false);

        $this->assertTrue(
            app(AudienceSyncService::class)->query('dropped_off')->whereKey($lead->patient_id)->exists(),
        );
    }

    public function test_somebody_with_no_contact_details_is_never_uploaded(): void
    {
        // Meta cannot match them, and including them only makes the audience
        // look larger than it is.
        $patient = Patient::create(['wife_name' => 'No Contact', 'journey_stage' => PatientJourney::TREATMENT]);

        $this->assertFalse(
            app(AudienceSyncService::class)->query('patients')->whereKey($patient->id)->exists(),
        );
    }

    public function test_the_audiences_page_lists_every_segment_with_its_size(): void
    {
        Http::fake();

        $response = $this->actingAs($this->admin())
            ->get(route('marketing.audiences.index'))
            ->assertOk();

        foreach (AudienceSyncService::SEGMENTS as [$label]) {
            $response->assertSee($label);
        }

        $response->assertSee('hashed with SHA-256', false);
    }

    public function test_syncing_without_meta_configured_explains_itself(): void
    {
        \App\Models\Setting::where('group', 'meta')->where('key', 'ad_account_id')->delete();
        app(\App\Support\SettingsRepository::class)->flush('meta');

        $this->actingAs($this->admin())
            ->postJson(route('marketing.audiences.sync'), ['segment' => 'patients'])
            ->assertStatus(422)
            ->assertJson(['success' => false]);
    }

    public function test_an_unknown_segment_is_refused(): void
    {
        $this->actingAs($this->admin())
            ->postJson(route('marketing.audiences.sync'), ['segment' => 'everyone'])
            ->assertStatus(422);
    }
}
