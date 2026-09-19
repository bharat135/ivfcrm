<?php

namespace Tests\Feature;

use App\Models\Campaign;
use App\Models\Center;
use App\Models\Disposition;
use App\Models\Lead;
use App\Models\LeadSource;
use App\Models\Patient;
use App\Models\User;
use App\Services\Meta\MetaSyncService;
use App\Services\Telephony\SmartfloClient;
use App\Support\SettingsRepository;
use Database\Seeders\DispositionSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The clinic's own configuration: one flat disposition list, one Meta lead
 * source, the couple's timeline stored as dates, leads captured per campaign,
 * and a Smartflo token that survives early invalidation.
 */
class IntakeConfigurationTest extends TestCase
{
    use DatabaseTransactions;

    private User $admin;
    private User $telecaller;

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
            'name'         => 'Intake Telecaller',
            'is_active'    => true,
            'is_available' => true,
            'center_id'    => $this->admin->center_id ?: Center::value('id'),
        ]);
        $this->telecaller->assignRole('Telecaller');
    }

    private function mobile(): string
    {
        return '9' . str_pad((string) random_int(0, 999999999), 9, '0', STR_PAD_LEFT);
    }

    // =================================================================
    // Dispositions
    // =================================================================

    public function test_the_clinic_disposition_list_is_flat_and_complete(): void
    {
        (new DispositionSeeder)->run();

        $active = Disposition::where('is_active', true)->get();

        $this->assertCount(21, $active);
        $this->assertTrue($active->every(fn ($d) => $d->parent_id === null), 'No sub-dispositions expected.');

        $names = $active->sortBy('sort_order')->pluck('name')->values()->all();
        $this->assertSame('Visit done', $names[0]);
        $this->assertSame('Not reachable', end($names));
        $this->assertContains('Appointment Booked', $names);

        $this->assertTrue(Disposition::where('slug', 'warm-lead')->value('requires_followup'));
        $this->assertTrue(Disposition::where('slug', 'wrong-number')->value('is_lost'));
        $this->assertFalse(Disposition::where('slug', 'visit-done')->value('is_lost'));

        // The retired tree is hidden, never deleted (old call logs still point at it).
        $this->assertSame(0, Disposition::where('is_active', true)->whereIn('slug', ['interested', 'follow-up', 'converted'])->count());
    }

    public function test_the_profile_disposition_form_offers_only_active_outcomes(): void
    {
        $lead = Lead::first();
        $this->assertNotNull($lead);

        $this->actingAs($this->admin)
            ->get(route('leads.show', $lead))
            ->assertOk()
            ->assertSee('Call back later')
            ->assertDontSee('Follow Up Next Week');
    }

    // =================================================================
    // Lead sources
    // =================================================================

    public function test_meta_is_the_only_social_lead_source(): void
    {
        $this->assertSame(0, LeadSource::whereIn('channel', ['facebook', 'instagram'])->count());
        $this->assertSame(0, LeadSource::whereIn('slug', ['facebook-ads', 'instagram-ads', 'facebook-lead-ads'])->count());

        $meta = LeadSource::where('slug', 'meta')->first();
        $this->assertNotNull($meta);
        $this->assertSame('meta', $meta->channel);

        $this->actingAs($this->admin)
            ->get(route('settings.lead_sources.index'))
            ->assertOk()
            ->assertSee('Meta (Facebook &amp; Instagram)', false)
            ->assertDontSee('>Instagram<', false);
    }

    // =================================================================
    // Couple dates
    // =================================================================

    public function test_ages_typed_on_the_lead_form_are_stored_as_dates_and_mirrored_to_the_patient(): void
    {
        $mobile = $this->mobile();

        $this->actingAs($this->telecaller)->post(route('leads.store'), [
            'name'          => 'Couple Dates',
            'mobile'        => $mobile,
            'gender'        => 'female',
            'wife_age'      => 30,
            'husband_age'   => 34,
            'years_married' => 6,
            'years_trying'  => 3,
        ])->assertRedirect();

        $lead = Lead::where('mobile', $mobile)->first();
        $this->assertNotNull($lead);

        $this->assertSame(30, $lead->wife_age_years);
        $this->assertSame(34, $lead->husband_age_years);
        $this->assertSame(6, $lead->years_married);
        $this->assertSame(3, $lead->years_trying);
        $this->assertSame(30, $lead->age, 'The legacy age column follows the wife\'s age.');
        $this->assertSame(now()->subYears(30)->year, $lead->wife_dob->year);

        $patient = Patient::where('lead_id', $lead->id)->first();
        $this->assertNotNull($patient);
        $this->assertSame(30, $patient->wife_age_years);
        $this->assertSame(30, $patient->wife_age);
        $this->assertSame(6, $patient->years_married);
        $this->assertSame(3, $patient->years_trying);
        $this->assertTrue($patient->marriage_date->isSameDay($lead->marriage_date));
    }

    public function test_editing_years_on_the_patient_updates_both_facets_without_resetting_unchanged_dates(): void
    {
        $mobile = $this->mobile();

        $this->actingAs($this->telecaller)->post(route('leads.store'), [
            'name' => 'Edit Dates', 'mobile' => $mobile, 'wife_age' => 28, 'years_married' => 4,
        ]);

        $lead    = Lead::where('mobile', $mobile)->first();
        $patient = Patient::where('lead_id', $lead->id)->first();

        // Pretend the marriage date was recorded precisely, some months ago.
        $precise = now()->subYears(4)->subMonths(5)->startOfDay();
        $patient->forceFill(['marriage_date' => $precise])->save();

        $this->actingAs($this->admin)->put(route('patients.update', $patient), [
            'wife_name'     => 'Edit Dates',
            'wife_age'      => 28,   // unchanged -> precise date must survive
            'husband_age'   => 31,   // new
            'years_married' => 4,    // unchanged -> precise date must survive
            'years_trying'  => 2,    // new
        ])->assertRedirect();

        $patient->refresh();
        $lead->refresh();

        $this->assertTrue($patient->marriage_date->isSameDay($precise), 'Same years typed must keep the precise date.');
        $this->assertSame(31, $patient->husband_age_years);
        $this->assertSame(31, $patient->husband_age);
        $this->assertSame(2, $patient->years_trying);

        $this->assertSame(31, $lead->husband_age_years, 'The lead facet mirrors the patient.');
        $this->assertTrue($lead->marriage_date->isSameDay($precise));
    }

    public function test_the_lead_edit_and_profile_screens_show_the_couple_fields(): void
    {
        $mobile = $this->mobile();
        $this->actingAs($this->telecaller)->post(route('leads.store'), [
            'name' => 'Screen Dates', 'mobile' => $mobile, 'wife_age' => 29, 'years_trying' => 2,
        ]);
        $lead = Lead::where('mobile', $mobile)->first();

        $this->actingAs($this->admin)->get(route('leads.edit', $lead))
            ->assertOk()->assertSee('name="years_trying"', false)->assertSee('value="29"', false);

        $this->actingAs($this->admin)->get(route('leads.show', $lead))
            ->assertOk()->assertSee('Trying')->assertSee('2 yrs');
    }

    // =================================================================
    // Meta leads captured per campaign
    // =================================================================

    public function test_a_meta_lead_takes_centre_agent_and_source_from_its_campaign(): void
    {
        $center = Center::where('is_active', true)->first();
        $this->assertNotNull($center);

        $campaign = Campaign::create([
            'name'        => 'Capture test campaign',
            'external_id' => 'cmp-' . random_int(100000, 999999),
            'platform'    => 'meta',
            'status'      => 'ACTIVE',
            'center_id'   => $center->id,
            'assign_to'   => $this->telecaller->id,
            'is_active'   => true,
        ]);

        $mobile   = $this->mobile();
        $remoteId = 'lg-' . random_int(100000, 999999);

        $created = app(MetaSyncService::class)->importLead([
            'id'           => $remoteId,
            'created_time' => now()->toIso8601String(),
            'campaign_id'  => $campaign->external_id,
            'form_id'      => 'form-that-is-not-mirrored',
            'field_data'   => [
                ['name' => 'full_name',    'values' => ['Campaign Couple']],
                ['name' => 'phone_number', 'values' => ['+91' . $mobile]],
            ],
        ]);

        $this->assertTrue($created);

        $lead = Lead::where('external_lead_id', $remoteId)->first();
        $this->assertNotNull($lead);
        $this->assertSame($campaign->id, $lead->campaign_id);
        $this->assertSame($center->id, $lead->center_id);
        $this->assertSame($this->telecaller->id, $lead->assigned_to);
        $this->assertSame(LeadSource::where('slug', 'meta')->value('id'), $lead->lead_source_id);
        $this->assertSame('Capture test campaign', $lead->utm_campaign);
    }

    public function test_an_unmirrored_campaign_is_fetched_on_demand_when_its_first_lead_arrives(): void
    {
        $settings = app(SettingsRepository::class);
        $settings->set('meta', 'access_token', 'user-token');
        $settings->set('meta', 'page_id', '111222333');
        $settings->set('meta', 'page_tokens', ['111222333' => 'page-token']);

        $external = 'cmp-new-' . random_int(100000, 999999);

        Http::fake([
            'graph.facebook.com/*/' . $external . '*' => Http::response([
                'id' => $external, 'name' => 'Brand new campaign', 'status' => 'ACTIVE', 'objective' => 'OUTCOME_LEADS',
            ]),
        ]);

        $remoteId = 'lg-' . random_int(100000, 999999);

        app(MetaSyncService::class)->importLead([
            'id'          => $remoteId,
            'campaign_id' => $external,
            'field_data'  => [['name' => 'phone_number', 'values' => ['+91' . $this->mobile()]]],
        ]);

        $campaign = Campaign::where('external_id', $external)->first();
        $this->assertNotNull($campaign, 'The campaign should have been mirrored on demand.');
        $this->assertSame('Brand new campaign', $campaign->name);
        $this->assertSame(LeadSource::where('slug', 'meta')->value('id'), $campaign->lead_source_id);
        $this->assertSame($campaign->id, Lead::where('external_lead_id', $remoteId)->value('campaign_id'));
    }

    public function test_campaign_screen_saves_the_agent_leads_are_routed_to(): void
    {
        $campaign = Campaign::create([
            'name' => 'Routing campaign', 'platform' => 'meta', 'status' => 'PAUSED', 'is_active' => false,
        ]);

        $this->actingAs($this->admin)->postJson(route('marketing.campaigns.update', $campaign), [
            '_method'   => 'PUT',
            'name'      => 'Routing campaign',
            'assign_to' => $this->telecaller->id,
        ])->assertOk();

        $this->assertSame($this->telecaller->id, $campaign->fresh()->assign_to);

        $this->actingAs($this->admin)->get(route('marketing.campaigns.index'))
            ->assertOk()->assertSee('Assign leads to');
    }

    // =================================================================
    // Tata Smartflo token
    // =================================================================

    public function test_a_rejected_smartflo_token_is_reminted_and_the_call_retried(): void
    {
        $settings = app(SettingsRepository::class);
        $settings->set('telephony', 'tata_login_id', 'agent@clinic.test');
        $settings->set('telephony', 'tata_password', 'secret');
        $settings->set('telephony', 'tata_access_token', 'stale-token');
        $settings->set('telephony', 'tata_token_expires_at', now()->addMinutes(40)->toDateTimeString());

        Http::fake([
            '*/v1/auth/login' => Http::response(['access_token' => 'fresh-token', 'token_type' => 'bearer', 'expires_in' => 3600]),
            '*/v1/users*'     => Http::sequence()
                ->push(['message' => 'Unauthenticated.'], 401)
                ->push([['id' => 1, 'name' => 'Agent One', 'agent' => ['number' => '9000000001']]], 200),
        ]);

        $client = app(SmartfloClient::class);
        $users  = $client->users();

        $this->assertCount(1, $users);
        $this->assertSame('fresh-token', $client->apiToken());

        Http::assertSentInOrder([
            fn ($r) => str_contains($r->url(), '/v1/users') && $r->hasHeader('Authorization', 'Bearer stale-token'),
            fn ($r) => str_contains($r->url(), '/v1/auth/login'),
            fn ($r) => str_contains($r->url(), '/v1/users') && $r->hasHeader('Authorization', 'Bearer fresh-token'),
        ]);
    }

    public function test_an_untracked_token_is_refreshed_by_the_scheduler_when_credentials_exist(): void
    {
        $settings = app(SettingsRepository::class);
        $settings->set('telephony', 'tata_login_id', 'agent@clinic.test');
        $settings->set('telephony', 'tata_password', 'secret');
        $settings->set('telephony', 'tata_access_token', 'pasted-by-hand');
        $settings->forget('telephony', 'tata_token_expires_at');

        $client = app(SmartfloClient::class);
        $this->assertTrue($client->tokenNeedsRefresh(3000));

        Http::fake(['*/v1/auth/login' => Http::response(['access_token' => 'tracked', 'expires_in' => 3600])]);

        $this->artisan('tata:refresh-token')->assertSuccessful();

        $this->assertSame('tracked', $client->apiToken());
        $this->assertNotNull($client->tokenExpiresAt());
        $this->assertFalse($client->tokenNeedsRefresh(60));
    }

    // =================================================================
    // Smartflo disposition codes, CDR pull, test leads
    // =================================================================

    public function test_smartflo_disposition_codes_are_at_most_five_characters_and_unique(): void
    {
        $sync  = app(\App\Services\Telephony\Tata\TataSyncService::class);
        $codes = Disposition::where('is_active', true)->get()->map(fn ($d) => $sync->smartfloCode($d));

        $this->assertTrue($codes->every(fn ($c) => mb_strlen($c) >= 1 && mb_strlen($c) <= 5), 'Smartflo rejects codes over 5 characters: ' . $codes->join(','));
        $this->assertSame($codes->count(), $codes->unique()->count(), 'Codes must be unique: ' . $codes->join(','));
        $this->assertSame('NLFT', $sync->smartfloCode(Disposition::where('slug', 'not-looking-for-treatment')->firstOrFail()));
    }

    public function test_a_smartflo_call_record_with_a_long_extension_is_stored(): void
    {
        $call = app(\App\Services\Telephony\CallService::class)->recordExternal([
            'provider'     => 'tata',
            'direction'    => 'outbound',
            'from_number'  => '+917965083657',
            'to_number'    => $this->mobile(),
            'agent_number' => 'Extension-0608031380004',
            'channel_id'   => 'cdr-' . random_int(1000, 9999),
            'started_at'   => now()->toDateTimeString(),
        ]);

        $this->assertNotNull($call->id);
        $this->assertSame('Extension-0608031380004', $call->fresh()->extension);
    }

    public function test_a_test_lead_runs_through_the_real_import_path_and_is_flagged(): void
    {
        $center   = Center::where('is_active', true)->first();
        $campaign = Campaign::create([
            'name' => 'Test-lead campaign', 'external_id' => 'cmp-t-' . random_int(100000, 999999),
            'platform' => 'meta', 'status' => 'ACTIVE', 'is_active' => true,
            'center_id' => $center->id, 'assign_to' => $this->telecaller->id,
        ]);
        $form = \App\Models\MetaLeadForm::create([
            'external_id' => 'form-t-' . random_int(100000, 999999), 'page_id' => '1', 'name' => 'Test button form',
            'status' => 'ACTIVE', 'campaign_id' => $campaign->id,
        ]);

        $response = $this->actingAs($this->admin)
            ->postJson(route('marketing.leadforms.test', $form))
            ->assertOk()
            ->assertJson(['success' => true]);

        $lead = Lead::where('external_lead_id', 'like', 'test-' . $form->id . '-%')->first();
        $this->assertNotNull($lead);
        $this->assertTrue((bool) ($lead->extra['is_test'] ?? false));
        $this->assertStringContainsString('TEST LEAD', (string) $lead->remark);
        $this->assertSame($campaign->id, $lead->campaign_id);
        $this->assertSame($center->id, $lead->center_id);
        $this->assertSame($this->telecaller->id, $lead->assigned_to);
        $this->assertSame(route('leads.show', $lead), $response->json('url'));

        // The lead-forms screen itself is a marketing screen, so a telecaller cannot fire tests.
        $this->actingAs($this->telecaller)->postJson(route('marketing.leadforms.test', $form))->assertForbidden();
    }
}
